<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function createdCode(string $recipient, string $code = '472819'): void
    {
        $id = (string) Str::uuid7();
        $secret = hash('sha256', config('app.key').'|orbit-otp-v1', true);
        $digest = hash_hmac('sha256', 'email|'.strtolower($recipient), $secret);
        DB::table('otp_challenges')->insert([
            'id' => $id, 'channel' => 'email',
            'recipient_hash' => $digest,
            'code_hash' => hash_hmac('sha256', $id.'|'.$code, $secret),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function signIn(string $email = 'member@example.test', string $deviceId = 'dc2dca36-eb23-4a02-92bf-af94d7f58000'): array
    {
        $this->createdCode($email);
        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'channel' => 'email', 'recipient' => $email, 'code' => '472819',
            'device_public_id' => $deviceId, 'platform' => 'android', 'device_name' => 'Test Android',
        ])->assertOk();
        return $response->json('data');
    }

    private function registerTrustedDevice(array $tokens): void
    {
        $this->withToken($tokens['access_token'])->postJson('/api/v1/devices/register', [
            'public_key' => base64_encode(random_bytes(32)),
        ])->assertOk();
    }

    public function test_otp_request_uses_configured_mail_transport_without_returning_code(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/otp/request', [
            'channel' => 'email', 'recipient' => 'member@example.test',
        ])->assertStatus(202)->assertJsonMissing(['code' => true]);
        $this->assertSame(1, DB::table('otp_challenges')->count());
    }

    public function test_invalid_and_expired_otp_are_rejected(): void
    {
        $this->createdCode('member@example.test');
        $input = ['channel' => 'email', 'recipient' => 'member@example.test',
            'code' => '000000', 'device_public_id' => (string) Str::uuid7(),
            'platform' => 'ios', 'device_name' => 'My iPhone'];
        $this->postJson('/api/v1/auth/otp/verify', $input)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 0);
        DB::table('otp_challenges')->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/otp/verify', array_merge($input,['code'=>'472819']))->assertUnprocessable();
    }

    public function test_otp_creates_user_device_and_session_and_prevents_code_reuse(): void
    {
        $tokens = $this->signIn();
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(900, $tokens['expires_in']);
        $this->assertTrue($tokens['device_requires_registration']);
        $this->assertDatabaseCount('orbit_users', 1);
        $this->assertDatabaseCount('user_identities', 1);
        $this->assertDatabaseCount('devices', 1);
        $this->assertDatabaseCount('orbit_sessions', 1);
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertForbidden();
        $this->registerTrustedDevice($tokens);
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $tokens['user']['id']);
        $this->postJson('/api/v1/auth/otp/verify', [
            'channel'=>'email', 'recipient'=>'member@example.test', 'code'=>'472819',
            'device_public_id'=>(string) Str::uuid7(), 'platform'=>'android','device_name'=>'Other',
        ])->assertUnprocessable();
    }

    public function test_rotated_refresh_replay_revokes_device_session(): void
    {
        $initial = $this->signIn();
        $next = $this->postJson('/api/v1/auth/refresh', ['refresh_token'=>$initial['refresh_token']])
            ->assertOk()->json('data');
        $this->assertNotSame($initial['refresh_token'], $next['refresh_token']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token'=>$initial['refresh_token']])->assertUnauthorized();
        $this->withToken($next['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertNotNull(OrbitSession::firstOrFail()->revoked_at);
    }

    public function test_device_registration_rejects_public_key_overwrite_and_unauthorized_users(): void
    {
        $initial = $this->signIn();
        $valid = base64_encode(random_bytes(32));
        $this->postJson('/api/v1/devices/register', ['public_key'=>$valid])->assertUnauthorized();
        $this->withToken($initial['access_token'])
            ->postJson('/api/v1/devices/register', ['public_key'=>$valid])->assertOk();
        $this->withToken($initial['access_token'])
            ->postJson('/api/v1/devices/register', ['public_key'=>base64_encode(random_bytes(32))])->assertStatus(409);
        $this->assertSame($valid, OrbitDevice::firstOrFail()->public_key);
    }

    public function test_revoke_another_device_invalidates_only_that_devices_sessions(): void
    {
        $a = $this->signIn('member@example.test');
        $b = $this->signIn('member@example.test','ac2dca36-eb23-4a02-92bf-af94d7f58000');
        $this->assertTrue($b['device_requires_approval']);
        $this->registerTrustedDevice($a);
        $this->withToken($b['access_token'])->getJson('/api/v1/me')->assertForbidden();
        $this->withToken($a['access_token'])->deleteJson('/api/v1/me/devices/'.$b['device']['id'])->assertOk();
        $this->withToken($b['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($a['access_token'])->getJson('/api/v1/me')->assertOk();
    }


    public function test_user_lists_and_revokes_a_specific_session(): void
    {
        $initial = $this->signIn();
        $this->registerTrustedDevice($initial);
        $this->withToken($initial['access_token'])->getJson('/api/v1/me/sessions')->assertOk()
            ->assertJsonCount(1, 'data');
        $session = OrbitSession::firstOrFail();
        $this->withToken($initial['access_token'])
            ->deleteJson('/api/v1/me/sessions/'.$session->id)->assertOk();
        $this->withToken($initial['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_twilio_verify_service_is_used_for_sms_and_is_single_use(): void
    {
        config()->set('orbit_identity.sms.sid', 'AC'.str_repeat('a', 32));
        config()->set('orbit_identity.sms.token', 'test-token');
        config()->set('orbit_identity.sms.verify_service_sid', 'VA'.str_repeat('b', 32));
        config()->set('orbit_identity.sms.from', null);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_ends_with($request->url(), '/Verifications')) {
                return Http::response(['status' => 'pending'], 201);
            }
            if (str_ends_with($request->url(), '/VerificationCheck')) {
                return Http::response(['status' => $request['Code'] === '472819' ? 'approved' : 'pending'], 200);
            }
            return Http::response([], 404);
        });

        $this->postJson('/api/v1/auth/otp/request', [
            'channel' => 'sms', 'recipient' => '+923001234567',
        ])->assertStatus(202);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $req) =>
            str_ends_with($req->url(), '/Verifications') && $req['Channel'] === 'sms'
            && $req['To'] === '+923001234567');
        $payload = [
            'channel' => 'sms', 'recipient' => '+923001234567',
            'code' => '000000', 'device_public_id' => (string) Str::uuid7(),
            'platform' => 'android', 'device_name' => 'SMS test device',
        ];
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 0);
        $payload['code'] = '472819';
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertOk()->assertJsonPath('data.device_requires_registration', true);
        $this->assertDatabaseCount('orbit_users', 1);
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 1);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $req) =>
            str_ends_with($req->url(), '/VerificationCheck') && $req['Code'] === '472819');
    }

    public function test_sms_verify_without_valid_credentials_fails_closed(): void
    {
        config()->set('orbit_identity.sms.verify_service_sid', 'VA'.str_repeat('b', 32));
        config()->set('orbit_identity.sms.sid', 'AC'.str_repeat('a', 32));
        config()->set('orbit_identity.sms.token', null);
        $this->postJson('/api/v1/auth/otp/request', [
            'channel' => 'sms', 'recipient' => '+923001234567',
        ])->assertStatus(503);
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_logout_all_revokes_every_session(): void
    {
        $first = $this->signIn();
        $this->registerTrustedDevice($first);
        $this->withToken($first['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->withToken($first['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertSame(0, OrbitSession::whereNull('revoked_at')->count());
    }

    public function test_deletion_request_records_pending_erasure_and_revokes_tokens(): void
    {
        $first = $this->signIn();
        $this->registerTrustedDevice($first);
        $this->withToken($first['access_token'])->postJson('/api/v1/me/deletion-request', ['confirm' => true])
            ->assertStatus(202)->assertJsonFragment(['message' => 'Account deletion request recorded. All sessions were signed out. Final deletion is pending processing.']);
        $this->assertNotNull(OrbitUser::firstOrFail()->deletion_requested_at);
        $this->assertDatabaseCount('orbit_users', 1);
        $this->withToken($first['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_linking_verified_email_preserves_original_account_and_is_single_use(): void
    {
        $initial = $this->signIn();
        $this->registerTrustedDevice($initial);
        $this->createdCode('linked@example.test');
        $payload = ['channel' => 'email', 'recipient' => 'linked@example.test', 'code' => '472819'];
        $this->withToken($initial['access_token'])->postJson('/api/v1/me/identities/link', $payload)->assertOk();
        $this->assertDatabaseCount('orbit_users', 1);
        $this->assertDatabaseCount('user_identities', 2);
        $this->withToken($initial['access_token'])->getJson('/api/v1/me')
            ->assertJsonPath('data.verified_methods.1', 'email');
        $this->withToken($initial['access_token'])->postJson('/api/v1/me/identities/link', $payload)
            ->assertStatus(409);
        $this->assertDatabaseCount('user_identities', 2);
    }

    public function test_cannot_link_identity_already_used_by_another_account(): void
    {
        $first = $this->signIn();
        $this->registerTrustedDevice($first);
        $second = $this->signIn('second@example.test', 'cc2dca36-eb23-4a02-92bf-af94d7f58000');
        $this->registerTrustedDevice($second);
        $this->createdCode('member@example.test');
        $this->withToken($second['access_token'])
            ->postJson('/api/v1/me/identities/link', [
                'channel' => 'email', 'recipient' => 'member@example.test', 'code' => '472819',
            ])->assertStatus(409);
        $this->assertDatabaseCount('orbit_users', 2);
        $this->assertDatabaseCount('user_identities', 2);
    }

    public function test_admin_user_view_is_not_public(): void
    {
        $this->get('/admin/users')->assertRedirect(route('orbit.admin.login'));
        $this->get('/api/v1/me/devices')->assertUnauthorized();
    }
}
