<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM7MomentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp():void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_media.enabled',true);
        config()->set('orbit_moments.enabled',true);
    }

    private function principal():array
    {
        $user=OrbitUser::create([
            'username'=>'m7_'.Str::random(10),'display_name'=>'M7 local tester','status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M7 test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,$device,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    private function readyPrivateMedia(OrbitUser $user,OrbitDevice $device):string
    {
        $media=(string)Str::uuid7();$grant=(string)Str::uuid7();
        DB::table('media_objects')->insert([
            'id'=>$media,'owner_user_id'=>$user->id,'purpose'=>'moment','kind'=>'image',
            'declared_mime'=>'image/jpeg','cipher_suite'=>'aes256gcm-v1',
            'object_key'=>'orbit-private/m7/'.Str::uuid(),'state'=>'ready',
            'cipher_bytes'=>122,'cipher_sha256'=>str_repeat('a',64),
            'created_at'=>now()->subDay(),'updated_at'=>now(),'ready_at'=>now(),
        ]);
        DB::table('media_variants')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$media,'variant'=>'thumbnail',
            'object_key'=>'orbit-private/m7/'.Str::uuid(),'state'=>'ready',
            'cipher_bytes'=>55,'cipher_sha256'=>str_repeat('b',64),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('media_audience_grants')->insert([
            'id'=>$grant,'media_id'=>$media,'issuer_user_id'=>$user->id,
            'recipient_user_id'=>$user->id,'circle_id'=>null,'audience_kind'=>'only_me',
            'policy_version'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach(['original','thumbnail'] as $variant)DB::table('media_device_key_envelopes')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$media,'grant_id'=>$grant,
            'recipient_device_id'=>$device->id,'variant'=>$variant,
            'key_version'=>'v1','algorithm'=>'x25519-hkdfsha256-aes256gcm-v1',
            'wrapped_key_ciphertext'=>str_repeat('E',80),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $media;
    }

    public function test_flag_closed_denies_moments_even_with_valid_token():void
    {
        [$user,$device,$token]=$this->principal();
        config()->set('orbit_moments.enabled',false);
        $this->withToken($token)->getJson('/api/v1/moments')->assertNotFound();
        $this->assertDatabaseCount('moments',0);
    }

    public function test_private_publish_is_real_idempotent_and_not_visible_to_strangers():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $payload=['client_id'=>(string)Str::uuid(),'media_id'=>$media,
            'audience_kind'=>'only_me','target_ids'=>[]];
        $key=(string)Str::uuid();
        $post=$this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments',$payload)->assertCreated()
            ->assertJsonPath('data.published',true)
            ->assertJsonPath('data.audience_keys_confirmed',true);
        $id=$post->json('data.moment_id');
        $this->assertTrue(Str::isUuid($id));
        $this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments',$payload)->assertOk()->assertJsonPath('data.moment_id',$id);
        $this->assertDatabaseCount('moments',1);
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)->assertOk()->assertJsonPath('data.media_id',$media);
        [$stranger,$theirDevice,$theirToken]=$this->principal();
        $this->withToken($theirToken)->getJson('/api/v1/moments/'.$id)->assertNotFound();
        $this->withToken($theirToken)->postJson('/api/v1/moments/'.$id.'/views')->assertNotFound();
        $this->withToken($theirToken)->getJson('/api/v1/moments/'.$id.'/comments')->assertNotFound();
        $this->withToken($theirToken)->getJson('/api/v1/moments/'.$id.'/media-manifest')->assertNotFound();
        $this->withToken($token)->putJson('/api/v1/moments/'.$id.'/reaction',['reaction_code'=>'love'])
            ->assertOk()->assertJsonPath('data.my_reaction','love');
        $this->withToken($token)->postJson('/api/v1/moments/'.$id.'/views')->assertNoContent();
        $this->assertDatabaseCount('moment_views',1);
    }

    public function test_unauthorized_grants_never_create_a_moment():void
    {
        [$user,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($user,$device);
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'selected_friends','target_ids'=>[(string)Str::uuid()],
            ])->assertStatus(422);
        $this->assertDatabaseCount('moments',0);
    }
}
