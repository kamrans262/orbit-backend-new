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
            ->assertSee('M8 Home Dashboard')
            ->assertSee('M9 Presence');
        $this->get('/admin/home-dashboard')->assertOk()
            ->assertSee('M8 API runtime:')
            ->assertSee('Not activated')
            ->assertHeader('Cache-Control','private, no-store');
        $this->get('/admin/presence')->assertOk()
            ->assertSee('M9 API runtime:')
            ->assertSee('Not activated')
            ->assertSee('Realtime transport switch')
            ->assertHeader('Cache-Control','private, no-store');
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
            ->assertDontSee('M8 Home Dashboard')
            ->assertDontSee('M9 Presence');
    }
}
