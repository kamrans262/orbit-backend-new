<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Realtime\Services\RealtimeSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM25RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private ?string $keyFile=null;
    private array $writes=[];
    private bool $conflict=false;

    private function person():array
    {
        $user=OrbitUser::create(['username'=>'m25_'.Str::lower(Str::random(12)),
            'display_name'=>'Synthetic M25','status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Synthetic phone','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token'],$device];
    }
    private function enableTransport():void
    {
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key,$pem);
        $this->keyFile=tempnam(sys_get_temp_dir(),'orbit-m25-');
        file_put_contents($this->keyFile,json_encode(['type'=>'service_account','project_id'=>'orbit-ci',
            'client_email'=>'svc@orbit-ci.iam.gserviceaccount.com','private_key'=>$pem],JSON_THROW_ON_ERROR));
        chmod($this->keyFile,0600);
        config([
            'orbit_realtime.enabled'=>true,'orbit_realtime.verified'=>true,
            'orbit_realtime.modules.presence'=>true,'orbit_realtime.modules.ping'=>true,
            'orbit_realtime.project_id'=>'orbit-ci','orbit_realtime.database_url'=>'https://orbit-ci-default-rtdb.firebaseio.com',
            'orbit_realtime.service_account_path'=>$this->keyFile,'orbit_realtime.api_key'=>'synthetic-api-key',
            'orbit_realtime.sender_id'=>'123','orbit_realtime.android_app_id'=>'1:123:android:abc',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://identitytoolkit.googleapis.com/v1/projects/orbit-ci/accounts:delete'=>Http::response([]),
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'synthetic-oauth','expires_in'=>3600]),
            'https://orbit-ci-default-rtdb.firebaseio.com/*'=>function($request){
                $this->assertTrue($request->hasHeader('Authorization','Bearer synthetic-oauth'));
                if(str_ends_with($request->url(),'/orbit_m25/health_probe.json'))return Http::response('null',200);
                if($request->method()==='GET'){
                    $this->assertTrue($request->hasHeader('X-Firebase-ETag','true'));
                    return Http::response('null',200,['ETag'=>'"synthetic-etag"']);
                }
                $this->assertSame('PUT',$request->method());
                $this->assertTrue($request->hasHeader('If-Match','"synthetic-etag"'));
                $value=json_decode($request->body(),true,512,JSON_THROW_ON_ERROR);
                $this->writes[]=['url'=>$request->url(),'body'=>$value];
                return Http::response($value,$this->conflict?412:200);
            },
        ]);
    }
    protected function tearDown():void
    {
        if($this->keyFile!==null)@unlink($this->keyFile);
        parent::tearDown();
    }
    public function test_disabled_and_unverified_transport_never_contacts_provider():void
    {
        Http::fake();
        [$user,$token]=$this->person();
        $this->postJson('/api/v1/realtime/bootstrap')->assertUnauthorized();
        config(['orbit_realtime.enabled'=>true,'orbit_realtime.verified'=>false]);
        $this->withToken($token)->postJson('/api/v1/realtime/bootstrap')
            ->assertOk()->assertJsonPath('data.enabled',false)->assertJsonPath('data.transport','https');
        Http::assertNothingSent();
    }
    public function test_bootstrap_scopes_lease_and_publishes_only_generic_revision():void
    {
        $this->enableTransport();
        [$user,$token,$device]=$this->person();
        $data=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data');
        $sid=$data['uid'];
        $this->assertSame('firebase_rtdb',$data['transport']);
        $this->assertSame('orbit_m25/sessions/'.$sid.'/signal',$data['signal_path']);
        $this->assertLessThanOrEqual(now()->getTimestampMs()+60000,$data['lease_until_ms']);
        $this->assertContains('presence',$data['modules']);
        $parts=explode('.',$data['custom_token']);
        $claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame($sid,$claims['uid']);
        $this->assertSame($device->id,$claims['claims']['orbit_device']);
        $this->assertSame($data['generation'],$claims['claims']['orbit_generation']);
        $this->assertSame(60,$claims['exp']-$claims['iat']);
        $this->assertSame(['lease','signal'],array_keys($this->writes[0]['body']));
        $this->assertSame(['schema_version'=>1,'revision'=>1],$this->writes[0]['body']['signal']);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sid,'revision'=>1,'dirty'=>false]);
        $renew=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data');
        $this->assertSame($data['generation'],$renew['generation']);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sid,'revision'=>1]);
        $this->travel(61)->seconds();
        $fresh=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data');
        $this->assertNotSame($data['generation'],$fresh['generation']);
        $this->travelBack();
    }
    public function test_targeted_signals_retry_conflicts_and_remove_revoked_session():void
    {
        $this->enableTransport();
        [$a,$ta]=$this->person();[$b,$tb]=$this->person();
        $sa=$this->withToken($ta)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $sb=$this->withToken($tb)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $svc=app(RealtimeSessions::class);
        $this->conflict=true;
        $svc->signalUsers('ping',[$a->id]);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sa,'revision'=>2,'dirty'=>true]);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sb,'revision'=>1]);
        $this->assertFalse($svc->publish($sa));
        $this->assertGreaterThan(0,(int)DB::table('orbit_realtime_sessions')->where('session_id',$sa)->value('attempts'));
        $this->conflict=false;
        DB::table('orbit_realtime_sessions')->where('session_id',$sa)->update(['available_at'=>now()]);
        $this->assertGreaterThanOrEqual(1,$svc->process());
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sa,'published_revision'=>2,'dirty'=>false]);
        DB::table('orbit_sessions')->where('id',$sa)->update(['revoked_at'=>now()]);
        $svc->revokeInvalidUser($a->id);
        $svc->process();
        $this->assertDatabaseMissing('orbit_realtime_sessions',['session_id'=>$sa]);
        $this->assertArrayHasKey('revoked',end($this->writes)['body']);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sb]);
    }
    public function test_location_signals_only_consented_viewer_and_never_coordinates():void
    {
        $this->enableTransport();
        config(['orbit_realtime.modules.location'=>true,'orbit_location.enabled'=>true,
            'orbit_location.realtime_enabled'=>true,'orbit_circle.enabled'=>true,
            'orbit_circle.consumer_enabled.location'=>true]);
        [$owner,$token]=$this->person();[$viewer,$vt]=$this->person();[$other,$ot]=$this->person();
        $vs=$this->withToken($vt)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $os=$this->withToken($ot)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $pair=[$owner->id,$viewer->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$pair[0],'user_high_id'=>$pair[1],'created_at'=>now()]);
        DB::table('orbit_user_preferences')->insert(['user_id'=>$owner->id,
            'privacy'=>json_encode(['location_visibility'=>'Friends','location_activity'=>'Active',
                'location_sharing_enabled'=>true,'ghost_mode_enabled'=>false]),'created_at'=>now(),'updated_at'=>now()]);
        $share=$this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$viewer->id,'precision'=>'approximate',
            'duration'=>'one_hour','reviewed'=>true])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.582045,'longitude'=>74.329376,'accuracy_m'=>12.5,
            'observed_at'=>now()->toIso8601String()])->assertAccepted();
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$vs,'revision'=>3]);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$os,'revision'=>1]);
        app(RealtimeSessions::class)->publish($vs);
        $this->assertSame(['schema_version'=>1,'revision'=>3],end($this->writes)['body']['signal']);
        $this->assertStringNotContainsString('31.582045',json_encode($this->writes));
        $this->withToken($vt)->getJson('/api/v1/location/map')->assertOk()->assertJsonCount(1,'data.markers');
        $this->withToken($ot)->getJson('/api/v1/location/map')->assertOk()->assertJsonCount(0,'data.markers');
        $this->withToken($token)->deleteJson('/api/v1/me/location/shares/'.$share)->assertOk();
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$vs,'revision'=>4]);
        $this->withToken($vt)->getJson('/api/v1/location/map')->assertOk()->assertJsonCount(0,'data.markers');
    }

    public function test_delayed_location_signal_is_suppressed_after_ghost_mode():void
    {
        $this->enableTransport();
        config(['orbit_realtime.modules.location'=>true,'orbit_location.enabled'=>true]);
        [$owner,$ot]=$this->person();[$viewer,$vt]=$this->person();
        $sid=$this->withToken($vt)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $pair=[$owner->id,$viewer->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$pair[0],'user_high_id'=>$pair[1],'created_at'=>now()]);
        DB::table('orbit_user_preferences')->insert(['user_id'=>$owner->id,'privacy'=>json_encode([
            'location_visibility'=>'Friends','location_activity'=>'Active','location_sharing_enabled'=>true,
            'ghost_mode_enabled'=>false]),'created_at'=>now(),'updated_at'=>now()]);
        $this->conflict=true; // Force a real delayed publication instead of the normal immediate path.
        $this->withToken($ot)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$viewer->id,'precision'=>'approximate',
            'duration'=>'one_hour','reviewed'=>true])->assertCreated();
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sid,'revision'=>2,'visible_revision'=>1]);
        DB::table('orbit_user_preferences')->where('user_id',$owner->id)->update(['privacy'=>json_encode([
            'location_visibility'=>'Friends','location_activity'=>'Active','location_sharing_enabled'=>true,
            'ghost_mode_enabled'=>true])]);
        $this->conflict=false;
        $this->assertTrue(app(RealtimeSessions::class)->publish($sid));
        $this->assertSame(1,end($this->writes)['body']['signal']['revision']);
        $this->assertDatabaseHas('orbit_realtime_sessions',['session_id'=>$sid,'dirty'=>false,'visible_revision'=>1,'pending_scopes'=>null]);
    }

    public function test_disconnect_and_admin_pause_remove_lease_without_disabling_rest():void
    {
        $this->enableTransport();
        [$u,$token]=$this->person();
        $sid=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $this->postJson('/api/v1/realtime/disconnect')->assertOk();
        $this->assertDatabaseMissing('orbit_realtime_sessions',['session_id'=>$sid]);
        $this->assertArrayHasKey('revoked',end($this->writes)['body']);
        $this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk();
        config(['orbit_realtime.admin_allow'=>0]);
        $this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->assertJsonPath('data.enabled',false);
        app(RealtimeSessions::class)->process();
        $this->assertDatabaseMissing('orbit_realtime_sessions',['session_id'=>$sid]);
        $this->assertArrayHasKey('revoked',end($this->writes)['body']);
    }

    private function operator(array $permissions):\App\Models\AdminUser
    {
        $role=\App\Models\AdminRole::create(['name'=>'M25 '.Str::uuid(),'permissions'=>$permissions]);
        $admin=\App\Models\AdminUser::create(['name'=>'Synthetic operator','email'=>Str::uuid().'@example.test',
            'password'=>'StrongTestPassword123!','totp_secret'=>app(\App\Modules\Config\Services\Totp::class)->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);return $admin;
    }
    public function test_admin_recovery_is_confirmed_permissioned_idempotent_and_bounded():void
    {
        $this->enableTransport();
        [$u,$token]=$this->person();
        $sid=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        DB::table('orbit_realtime_sessions')->where('session_id',$sid)->update(['attempts'=>3,'available_at'=>now()->addMinutes(5)]);
        $url=route('orbit.admin.operations.realtime.recovery');
        $payload=['request_id'=>(string)Str::uuid(),'operation'=>'retry','reason'=>'Reviewed synthetic retry','confirm'=>1];
        $this->be($this->operator(['users.read']),'admin');
        $this->postJson($url,$payload)->assertForbidden();
        $this->be($this->operator(['config.manage']),'admin');
        $this->postJson($url,array_diff_key($payload,['confirm'=>true]))->assertUnprocessable();
        $before=count($this->writes);
        $this->post($url,$payload)->assertRedirect(route('orbit.admin.operations.realtime'));
        $this->post($url,$payload)->assertRedirect(route('orbit.admin.operations.realtime'));
        $this->assertSame($before,count($this->writes));
        $this->assertDatabaseHas('orbit_realtime_recovery',['id'=>$payload['request_id'],'operation'=>'retry','affected'=>1]);
        $this->assertSame(1,DB::table('admin_audit_log')->where('action','realtime.recovery')->where('request_id',$payload['request_id'])->count());
        config(['orbit_realtime.verified'=>false]);
        $this->travel(61)->seconds();
        $this->postJson($url,[...$payload,'request_id'=>(string)Str::uuid()])->assertStatus(409);
        $this->travelBack();
    }

    public function test_permanently_revoked_session_cleanup_waits_for_token_replay_window():void
    {
        $this->enableTransport();
        [$u,$token]=$this->person();
        $sid=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        DB::table('orbit_sessions')->where('id',$sid)->update(['revoked_at'=>now()]);
        $svc=app(RealtimeSessions::class);
        $svc->revokeInvalidUser($u->id);$svc->process();
        $this->assertArrayHasKey('revoked',end($this->writes)['body']);
        $cleanup=app(\App\Modules\Realtime\Services\RealtimeAuthCleanup::class);
        $cleanup->process();
        $this->assertDatabaseHas('orbit_realtime_auth_receipts',['session_id'=>$sid]);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'accounts:delete'));
        $this->travel(3)->minutes();
        $cleanup->process();
        $this->assertNull(end($this->writes)['body']);
        $this->assertDatabaseHas('orbit_realtime_auth_receipts',['session_id'=>$sid]);
        $this->travel(14)->minutes();
        $cleanup->process();
        Http::assertSent(fn($r)=>str_contains($r->url(),'accounts:delete')&&$r['localId']===$sid);
        $this->assertDatabaseMissing('orbit_realtime_auth_receipts',['session_id'=>$sid]);
        $this->travelBack();
    }

    public function test_probe_is_read_only_queued_and_reports_aggregate_health():void
    {
        $this->enableTransport();
        $this->be($this->operator(['config.manage']),'admin');
        $id=(string)Str::uuid();
        $this->post(route('orbit.admin.operations.realtime.recovery'),[
            'request_id'=>$id,'operation'=>'probe','reason'=>'Reviewed synthetic provider probe','confirm'=>1,
        ])->assertRedirect();
        Http::assertNothingSent();
        app(\App\Modules\Realtime\Services\RealtimeRecovery::class)->processProbe();
        $this->assertDatabaseHas('orbit_realtime_recovery',['id'=>$id,'result'=>'Reachable']);
        $this->get(route('orbit.admin.operations.realtime'))->assertOk()->assertSee('Reachable')->assertDontSee('synthetic-oauth');
        Http::assertNotSent(fn($r)=>$r->method()==='PUT');
    }

    public function test_circle_membership_revalidation_works_without_legacy_firebase_bridge():void
    {
        $this->enableTransport();
        config(['orbit_realtime.modules.circles'=>true,'orbit_circle.enabled'=>true,'orbit_circle.firebase.enabled'=>false]);
        [$u,$token]=$this->person();
        $sid=$this->withToken($token)->postJson('/api/v1/realtime/bootstrap')->assertOk()->json('data.uid');
        $id=$this->withToken($token)->postJson('/api/v1/circles',['name'=>'Synthetic circle'])->assertCreated()->json('data.id');
        $svc=app(RealtimeSessions::class);
        $svc->signalUsers('circles',[$u->id],['kind'=>'circle','id'=>$id]);
        $svc->publish($sid);
        $this->assertGreaterThan(1,end($this->writes)['body']['signal']['revision']);
        $this->assertFalse(app(\App\Modules\Circle\Services\CircleFirebaseBridge::class)->enabled());
        $this->assertGreaterThan(0,(int)DB::table('orbit_realtime_metrics')->sum('published'));
    }

    public function test_redis_free_presence_and_legacy_gateway_exclusion():void
    {
        config(['orbit_realtime.enabled'=>true,'orbit_realtime.verified'=>false,
            'orbit_presence.enabled'=>true,'orbit_presence.realtime_enabled'=>true,
            'orbit_presence.gateway_secret'=>str_repeat('x',48),'orbit_presence.store'=>'redis',
            'orbit_circle.enabled'=>true,'orbit_circle.consumer_enabled.presence'=>true]);
        Http::fake();
        [$u,$token]=$this->person();
        $this->withToken($token)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertOk();
        $this->assertGreaterThan(0,DB::table('orbit_ephemeral_cache')->count());
        $this->withToken($token)->postJson('/api/v1/presence/realtime/ticket')->assertNotFound();
        $this->travel(91)->seconds();
        app(RealtimeSessions::class)->pruneEphemeral();
        $this->assertSame(0,DB::table('orbit_ephemeral_cache')->count());
        $this->travelBack();
        Http::assertNothingSent();
    }
}
