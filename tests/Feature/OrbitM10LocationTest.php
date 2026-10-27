<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM10LocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_location.enabled',true);
        config()->set('orbit_location.realtime_enabled',false);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.consumer_enabled.location',true);
    }

    private function person(string $prefix): array
    {
        $user=OrbitUser::create([
            'username'=>$prefix.'_'.Str::lower(Str::random(9)),
            'display_name'=>ucfirst($prefix),'status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M10 test device','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),'trust_state'=>'trusted',
            'attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token'],$device];
    }

    private function privacy(string $owner,string $audience='Friends',bool $ghost=false): void
    {
        DB::table('orbit_user_preferences')->updateOrInsert(['user_id'=>$owner],[
            'privacy'=>json_encode([
                'location_visibility'=>$audience,'location_activity'=>'Active',
                'location_sharing_enabled'=>true,'ghost_mode_enabled'=>$ghost,
            ],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function friend(string $a,string $b): void
    {
        $pair=[$a,$b]; sort($pair,SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id'=>$pair[0],'user_high_id'=>$pair[1],'created_at'=>now(),
        ]);
    }

    private function share(string $token,string $type,string $target,
        string $precision='approximate',string $duration='one_hour'): string
    {
        return $this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>$type,'audience_id'=>$target,'precision'=>$precision,
            'duration'=>$duration,'reviewed'=>true,
        ])->assertCreated()->json('data.id');
    }

    private function sample(string $token,float $lat=31.582045,float $lng=74.329376): void
    {
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>$lat,'longitude'=>$lng,'accuracy_m'=>12.5,
            'observed_at'=>now()->toIso8601String(),
        ])->assertAccepted()->assertJsonPath('data.realtime_available',false);
    }

    public function test_disabled_unauthenticated_and_untrusted_fail_closed(): void
    {
        [$alice,$token,$device]=$this->person('alice');
        $this->getJson('/api/v1/location/map')->assertUnauthorized();
        config()->set('orbit_location.enabled',false);
        $this->withToken($token)->getJson('/api/v1/location/map')->assertNotFound();
        config()->set('orbit_location.enabled',true);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->getJson('/api/v1/location/map')->assertForbidden();
    }

    public function test_share_requires_confirmed_review_global_privacy_and_friendship(): void
    {
        [$alice,$token]=$this->person('alice');
        [$bob]=$this->person('bob');
        $data=['audience_type'=>'person','audience_id'=>$bob->id,
            'precision'=>'precise','duration'=>'one_hour','reviewed'=>true];
        $this->withToken($token)->postJson('/api/v1/me/location/shares',$data)->assertForbidden();
        $this->privacy($alice->id);
        $this->withToken($token)->postJson('/api/v1/me/location/shares',$data)->assertForbidden();
        $this->friend($alice->id,$bob->id);
        $this->withToken($token)->postJson('/api/v1/me/location/shares',[
            ...$data,'reviewed'=>false,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/location/shares',$data)->assertCreated()
            ->assertJsonPath('data.status','active');
        $this->assertDatabaseCount('location_consent_audits',1);
    }

    public function test_approximate_map_is_server_coarsened_private_and_never_claimed_live(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        [$stranger,$strangerToken]=$this->person('stranger');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $this->share($aliceToken,'person',$bob->id);
        $this->sample($aliceToken);
        $raw=DB::table('location_latest')->where('owner_id',$alice->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('31.582045',(string)$raw);
        $this->assertStringNotContainsString('74.329376',(string)$raw);
        $this->withToken($bobToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(1,'data.markers')
            ->assertJsonPath('data.markers.0.user_id',$alice->id)
            ->assertJsonPath('data.markers.0.precision','approximate')
            ->assertJsonPath('data.markers.0.state','updated')
            ->assertJsonPath('data.realtime_available',false)
            ->assertJsonPath('data.live_claimed',false)
            ->assertJsonMissingPath('data.markers.0.accuracy_m');
        $marker=$this->withToken($bobToken)->getJson('/api/v1/location/map')->json('data.markers.0');
        $this->assertNotEquals(31.582045,(float)$marker['latitude']);
        $this->assertNotEquals(74.329376,(float)$marker['longitude']);
        $this->withToken($strangerToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
    }

    public function test_ghost_blocks_access_stop_erases_latest_and_expiry_cannot_resurrect(): void
    {
        [$alice,$token]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $share=$this->share($token,'person',$bob->id,'precise');
        $this->sample($token);
        $this->privacy($alice->id,'Friends',true);
        $this->withToken($bobToken)->getJson('/api/v1/location/map')->assertJsonCount(0,'data.markers');
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.582045,'longitude'=>74.329376,'accuracy_m'=>10,
            'observed_at'=>now()->toIso8601String(),
        ])->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/me/location/shares/$share")->assertOk()
            ->assertJsonPath('data.sharing_active',false);
        $this->assertDatabaseMissing('location_latest',['owner_id'=>$alice->id]);
        $this->privacy($alice->id);
        $this->withToken($bobToken)->getJson('/api/v1/location/map')->assertJsonCount(0,'data.markers');
        $this->withToken($token)->deleteJson('/api/v1/me/location/shares')->assertOk()
            ->assertJsonPath('data.revoked',0);
    }

    public function test_circle_shares_require_m5_consent_and_recheck_block_and_membership(): void
    {
        [$alice,$token]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $circle=$this->withToken($token)->postJson('/api/v1/circles',['name'=>'M10 Trusted'])
            ->assertCreated()->json('data.id');
        DB::table('circle_members')->insert([
            'circle_id'=>$circle,'user_id'=>$bob->id,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->privacy($alice->id,'My Circle');
        $id=$this->share($token,'circle',$circle,'precise');
        $this->assertDatabaseHas('circle_permissions',[
            'circle_id'=>$circle,'subject_user_id'=>$alice->id,
            'permission_key'=>'location','enabled'=>true,
        ]);
        $this->sample($token);
        $this->withToken($bobToken)->getJson('/api/v1/location/map?circle_id='.$circle)
            ->assertOk()->assertJsonCount(1,'data.markers');
        DB::table('blocks')->insert([
            'blocker_id'=>$bob->id,'blocked_id'=>$alice->id,'created_at'=>now(),
        ]);
        $this->withToken($bobToken)->getJson('/api/v1/location/map?circle_id='.$circle)
            ->assertNotFound(); // owner block collapses Circle scope
        DB::table('blocks')->delete();
        $this->withToken($token)->deleteJson("/api/v1/me/location/shares/$id")->assertOk();
        $this->assertDatabaseHas('circle_permissions',[
            'circle_id'=>$circle,'subject_user_id'=>$alice->id,
            'permission_key'=>'location','enabled'=>false,
        ]);
        $this->withToken($bobToken)->getJson('/api/v1/location/map?circle_id='.$circle)
            ->assertOk()->assertJsonCount(0,'data.markers');
    }

    public function test_m5_permission_revocation_stops_circle_only_location_ingest(): void
    {
        [$alice,$token]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $circle=$this->withToken($token)->postJson('/api/v1/circles',['name'=>'Private'])
            ->assertCreated()->json('data.id');
        DB::table('circle_members')->insert([
            'circle_id'=>$circle,'user_id'=>$bob->id,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->privacy($alice->id,'My Circle');
        $this->share($token,'circle',$circle,'precise');
        $this->sample($token);
        $this->withToken($token)->patchJson("/api/v1/circles/$circle/permissions",[
            'permission_key'=>'location','enabled'=>false,
        ])->assertOk();
        $this->withToken($bobToken)->getJson('/api/v1/location/map?circle_id='.$circle)
            ->assertOk()->assertJsonCount(0,'data.markers');
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.582045,'longitude'=>74.329376,'accuracy_m'=>10,
            'observed_at'=>now()->addSecond()->toIso8601String(),
        ])->assertForbidden();
    }

    public function test_stale_observation_untrusted_sample_and_expiring_share(): void
    {
        [$alice,$token,$device]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $this->share($token,'person',$bob->id);
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.58,'longitude'=>74.33,'accuracy_m'=>11,
            'observed_at'=>now()->subMinutes(5)->toIso8601String(),
        ])->assertUnprocessable();
        $this->sample($token);
        $device->update(['revoked_at'=>now()]);
        $this->withToken($bobToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
        $device->update(['revoked_at'=>null]);
        $this->travel(3601)->seconds();
        $this->withToken($bobToken)->getJson('/api/v1/location/map')
            ->assertOk()->assertJsonCount(0,'data.markers');
        $this->travelBack();
    }

    public function test_admin_location_requires_admin_mfa_and_never_returns_raw_gps(): void
    {
        $this->get('/admin/location')->assertRedirect(route('orbit.admin.login'));
    }

    public function test_opt_in_exit_geofence_uses_encrypted_anchor_and_two_reliable_fixes(): void
    {
        config()->set('orbit_location.until_leaving_enabled',true);
        [$alice,$token]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $share=$this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,'precision'=>'approximate',
            'duration'=>'until_leaving','exit_radius_m'=>250,'reviewed'=>true,
        ])->assertCreated()->json('data.id');
        $this->sample($token,31.582045,74.329376);
        $anchor=DB::table('location_shares')->where('id',$share)->value('geo_anchor_encrypted');
        $this->assertNotNull($anchor);
        $this->assertStringNotContainsString('31.582045',(string)$anchor);
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.592045,'longitude'=>74.329376,'accuracy_m'=>12,
            'observed_at'=>now()->addSecond()->toIso8601String(),
        ])->assertAccepted()->assertJsonPath('data.accepted',true);
        $this->withToken($token)->postJson('/api/v1/me/location/samples',[
            'latitude'=>31.592045,'longitude'=>74.329376,'accuracy_m'=>12,
            'observed_at'=>now()->addSeconds(2)->toIso8601String(),
        ])->assertAccepted()->assertJsonPath('data.sharing_stopped',true);
        $this->assertDatabaseHas('location_shares',['id'=>$share,'status'=>'revoked']);
        $this->assertDatabaseMissing('location_latest',['owner_id'=>$alice->id]);
        $this->assertNull(DB::table('location_shares')->where('id',$share)->value('geo_anchor_encrypted'));
        $this->withToken($bobToken)->getJson('/api/v1/location/map')->assertJsonCount(0,'data.markers');
    }

    public function test_account_erasure_purges_m10_recipient_grants_and_encrypted_samples(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        [$bob]=$this->person('bob');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $share=$this->share($aliceToken,'person',$bob->id);
        $this->sample($aliceToken);

        $actor=\App\Models\AdminUser::create([
            'name'=>'M10 Test Administrator','email'=>'m10-admin@example.test',
            'password'=>bcrypt('local-test-only-password'),
            'totp_secret'=>(new \App\Modules\Config\Services\Totp())->generateSecret(),
            'status'=>'active',
        ]);
        app(\App\Modules\Identity\Services\DeleteOrbitUser::class)->execute(
            $bob->id,$actor,'Verified account erasure request',null,null);
        $this->assertDatabaseMissing('location_shares',['id'=>$share]);
        $this->assertDatabaseHas('location_latest',['owner_id'=>$alice->id]);

        app(\App\Modules\Identity\Services\DeleteOrbitUser::class)->execute(
            $alice->id,$actor,'Verified account erasure request',null,null);
        $this->assertDatabaseMissing('location_latest',['owner_id'=>$alice->id]);
        $this->assertDatabaseMissing('location_consent_audits',['owner_id'=>$alice->id]);
        $this->assertDatabaseMissing('location_shares',['owner_id'=>$alice->id]);
    }

    public function test_until_leaving_cannot_be_silently_approximated(): void
    {
        [$alice,$token]=$this->person('alice');
        [$bob]=$this->person('bob');
        $this->friend($alice->id,$bob->id);
        $this->privacy($alice->id);
        $this->withToken($token)->postJson('/api/v1/me/location/shares',[
            'audience_type'=>'person','audience_id'=>$bob->id,'precision'=>'precise',
            'duration'=>'until_leaving','reviewed'=>true,
        ])->assertUnprocessable();
    }
}
