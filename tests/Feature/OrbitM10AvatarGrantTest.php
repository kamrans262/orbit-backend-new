<?php
namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM10AvatarGrantTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name): array
    {
        $owner=OrbitUser::create(['display_name'=>$name,
            'username'=>strtolower($name).'_'.Str::lower(Str::random(9)),
            'status'=>'active']);
        $device=OrbitDevice::create([
            'user_id'=>$owner->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$owner,$device,app(TokenService::class)->issue($owner,$device,null)['access_token']];
    }

    public function test_private_avatar_requires_explicit_location_binding_and_is_revoked_with_share(): void
    {
        config()->set('orbit_location.enabled',true);
        config()->set('orbit_media.enabled',true);
        [$alice,$aliceDevice,$ownerToken]=$this->person('Alice');
        [$bob,$bobDevice,$friendToken]=$this->person('Bob');
        [$outsider,,$outsiderToken]=$this->person('Outsider');
        $pair=[$alice->id,$bob->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id'=>$pair[0],'user_high_id'=>$pair[1],'created_at'=>now()]);
        DB::table('orbit_user_preferences')->updateOrInsert(['user_id'=>$alice->id],[
            'privacy'=>json_encode([
                'location_visibility'=>'Friends','location_activity'=>'Active',
                'location_sharing_enabled'=>true,'ghost_mode_enabled'=>false,
            ],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now(),
        ]);

        $share=$this->withToken($ownerToken)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,
            'precision'=>'approximate','duration'=>'manual','reviewed'=>true,
        ])->assertCreated()->json('data.id');

        $mediaId=(string)Str::uuid();
        $grantId=(string)Str::uuid();
        DB::table('media_objects')->insert([
            'id'=>$mediaId,'owner_user_id'=>$alice->id,'purpose'=>'avatar',
            'kind'=>'image','declared_mime'=>'image/jpeg',
            'cipher_suite'=>'aes256gcm-v1','object_key'=>'m10-test/'.Str::uuid(),
            'state'=>'ready','created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('orbit_users')->where('id',$alice->id)->update([
            'avatar_media_id'=>$mediaId]);
        DB::table('media_audience_grants')->insert([
            'id'=>$grantId,'media_id'=>$mediaId,
            'issuer_user_id'=>$alice->id,
            'recipient_user_id'=>$bob->id,'audience_kind'=>'selected_friends',
            'policy_version'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('media_device_key_envelopes')->insert([
            'id'=>(string)Str::uuid(),'media_id'=>$mediaId,
            'grant_id'=>$grantId,'recipient_device_id'=>$bobDevice->id,
            'variant'=>'original','key_version'=>'v1',
            'algorithm'=>'x25519-hkdfsha256-aes256gcm-v1',
            'wrapped_key_ciphertext'=>str_repeat('a',80),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->withToken($ownerToken)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.582045,'longitude'=>74.329376,
            'accuracy_m'=>12,'observed_at'=>now()->toIso8601String(),
        ])->assertAccepted();
        // The existing M6 grant is not enough to put an image on the map.
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(1,'data.markers')
            ->assertJsonMissingPath('data.markers.0.avatar_media_id');

        $this->withToken($ownerToken)->postJson(
            '/api/v1/me/location/shares/'.$share.'/avatar-grant',[
                'media_id'=>$mediaId,'grant_id'=>$grantId,
            ])->assertOk()->assertJsonPath('data.avatar_shared',true);
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonPath('data.markers.0.display_name','Alice')
            ->assertJsonPath('data.markers.0.avatar_media_id',$mediaId)
            ->assertJsonMissingPath('data.markers.0.wrapped_key_ciphertext');
        $this->withToken($outsiderToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');

        $this->withToken($ownerToken)->deleteJson(
            '/api/v1/me/location/shares/'.$share)->assertOk();
        $this->assertNotNull(DB::table('media_audience_grants')
            ->where('id',$grantId)->value('revoked_at'));
        $this->assertNotNull(DB::table('media_device_key_envelopes')
            ->where('grant_id',$grantId)->value('revoked_at'));
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
    }
}
