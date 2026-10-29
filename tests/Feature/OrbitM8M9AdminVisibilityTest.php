<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM8M9AdminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create([
            'name'=>'M8 M9 inspector '.Str::uuid(),
            'permissions'=>$permissions,
        ]);
        $admin=AdminUser::create([
            'name'=>'Module Inspector',
            'email'=>Str::uuid().'@example.test',
            'password'=>'Secure123!password',
            'totp_secret'=>(new Totp())->generateSecret(),
            'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_module_admin_pages_remain_visible_without_enabling_mobile_runtime(): void
    {
        config()->set('orbit_dashboard.enabled',false);
        config()->set('orbit_presence.enabled',false);
        config()->set('orbit_presence.realtime_enabled',false);

        $this->get('/admin/home-dashboard')->assertRedirect(route('orbit.admin.login'));
        $this->get('/admin/presence')->assertRedirect(route('orbit.admin.login'));

        $this->be($this->admin(['config.manage']),'admin');
        $this->get('/admin')->assertOk()
            ->assertSee('Home Dashboard')
            ->assertSee('Presence &amp; Activity',false)
            ->assertSee('Location Sharing');
        $m8=$this->get('/admin/home-dashboard')->assertOk()
            ->assertSee('Dashboard API:')
            ->assertSee('Disabled');
        $this->assertStringContainsString('no-store',
            (string) $m8->headers->get('Cache-Control'));
        $m9=$this->get('/admin/presence')->assertOk()
            ->assertSee('Presence API:')
            ->assertSee('Unavailable — presence storage not connected')
            ->assertSee('Realtime connection setting');
        $this->assertStringContainsString('no-store',
            (string) $m9->headers->get('Cache-Control'));
    }

    public function test_pages_are_still_permission_gated_when_mobile_runtime_is_off(): void
    {
        config()->set('orbit_dashboard.enabled',false);
        config()->set('orbit_presence.enabled',false);
        $this->be($this->admin(['users.read']),'admin');

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/home-dashboard')->assertForbidden();
        $this->get('/admin/presence')->assertForbidden();
        $this->get('/admin/users')->assertOk()
            ->assertDontSee('Home Dashboard')
            ->assertDontSee('Presence &amp; Activity',false)
            ->assertDontSee('Location Sharing');
    }

    public function test_location_admin_reports_enabled_api_without_milestone_release_claims(): void
    {
        config()->set('orbit_location.enabled',true);
        config()->set('orbit_circle.consumer_enabled.location',true);
        config()->set('orbit_location.realtime_enabled',false);
        $this->be($this->admin(['config.manage']),'admin');

        $this->get('/admin/location')->assertOk()
            ->assertSee('Location Sharing &amp; Map',false)
            ->assertSee('Location API:')
            ->assertSee('Map access:')
            ->assertSee('Circle location sharing')
            ->assertSee('Standard refresh available; realtime connection not configured')
            ->assertDontSee('NOT released')
            ->assertDontSee('M10 location API')
            ->assertDontSee('M5 Location consumer');
    }
}
