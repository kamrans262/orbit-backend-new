<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
        $this->withToken($a['access_token'])->deleteJson('/api/v1/me/devices/'.$b['device']['id'])->assertOk();
        $this->withToken($b['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($a['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_admin_user_view_is_not_public(): void
    {
        $this->get('/admin/users')->assertRedirect(route('orbit.admin.login'));
        $this->get('/api/v1/me/devices')->assertUnauthorized();
    }
}
