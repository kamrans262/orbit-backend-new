<?php
namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Models\RemoteConfig;
use App\Modules\Camera\Services\CameraPolicy;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Media\Services\MediaObjectStore;
use App\Modules\Media\Services\MediaLifecycleWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM23CameraTest extends TestCase
{
    use RefreshDatabase;
    private MediaObjectStore $store;
    protected function setUp():void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config(['orbit_media.enabled'=>true,'orbit_moments.enabled'=>true]);
        $this->store=new class extends MediaObjectStore{
            public array $parts=[];
            public int $initiations=0;
            public function initiate(string $key):string{$this->initiations++;return 'camera-private-provider';}
            public function uploadedParts(string $key,string $id):array{return $this->parts;}
            public function finish(string $key,string $id,array $parts):void{}
            public function verify(string $key,int $bytes,string $hash):bool{return true;}
            public function abort(string $key,string $id):void{}
        };
        app()->instance(MediaObjectStore::class,$this->store);
    }
    private function admin(array $permissions):AdminUser
    {
        $role=AdminRole::create(['name'=>'Camera '.Str::uuid(),'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'Camera operator','email'=>Str::uuid().'@example.test',
            'password'=>'StrongTestPassword123!','totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);return $admin;
    }
    private function principal():array
    {
        $user=OrbitUser::create(['username'=>'camera_'.Str::random(10),'display_name'=>'Camera tester','status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Test device','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$user,$device,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }
    private function controls(array $override=[]):array
    {
        $input=['enhancements_enabled'=>0,'revision'=>1,'reason'=>'Review camera operational limits','confirm'=>1];
        foreach(CameraPolicy::RANGES as $key=>$range)$input[$key]=CameraPolicy::DEFAULTS[$key];
        return array_replace($input,$override);
    }
    private function policy(array $override):void
    {
        $record=RemoteConfig::where('key',CameraPolicy::KEY)->firstOrFail();
        $record->update(['value'=>array_replace(CameraPolicy::DEFAULTS,$override),'version'=>$record->version+1]);
    }
    private function upload(string $token,array $override=[],?string $key=null)
    {
        return $this->withToken($token)->withHeader('Idempotency-Key',$key??(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',array_replace([
                'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/jpeg',
                'declared_bytes'=>1024,'declared_width'=>1200,'declared_height'=>800,
                'cipher_suite'=>'aes256gcm-v1',
            ],$override));
    }
    private function complete(string $token,string $upload,string $key)
    {
        $this->store->parts=[['part_number'=>1,'etag'=>'etag-one','bytes'=>1024]];
        return $this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/media/uploads/'.$upload.'/complete',[
                'total_cipher_bytes'=>1024,'cipher_sha256'=>str_repeat('a',64),
                'parts'=>[['part_number'=>1,'etag'=>'etag-one']],
            ]);
    }
    public function test_dedicated_settings_are_read_only_without_manage_and_generic_bypass_is_blocked():void
    {
        $this->get('/admin/camera')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['camera.read']),'admin');
        $this->get('/admin/camera')->assertOk()->assertSee('Camera &amp; Editing',false)->assertDontSee('Save Controls');
        $this->post('/admin/camera/settings',$this->controls())->assertForbidden();
        $this->be($this->admin(['config.manage']),'admin');
        $record=RemoteConfig::where('key',CameraPolicy::KEY)->firstOrFail();
        $this->get('/admin/camera')->assertForbidden();
        $this->get(route('orbit.admin.config.index'))->assertOk()->assertDontSee(CameraPolicy::KEY);
        $this->get(route('orbit.admin.config.edit',$record))->assertForbidden();
        $this->post(route('orbit.admin.config.update',$record),['key'=>CameraPolicy::KEY])->assertForbidden();
        $this->post(route('orbit.admin.config.store'),['key'=>CameraPolicy::KEY])->assertForbidden();
        $this->post(route('orbit.admin.rollback',['type'=>'remote','key'=>CameraPolicy::KEY]),[])->assertForbidden();
        $this->assertDatabaseHas('remote_configs',['id'=>$record->id,'version'=>1]);
    }
    public function test_bounded_revisioned_settings_and_allowlisted_bootstrap():void
    {
        $this->be($this->admin(['camera.read','camera.manage']),'admin');
        $initial=$this->getJson('/api/v1/config/bootstrap')->assertOk()->json('data.config');
        $this->assertFalse($initial[CameraPolicy::KEY]['enhancements_enabled']);
        $this->assertSame(1,$initial[CameraPolicy::KEY]['schema_version']);
        $this->postJson('/admin/camera/settings',$this->controls(['video_duration_ms'=>60001]))->assertUnprocessable();
        $this->postJson('/admin/camera/settings',$this->controls(['photo_long_edge_px'=>720]))->assertUnprocessable();
        $this->post('/admin/camera/settings',$this->controls(['confirm'=>0]))->assertSessionHasErrors('confirm');
        $this->post('/admin/camera/settings',$this->controls(['photo_long_edge_px'=>1080]))
            ->assertRedirect(route('orbit.admin.camera.index'));
        $this->post('/admin/camera/settings',$this->controls())->assertStatus(409);
        $this->assertDatabaseHas('config_revisions',['resource_key'=>CameraPolicy::KEY,'version'=>2]);
        $this->assertDatabaseHas('admin_audit_log',['target'=>'remote:'.CameraPolicy::KEY]);
        $this->get('/admin/camera')->assertOk()->assertSee('Review camera operational limits');
        $record=RemoteConfig::where('key',CameraPolicy::KEY)->firstOrFail();
        $record->update(['value'=>array_replace((array)$record->value,['private_secret'=>'do-not-expose'])]);
        $hints=$this->getJson('/api/v1/config/bootstrap')->assertOk()->json('data.config');
        // Dot-containing config key is literal, not JSON-path nesting.
        $this->assertSame(1080,$hints[CameraPolicy::KEY]['photo_long_edge_px']);
        $this->assertSame(2,$hints[CameraPolicy::KEY]['revision']);
        $this->assertArrayNotHasKey('private_secret',$hints[CameraPolicy::KEY]);
        $this->assertFalse((bool)$record->client_visible);
    }
    public function test_declared_shape_duration_and_format_are_enforced_without_affecting_other_purposes():void
    {
        [,,$token]=$this->principal();
        $this->upload($token,['declared_width'=>1441])->assertUnprocessable();
        $this->upload($token,['declared_height'=>null])->assertUnprocessable();
        $this->upload($token,['kind'=>'video','declared_mime'=>'video/mp4','declared_duration_ms'=>60001])->assertUnprocessable();
        $this->upload($token,['kind'=>'audio','declared_mime'=>'audio/mp4','declared_width'=>null,'declared_height'=>null])->assertUnprocessable();
        $this->upload($token,['kind'=>'audio','declared_mime'=>'audio/mp4','declared_width'=>null,'declared_height'=>null,'declared_duration_ms'=>60001])->assertUnprocessable();
        $this->assertSame(0,$this->store->initiations);
        $this->upload($token,['purpose'=>'message','declared_width'=>2000])->assertCreated();
        $this->upload($token,['purpose'=>'sos','declared_width'=>2000])->assertCreated();
        $this->upload($token,['purpose'=>'avatar','declared_width'=>2000])->assertCreated();
        $this->assertSame(3,$this->store->initiations);
    }
    public function test_legacy_compatibility_and_opt_in_strict_declarations():void
    {
        [,,$token]=$this->principal();
        $this->upload($token,['declared_width'=>null,'declared_height'=>null,'declared_mime'=>'image/png'])->assertCreated();
        $this->policy(['enhancements_enabled'=>true]);
        $this->upload($token,['declared_width'=>null,'declared_height'=>null])->assertUnprocessable();
        $this->upload($token,['declared_mime'=>'image/png'])->assertUnprocessable();
        $this->upload($token)->assertCreated();
        $this->assertDatabaseCount('media_objects',2);
    }
    public function test_policy_change_blocks_pending_resume_and_finalize_without_replacing_ciphertext():void
    {
        [,,$token]=$this->principal();$key=(string)Str::uuid();
        $first=$this->upload($token,[],$key)->assertCreated()->json('data');
        $this->policy(['photo_long_edge_px'=>1080]);
        $this->upload($token,[],$key)->assertStatus(409);
        $this->complete($token,$first['upload_id'],(string)Str::uuid())->assertStatus(409);
        $this->upload($token,['declared_width'=>1080],$key)->assertStatus(409);
        $this->assertDatabaseCount('media_objects',1);
        $this->assertDatabaseCount('media_upload_sessions',1);
        $this->assertSame(1,$this->store->initiations);
        $this->assertDatabaseHas('media_objects',['id'=>$first['media_id'],'state'=>'initiated']);
    }
    public function test_completed_receipt_replay_survives_changed_policy():void
    {
        [,,$token]=$this->principal();$key=(string)Str::uuid();$complete=(string)Str::uuid();
        $first=$this->upload($token,[],$key)->assertCreated()->json('data');
        $this->complete($token,$first['upload_id'],$complete)->assertStatus(202);
        $this->policy(['photo_long_edge_px'=>1080]);
        $this->upload($token,[],$key)->assertCreated()->assertJsonPath('data.upload_id',$first['upload_id']);
        $this->complete($token,$first['upload_id'],$complete)->assertStatus(202);
        $this->assertSame(1,$this->store->initiations);
    }
    public function test_missing_or_malformed_policy_cannot_enable_enhancements_or_raise_hard_limits():void
    {
        $record=RemoteConfig::where('key',CameraPolicy::KEY)->firstOrFail();
        $record->update(['value'=>['enhancements_enabled'=>true,'photo_long_edge_px'=>999999]]);
        $s=app(CameraPolicy::class)->settings();
        $this->assertFalse($s['enhancements_enabled']);
        $this->assertSame(1440,$s['photo_long_edge_px']);
        $record->delete();
        $this->assertSame(0,app(CameraPolicy::class)->settings()['revision']);
        $this->assertFalse(app(CameraPolicy::class)->settings()['enhancements_enabled']);
        $this->be($this->admin(['camera.read','camera.manage']),'admin');
        $this->get('/admin/camera')->assertOk()->assertSee('not provisioned');
        $this->post('/admin/camera/settings',$this->controls())->assertStatus(503);
    }
}
