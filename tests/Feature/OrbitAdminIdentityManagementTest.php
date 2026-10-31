<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitAdminIdentityManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): void
    {
        $role = AdminRole::create([
            'name' => 'Identity operator',
            'permissions' => $permissions,
        ]);
        $admin = AdminUser::create([
            'name' => 'Identity support',
            'email' => 'identity-operator@example.test',
            'password' => 'StrongIdentityAdminPassword123!',
            'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);
        $this->be($admin, 'admin');
    }

    private function fixtures(): array
    {
        $user = OrbitUser::create([
            'display_name' => 'Member',
            'username' => 'member_m2',
            'status' => 'active',
        ]);
        $devices = [];
        foreach (['Trusted phone', 'Trusted tablet'] as $name) {
            $devices[] = OrbitDevice::create([
                'user_id' => $user->id,
                'device_public_id' => (string) Str::uuid(),
                'name' => $name,
                'platform' => 'android',
                'trust_state' => 'trusted',
                'approved_at' => now(),
                'public_key' => base64_encode(random_bytes(32)),
            ]);
        }
        $session = OrbitSession::create([
            'user_id' => $user->id,
            'device_id' => $devices[0]->id,
            'token_family_id' => (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addDays(30),
        ]);
        DB::table('orbit_identity_events')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'device_id' => $devices[0]->id,
            'action' => 'auth.otp_verified',
            'reason_code' => 'email',
            'created_at' => now(),
        ]);
        return [$user, $devices[0], $devices[1], $session];
    }

    public function test_users_directory_filters_actual_device_trust_without_new_admin_section(): void
    {
        [$user, $first] = $this->fixtures();
        $this->admin(['users.read']);
        $this->get('/admin/users?device_status=trusted')->assertOk()
            ->assertSee($user->username)
            ->assertSee('Device access');
        $this->get('/admin/users?device_status=pending')->assertOk()
            ->assertDontSee($user->username);
        $this->get('/admin/users?device_status=invalid')
            ->assertSessionHasErrors('device_status');
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('Recent sign-in and device activity')
            ->assertSee('Auth Otp Verified')
            ->assertSee('1 active')
            ->assertDontSee('data-open-device-revoke', false);
    }

    public function test_only_authorized_admin_can_revoke_a_device_and_its_sessions(): void
    {
        [$user, $first, $second, $session] = $this->fixtures();
        $url = '/admin/users/'.$user->id.'/devices/'.$first->id.'/revoke';
        $this->admin(['users.read']);
        $this->post($url, [
            'confirmation' => 'REVOKE',
            'reason' => 'Confirmed stolen phone support case',
        ])->assertForbidden();
        $this->assertNull($session->fresh()->revoked_at);

        // Use a fresh authorized test identity without exposing device keys.
        $admin = AdminUser::query()->firstOrFail();
        $role = $admin->roles()->firstOrFail();
        $role->update(['permissions' => ['users.read','users.manage']]);
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('data-open-device-revoke', false);
        $this->post($url, [
            'confirmation' => 'NO',
            'reason' => 'Confirmed stolen phone support case',
        ])->assertSessionHasErrors(['confirmation']);
        $this->post($url, [
            'confirmation' => 'REVOKE',
            'reason' => 'short',
        ])->assertSessionHasErrors(['reason']);

        $this->post($url, [
            'confirmation' => 'REVOKE',
            'reason' => 'Confirmed stolen phone support case',
        ])->assertRedirect(route('orbit.admin.users.show', $user));
        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertDatabaseHas('orbit_identity_events', [
            'user_id' => $user->id,
            'device_id' => $first->id,
            'action' => 'device.revoked',
            'reason_code' => 'admin_revoke',
        ]);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'devices.revoke',
            'target' => 'device:'.$first->id,
        ]);
        $this->post($url, [
            'confirmation' => 'REVOKE',
            'reason' => 'Repeat unauthorized device revoke request',
        ])->assertStatus(409);
    }

    public function test_last_trusted_device_and_cross_account_device_cannot_be_revoked(): void
    {
        [$user, $first, $second] = $this->fixtures();
        $other = OrbitUser::create([
            'display_name' => 'Other member',
            'username' => 'other_m2',
            'status' => 'active',
        ]);
        $this->admin(['users.read','users.manage']);

        $cross = '/admin/users/'.$other->id.'/devices/'.$first->id.'/revoke';
        $this->post($cross, [
            'confirmation' => 'REVOKE',
            'reason' => 'Security review of unrelated account',
        ])->assertNotFound();

        $first->update(['trust_state' => 'revoked', 'revoked_at' => now()]);
        $last = '/admin/users/'.$user->id.'/devices/'.$second->id.'/revoke';
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('Only trusted device');
        $this->post($last, [
            'confirmation' => 'REVOKE',
            'reason' => 'Attempt to revoke final trusted device',
        ])->assertStatus(409);
        $this->assertNull($second->fresh()->revoked_at);
        $this->assertDatabaseCount('admin_audit_log', 0);
    }
}
