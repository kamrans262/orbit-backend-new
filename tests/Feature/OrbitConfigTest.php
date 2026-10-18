<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\FeatureFlag;
use App\Models\RemoteConfig;
use App\Modules\Config\Services\ConfigRegistry;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrbitConfigTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        $role = AdminRole::create(['name' => 'Super Admin', 'permissions' => ['*']]);
        $admin = AdminUser::create([
            'name' => 'Test Admin', 'email' => 'admin@example.test',
            'password' => 'TestingPassword123!', 'totp_secret' => (new Totp())->generateSecret(),
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_public_bootstrap_does_not_expose_drafts_or_server_only_settings(): void
    {
        RemoteConfig::create(['key' => 'private.setting', 'value' => ['unsafe' => true],
            'value_type' => 'object', 'published' => true, 'client_visible' => false]);
        RemoteConfig::create(['key' => 'draft.setting', 'value' => ['hidden' => true],
            'value_type' => 'object', 'published' => false, 'client_visible' => true]);
        RemoteConfig::create(['key' => 'public.setting', 'value' => ['refresh' => 30],
            'value_type' => 'object', 'published' => true, 'client_visible' => true]);
        $response = $this->getJson('/api/v1/config/bootstrap?platform=ios&app_version=1.0.0&country=PK');
        $response->assertOk();
        // Configuration keys are literal dotted strings, not JSON path separators.
        $configs = $response->json('data.config');
        $this->assertSame(['refresh' => 30], $configs['public.setting']);
        $this->assertArrayNotHasKey('private.setting', $configs);
        $this->assertArrayNotHasKey('draft.setting', $configs);
    }

    public function test_feature_flag_kill_switch_has_absolute_precedence(): void
    {
        $flag = FeatureFlag::create(['key' => 'relay.beta', 'enabled' => true,
            'kill_switch' => true, 'rollout_percentage' => 100, 'platforms' => ['ios']]);
        $service = app(ConfigRegistry::class);
        $this->assertFalse($service->flagEnabled($flag, ['platform' => 'ios', 'app_version' => '2.0.0']));
        $flag->update(['kill_switch' => false]);
        $this->assertTrue($service->flagEnabled($flag->fresh(), ['platform' => 'ios', 'app_version' => '2.0.0']));
        $this->assertFalse($service->flagEnabled($flag->fresh(), ['platform' => 'android']));
    }

    public function test_admin_auth_requires_mfa(): void
    {
        $admin = $this->admin();
        $this->get('/admin')->assertRedirect(route('orbit.admin.login'));
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'TestingPassword123!'])
            ->assertRedirect(route('orbit.admin.mfa'));
        $this->get('/admin')->assertRedirect(route('orbit.admin.login'));
        $this->post('/admin/mfa', ['code' => (new Totp())->codeAt($admin->totp_secret, time())])
            ->assertRedirect(route('orbit.admin.dashboard'));
        $this->get('/admin')->assertOk()->assertSee('Platform configuration');
    }

    public function test_admin_config_change_is_versioned_and_audited(): void
    {
        $admin = $this->admin();
        $this->be($admin, 'admin');
        $payload = ['key' => 'location.experimental', 'description' => 'Test location flag',
            'enabled' => '1', 'rollout_percentage' => 20, 'platforms' => ['android'],
            'reason' => 'Approved for the test cohort'];
        $this->post('/admin/flags', $payload)->assertRedirect(route('orbit.admin.dashboard'));
        $flag = FeatureFlag::where('key', 'location.experimental')->firstOrFail();
        $this->assertSame(1, $flag->version);
        $revision = DB::table('config_revisions')->where('resource_key', $flag->key)->first();
        $this->assertNotNull($revision);
        $this->assertDatabaseHas('admin_audit_log', ['target' => 'flag:location.experimental']);
        $this->post('/admin/flags/'.$flag->id, array_merge($payload, ['enabled' => '0', 'reason' => 'Close test cohort safely']))
            ->assertRedirect(route('orbit.admin.dashboard'));
        $this->assertSame(2, $flag->fresh()->version);
        $this->post('/admin/revisions/flag/location.experimental/rollback', [
            'revision' => $revision->id, 'reason' => 'Restore last approved state', 'confirm' => '1',
        ])->assertRedirect(route('orbit.admin.dashboard'));
        $this->assertSame(3, $flag->fresh()->version);
        $this->assertTrue($flag->fresh()->enabled);
    }


    public function test_nested_remote_schema_rejects_invalid_payload_and_accepts_valid_one(): void
    {
        $admin = $this->admin();
        $this->be($admin, 'admin');
        $base = [
            'key' => 'camera.limits',
            'value_type' => 'object',
            'schema_json' => '{"type":"object","required":["max_seconds"],"properties":{"max_seconds":{"type":"number","minimum":1,"maximum":60}},"additionalProperties":false}',
            'published' => '1',
            'client_visible' => '1',
            'reason' => 'Approved validation contract',
        ];
        $this->post('/admin/config', array_merge($base, ['value_json' => '{"max_seconds":120}']))
            ->assertSessionHasErrors('value_json');
        $this->assertDatabaseMissing('remote_configs', ['key' => 'camera.limits']);
        $this->post('/admin/config', array_merge($base, ['value_json' => '{"max_seconds":30}']))
            ->assertRedirect(route('orbit.admin.dashboard'));
        $this->assertDatabaseHas('remote_configs', ['key' => 'camera.limits', 'published' => 1]);
        $this->assertSame(30, $this->getJson('/api/v1/config/bootstrap')->json('data.config')['camera.limits']['max_seconds']);
    }

    public function test_configuration_editors_render_for_authorized_admin(): void
    {
        $this->be($this->admin(), 'admin');
        $this->get('/admin/flags/new')->assertOk()->assertSee('Create feature flag');
        $this->get('/admin/config/new')->assertOk()->assertSee('JSON Schema');
    }

    public function test_admin_assets_are_served_via_laravel_without_public_html_copies(): void
    {
        $this->assertFileExists(public_path('assets/orbit-admin/admin.css'));
        $this->assertFileExists(public_path('assets/orbit-admin/admin.js'));
        $this->get('/admin/assets/admin.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/admin/assets/admin.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $this->get('/admin/assets/nope.php')->assertNotFound();
    }

    public function test_admin_without_config_permission_is_denied(): void
    {
        $admin = $this->admin();
        $role = $admin->roles()->firstOrFail();
        $role->update(['permissions' => ['users.read']]);
        $this->be($admin, 'admin');
        $this->get('/admin')->assertForbidden();
    }
}
