<?php
namespace Tests\Feature;
use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\OperationalControls;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class OrbitM24OperationalControlsTest extends TestCase{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);}
    private function admin(array $permissions):AdminUser{
        $role=AdminRole::create(['name'=>'M24 '.Str::uuid(),'permissions'=>$permissions]);
        $a=AdminUser::create(['name'=>'Operator','email'=>Str::uuid().'@example.test','password'=>'StrongTestPassword123!',
            'totp_secret'=>app(Totp::class)->generateSecret(),'status'=>'active']);$a->roles()->attach($role);return $a;
    }
    private function person():array{
        $u=OrbitUser::create(['username'=>'m24_'.Str::random(12),'display_name'=>'Test account','status'=>'active']);
        $d=OrbitDevice::create(['user_id'=>$u->id,'device_public_id'=>(string)Str::uuid(),'name'=>'Test','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$u,$d,app(TokenService::class)->issue($u,$d,null)['access_token']];
    }
    private function controls(string $module,array $values=[],int $revision=0):array{
        $fields=OperationalControls::MODULES[$module][3];
        return ['revision'=>$revision,'reason'=>'Approved operational policy test','confirm'=>1,
            'values'=>array_replace(array_map(fn($f)=>min((int)config($f[0]),$f[3]),$fields),$values)];
    }
    public function test_all_operational_settings_pages_have_real_revisioned_controls():void{
        $this->be($this->admin(['*']),'admin');
        foreach(OperationalControls::MODULES as $module=>$def){
            $url=route('orbit.admin.operations.'.$module);
            $this->get($url)->assertOk()->assertSee('Save Controls');
            $this->post($url,$this->controls($module))->assertRedirect($url);
            $this->get($url)->assertOk()->assertSee('Policy revision 1');
        }
        $this->assertSame(count(OperationalControls::MODULES),DB::table('remote_configs')->where('key','like','m24.operations.%')->count());
        $this->assertSame(count(OperationalControls::MODULES),DB::table('config_revisions')->where('resource_key','like','m24.operations.%')->count());
    }
    public function test_stale_unknown_range_and_generic_bypass_are_rejected():void{
        $this->be($this->admin(['*']),'admin');
        $url=route('orbit.admin.operations.ping');
        $this->post($url,$this->controls('ping',['sender_minute'=>1]))->assertRedirect($url);
        $this->postJson($url,$this->controls('ping',['sender_minute'=>2]))->assertStatus(409);
        $this->postJson($url,$this->controls('ping',['sender_minute'=>999],1))->assertUnprocessable();
        $this->postJson($url,$this->controls('ping',['release_enabled'=>1],1))->assertUnprocessable();
        $this->postJson('/admin/config',['key'=>'m24.operations.ping'])->assertForbidden();
        $id=DB::table('remote_configs')->where('key','m24.operations.ping')->value('id');
        $this->get('/admin/config/'.$id.'/edit')->assertForbidden();
        $this->postJson('/admin/revisions/remote/m24.operations.ping/rollback',[])->assertForbidden();
        $this->assertSame(1,DB::table('remote_configs')->where('id',$id)->value('version'));
    }
    public function test_read_only_role_cannot_write_and_scopes_are_separate():void{
        $this->be($this->admin(['social.read']),'admin');
        $this->get(route('orbit.admin.operations.social'))->assertOk()->assertDontSee('Save Controls');
        $this->postJson(route('orbit.admin.operations.social'),$this->controls('social'))->assertForbidden();
        $this->get(route('orbit.admin.operations.ping'))->assertForbidden();
        $this->get('/admin/administrators')->assertForbidden();
    }
    public function test_saved_ping_cap_reaches_existing_mobile_service_without_bypassing_consent():void{
        config(['orbit_ping.enabled'=>true,'orbit_ping.sender_per_minute'=>12,'orbit_ping.pair_per_hour'=>6]);
        [$a,,$token]=$this->person();[$b]=$this->person();
        $ids=[$a->id,$b->id];sort($ids,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$ids[0],'user_high_id'=>$ids[1],'created_at'=>now()]);
        DB::table('orbit_user_preferences')->insert(['user_id'=>$b->id,'privacy'=>json_encode(['ping_eligibility'=>'Friends']),'notifications'=>json_encode([]),'created_at'=>now(),'updated_at'=>now()]);
        $this->be($this->admin(['config.manage']),'admin');
        $url=route('orbit.admin.operations.ping');
        $this->post($url,$this->controls('ping',['sender_minute'=>1]))->assertRedirect();
        $this->withToken($token)->postJson('/api/v1/pings',['client_id'=>(string)Str::uuid(),'recipient_id'=>$b->id,'context'=>['type'=>'friend'],'type'=>'hello'])->assertCreated();
        $this->postJson('/api/v1/pings',['client_id'=>(string)Str::uuid(),'recipient_id'=>$b->id,'context'=>['type'=>'friend'],'type'=>'hello'])->assertStatus(429);
        $this->assertSame(12,config('orbit_ping.sender_per_minute'));
        $this->assertDatabaseCount('pings',1);
        $data=$this->getJson('/api/v1/config/bootstrap?platform=android&app_version=1.0.0')->assertOk()->json('data.config');
        $this->assertSame(1,$data['m24.operational_limits']['modules']['ping']['values']['sender_minute']);
        $this->assertArrayNotHasKey('m24.operations.ping',$data);
    }
    public function test_owner_scoped_revocation_invalidates_actual_bearer():void{
        [$a,$d,$token]=$this->person();[$b,$other,$otherToken]=$this->person();
        $this->be($this->admin(['users.read','users.manage']),'admin');
        $url=route('orbit.admin.users.revoke',$a);
        $data=['scope'=>'device','device_id'=>$other->id,'reason'=>'Verified compromised device request','confirm'=>1];
        $this->postJson($url,$data)->assertNotFound();
        $this->post($url,array_replace($data,['device_id'=>$d->id]))->assertRedirect();
        $this->assertDatabaseHas('devices',['id'=>$d->id,'trust_state'=>'revoked']);
        $this->withToken($token)->getJson('/api/v1/friends')->assertUnauthorized();
        $this->withToken($otherToken)->getJson('/api/v1/friends')->assertOk();
        $this->assertDatabaseHas('admin_audit_log',['action'=>'users.device.revoke']);
    }
    private function code(AdminUser $a):string{return app(Totp::class)->codeAt($a->totp_secret,time());}
    public function test_independent_access_reviews_change_permissions_once_and_protect_wildcard():void{
        $first=$this->admin(['*']);$second=$this->admin(['*']);$target=$this->admin(['users.read']);
        $role=AdminRole::create(['name'=>'Allowed operator','permissions'=>['social.read']]);
        $this->be($first,'admin');
        $payload=['target_id'=>$target->id,'operation'=>'grant','role_id'=>$role->id,'reason'=>'Approved limited operator role request','confirm'=>1,'mfa_code'=>$this->code($first)];
        $this->post('/admin/administrators/reviews',$payload)->assertRedirect();
        $this->assertFalse($target->canPerform('social.read'));
        $review=DB::table('admin_access_reviews')->value('id');
        $decision=['decision'=>'approve','reason'=>'Independent supervisor approval','confirm'=>1,'mfa_code'=>$this->code($first)];
        $this->postJson('/admin/administrators/reviews/'.$review,$decision)->assertForbidden();
        $this->postJson('/admin/administrators/reviews',array_replace($payload,['target_id'=>$second->id]))->assertForbidden();
        $this->be($second,'admin');
        $decision['mfa_code']=$this->code($second);
        $this->post('/admin/administrators/reviews/'.$review,$decision)->assertRedirect();
        $this->assertTrue($target->fresh()->canPerform('social.read'));
        $this->assertEquals(1,$target->fresh()->session_revision);
        $this->postJson('/admin/administrators/reviews/'.$review,$decision)->assertStatus(409);
        $this->be($target->fresh(),'admin');
        $this->withSession(['orbit_admin_session_revision'=>0])->get('/admin/social-graph')->assertRedirect(route('orbit.admin.login'));
    }

    public function test_dashboard_visibility_and_rollback_change_real_summary():void{
        config(['orbit_dashboard.enabled'=>true,'orbit_circle.enabled'=>true,'orbit_moments.enabled'=>true,'orbit_media.enabled'=>true]);
        [$u,,$token]=$this->person();
        $this->be($this->admin(['config.manage']),'admin');
        $url=route('orbit.admin.operations.dashboard');
        $this->post($url,$this->controls('dashboard',['show_people'=>0]))->assertRedirect();
        $first=DB::table('config_revisions')->where('resource_key','m24.operations.dashboard')->where('version',1)->value('id');
        $hidden=$this->withToken($token)->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.sections.people.state','disabled')->assertJsonPath('data.capabilities.people',false)->json('data.viewer_policy_revision');
        $this->post($url,$this->controls('dashboard',['show_people'=>1],1))->assertRedirect();
        $visible=$this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.capabilities.people',true)->json('data.viewer_policy_revision');
        $this->assertNotSame($hidden,$visible);
        $this->post(route('orbit.admin.operations.dashboard.rollback'),['snapshot'=>$first,'revision'=>2,'reason'=>'Restore reviewed visibility setting','confirm'=>1])->assertRedirect();
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.sections.people.state','disabled');
        $this->assertEquals(3,DB::table('remote_configs')->where('key','m24.operations.dashboard')->value('version'));
    }

    public function test_camera_catalog_uses_only_approved_tools_and_sanitized_bootstrap():void{
        $this->be($this->admin(['camera.read','camera.manage']),'admin');
        $d=['filters'=>['natural','warm'],'stickers'=>['⭐'],'revision'=>0,'reason'=>'Reviewed local camera tool availability','confirm'=>1];
        $this->post('/admin/camera/tools',$d)->assertRedirect();
        $this->postJson('/admin/camera/tools',array_replace($d,['revision'=>1,'filters'=>['natural','ar_new']]))->assertUnprocessable();
        $this->postJson('/admin/camera/tools',$d)->assertStatus(409);
        $h=$this->getJson('/api/v1/config/bootstrap?platform=android&app_version=1.0.0')->assertOk()->json('data.config');
        $this->assertSame(['natural','warm'],$h['m24.camera_tools']['filters']);
        $this->assertSame(['⭐'],$h['m24.camera_tools']['stickers']);
        $this->be($this->admin(['*']),'admin');
        $this->postJson('/admin/config',['key'=>'m24.camera_tools'])->assertForbidden();
        $this->get('/admin/administrators/audits')->assertOk()->assertSee('Administrative audit');
    }
}
