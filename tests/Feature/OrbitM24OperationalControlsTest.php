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
    public function test_discovery_and_consented_contact_caps_are_enforced_on_real_api():void{
        [$viewer,,$token]=$this->person();
        [$other,,$otherToken]=$this->person();
        $this->be($this->admin(['social.read','social.manage']),'admin');
        $this->post(route('orbit.admin.operations.social'),$this->controls('social',[
            'search_minute'=>1,'contact_batch'=>1,'contact_daily'=>1
        ]))->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/people/search?q='.$other->username)->assertOk();
        $this->getJson('/api/v1/people/search?q='.$other->username)->assertStatus(429);
        $this->withToken($otherToken)->getJson('/api/v1/people/search?q='.$viewer->username)->assertOk();
        $this->withToken($token)->postJson('/api/v1/people/contacts/match',[
            'consent_confirmed'=>false,'contacts'=>[['type'=>'email','value'=>'unknown@example.test']]
        ])->assertUnprocessable();
        $this->postJson('/api/v1/people/contacts/match',[
            'consent_confirmed'=>true,'contacts'=>[['type'=>'email','value'=>'first@example.test'],['type'=>'email','value'=>'second@example.test']]
        ])->assertUnprocessable();
        $body=['consent_confirmed'=>true,'contacts'=>[['type'=>'email','value'=>'unknown@example.test']]];
        $this->postJson('/api/v1/people/contacts/match',$body)->assertOk();
        $this->postJson('/api/v1/people/contacts/match',$body)->assertStatus(429);
        $this->assertSame(60,config('orbit_social.search_per_account_per_minute'));
    }
    public function test_plan_circle_cap_blocks_additions_without_invalidating_existing_plan():void{
        config(['orbit_plans.enabled'=>true,'orbit_circle.enabled'=>true]);
        [$owner,,$token]=$this->person();
        $ids=[];
        foreach(['Family','Friends'] as $name)$ids[]=$this->withToken($token)->postJson('/api/v1/circles',['name'=>$name])->assertCreated()->json('data.id');
        $this->be($this->admin(['plans.read','plans.manage']),'admin');
        $this->post(route('orbit.admin.operations.plans'),$this->controls('plans',['circle_limit'=>1]))->assertRedirect();
        $body=['client_id'=>(string)Str::uuid(),'title'=>'Test plan','starts_at'=>now()->addDays(2)->toIso8601String(),'circle_ids'=>$ids];
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertUnprocessable();
        $body['circle_ids']=[$ids[0]];
        $id=$this->postJson('/api/v1/plans',$body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/plans',$body)->assertOk();
        $this->patchJson('/api/v1/plans/'.$id,['revision'=>1,'circle_ids'=>[$ids[1]]])->assertUnprocessable();
        $this->getJson('/api/v1/plans/'.$id)->assertOk()->assertJsonPath('data.revision',1);
        $this->assertDatabaseCount('plan_circles',1);
    }
    public function test_location_ttl_control_reaches_capabilities_and_legacy_row_remains_readable():void{
        config(['orbit_location.enabled'=>true,'orbit_location.latest_ttl_seconds'=>120,'orbit_location.fresh_seconds'=>30]);
        [$u,,$token]=$this->person();
        $this->be($this->admin(['config.manage']),'admin');
        $url=route('orbit.admin.operations.location');
        $this->post($url,$this->controls('location',['latest_ttl'=>60,'fresh_seconds'=>15]))->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/location/capabilities')->assertOk()
            ->assertJsonPath('data.latest_ttl_seconds',60)->assertJsonPath('data.sharing_active_by_default',false);
        $this->assertSame(120,config('orbit_location.latest_ttl_seconds'));
        $row=\App\Models\RemoteConfig::where('key','m24.operations.location')->firstOrFail();
        $old=(array)$row->value;unset($old['latest_ttl'],$old['fresh_seconds']);
        $row->update(['value'=>$old]);
        $this->getJson('/api/v1/location/capabilities')->assertOk()->assertJsonPath('data.latest_ttl_seconds',120);
        $this->get($url)->assertOk();
    }

    public function test_worker_caps_reach_real_projector_without_http_overlay_and_preserve_privacy():void{
        config(['orbit_activity.enabled'=>true,'orbit_activity.project_batch'=>60,
            'orbit_moments.enabled'=>true,'orbit_media.enabled'=>true,'orbit_check_in.enabled'=>true]);
        [$owner]=$this->person();[$outsider,,$otherToken]=$this->person();
        foreach(range(1,3) as $i){
            $id=(string)Str::uuid();
            DB::table('checkins')->insert(['id'=>$id,'creator_id'=>$owner->id,
                'client_id'=>(string)Str::uuid(),'payload_hash'=>str_repeat('c',64),
                'type'=>'custom','text'=>'Private test only','audience_revision'=>1,
                'created_at'=>now(),'updated_at'=>now()]);
            DB::table('checkin_audience')->insert(['checkin_id'=>$id,'audience_revision'=>1,
                'audience_type'=>'only_me','audience_id'=>'']);
        }
        $this->be($this->admin(['activity.read','activity.manage']),'admin');
        $url=route('orbit.admin.operations.activity');
        $this->post($url,$this->controls('activity',['project_batch'=>1]))->assertRedirect();
        $worker=app(\App\Modules\Activity\Services\ActivityProjector::class);
        $this->assertSame(1,$worker->run(60)['check_ins_added']);
        $this->assertDatabaseCount('activity_events',1);
        $this->assertSame(1,$worker->run(60)['check_ins_added']);
        $this->assertSame(1,$worker->run(60)['check_ins_added']);
        $this->assertSame(0,$worker->run(60)['check_ins_added']);
        $this->assertDatabaseCount('activity_events',3);
        $this->withToken($otherToken)->getJson('/api/v1/activity/feed')->assertOk()->assertJsonCount(0,'data.items');
        $this->assertSame(60,config('orbit_activity.project_batch'));
        $row=\App\Models\RemoteConfig::where('key','m24.operations.activity')->firstOrFail();
        $row->update(['value'=>['page_size'=>30]]);
        $this->assertSame(40,app(OperationalControls::class)->workerLimit('activity','project_batch',40));
    }
    public function test_worker_caps_are_private_revisioned_and_never_raise_requested_limits():void{
        $this->be($this->admin(['*']),'admin');
        $this->post(route('orbit.admin.operations.notifications'),$this->controls('notifications',['project_batch'=>4,'dispatch_batch'=>3]))->assertRedirect();
        $this->post(route('orbit.admin.operations.plans'),$this->controls('plans',['worker_batch'=>2]))->assertRedirect();
        $service=app(OperationalControls::class);
        $this->assertSame(4,$service->workerLimit('notifications','project_batch',75));
        $this->assertSame(3,$service->workerLimit('notifications','dispatch_batch',40));
        $this->assertSame(2,$service->workerLimit('plans','worker_batch',40));
        $this->assertSame(1,$service->workerLimit('plans','worker_batch',1));
        config(['orbit_notifications.project_batch'=>2]);
        $this->assertSame(2,$service->workerLimit('notifications','project_batch',75));
        $data=$this->getJson('/api/v1/config/bootstrap?platform=android&app_version=1.0.0')->assertOk()->json('data.config');
        $this->assertArrayNotHasKey('m24.operations.notifications',$data);
        $this->assertArrayNotHasKey('m24.operations.plans',$data);
        $this->assertArrayNotHasKey('notifications',$data['m24.operational_limits']['modules']??[]);
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
