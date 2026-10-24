<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Moments\Services\MomentLifecycleWorker;
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
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)
            ->assertOk()->assertJsonPath('data.my_reaction','love');
        $this->withToken($token)->postJson('/api/v1/moments/'.$id.'/views')->assertNoContent();
        $this->assertDatabaseCount('moment_views',0);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'read_receipts_enabled'=>true,
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/moments/'.$id.'/views')->assertNoContent();
        $this->assertDatabaseCount('moment_views',1);
    }

    public function test_owner_caption_edit_and_audience_retry_require_versions():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $id=$this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'only_me','target_ids'=>[],
            ])->assertCreated()->json('data.moment_id');
        $this->withToken($token)->patchJson('/api/v1/moments/'.$id,[
            'revision'=>1,'caption_ciphertext'=>str_repeat('C',64),
            'caption_key_version'=>'v1',
        ])->assertOk()->assertJsonPath('data.publish_version',2);
        $this->withToken($token)->patchJson('/api/v1/moments/'.$id,[
            'revision'=>1,'caption_ciphertext'=>str_repeat('C',64),
            'caption_key_version'=>'v1',
        ])->assertStatus(409);
        $payload=['audience_kind'=>'only_me','target_ids'=>[],'revision'=>1];
        $this->withToken($token)->postJson('/api/v1/moments/'.$id.'/audience',
            $payload)->assertOk()->assertJsonPath('data.audience_revision',2);
        $this->withToken($token)->postJson('/api/v1/moments/'.$id.'/audience',
            $payload)->assertOk()->assertJsonPath('data.replayed',true);
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)
            ->assertOk()->assertJsonPath('data.audience_kind','only_me')
            ->assertJsonPath('data.publish_version',2);
    }

    public function test_unauthorized_grants_never_create_a_moment():void
    {
        [$user,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($user,$device);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'moment_default_audience'=>'Selected People',
        ])->assertOk();
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'selected_friends','target_ids'=>[(string)Str::uuid()],
            ])->assertStatus(422);
        $this->assertDatabaseCount('moments',0);
    }
    public function test_m7_reads_expire_at_seven_days_from_publication_not_upload():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $res=$this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'only_me','target_ids'=>[],
            ])->assertCreated();
        $id=$res->json('data.moment_id');
        $this->travel(6)->days();
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)->assertOk();
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)->assertNotFound();
        $this->assertSame(1,app(MomentLifecycleWorker::class)->run()['expired']);
        $this->assertDatabaseHas('moments',['id'=>$id,'state'=>'expired']);
        $this->travel(23)->days();
        $this->assertSame(1,app(MomentLifecycleWorker::class)->run()['queued_purges']);
        $this->assertDatabaseHas('media_delete_jobs',['media_id'=>$media,'reason'=>'moment_retention']);
    }

    public function test_comment_replay_and_report_never_expose_plaintext():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $id=$this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'only_me','target_ids'=>[],
            ])->assertCreated()->json('data.moment_id');
        $key=(string)Str::uuid();
        $payload=['body_ciphertext'=>str_repeat('C',80),'key_version'=>'v1'];
        $first=$this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments/'.$id.'/comments',$payload)->assertCreated();
        $cid=$first->json('data.comment_id');
        $this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments/'.$id.'/comments',$payload)
            ->assertOk()->assertJsonPath('data.comment_id',$cid);
        $this->assertDatabaseCount('moment_comments',1);
        $this->withToken($token)->getJson('/api/v1/moments/'.$id.'/comments')
            ->assertOk()->assertJsonCount(1,'data.items');
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments/'.$id.'/reports',[
            'reason'=>'privacy','evidence_consent'=>false,
        ])->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments/'.$id.'/reports',[
            'reason'=>'privacy','evidence_consent'=>false,
            'consent_evidence_ciphertext'=>str_repeat('X',64),
        ])->assertUnprocessable();
    }

    public function test_global_nobody_setting_blocks_friend_publication_even_with_grants():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        [$friend,$friendDevice,$friendToken]=$this->principal();
        [$lo,$hi]=app(\App\Modules\Social\Services\SocialGraphService::class)->canonical($owner->id,$friend->id);
        DB::table('friendships')->insert(['user_low_id'=>$lo,'user_high_id'=>$hi,'created_at'=>now()]);
        $grant=(string)Str::uuid7();
        DB::table('media_audience_grants')->insert([
            'id'=>$grant,'media_id'=>$media,'issuer_user_id'=>$owner->id,'recipient_user_id'=>$friend->id,
            'audience_kind'=>'selected_friends','policy_version'=>2,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach(['original','thumbnail'] as $v)DB::table('media_device_key_envelopes')->insert([
            'id'=>(string)Str::uuid7(),'media_id'=>$media,'grant_id'=>$grant,
            'recipient_device_id'=>$friendDevice->id,'variant'=>$v,'key_version'=>'v1',
            'algorithm'=>'x25519-hkdfsha256-aes256gcm-v1',
            'wrapped_key_ciphertext'=>str_repeat('W',80),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $request=['client_id'=>(string)Str::uuid(),'media_id'=>$media,
            'audience_kind'=>'selected_friends','target_ids'=>[$friend->id]];
        $key=(string)Str::uuid();
        $this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments',$request)->assertForbidden();
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'moment_default_audience'=>'Selected People',
        ])->assertOk();
        // Grant envelopes can be staged before M7 publication, but never
        // reveal any ciphertext until the server commits a live Moment.
        $this->withToken($friendToken)->getJson('/api/v1/media/'.$media)->assertNotFound();
        $id=$this->withToken($token)->withHeader('Idempotency-Key',$key)
            ->postJson('/api/v1/moments',$request)->assertCreated()->json('data.moment_id');
        $this->withToken($friendToken)->getJson('/api/v1/media/'.$media)->assertOk();
        // An M6 key grant alone cannot bypass the M7 audience revision.
        DB::table('moment_recipients')->where('moment_id',$id)
            ->where('viewer_id',$friend->id)->update(['revoked_at'=>now()]);
        $this->withToken($friendToken)->getJson('/api/v1/media/'.$media)->assertNotFound();
        $this->withToken($friendToken)->getJson('/api/v1/moments/'.$id)->assertNotFound();
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'moment_default_audience'=>'Nobody',
        ])->assertOk();
        $this->withToken($friendToken)->getJson('/api/v1/moments/'.$id)->assertNotFound();
    }

    public function test_admin_reuses_layout_and_is_permission_gated():void
    {
        $role=AdminRole::create(['name'=>'M7 auditor','permissions'=>['moments.read']]);
        $admin=AdminUser::create([
            'name'=>'M7 Auditor','email'=>'m7-auditor@example.test','status'=>'active',
            'password'=>'Secure-Password!123','totp_secret'=>(new Totp())->generateSecret(),
        ]);
        $admin->roles()->attach($role->id);
        $this->be($admin,'admin');
        $this->get('/admin/moments')->assertOk()->assertSee('Moment Management')
            ->assertDontSee('wrapped_key_ciphertext')->assertDontSee('body_ciphertext');
        $role->update(['permissions'=>['users.read']]);
        $this->get('/admin/moments')->assertForbidden();
    }

    public function test_interrupted_prep_reclaims_only_orphan_recipient_keys_not_private_owner_keys():void
    {
        [$owner,$device,$token]=$this->principal();
        [$friend,$friendDevice,$friendToken]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        DB::table('media_objects')->where('id',$media)
            ->update(['created_at'=>now()->subHours(4)]);
        $orphan=(string)Str::uuid7();
        DB::table('media_audience_grants')->insert([
            'id'=>$orphan,'media_id'=>$media,'issuer_user_id'=>$owner->id,
            'recipient_user_id'=>$friend->id,'circle_id'=>null,
            'audience_kind'=>'selected_friends','policy_version'=>2,
            'created_at'=>now()->subHours(3),'updated_at'=>now()->subHours(3),
        ]);
        $stats=app(MomentLifecycleWorker::class)->run();
        $this->assertSame(1,$stats['orphan_grants_revoked']);
        $this->assertNotNull(DB::table('media_audience_grants')
            ->where('id',$orphan)->value('revoked_at'));
        $this->assertDatabaseHas('media_audience_grants',[
            'media_id'=>$media,'recipient_user_id'=>$owner->id,
            'audience_kind'=>'only_me','revoked_at'=>null,
        ]);
    }

    public function test_voice_moment_requires_verified_independently_encrypted_waveform():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        DB::table('media_objects')->where('id',$media)->update([
            'kind'=>'audio','declared_mime'=>'audio/mp4',
            'declared_duration_ms'=>45000,
        ]);
        // The image derivative is not valid for an audio Moment.
        $withoutWave=['client_id'=>(string)Str::uuid(),'media_id'=>$media,
            'audience_kind'=>'only_me','target_ids'=>[]];
        $this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',$withoutWave)->assertStatus(409);
        DB::table('media_variants')->where('media_id',$media)
            ->where('variant','thumbnail')->update(['variant'=>'waveform']);
        DB::table('media_device_key_envelopes')->where('media_id',$media)
            ->where('variant','thumbnail')->update(['variant'=>'waveform']);
        $client=(string)Str::uuid();
        $payload=['client_id'=>$client,'media_id'=>$media,
            'audience_kind'=>'only_me','target_ids'=>[]];
        $id=$this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',$payload)->assertCreated()
            ->assertJsonPath('data.published',true)->json('data.moment_id');
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)
            ->assertOk()->assertJsonPath('data.media_kind','audio')
            ->assertJsonPath('data.duration_ms',45000);
        $this->withToken($token)->getJson('/api/v1/moments/receipt/'.$client)
            ->assertOk()->assertJsonPath('data.moment_id',$id)
            ->assertJsonPath('data.audience_keys_confirmed',true);
        [$other,$otherDevice,$theirToken]=$this->principal();
        $this->withToken($theirToken)->getJson('/api/v1/moments/receipt/'.$client)
            ->assertNotFound();
        $this->withToken($theirToken)->getJson('/api/v1/moments/'.$id)
            ->assertNotFound();
    }

    public function test_place_metadata_is_private_ciphertext_and_requires_explicit_consent():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $body=['client_id'=>(string)Str::uuid(),'media_id'=>$media,
            'audience_kind'=>'only_me','target_ids'=>[],
            'place_ciphertext'=>str_repeat('Q',80),
            'place_key_version'=>'v1'];
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',$body)->assertUnprocessable();
        $body['location_consent']=true;
        $id=$this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',$body)->assertCreated()
            ->json('data.moment_id');
        $this->withToken($token)->getJson('/api/v1/moments/'.$id)
            ->assertOk()->assertJsonPath('data.place_ciphertext',str_repeat('Q',80))
            ->assertJsonPath('data.tagged_user_ids',[]);
        [$stranger,$otherDevice,$otherToken]=$this->principal();
        $this->withToken($otherToken)->getJson('/api/v1/moments/'.$id)->assertNotFound();
        $this->assertDatabaseHas('moments',['id'=>$id,'location_consent'=>true]);
    }

    public function test_moment_rejects_tagging_unrelated_people():void
    {
        [$owner,$device,$token]=$this->principal();
        [$other]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $payload=['client_id'=>(string)Str::uuid(),'media_id'=>$media,
            'audience_kind'=>'only_me','target_ids'=>[],
            'tagged_user_ids'=>[$other->id]];
        $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',$payload)->assertUnprocessable();
        $this->assertDatabaseCount('moment_people_tags',0);
    }

    public function test_encrypted_comment_cursor_does_not_skip_same_second_replies():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $moment=$this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'only_me','target_ids'=>[],
            ])->assertCreated()->json('data.moment_id');
        for($i=0;$i<3;$i++){
            $this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
                ->postJson('/api/v1/moments/'.$moment.'/comments',[
                    'context_id'=>'self','body_ciphertext'=>str_repeat((string)($i+1),64),
                    'key_version'=>'v1',
                ])->assertCreated();
        }
        // Regression: multiple encrypted replies can share one timestamp.
        $sameSecond=now()->startOfSecond();
        DB::table('moment_comments')->where('moment_id',$moment)
            ->update(['created_at'=>$sameSecond]);
        $first=$this->withToken($token)->getJson(
            '/api/v1/moments/'.$moment.'/comments?context_id=self&limit=2')
            ->assertOk()->json('data');
        $this->assertCount(2,$first['items']);
        $this->assertNotNull($first['next_before_id']);
        $second=$this->withToken($token)->getJson(
            '/api/v1/moments/'.$moment.'/comments?' . http_build_query([
                'context_id'=>'self','limit'=>2,
                'before'=>$first['next_before'],
                'before_id'=>$first['next_before_id'],
            ]))->assertOk()->json('data');
        $this->assertCount(1,$second['items']);
        $ids=array_merge(array_column($first['items'],'id'),
            array_column($second['items'],'id'));
        $this->assertCount(3,array_unique($ids));
    }

    public function test_owner_cannot_use_account_report_to_target_themself():void
    {
        [$owner,$device,$token]=$this->principal();
        $media=$this->readyPrivateMedia($owner,$device);
        $id=$this->withToken($token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments',[
                'client_id'=>(string)Str::uuid(),'media_id'=>$media,
                'audience_kind'=>'only_me','target_ids'=>[],
            ])->assertCreated()->json('data.moment_id');
        $this->withToken($token)
            ->withHeader('Idempotency-Key',(string)Str::uuid())
            ->postJson('/api/v1/moments/'.$id.'/reports',[
                'context_id'=>'self','reason'=>'spam',
                'evidence_consent'=>false,'report_target'=>'account',
            ])->assertUnprocessable();
        $this->assertDatabaseCount('moment_reports',0);
    }

}
