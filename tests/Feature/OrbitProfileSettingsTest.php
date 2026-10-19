<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $email): array
    {
        $key = (string) config('app.key');
        $secret = hash('sha256', $key.'|orbit-otp-v1', true);
        $id = (string) Str::uuid();
        $code = '473926';
        $digest = hash_hmac('sha256', 'email|'.strtolower($email), $secret);
        DB::table('otp_challenges')->insert([
            'id' => $id, 'channel' => 'email', 'purpose' => 'signup',
            'recipient_hash' => $digest,
            'code_hash' => hash_hmac('sha256', $id.'|'.$code, $secret),
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->postJson('/api/v1/auth/otp/verify', [
            'channel' => 'email', 'purpose' => 'signup', 'recipient' => $email, 'code' => $code,
            'device_public_id' => (string) Str::uuid(),
            'platform' => 'android', 'device_name' => 'Profile test phone',
        ])->assertOk()->json('data');
        $this->withToken($result['access_token'])->postJson('/api/v1/devices/register', [
            'public_key' => base64_encode(random_bytes(32)),
        ])->assertOk();

        return $result;
    }

    public function test_profile_requires_a_trusted_authenticated_session(): void
    {
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
        $this->withToken('invalid.token')->getJson('/api/v1/me/preferences/privacy')->assertUnauthorized();
    }

    public function test_profile_save_persists_bio_and_rejects_invalid_username_or_external_fields(): void
    {
        $session = $this->signIn('owner@example.test');
        $this->withToken($session['access_token'])->getJson('/api/v1/me/profile')
            ->assertOk()->assertJsonPath('data.bio', null);
        $data = ['display_name' => 'First Person', 'username' => 'person.one', 'bio' => 'Hello Orbit'];
        $this->patchJson('/api/v1/me/profile', $data)->assertOk()
            ->assertJsonPath('data.username', 'person.one')->assertJsonPath('data.bio', 'Hello Orbit');
        $this->getJson('/api/v1/me/profile')->assertJsonPath('data.bio', 'Hello Orbit');
        $this->patchJson('/api/v1/me/profile', [
            'display_name' => 'Another Name', 'username' => 'bad space',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/me/profile', [
            'display_name' => 'Another Name', 'username' => 'person.one',
            'bio' => str_repeat('x', 281),
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/me/profile', [
            'display_name' => 'Another Name', 'username' => 'person.one',
            'status' => 'active', 'password_hash' => 'exposed',
        ])->assertOk()->assertJsonMissingPath('data.password_hash');
        $this->assertNotSame('exposed', OrbitUser::findOrFail($session['user']['id'])->password_hash);
    }

    public function test_username_unique_per_account_and_current_name_may_be_reused(): void
    {
        $first = $this->signIn('first@example.test');
        $this->withToken($first['access_token'])->patchJson('/api/v1/me/profile',
            ['display_name' => 'First Person', 'username' => 'Already.Name'])->assertOk();
        $second = $this->signIn('second@example.test');
        $this->withToken($second['access_token'])->patchJson('/api/v1/me/profile',
            ['display_name' => 'Second Person', 'username' => 'Already.Name'])->assertUnprocessable()
            ->assertJsonValidationErrors('username');
        $this->withToken($first['access_token'])->patchJson('/api/v1/me/profile',
            ['display_name' => 'First Person', 'username' => 'Already.Name'])->assertOk();
    }

    public function test_notification_settings_are_scoped_and_survive_reload(): void
    {
        $first = $this->signIn('notify-one@example.test');
        $this->withToken($first['access_token'])->getJson('/api/v1/me/preferences/notifications')
            ->assertOk()->assertJsonPath('data.push_notifications_enabled', false)
            ->assertJsonPath('data.sos_alerts_enabled', true);
        $this->patchJson('/api/v1/me/preferences/notifications', [
            'ping_notifications_enabled' => true, 'sound_enabled' => false,
        ])->assertOk()->assertJsonPath('data.ping_notifications_enabled', true);
        $this->getJson('/api/v1/me/preferences/notifications')
            ->assertJsonPath('data.sound_enabled', false)
            ->assertJsonPath('data.sos_alerts_enabled', true);
        $this->patchJson('/api/v1/me/preferences/notifications', [
            'ping_notifications_enabled' => 'invalid',
        ])->assertUnprocessable();

        $second = $this->signIn('notify-two@example.test');
        $this->withToken($second['access_token'])->getJson('/api/v1/me/preferences/notifications')
            ->assertJsonPath('data.ping_notifications_enabled', false);
    }

    public function test_privacy_defaults_are_restrictive_and_ghost_mode_disables_sharing(): void
    {
        $session = $this->signIn('privacy@example.test');
        $this->withToken($session['access_token'])->getJson('/api/v1/me/preferences/privacy')
            ->assertOk()->assertJsonPath('data.location_visibility', 'Nobody')
            ->assertJsonPath('data.location_sharing_enabled', false)
            ->assertJsonPath('data.ghost_mode_enabled', true);
        $this->patchJson('/api/v1/me/preferences/privacy', [
            'location_sharing_enabled' => true,
        ])->assertOk()->assertJsonPath('data.location_sharing_enabled', false);
        $this->patchJson('/api/v1/me/preferences/privacy', [
            'location_visibility' => 'Friends', 'ghost_mode_enabled' => false,
            'location_sharing_enabled' => true,
        ])->assertJsonPath('data.location_sharing_enabled', true);
        $this->patchJson('/api/v1/me/preferences/privacy', [
            'ghost_mode_enabled' => true,
        ])->assertJsonPath('data.location_sharing_enabled', false);
        $this->getJson('/api/v1/me/preferences/privacy')
            ->assertJsonPath('data.ghost_mode_enabled', true)
            ->assertJsonPath('data.location_sharing_enabled', false);
        $this->patchJson('/api/v1/me/preferences/privacy', [
            'location_visibility' => 'Public',
        ])->assertUnprocessable();
    }
}
