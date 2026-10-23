<?php
namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Media\Services\MediaObjectStore;
use App\Modules\Media\Services\MediaLifecycleWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM6MediaTest extends TestCase
{
    use RefreshDatabase;

    private MediaObjectStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_media.enabled',true);
        $this->store=new class extends MediaObjectStore {
            public array $parts=[];
            public bool $verifyResult=true;
            public array $deleted=[];
            public function initiate(string $key):string {return 'multipart-provider-id';}
            public function signPart(string $key,string $id,int $part,string $hash,int $length):array
            { return ['url'=>'https://private.example.test/upload','method'=>'PUT',
                'required_headers'=>['x-amz-checksum-sha256'=>base64_encode(hex2bin($hash))],
                'expires_at'=>now()->addMinutes(10)->toIso8601String()];}
            public function uploadedParts(string $key,string $id):array { return $this->parts; }
            public function finish(string $key,string $id,array $parts):void {}
            public function verify(string $key,int $expectedBytes,string $shaHex):bool {return $this->verifyResult;}
            public function download(string $key):string {return 'https://private.example.test/download';}
            public function abort(string $key,string $id):void {}
            public function delete(string $key):void {$this->deleted[]=$key;}
        };
        app()->instance(MediaObjectStore::class,$this->store);
    }

    private function person(string $prefix):array
    {
        $user=OrbitUser::create([
            'username'=>$prefix.'_'.Str::random(7),
            'display_name'=>ucfirst($prefix),'status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M6 test device','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token'],$device];
    }
    private function init(string $token,?string $key=null,array $override=[]):array
    {
        $request=array_merge([
            'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/jpeg',
            'declared_bytes'=>1024,'cipher_suite'=>'xchacha20poly1305-v1',
        ],$override);
        return $this->withToken($token)->withHeader('Idempotency-Key',$key??(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',$request)->assertCreated()->json('data');
    }
    private function finalize(string $token,string $upload):void
    {
        $this->store->parts=[['part_number'=>1,'etag'=>'"etag-one"','bytes'=>1024]];
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads/'.$upload.'/complete',[
                'total_cipher_bytes'=>1024,'cipher_sha256'=>str_repeat('a',64),
                'parts'=>[['part_number'=>1,'etag'=>'"etag-one"']],
            ])->assertStatus(202)->assertJsonPath('data.state','verifying');
    }
    private function ready(string $token):array
    {
        $u=$this->init($token);
        $this->finalize($token,$u['upload_id']);
        $this->assertSame(1,app(MediaLifecycleWorker::class)->run(10)['verified']);
        $this->assertDatabaseHas('media_objects',['id'=>$u['media_id'],'state'=>'ready']);
        return $u;
    }

    public function test_user_session_and_private_feature_gate():void
    {
        $this->postJson('/api/v1/media/uploads',[])->assertUnauthorized();
        [$u,$token,$device]=$this->person('alpha');
        config()->set('orbit_media.enabled',false);
        $this->withToken($token)->postJson('/api/v1/media/uploads',[])->assertNotFound();
        config()->set('orbit_media.enabled',true);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->postJson('/api/v1/media/uploads',[])->assertForbidden();
    }
    public function test_upload_validations_and_no_video_over_sixty_seconds():void
    {
        [$a,$token]=$this->person('alpha');
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',[
                'purpose'=>'moment','kind'=>'video','declared_mime'=>'video/mp4',
                'declared_bytes'=>1024,'declared_duration_ms'=>60001,
                'cipher_suite'=>'xchacha20poly1305-v1',
            ])->assertUnprocessable();
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',[
                'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/svg+xml',
                'declared_bytes'=>1024,'cipher_suite'=>'xchacha20poly1305-v1',
            ])->assertStatus(415);
    }
    public function test_idempotent_upload_reservation_and_foreign_device_denial():void
    {
        [$a,$ta]=$this->person('alpha');[$b,$tb]=$this->person('bravo');
        $key=(string)Str::uuid();
        $one=$this->init($ta,$key);
        $again=$this->init($ta,$key);
        $this->assertSame($one['media_id'],$again['media_id']);
        $this->withToken($ta)->withHeader('Idempotency-Key',$key)->postJson(
            '/api/v1/media/uploads',[
                'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/png',
                'declared_bytes'=>1024,'cipher_suite'=>'xchacha20poly1305-v1',
            ])->assertStatus(409);
        $this->withToken($tb)->getJson('/api/v1/media/uploads/'.$one['upload_id'])->assertNotFound();
        $this->assertDatabaseCount('media_objects',1);
        $this->assertSame(1024,DB::table('media_upload_sessions')->where('id',$one['upload_id'])->value('declared_bytes'));
        $this->assertNotNull(DB::table('media_upload_sessions')->where('id',$one['upload_id'])->value('provider_upload_id_encrypted'));
    }
    public function test_full_ciphertext_receipt_and_private_owner_metadata():void
    {
        [$a,$ta,$device]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $media=$this->ready($ta);
        $this->withToken($ta)->getJson('/api/v1/media/'.$media['media_id'])->assertOk()
            ->assertJsonPath('data.state','ready')->assertJsonMissingPath('data.object_key');
        $this->withToken($tb)->getJson('/api/v1/media/'.$media['media_id'])->assertNotFound();
        $this->assertDatabaseHas('media_event_outbox',['media_id'=>$media['media_id'],
            'event_type'=>'media.upload.ready.v1']);
        // No plaintext key envelope: no download link, even for the owner.
        $this->withToken($ta)->postJson('/api/v1/media/'.$media['media_id'].'/access',
            ['device_id'=>$device->id,'variant'=>'original'])->assertNotFound();
    }
    public function test_only_me_envelope_and_revoke_prevent_access():void
    {
        [$a,$ta,$device]=$this->person('alpha');
        $media=$this->ready($ta);$id=$media['media_id'];
        $grants=$this->withToken($ta)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/'.$id.'/grants',[
                'audience_kind'=>'only_me','target_ids'=>[],
                'key_envelopes'=>[[
                    'device_id'=>$device->id,'variant'=>'original',
                    'key_version'=>'v1','algorithm'=>'x25519+hkdf+chacha20poly1305',
                    'wrapped_key_ciphertext'=>str_repeat('A',60),
                ]],
            ])->assertCreated()->json('data.grant_ids');
        $this->withToken($ta)->postJson('/api/v1/media/'.$id.'/access',[
            'device_id'=>$device->id,'variant'=>'original',
        ])->assertOk()->assertJsonPath('data.ciphertext_url','https://private.example.test/download');
        $this->withToken($ta)->getJson('/api/v1/media/'.$id.'/key-envelope?device_id='.$device->id.'&variant=original')
            ->assertOk()->assertJsonPath('data.key_version','v1');
        $this->withToken($ta)->deleteJson('/api/v1/media/'.$id.'/grants/'.$grants[0])->assertNoContent();
        $this->withToken($ta)->postJson('/api/v1/media/'.$id.'/access',[
            'device_id'=>$device->id,'variant'=>'original',
        ])->assertNotFound();
    }
    public function test_delete_immediately_denies_new_reads_and_purges_in_worker():void
    {
        [$a,$ta]=$this->person('alpha');$media=$this->ready($ta);$id=$media['media_id'];
        $this->withToken($ta)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->deleteJson('/api/v1/media/'.$id)->assertStatus(202);
        $this->withToken($ta)->getJson('/api/v1/media/'.$id)->assertNotFound();
        $result=app(MediaLifecycleWorker::class)->run();
        $this->assertSame(1,$result['purged']);
        $this->assertDatabaseHas('media_objects',['id'=>$id,'state'=>'deleted']);
        $this->assertCount(1,$this->store->deleted);
    }
    public function test_moment_view_ends_at_day_seven_without_early_purge():void
    {
        [$a,$ta]=$this->person('alpha');$media=$this->ready($ta);
        DB::table('media_objects')->where('id',$media['media_id'])->update(['created_at'=>now()->subDays(8)]);
        $this->withToken($ta)->getJson('/api/v1/media/'.$media['media_id'])->assertNotFound();
        $this->assertDatabaseHas('media_objects',['id'=>$media['media_id'],'state'=>'ready']);
    }
    public function test_admin_read_only_permission_and_no_secret_fields():void
    {
        $role=AdminRole::create(['name'=>'M6 media auditor','permissions'=>['media.read']]);
        $admin=AdminUser::create([
            'name'=>'M6 Auditor','email'=>'m6-audit@example.test','status'=>'active',
            'password'=>'Secure-Password!123','totp_secret'=>(new Totp())->generateSecret(),
        ]);
        $admin->roles()->attach($role->id);
        $this->be($admin,'admin');
        $this->get('/admin/media')->assertOk()->assertSee('Encrypted Media Operations')
            ->assertDontSee('wrapped_key_ciphertext');
        $this->post('/admin/media')->assertStatus(405);
        $role->update(['permissions'=>['users.read']]);
        $this->get('/admin/media')->assertForbidden();
    }

    public function test_encrypted_variant_never_becomes_ready_without_verification():void
    {
        [$owner,$token]=$this->person('owner');
        $media=$this->ready($token);
        $this->store->parts=[];
        $v=$this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/'.$media['media_id'].'/variants/uploads',[
                'variant'=>'thumbnail','declared_bytes'=>128,'cipher_sha256'=>str_repeat('b',64),
            ])->assertCreated()->json('data');
        $this->assertDatabaseHas('media_variants',['media_id'=>$media['media_id'],'state'=>'initiated']);
        $this->store->parts=[['part_number'=>1,'etag'=>'"thumbnail-etag"','bytes'=>128]];
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads/'.$v['upload_id'].'/complete',[
                'total_cipher_bytes'=>128,'cipher_sha256'=>str_repeat('b',64),
                'parts'=>[['part_number'=>1,'etag'=>'"thumbnail-etag"']],
            ])->assertStatus(202);
        $this->assertDatabaseHas('media_variants',['media_id'=>$media['media_id'],'state'=>'verifying']);
        $this->assertSame(1,app(MediaLifecycleWorker::class)->run()['verified']);
        $this->assertDatabaseHas('media_variants',['media_id'=>$media['media_id'],
            'variant'=>'thumbnail','state'=>'ready']);
        $this->withToken($token)->getJson('/api/v1/media/'.$media['media_id'])
            ->assertOk()->assertJsonPath('data.variants.0','thumbnail');
    }

    public function test_friend_media_grant_is_revoked_immediately_after_block():void
    {
        [$owner,$ownerToken]=$this->person('owner');
        [$friend,$friendToken,$friendDevice]=$this->person('friend');
        [$low,$high]=app(\App\Modules\Social\Services\SocialGraphService::class)
            ->canonical($owner->id,$friend->id);
        DB::table('friendships')->insert(['user_low_id'=>$low,'user_high_id'=>$high,'created_at'=>now()]);
        $media=$this->ready($ownerToken);
        $this->withToken($ownerToken)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/'.$media['media_id'].'/grants',[
                'audience_kind'=>'selected_friends','target_ids'=>[$friend->id],
                'key_envelopes'=>[[
                    'device_id'=>$friendDevice->id,'variant'=>'original',
                    'key_version'=>'v1','algorithm'=>'x25519+hkdf+chacha20poly1305',
                    'wrapped_key_ciphertext'=>str_repeat('E',64),
                ]],
            ])->assertCreated();
        $this->withToken($friendToken)->postJson('/api/v1/media/'.$media['media_id'].'/access',[
            'device_id'=>$friendDevice->id,'variant'=>'original',
        ])->assertOk();
        $this->withToken($ownerToken)->postJson('/api/v1/me/blocks',[
            'user_id'=>$friend->id,
        ])->assertCreated();
        $this->withToken($friendToken)->postJson('/api/v1/media/'.$media['media_id'].'/access',[
            'device_id'=>$friendDevice->id,'variant'=>'original',
        ])->assertNotFound();
    }

    public function test_missing_provider_configuration_never_falls_back_to_public_storage():void
    {
        [$owner,$token]=$this->person('owner');
        app()->instance(MediaObjectStore::class,new MediaObjectStore());
        config()->set('orbit_media.bucket',null);
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',[
                'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/jpeg',
                'declared_bytes'=>1024,'cipher_suite'=>'xchacha20poly1305-v1',
            ])->assertStatus(503);
        $this->assertDatabaseCount('media_objects',0);
    }

}