<?php
namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Media\Services\HostingerPrivateMediaStore;
use App\Modules\Media\Services\MediaLifecycleWorker;
use App\Modules\Media\Services\MediaObjectStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end local private Hostinger adapter test with actual filesystem
 * ciphertext bytes; no Cloudflare account, VPS, or real user credentials.
 */
final class OrbitM6HostingerMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The suite shares a single loopback IP and rate-limiter cache. This
        // filesystem/integrity test verifies authentication and ACL directly;
        // rate limit behavior has separate middleware coverage.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_media.enabled',true);
        config()->set('orbit_media.driver','hostinger_private');
        app()->instance(MediaObjectStore::class,new HostingerPrivateMediaStore());
    }

    private function uploadChunk(string $token,string $url,string $body,string $hash): \Illuminate\Testing\TestResponse
    {
        return $this->call('PUT',$url,[],[],[],[
            'HTTP_AUTHORIZATION'=>'Bearer '.$token,
            'CONTENT_TYPE'=>'application/octet-stream',
            'HTTP_X_ORBIT_CIPHER_SHA256'=>$hash,
        ],$body);
    }

    public function test_small_encrypted_avatar_is_hash_verified_before_complete_responds_ready(): void
    {
        $user=OrbitUser::create([
            'username'=>'avatar_'.Str::random(8),
            'display_name'=>'Avatar Owner','status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Avatar test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        $cipher=random_bytes(30_016); // Bounded small encrypted avatar.
        $init=$this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',[
                'purpose'=>'avatar','kind'=>'image','declared_mime'=>'image/jpeg',
                'declared_bytes'=>strlen($cipher),'cipher_suite'=>'aes256gcm-v1',
            ])->assertCreated()->json('data');
        $upload=$init['upload_id'];$media=$init['media_id'];
        $etag=$this->uploadChunk($token,
            '/api/v1/media/uploads/'.$upload.'/chunks/1',$cipher,
            hash('sha256',$cipher))->assertCreated()->json('data.etag');
        $this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads/'.$upload.'/complete',[
                'total_cipher_bytes'=>strlen($cipher),
                'cipher_sha256'=>hash('sha256',$cipher),
                'parts'=>[['part_number'=>1,'etag'=>$etag]],
            ])->assertOk()->assertJsonPath('data.state','ready');
        $this->assertDatabaseHas('media_objects',[
            'id'=>$media,'purpose'=>'avatar','state'=>'ready',
        ]);
        // A second cron pass cannot double-verify or emit another ready event.
        $this->assertSame(0,app(MediaLifecycleWorker::class)->run()['verified']);
        $this->assertSame(1,DB::table('media_event_outbox')
            ->where('media_id',$media)->where('event_type','media.upload.ready.v1')->count());
    }

    public function test_hostinger_private_chunk_replay_integrity_lifecycle_and_download(): void
    {
        $user=OrbitUser::create([
            'username'=>'m6local_'.Str::random(8),
            'display_name'=>'Local media user','status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Hostinger test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        $bytes=random_bytes(1048576+12);
        $init=$this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads',[
                'purpose'=>'moment','kind'=>'image','declared_mime'=>'image/jpeg',
                'declared_bytes'=>strlen($bytes),'cipher_suite'=>'aes256gcm-v1',
            ])->assertCreated()->assertJsonPath('data.storage_mode','local_chunked')
              ->json('data');
        $upload=$init['upload_id'];
        $media=$init['media_id'];
        $first=substr($bytes,0,1048576);
        $second=substr($bytes,1048576);
        $url='/api/v1/media/uploads/'.$upload.'/chunks/';
        $this->uploadChunk($token,$url.'1',$first,str_repeat('0',64))->assertUnprocessable();
        $etag1=$this->uploadChunk($token,$url.'1',$first,hash('sha256',$first))
            ->assertCreated()->json('data.etag');
        $this->uploadChunk($token,$url.'1',$first,hash('sha256',$first))
            ->assertCreated()->assertJsonPath('data.etag',$etag1);
        $etag2=$this->uploadChunk($token,$url.'2',$second,hash('sha256',$second))
            ->assertCreated()->json('data.etag');
        $this->withToken($token)->getJson('/api/v1/media/uploads/'.$upload)
            ->assertOk()->assertJsonCount(2,'data.uploaded_parts');
        $this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/uploads/'.$upload.'/complete',[
                'total_cipher_bytes'=>strlen($bytes),
                'cipher_sha256'=>hash('sha256',$bytes),
                'parts'=>[
                    ['part_number'=>1,'etag'=>$etag1],
                    ['part_number'=>2,'etag'=>$etag2],
                ],
            ])->assertStatus(202)->assertJsonPath('data.state','verifying');
        $result=app(MediaLifecycleWorker::class)->run();
        $this->assertSame(1,$result['verified']);
        $this->assertDatabaseHas('media_objects',['id'=>$media,'state'=>'ready']);
        // No grants => no ciphertext can leave private storage.
        $this->withToken($token)->get('/api/v1/media/'.$media.
            '/ciphertext?variant=original')->assertNotFound();
        $this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/media/'.$media.'/grants',[
                'audience_kind'=>'only_me','target_ids'=>[],
                'key_envelopes'=>[[
                    'device_id'=>$device->id,'variant'=>'original',
                    'key_version'=>'v1','algorithm'=>'x25519-hkdfsha256-aes256gcm-v1',
                    'wrapped_key_ciphertext'=>str_repeat('W',80),
                ]],
            ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/media/'.$media.'/access',[
            'variant'=>'original','device_id'=>$device->id,
        ])->assertOk()
          ->assertJsonPath('data.download_mode','authenticated');
        $response=$this->withToken($token)->get('/api/v1/media/'.$media.
            '/ciphertext?variant=original')->assertOk();
        $this->assertSame(hash('sha256',$bytes),
            hash_file('sha256',$response->baseResponse->getFile()->getPathname()));
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->deleteJson('/api/v1/media/'.$media)->assertStatus(202);
        $this->withToken($token)->get('/api/v1/media/'.$media.
            '/ciphertext?variant=original')->assertNotFound();
        $this->assertSame(1,app(MediaLifecycleWorker::class)->run()['purged']);
        $this->assertDatabaseHas('media_objects',['id'=>$media,'state'=>'deleted']);
    }
}
