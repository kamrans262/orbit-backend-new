<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitAdminM3Test extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions = ['*']): AdminUser
    {
        $role = AdminRole::create([
            'name' => 'Role '.Str::uuid(), 'permissions' => $permissions,
        ]);
        $admin = AdminUser::create([
            'name' => 'M3 Auditor', 'email' => (string) Str::uuid().'@example.test',
            'password' => 'AdminTestPass123!',
            'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function user(): OrbitUser
    {
        return OrbitUser::create([
            'username' => 'private.account', 'display_name' => 'Example Member',
            'bio' => 'CONFIDENTIAL_USER_BIO_NOT_FOR_ADMIN', 'status' => 'active',
        ]);
    }

    public function test_configurations_and_accounts_show_expandable_subsections_and_real_links(): void
    {
        $this->be($this->admin(), 'admin');
        $response = $this->get('/admin')->assertOk();
        $response->assertSee('Configurations')
            ->assertSee('Feature Flags')
            ->assertSee('Remote Config')
            ->assertSee('Accounts')
            ->assertSee('Deletion Requests')
            ->assertSee('Settings Audit')
            ->assertSee('class="nav-group" open', false);
        $this->get('/admin/flags')->assertOk()->assertSee('Configured flags');
        $this->get('/admin/config')->assertOk()->assertSee('Configuration registry');
    }

    public function test_user_profile_shows_metadata_but_never_bio_or_preference_values(): void
    {
        $user = $this->user();
        DB::table('orbit_user_preferences')->insert([
            'user_id' => $user->id, 'locale' => 'en',
            'timezone' => 'Asia/Karachi',
            'privacy' => json_encode(['location_visibility' => 'Nobody',
                'private_marker' => 'SECRET_LOCATION_MARKER']),
            'notifications' => json_encode(['private_marker' => 'SECRET_NOTIFICATION_MARKER']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->be($this->admin(['users.read']), 'admin');
        $res = $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('Account profile metadata')
            ->assertSee('Settings record status')
            ->assertSee('Saved on server')
            ->assertSee('On file')
            ->assertDontSee('CONFIDENTIAL_USER_BIO_NOT_FOR_ADMIN')
            ->assertDontSee('SECRET_LOCATION_MARKER')
            ->assertDontSee('SECRET_NOTIFICATION_MARKER')
            ->assertDontSee('Asia/Karachi')
            ->assertDontSee('Settings Audit');
        $res->assertHeader('Cache-Control', 'private, no-store');
    }

    public function test_delete_requests_queue_is_read_only_and_never_claims_completion(): void
    {
        $user = $this->user();
        DB::table('orbit_account_deletion_requests')->insert([
            'user_id' => $user->id, 'request_id' => (string) Str::uuid(),
            'status' => 'pending', 'requested_at' => now()->subDays(32),
            'eligible_for_erasure_at' => now()->subDays(2),
            'created_at' => now()->subDays(32), 'updated_at' => now(),
        ]);
        $this->be($this->admin(['deletions.read']), 'admin');
        $this->get('/admin/deletion-requests')->assertOk()
            ->assertSee('Account deletion requests')
            ->assertSee('Not completed')
            ->assertSee('not automatically erased')
            ->assertDontSee('Permanently delete');
        $this->get('/admin/deletion-requests?status=cancelled')->assertOk()
            ->assertDontSee($user->username);
        $this->get('/admin/deletion-requests?status=unrecognized')
            ->assertSessionHasErrors('status');
        $this->get('/admin/users/'.$user->id)->assertForbidden();
    }

    public function test_settings_audit_requires_special_privilege_and_displays_field_names_only(): void
    {
        $user = $this->user();
        DB::table('orbit_identity_events')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'device_id' => null, 'action' => 'settings.privacy_updated',
            'reason_code' => 'presence_visibility', 'created_at' => now(),
        ]);
        $readOnly = $this->admin(['users.read']);
        $this->be($readOnly, 'admin');
        $this->get('/admin/settings-activity')->assertForbidden();
        $this->get('/admin/deletion-requests')->assertForbidden();
        $this->get('/admin/flags')->assertForbidden();
        $this->get('/admin/config')->assertForbidden();
        $this->get('/admin/users')->assertOk()
            ->assertDontSee('Settings Audit')
            ->assertDontSee('Remote Config');
        $this->be($this->admin(['settings.audit.read']), 'admin');
        $this->get('/admin/settings-activity?user_id='.$user->id)->assertOk()
            ->assertSee('presence_visibility')
            ->assertSee('Field name only')
            ->assertDontSee('CONFIDENTIAL_USER_BIO_NOT_FOR_ADMIN');
        $this->get('/admin/settings-activity?user_id=bad-identifier')
            ->assertSessionHasErrors('user_id');
        $this->get('/admin/settings-activity?action=free.text')
            ->assertSessionHasErrors('action');
    }

    public function test_support_after_mfa_lands_on_authorized_users_page(): void
    {
        $admin = $this->admin(['users.read']);
        $this->post('/admin/login', [
            'email' => $admin->email, 'password' => 'AdminTestPass123!',
        ])->assertRedirect(route('orbit.admin.mfa'));
        $this->post('/admin/mfa', [
            'code' => (new Totp())->codeAt($admin->totp_secret, time()),
        ])->assertRedirect(route('orbit.admin.landing'));
        $this->get('/admin/start')->assertRedirect(route('orbit.admin.users.index'));
        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_sections_require_mfa_session(): void
    {
        $this->get('/admin/deletion-requests')->assertRedirect(route('orbit.admin.login'));
        $this->get('/admin/settings-activity')->assertRedirect(route('orbit.admin.login'));
        $this->get('/admin/flags')->assertRedirect(route('orbit.admin.login'));
        $this->get('/admin/config')->assertRedirect(route('orbit.admin.login'));
    }
}
