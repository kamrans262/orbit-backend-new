<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Modules\Config\Services\AdminNavigation;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM21AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create(['name'=>'M21 '.Str::uuid(),'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'Module operator',
            'email'=>Str::uuid().'@example.test','password'=>'StrongTestPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_existing_modules_are_reachable_without_new_scopes_or_placeholder_sections(): void
    {
        $admin=$this->admin(['*']);
        $this->be($admin,'admin');
        $groups=AdminNavigation::groups($admin);
        $this->assertCount(18,$groups);
        $this->assertSame(55,array_sum(array_map(fn($g)=>count($g['children']),$groups)));
        $this->assertNotContains('Subscriptions & Billing',array_column($groups,'title'));
        $this->assertNotContains('Admin Finalization',array_column($groups,'title'));
        $this->get('/admin/start')->assertRedirect(route('orbit.admin.dashboard'));
        foreach($groups as $group)foreach($group['children'] as $link){
            $this->assertTrue(Route::has($link['route']));
            $this->get(route($link['route']))->assertOk();
        }
    }

    public function test_single_permission_roles_land_on_their_own_existing_pages(): void
    {
        foreach ([
            'messages.moderate'=>'messaging.reports',
            'circles.read'=>'circles.index',
            'media.read'=>'media.index',
            'moments.read'=>'moments.index',
            'advertising.analytics'=>'advertising.analytics',
            'advertising.audit'=>'advertising.audits',
            'plans.audit'=>'plans.audits',
            'safety.audit'=>'safety.audits',
            'moderation.audit'=>'moderation.audits',
            'activity.audit'=>'activity.audits',
        ] as $permission=>$route) {
            $admin=$this->admin([$permission]);
            $this->be($admin,'admin');
            $this->get('/admin/start')->assertRedirect(route('orbit.admin.'.$route));
            $this->get(route('orbit.admin.'.$route))->assertOk();
            $this->get('/admin')->assertForbidden();
        }
    }

    public function test_disabled_modules_show_safe_status_without_changing_runtime_gates(): void
    {
        config(['orbit_circle.enabled'=>false,'orbit_media.enabled'=>false,'orbit_moments.enabled'=>false]);
        $this->be($this->admin(['circles.read','media.read','moments.read','media.jobs.retry','moments.moderate']),'admin');
        foreach(['/admin/circles','/admin/media','/admin/moments'] as $url){
            $this->get($url)->assertOk()->assertSee('Operational availability')
                ->assertSee('Disabled')->assertDontSee('Exact Circle UUID lookup')
                ->assertDontSee('Schedule guarded retry')->assertDontSee('Record reviewed action');
        }
        $this->post('/admin/media/jobs/retry')->assertNotFound();
        $this->post('/admin/moments/reports/'.Str::uuid().'/action')->assertNotFound();
        $this->assertFalse(config('orbit_circle.enabled'));
        $this->assertFalse(config('orbit_media.enabled'));
        $this->assertFalse(config('orbit_moments.enabled'));
        $this->be($this->admin(['users.read']),'admin');
        foreach(['/admin/circles','/admin/media','/admin/moments'] as $url)$this->get($url)->assertForbidden();
    }

    public function test_admin_session_and_no_permission_boundaries(): void
    {
        $this->get('/admin/start')->assertRedirect(route('orbit.admin.login'));
        $admin=$this->admin([]);
        $this->be($admin,'admin');
        $this->get('/admin/start')->assertForbidden();
        $this->assertSame([],AdminNavigation::groups($admin));
        $this->be($this->admin(['config.manage']),'admin');
        $this->withSession(['orbit_admin_activity'=>time()-901])->get('/admin/start')
            ->assertRedirect(route('orbit.admin.login'));
    }
}
