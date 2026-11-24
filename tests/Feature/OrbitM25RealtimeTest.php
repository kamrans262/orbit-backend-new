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
            'https://oauth2.googleapis.com/token'=>Http::response(['access_token'=>'synthetic-oauth','expires_in'=>3600]),
            'https://orbit-ci-default-rtdb.firebaseio.com/*'=>function($request){
                $this->assertTrue($request->hasHeader('Authorization','Bearer synthetic-oauth'));
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
        $this->assertNull(end($this->writes)['body']);
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
