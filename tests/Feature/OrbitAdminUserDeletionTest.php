<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Models\UserIdentity;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitAdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): array
    {
        $admin = AdminUser::create([
            'name' => 'Data privacy operator', 'email' => 'privacy@example.test',
            'password' => 'LongTestingPassword123!',
            'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $role = AdminRole::create(['name' => 'Privacy operator', 'permissions' => $permissions]);
        $admin->roles()->attach($role->id);
        $this->be($admin, 'admin');
        return [$admin, $role];
    }

    private function fixture(string $email): array
    {
        $user = OrbitUser::create([
            'display_name' => 'Test user', 'username' => 'user_'.Str::random(8),
            'status' => 'active',
        ]);
        UserIdentity::create([
            'user_id' => $user->id, 'provider' => 'email',
            'provider_subject' => $email, 'email_norm' => $email,
            'verified_at' => now(),
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id, 'device_public_id' => (string) Str::uuid(),
            'name' => 'A test phone', 'platform' => 'android',
            'trust_state' => 'trusted', 'approved_at' => now(),
            'public_key' => base64_encode(random_bytes(32)),
        ]);
        $session = OrbitSession::create([
            'user_id' => $user->id, 'device_id' => $device->id,
            'token_family_id' => (string) Str::uuid(),
            'refresh_token_hash' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addDays(30),
        ]);
        DB::table('refresh_token_history')->insert([
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'session_id' => $session->id, 'used_at' => now(),
        ]);
        DB::table('orbit_identity_events')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'device_id' => $device->id, 'action' => 'auth.otp_verified',
            'reason_code' => 'email', 'created_at' => now(),
        ]);
        $secret = hash('sha256', (string) config('app.key').'|orbit-otp-v1', true);
        $challengeHash = hash_hmac('sha256', 'email|'.$email, $secret);
        DB::table('otp_challenges')->insert([
            'id' => (string) Str::uuid(), 'channel' => 'email',
            'recipient_hash' => $challengeHash,
            'code_hash' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$user, $device, $session, $challengeHash];
    }

    public function test_delete_requires_specific_users_delete_permission_and_never_uses_get(): void
    {
        [$user] = $this->fixture('target@example.test');
        $this->delete('/admin/users/'.$user->id, [
            'confirmation' => 'DELETE',
            'reason' => 'Verified privacy erasure request',
        ])->assertRedirect(route('orbit.admin.login'));
        [, $role] = $this->admin(['users.read', 'users.manage']);
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertDontSee('data-open-delete-user', false);
        $this->delete('/admin/users/'.$user->id, [
            'confirmation' => 'DELETE',
            'reason' => 'Verified privacy erasure request',
        ])->assertForbidden();
        $this->assertDatabaseHas('orbit_users', ['id' => $user->id]);
        $role->update(['permissions' => ['users.read', 'users.delete']]);
        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('data-open-delete-user', false)
            ->assertSee('data-delete-user-confirm', false)
            ->assertSee('Permanently delete user?', false);
        $this->get('/admin/users/'.$user->id.'/delete')->assertNotFound();
    }

    public function test_confirmation_and_reason_are_validated_server_side(): void
    {
        [$user] = $this->fixture('target@example.test');
        $this->admin(['users.read', 'users.delete']);
        $this->delete('/admin/users/'.$user->id, [
            'confirmation' => 'YES',
            'reason' => 'Verified privacy erasure request',
        ])->assertSessionHasErrors(['confirmation']);
        $this->delete('/admin/users/'.$user->id, [
            'confirmation' => 'DELETE',
            'reason' => 'too short',
        ])->assertSessionHasErrors(['reason']);
        $this->assertDatabaseHas('orbit_users', ['id' => $user->id]);
        $this->assertDatabaseCount('admin_audit_log', 0);
    }

    public function test_delete_removes_the_whole_current_user_graph_not_other_users_and_audits(): void
    {
        [$target, $targetDevice, $targetSession, $targetHash] =
            $this->fixture('target@example.test');
        [$other, $otherDevice, $otherSession, $otherHash] =
            $this->fixture('other@example.test');
        UserIdentity::create([
            'user_id' => $target->id, 'provider' => 'phone',
            'provider_subject' => '+923001234567',
            'phone_e164' => '+923001234567', 'verified_at' => now(),
        ]);
        DB::table('orbit_identity_events')->insert([
            'id' => (string) Str::uuid(), 'user_id' => null,
            'device_id' => $targetDevice->id, 'action' => 'device.approved',
            'reason_code' => 'trusted', 'created_at' => now(),
        ]);
        $this->admin(['users.read', 'users.delete']);
        $this->delete('/admin/users/'.$target->id, [
            'confirmation' => 'DELETE',
            'reason' => 'Verified account erasure support ticket',
        ])->assertRedirect(route('orbit.admin.users.index'));

        $this->assertDatabaseMissing('orbit_users', ['id' => $target->id]);
        $this->assertDatabaseMissing('user_identities', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('devices', ['id' => $targetDevice->id]);
        $this->assertDatabaseMissing('orbit_sessions', ['id' => $targetSession->id]);
        $this->assertDatabaseMissing('refresh_token_history', ['session_id' => $targetSession->id]);
        $this->assertDatabaseMissing('otp_challenges', ['recipient_hash' => $targetHash]);
        $this->assertDatabaseMissing('orbit_identity_events', ['device_id' => $targetDevice->id]);
        $this->assertDatabaseMissing('orbit_identity_events', ['user_id' => $target->id]);

        $this->assertDatabaseHas('orbit_users', ['id' => $other->id]);
        $this->assertDatabaseHas('devices', ['id' => $otherDevice->id]);
        $this->assertDatabaseHas('orbit_sessions', ['id' => $otherSession->id]);
        $this->assertDatabaseHas('otp_challenges', ['recipient_hash' => $otherHash]);
        $this->assertDatabaseCount('refresh_token_history', 1);
        $this->assertDatabaseHas('orbit_identity_events', ['user_id' => $other->id]);

        $audit = DB::table('admin_audit_log')->first();
        $this->assertSame('users.delete', $audit->action);
        $this->assertSame('Verified account erasure support ticket', $audit->reason);
        $this->assertStringStartsWith('deleted-user:', $audit->target);
        $this->assertStringNotContainsString($target->id, $audit->target);
        $this->get('/admin/users/'.$target->id)->assertNotFound();
    }
}
