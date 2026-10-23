<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleFirebaseBridge;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5FirebasePreparedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled', true);
        config()->set('orbit_circle.firebase.enabled', false);
    }

    private function person(string $name): array
    {
        $user = OrbitUser::create([
            'username' => $name.'_'.Str::lower(Str::random(7)),
            'display_name' => $name, 'status' => 'active',
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id, 'device_public_id' => (string) Str::uuid(),
            'name' => 'M5 Firebase QA', 'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)),
            'trust_state' => 'trusted', 'attestation_state' => 'verified',
            'approved_at' => now(),
        ]);
        $token = app(TokenService::class)->issue($user, $device, null)['access_token'];
        $session = OrbitSession::query()->where('user_id', $user->id)->latest('created_at')->firstOrFail();
        return [$user, $device, $session, $token];
    }

    /** Synthetic private key lives only in a temporary CI file, never GitHub. */
    private function configureTemporaryCredentials(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $file = tempnam(sys_get_temp_dir(), 'm5-firebase-ci-');
        file_put_contents($file, json_encode([
            'type' => 'service_account',
            'client_email' => 'orbit-qa@orbit-ci.iam.gserviceaccount.com',
            'project_id' => 'orbit-ci',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));
        config()->set('orbit_circle.firebase.enabled', true);
        config()->set('orbit_circle.firebase.service_account_path', $file);
        config()->set('orbit_circle.firebase.project_id', 'orbit-ci');
        config()->set('orbit_circle.firebase.database_url', 'https://orbit-ci-default-rtdb.firebaseio.com');
        config()->set('orbit_circle.firebase.api_key', 'fake-ci-public-key');
        config()->set('orbit_circle.firebase.app_id', '1:123:android:abc');
        config()->set('orbit_circle.firebase.sender_id', '123');
        return $file;
    }

    public function test_firebase_rules_are_deny_by_default_and_session_scoped(): void
    {
        $rules = json_decode(file_get_contents(base_path('firebase/database.rules.json')),
            true, 32, JSON_THROW_ON_ERROR)['rules'];
        $this->assertFalse($rules['.read']);
        $this->assertFalse($rules['.write']);
        $this->assertFalse($rules['orbit_m5']['acl']['.read']);
        $this->assertFalse($rules['orbit_m5']['acl']['.write']);
        $revision = $rules['orbit_m5']['revisions']['$circleId'];
        $this->assertFalse($revision['.write']);
        $this->assertStringContainsString('auth.uid', $revision['.read']);
        $this->assertStringContainsString('orbit_session', $revision['.read']);
        $this->assertStringContainsString('orbit_device', $revision['.read']);
    }

    public function test_firebase_is_fully_inert_until_both_server_gates_are_enabled(): void
    {
        Http::fake();
        [$owner, $device, $session, $token] = $this->person('owner');
        $circle = $this->withToken($token)->postJson('/api/v1/circles', [
            'name' => 'Private',
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/circles/realtime/bootstrap')
            ->assertNotFound();
        $this->assertSame(0, app(CircleFirebaseBridge::class)->publishPending());
        Http::assertNothingSent();
        $this->assertDatabaseHas('circle_event_outbox', [
            'circle_id' => $circle, 'processed_at' => null,
        ]);
        config()->set('orbit_circle.firebase.enabled', true);
        config()->set('orbit_circle.enabled', false);
        $this->withToken($token)->postJson('/api/v1/circles/realtime/bootstrap')
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_trusted_session_receives_verifiable_scoped_custom_token_not_private_key(): void
    {
        $file = $this->configureTemporaryCredentials();
        try {
            [$user, $device, $session, $token] = $this->person('verified');
            $payload = $this->withToken($token)
                ->postJson('/api/v1/circles/realtime/bootstrap')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->json('data');
            $this->assertTrue($payload['enabled']);
            $this->assertSame($session->id, $payload['uid']);
            $this->assertSame(900, $payload['expires_in']);
            $jwt = $payload['custom_token'];
            $parts = explode('.', $jwt);
            $this->assertCount(3, $parts);
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $this->assertSame($session->id, $claims['uid']);
            $this->assertSame($device->id, $claims['claims']['orbit_device']);
            $this->assertSame($session->id, $claims['claims']['orbit_session']);
            $this->assertTrue($claims['claims']['orbit_m5']);
            $creds = json_decode(file_get_contents($file), true);
            $private = openssl_pkey_get_private($creds['private_key']);
            $public = openssl_pkey_get_details($private)['key'];
            $signature = base64_decode(strtr($parts[2], '-_', '+/'));
            $this->assertSame(1, openssl_verify($parts[0].'.'.$parts[1],
                $signature, $public, OPENSSL_ALGO_SHA256));
            $this->assertArrayNotHasKey('private_key', $payload);
            $this->assertArrayNotHasKey('client_email', $payload);
            $this->assertArrayNotHasKey('location', $payload);
            $device->update(['trust_state' => 'pending']);
            $this->withToken($token)->postJson('/api/v1/circles/realtime/bootstrap')
                ->assertForbidden();
        } finally {
            @unlink($file);
        }
    }

    public function test_logout_clears_published_session_acl_even_if_membership_remains(): void
    {
        $file = $this->configureTemporaryCredentials();
        try {
            Http::fake([
                'https://oauth2.googleapis.com/token' =>
                    Http::response(['access_token' => 'fake-service-token'], 200),
                'https://orbit-ci-default-rtdb.firebaseio.com/orbit_m5.json' =>
                    Http::response(['ok' => true], 200),
            ]);
            [$owner, $device, $session, $token] = $this->person('signout');
            $circle = $this->withToken($token)->postJson('/api/v1/circles', [
                'name' => 'Logout revocation',
            ])->assertCreated()->json('data.id');
            $publisher = app(CircleFirebaseBridge::class);
            $publisher->publishPending(20);
            $initial = Http::recorded(fn($req) => $req->method() === 'PATCH')
                ->last()[0]->data();
            $this->assertSame($device->id, $initial['acl/'.$circle][$session->id]);
            $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
            $this->assertDatabaseHas('circle_event_outbox', [
                'circle_id' => $circle,
                'event_type' => 'circle.session_access_changed',
            ]);
            $publisher->publishPending(20);
            $revoked = Http::recorded(fn($req) => $req->method() === 'PATCH')
                ->last()[0]->data();
            $this->assertNull($revoked['acl/'.$circle]);
            $this->withToken($token)
                ->postJson('/api/v1/circles/realtime/bootstrap')->assertUnauthorized();
        } finally {
            @unlink($file);
        }
    }

    public function test_publisher_emits_only_session_acl_and_revision_then_removes_left_member(): void
    {
        $file = $this->configureTemporaryCredentials();
        try {
            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'fake-service-token',
                ], 200),
                'https://orbit-ci-default-rtdb.firebaseio.com/orbit_m5.json' =>
                    Http::response(['ok' => true], 200),
            ]);
            [$owner, $deviceA, $sessionA, $tokenA] = $this->person('alpha');
            [$guest, $deviceB, $sessionB, $tokenB] = $this->person('bravo');
            config()->set('orbit_circle.targeted_invites_enabled', true);
            config()->set('orbit_circle.targeted_invite_lifetime_hours', 168);
            $request = $this->withToken($tokenA)->postJson('/api/v1/friends/requests', [
                'recipient_id' => $guest->id,
            ])->assertCreated()->json('data.id');
            $this->withToken($tokenB)->postJson(
                '/api/v1/friends/requests/'.$request.'/accept')->assertOk();
            $circle = $this->withToken($tokenA)->postJson('/api/v1/circles', [
                'name' => 'Sensitive Circle Name',
            ])->assertCreated()->json('data.id');
            $invite = $this->withToken($tokenA)->postJson(
                '/api/v1/circles/'.$circle.'/invites', [
                    'invitee_id' => $guest->id,
                ])->assertCreated()->json('data.id');
            $this->withToken($tokenB)->postJson(
                '/api/v1/circles/invites/'.$invite.'/accept')->assertOk();

            app(CircleFirebaseBridge::class)->publishPending(20);
            $patches = Http::recorded(fn($req) => $req->method() === 'PATCH');
            $this->assertNotEmpty($patches);
            $last = $patches->last()[0]->data();
            $this->assertSame($deviceA->id, $last['acl/'.$circle][$sessionA->id]);
            $this->assertSame($deviceB->id, $last['acl/'.$circle][$sessionB->id]);
            $this->assertGreaterThan(0, $last['revisions/'.$circle]);
            $this->assertStringNotContainsString('Sensitive Circle Name', json_encode($last));
            $this->assertStringNotContainsString('invitee_id', json_encode($last));

            $this->withToken($tokenB)->postJson('/api/v1/circles/'.$circle.'/leave')->assertOk();
            app(CircleFirebaseBridge::class)->publishPending(20);
            $after = Http::recorded(fn($req) => $req->method() === 'PATCH')->last()[0]->data();
            $this->assertArrayNotHasKey($sessionB->id, $after['acl/'.$circle]);
            $this->assertSame($deviceA->id, $after['acl/'.$circle][$sessionA->id]);
            $this->assertDatabaseHas('circle_event_outbox', [
                'circle_id' => $circle, 'event_type' => 'circle.left',
            ]);
        } finally {
            @unlink($file);
        }
    }
}
