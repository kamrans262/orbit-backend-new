<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function createdCode(string $recipient, string $code = '472819', string $purpose = 'signup'): void
    {
        $id = (string) Str::uuid7();
        $secret = hash('sha256', config('app.key').'|orbit-otp-v1', true);
        $digest = hash_hmac('sha256', 'email|'.strtolower($recipient), $secret);
        DB::table('otp_challenges')->insert([
            'id' => $id, 'channel' => 'email', 'purpose' => $purpose,
            'recipient_hash' => $digest,
            'code_hash' => hash_hmac('sha256', $id.'|'.$code, $secret),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function signIn(string $email = 'member@example.test', string $deviceId = 'dc2dca36-eb23-4a02-92bf-af94d7f58000'): array
    {
        $isExisting = \App\Models\UserIdentity::where('provider', 'email')
            ->where('provider_subject', strtolower($email))->exists();
        if ($isExisting) {
            $record = \App\Models\UserIdentity::where('provider_subject', strtolower($email))->firstOrFail();
            OrbitUser::whereKey($record->user_id)->whereNull('password_hash')
                ->update(['password_hash' => Hash::make('ExistingTestPassword123!')]);
        }
        $purpose = $isExisting ? 'login' : 'signup';
        $this->createdCode($email, '472819', $purpose);
        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'channel' => 'email', 'purpose' => $purpose,
            'recipient' => $email, 'code' => '472819',
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
        $input = ['channel' => 'email', 'purpose' => 'signup', 'recipient' => 'member@example.test',
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
            'channel'=>'email', 'purpose'=>'signup', 'recipient'=>'member@example.test', 'code'=>'472819',
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
            'channel' => 'sms', 'purpose' => 'signup', 'recipient' => '+923001234567',
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
        $this->createdCode('linked@example.test', '472819', 'link');
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
        $this->createdCode('member@example.test', '472819', 'link');
        $this->withToken($second['access_token'])
            ->postJson('/api/v1/me/identities/link', [
                'channel' => 'email', 'recipient' => 'member@example.test', 'code' => '472819',
            ])->assertStatus(409);
        $this->assertDatabaseCount('orbit_users', 2);
        $this->assertDatabaseCount('user_identities', 2);
    }

    public function test_admin_user_lookup_filters_by_public_username_and_status(): void
    {
        $admin = \App\Models\AdminUser::create([
            'name' => 'Support', 'email' => 'support@example.test',
            'password' => 'TestingPassword123!', 'totp_secret' => (new \App\Modules\Config\Services\Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $role = \App\Models\AdminRole::create(['name' => 'Support', 'permissions' => ['users.read']]);
        $admin->roles()->attach($role->id);
        OrbitUser::create(['display_name' => 'First', 'username' => 'alpha_test', 'status' => 'active']);
        OrbitUser::create(['display_name' => 'Second', 'username' => 'beta_test', 'status' => 'suspended']);
        $this->be($admin, 'admin');
        $this->get('/admin/users?query=alpha&status=active')
            ->assertOk()->assertSee('alpha_test')->assertDontSee('beta_test');
    }

    public function test_admin_suspension_requires_permission_and_revokes_sessions(): void
    {
        $tokens = $this->signIn();
        $this->registerTrustedDevice($tokens);
        $user = OrbitUser::firstOrFail();
        $admin = \App\Models\AdminUser::create([
            'name' => 'Admin operator', 'email' => 'operator@example.test',
            'password' => 'TestingPassword123!', 'totp_secret' => (new \App\Modules\Config\Services\Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $role = \App\Models\AdminRole::create([
            'name' => 'Support reviewer', 'permissions' => ['users.read'],
        ]);
        $admin->roles()->attach($role->id);
        $this->be($admin, 'admin');

        $endpoint = '/admin/users/'.$user->id.'/status';
        $this->post($endpoint, ['status' => 'suspended', 'reason' => 'Account safety support review'])
            ->assertForbidden();
        $this->assertSame('active', $user->fresh()->status);

        $role->update(['permissions' => ['users.read', 'users.manage']]);
        $this->post($endpoint, ['status' => 'suspended', 'reason' => 'Account safety support review'])
            ->assertRedirect(route('orbit.admin.users.show', $user));
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertNotNull(OrbitSession::firstOrFail()->revoked_at);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'users.status', 'target' => 'user:'.$user->id,
        ]);
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_password_enrollment_requires_verified_trusted_device_and_never_overwrites(): void
    {
        $tokens = $this->signIn();
        $endpoint = '/api/v1/me/password';
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => 'StrongAndUniquePass123!',
        ])->assertForbidden();
        $this->registerTrustedDevice($tokens);
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => 'short',
        ])->assertUnprocessable();
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => 'onlyalphabeticpassword',
        ])->assertUnprocessable();
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => str_repeat('a', 260).'1',
        ])->assertUnprocessable();
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => 'StrongAndUniquePass123!',
        ])->assertOk();
        $user = OrbitUser::firstOrFail();
        $this->assertTrue(Hash::check('StrongAndUniquePass123!', $user->password_hash));
        $this->assertNotSame('StrongAndUniquePass123!', $user->password_hash);
        $this->withToken($tokens['access_token'])->postJson($endpoint, [
            'password' => 'AnotherSecretPassword456!',
        ])->assertStatus(409);
        $this->assertTrue(Hash::check('StrongAndUniquePass123!', $user->fresh()->password_hash));
    }

    public function test_password_login_starts_same_otp_flow_but_does_not_issue_tokens(): void
    {
        $tokens = $this->signIn();
        $this->registerTrustedDevice($tokens);
        $this->withToken($tokens['access_token'])->postJson('/api/v1/me/password', [
            'password' => 'StrongAndUniquePass123!',
        ])->assertOk();
        Mail::fake();
        $this->postJson('/api/v1/auth/password/otp/request', [
            'recipient' => 'member@example.test', 'password' => 'IncorrectPassword123!',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/otp/request', [
            'recipient' => 'member@example.test', 'password' => 'StrongAndUniquePass123!',
        ])->assertStatus(202)->assertJsonMissingPath('data.access_token');
        $this->assertDatabaseCount('orbit_sessions', 1);
    }


    public function test_existing_account_signup_request_is_rejected_without_sending_otp(): void
    {
        $this->signIn('member@example.test');
        Mail::fake();
        $before = DB::table('otp_challenges')->count();
        $this->postJson('/api/v1/auth/otp/request', [
            'channel' => 'email', 'recipient' => 'MEMBER@example.test', 'purpose' => 'signup',
        ])->assertStatus(409)->assertJsonFragment([
            'message' => 'An account already exists. Please log in instead.',
        ]);
        $this->assertSame($before, DB::table('otp_challenges')->count());
        $this->assertDatabaseCount('orbit_users', 1);
        Mail::assertNothingSent();
    }

    public function test_signup_cannot_log_in_as_an_existing_verified_identity(): void
    {
        $tokens = $this->signIn();
        $userId = $tokens['user']['id'];
        $this->createdCode('member@example.test', '472819', 'signup');
        $payload = [
            'channel' => 'email', 'purpose' => 'signup',
            'recipient' => 'member@example.test', 'code' => '472819',
            'device_public_id' => (string) Str::uuid(), 'platform' => 'android',
            'device_name' => 'Unauthorized signup',
        ];
        $this->postJson('/api/v1/auth/otp/verify', $payload)
            ->assertStatus(409)->assertJsonFragment(['message' => 'An account already exists. Please log in instead.']);
        $this->assertDatabaseCount('orbit_users', 1);
        $this->assertDatabaseCount('orbit_sessions', 1);
        $this->assertDatabaseHas('orbit_users', ['id' => $userId]);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_a_login_code_cannot_create_users_or_be_used_as_signup(): void
    {
        $this->createdCode('unknown@example.test', '472819', 'login');
        $payload = [
            'channel' => 'email', 'purpose' => 'login',
            'recipient' => 'unknown@example.test', 'code' => '472819',
            'device_public_id' => (string) Str::uuid(), 'platform' => 'android',
            'device_name' => 'Test Android',
        ];
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 0);
        $payload['purpose'] = 'signup';
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 0);
        $this->assertDatabaseCount('orbit_sessions', 0);
    }

    public function test_recovery_updates_password_without_issuing_a_session_and_revokes_all_existing_sessions(): void
    {
        $tokens = $this->signIn();
        $this->registerTrustedDevice($tokens);
        $user = OrbitUser::firstOrFail();
        $user->update(['password_hash' => Hash::make('OriginalTestPassword123!')]);
        Mail::fake();
        $this->postJson('/api/v1/auth/password/reset/request', [
            'recipient' => 'member@example.test',
        ])->assertStatus(202)->assertDontSee('access_token');
        $this->assertDatabaseHas('otp_challenges', ['purpose' => 'reset']);
        $challenge = DB::table('otp_challenges')->where('purpose', 'reset')->first();
        $secret = hash('sha256', config('app.key').'|orbit-otp-v1', true);
        // Fakes let us explicitly set a known code without exposing it in responses.
        DB::table('otp_challenges')->where('id', $challenge->id)->update([
            'code_hash' => hash_hmac('sha256', $challenge->id.'|472819', $secret),
        ]);
        $payload = [
            'recipient' => 'member@example.test', 'code' => '472819',
            'password' => 'NewStrongPassword456!',
        ];
        $this->postJson('/api/v1/auth/password/reset/confirm', array_merge($payload, [
            'password' => 'onlyalphabeticpassword',
        ]))->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset/confirm', array_merge($payload, ['code' => '000000']))
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset/confirm', $payload)
            ->assertOk()->assertJsonMissingPath('data.access_token');
        $this->assertTrue(Hash::check('NewStrongPassword456!', $user->fresh()->password_hash));
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/password/reset/confirm', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_users', 1);
        $this->assertDatabaseHas('orbit_identity_events', ['action' => 'auth.password_reset']);
    }


    public function test_legacy_otp_only_account_can_enroll_first_password_through_verified_recovery(): void
    {
        $tokens = $this->signIn('legacy@example.test');
        $account = OrbitUser::firstOrFail();
        $this->assertNull($account->password_hash);
        $this->createdCode('legacy@example.test', '472819', 'reset');
        $this->postJson('/api/v1/auth/password/reset/confirm', [
            'recipient' => 'legacy@example.test',
            'code' => '472819',
            'password' => 'NewLegacyPassword123',
        ])->assertOk();
        $this->assertTrue(Hash::check('NewLegacyPassword123', $account->fresh()->password_hash));
        $this->assertDatabaseCount('orbit_users', 1);
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_unknown_recovery_request_is_generic_and_creates_no_account_or_otp(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/password/reset/request', [
            'recipient' => 'unknown@example.test',
        ])->assertStatus(202)->assertJsonFragment([
            'message' => 'If the account exists, a password reset code has been sent.',
        ]);
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('orbit_users', 0);
        $this->postJson('/api/v1/auth/password/reset/confirm', [
            'recipient' => 'unknown@example.test',
            'code' => '472819', 'password' => 'NewStrongPassword456!',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orbit_sessions', 0);
    }

    public function test_password_login_code_cannot_be_used_as_signup_even_when_password_is_correct(): void
    {
        $tokens = $this->signIn();
        $this->registerTrustedDevice($tokens);
        OrbitUser::firstOrFail()->update(['password_hash' => Hash::make('OriginalTestPassword123!')]);
        Mail::fake();
        $this->postJson('/api/v1/auth/password/otp/request', [
            'recipient' => 'member@example.test', 'password' => 'OriginalTestPassword123!',
        ])->assertStatus(202);
        $challenge = DB::table('otp_challenges')->where('purpose', 'login')->first();
        $this->assertNotNull($challenge);
        $secret = hash('sha256', config('app.key').'|orbit-otp-v1', true);
        DB::table('otp_challenges')->where('id', $challenge->id)->update([
            'code_hash' => hash_hmac('sha256', $challenge->id.'|472819', $secret),
        ]);
        $payload = [
            'channel' => 'email', 'purpose' => 'signup',
            'recipient' => 'member@example.test', 'code' => '472819',
            'device_public_id' => (string) Str::uuid(), 'platform' => 'android',
            'device_name' => 'Test Android',
        ];
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('orbit_sessions', 1);
        $payload['purpose'] = 'login';
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertOk();
        $this->assertDatabaseCount('orbit_sessions', 2);
    }

    public function test_admin_user_view_is_not_public(): void
    {
        $this->get('/admin/users')->assertRedirect(route('orbit.admin.login'));
        $this->get('/api/v1/me/devices')->assertUnauthorized();
    }
}
