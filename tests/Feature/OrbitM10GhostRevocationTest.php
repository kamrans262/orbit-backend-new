<?php
namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrbitM10GhostRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $prefix): array
    {
        $user=OrbitUser::create(['display_name'=>ucfirst($prefix),
            'username'=>$prefix.'_'.Str::lower(Str::random(9)),'status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,
            'device_public_id'=>(string)Str::uuid(),'name'=>'Location device',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified',
            'approved_at'=>now()]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    public function test_global_ghost_revoke_is_durable_and_cannot_resurrect_old_location_grant(): void
    {
        config()->set('orbit_location.enabled',true);
        [$alice,$token]=$this->person('alice');
        [$bob,$friendToken]=$this->person('bob');
        $pair=[$alice->id,$bob->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$pair[0],
            'user_high_id'=>$pair[1],'created_at'=>now()]);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'ghost_mode_enabled'=>false,'location_sharing_enabled'=>true,
            'location_activity'=>'Active','location_visibility'=>'Friends',
        ])->assertOk();
        $share=$this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,
            'precision'=>'approximate','duration'=>'manual','reviewed'=>true,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.582045,'longitude'=>74.329376,
            'accuracy_m'=>12,'observed_at'=>now()->toIso8601String(),
        ])->assertAccepted();
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(1,'data.markers');
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'ghost_mode_enabled'=>true,
        ])->assertOk()->assertJsonPath('data.location_sharing_enabled',false);
        $this->assertDatabaseHas('location_shares',['id'=>$share,'status'=>'revoked']);
        $this->assertDatabaseMissing('location_latest',['owner_id'=>$alice->id]);
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'ghost_mode_enabled'=>false,'location_sharing_enabled'=>true,
        ])->assertOk();
        $this->withToken($friendToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
        $this->assertDatabaseHas('location_shares',['id'=>$share,'status'=>'revoked']);
    }

    public function test_visibility_boundary_change_revokes_old_friend_consent_permanently(): void
    {
        config()->set('orbit_location.enabled',true);
        [$alice,$token]=$this->person('alice');
        [$bob,$viewer]=$this->person('bob');
        $pair=[$alice->id,$bob->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$pair[0],
            'user_high_id'=>$pair[1],'created_at'=>now()]);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'ghost_mode_enabled'=>false,'location_sharing_enabled'=>true,
            'location_activity'=>'Active','location_visibility'=>'Friends',
        ])->assertOk();
        $id=$this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,
            'precision'=>'approximate','duration'=>'always','reviewed'=>true,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'location_visibility'=>'My Circle',
        ])->assertOk();
        $this->assertDatabaseHas('location_shares',['id'=>$id,'status'=>'revoked']);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'location_visibility'=>'Friends',
        ])->assertOk();
        $this->assertDatabaseHas('location_shares',['id'=>$id,'status'=>'revoked']);
        $this->withToken($viewer)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
    }

    public function test_while_moving_requires_trusted_nonstationary_speed(): void
    {
        config()->set('orbit_location.enabled',true);
        [$alice,$token]=$this->person('alice');
        [$bob]=$this->person('bob');
        $pair=[$alice->id,$bob->id];sort($pair,SORT_STRING);
        DB::table('friendships')->insert(['user_low_id'=>$pair[0],
            'user_high_id'=>$pair[1],'created_at'=>now()]);
        $this->withToken($token)->patchJson('/api/v1/me/preferences/privacy',[
            'ghost_mode_enabled'=>false,'location_sharing_enabled'=>true,
            'location_activity'=>'While moving','location_visibility'=>'Friends',
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,
            'precision'=>'approximate','duration'=>'manual','reviewed'=>true,
        ])->assertCreated();
        $sample=['latitude'=>31.582045,'longitude'=>74.329376,
            'accuracy_m'=>12,'observed_at'=>now()->toIso8601String()];
        $this->withToken($token)->postJson('/api/v1/me/location/samples',
            $sample)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/location/samples',
            [...$sample,'speed_mps'=>0.3])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/location/samples',
            [...$sample,'speed_mps'=>1.8])->assertAccepted();
    }
}
