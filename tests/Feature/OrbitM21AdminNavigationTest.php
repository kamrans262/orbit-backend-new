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
            $page=$this->get(route('orbit.admin.'.$route))->assertOk();
            if (str_ends_with($route,'.audits') || $route==='advertising.analytics') {
                $prefix=explode('.',$route)[0];
                $page->assertDontSee('href="'.route('orbit.admin.'.$prefix.'.overview').'"',false);
            }
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

    public function test_read_roles_cannot_execute_existing_mutation_endpoints(): void
    {
        // Isolate RBAC from the independent production rate limits.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->be($this->admin(['users.read','circles.read','media.read','moments.read',
            'notifications.read','plans.read','safety.read','moderation.read','activity.read','advertising.read']),'admin');
        $id=(string)Str::uuid();
        $member=\App\Models\OrbitUser::create(['username'=>'m21_read_'.Str::random(8),'display_name'=>'Fixture member','status'=>'active']);
        foreach ([
            '/admin/users/'.$member->id.'/status',
            '/admin/media/jobs/retry',
            '/admin/moments/reports/'.$id.'/action',
            '/admin/notifications/jobs/'.$id.'/retry',
            '/admin/plans/cancel',
            '/admin/safety/run',
            '/admin/moderation/cases/'.$id.'/enforce',
            '/admin/activity/control',
            '/admin/advertising/sponsors',
        ] as $path) {
            $response=$this->post($path,[]);
            $this->assertSame(403,$response->getStatusCode(),'Expected permission denial for '.$path);
        }
        $this->delete('/admin/users/'.$member->id)->assertForbidden();
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
    public function test_admin_sensitive_pages_are_never_browser_cached(): void
    {
        $this->be($this->admin(['*']),'admin');
        foreach (AdminNavigation::groups(auth()->guard('admin')->user()) as $group) {
            foreach ($group['children'] as $link) {
                $response=$this->get(route($link['route']))->assertOk();
                $this->assertStringContainsString('no-store',(string)$response->headers->get('Cache-Control'));
                $this->assertStringContainsString('private',(string)$response->headers->get('Cache-Control'));
            }
        }
    }

    public function test_real_web_csrf_rejects_missing_and_wrong_tokens_without_mutation(): void
    {
        $this->be($this->admin(['activity.read','activity.manage']),'admin');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $token=Str::random(40);
        $this->withSession(['_token'=>$token]);
        $payload=['key'=>'source.check_in','enabled'=>'0','reason'=>'Verify CSRF rejection and accepted request','confirm'=>'1'];
        $before=\Illuminate\Support\Facades\DB::table('activity_controls')->where('key','source.check_in')->value('enabled');
        $this->assertTrue((bool)$before);
        // Laravel bypasses its request-forgery middleware while running unit tests.
        // Change only the application environment binding for these in-process requests;
        // the disposable CI DB and all actual configuration remain unchanged.
        $previous=$this->app->environment();
        $this->app->instance('env','local');
        try {
            $this->post('/admin/activity/control',$payload)->assertStatus(419);
            $this->post('/admin/activity/control',$payload+['_token'=>Str::random(40)])->assertStatus(419);
            $this->assertDatabaseHas('activity_controls',['key'=>'source.check_in','enabled'=>1]);
            $this->assertDatabaseMissing('activity_admin_audits',['action'=>'source.changed','subject_key'=>'source.check_in']);
            $this->post('/admin/activity/control',$payload+['_token'=>$token])->assertRedirect();
            $this->assertDatabaseHas('activity_controls',['key'=>'source.check_in','enabled'=>0]);
            $this->assertDatabaseHas('activity_admin_audits',['action'=>'source.changed','subject_key'=>'source.check_in']);
        } finally {
            $this->app->instance('env',$previous);
        }
    }

}
