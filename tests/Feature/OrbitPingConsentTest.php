<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitPingConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_circle_ping_consent_requires_subject_authentication_and_global_privacy(): void
    {
        config(['orbit_circle.enabled' => true,
            'orbit_circle.consumer_enabled.ping' => true,
            'orbit_ping.enabled' => true]);
        $owner = OrbitUser::create(['display_name'=>'Member','username'=>'pingowner',
            'status'=>'active']);
        $other = OrbitUser::create(['display_name'=>'Other','username'=>'pingother',
            'status'=>'active']);
        $device = OrbitDevice::create(['user_id'=>$owner->id,
            'device_public_id'=>(string) Str::uuid(), 'name'=>'Phone',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        $token=app(TokenService::class)->issue($owner,$device,null)['access_token'];
        $circle=(string) Str::uuid();
        DB::table('circles')->insert(['id'=>$circle,'owner_id'=>$owner->id,
            'name'=>'Trusted Circle','created_at'=>now(),'updated_at'=>now()]);
        DB::table('circle_members')->insert([
            'circle_id'=>$circle,'user_id'=>$owner->id,'role'=>'owner',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $endpoint='/api/v1/me/pings/circles/'.$circle.'/consent';
        $this->putJson($endpoint,['enabled'=>true,'confirm'=>true])->assertUnauthorized();
        $this->withToken($token)->putJson($endpoint,['enabled'=>true,'confirm'=>true])
            ->assertUnprocessable();
        DB::table('orbit_user_preferences')->insert([
            'user_id'=>$owner->id,
            'privacy'=>json_encode(['ping_eligibility'=>'My Circle']),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->withToken($token)->putJson($endpoint,['enabled'=>true,'confirm'=>false])
            ->assertUnprocessable();
        $this->withToken($token)->putJson($endpoint,['enabled'=>true,'confirm'=>true])
            ->assertOk()->assertJsonPath('data.enabled',true);
        $this->assertDatabaseHas('circle_permissions',[
            'circle_id'=>$circle,'subject_user_id'=>$owner->id,
            'permission_key'=>'ping','enabled'=>true,
        ]);
        $this->withToken($token)->getJson($endpoint)
            ->assertOk()->assertJsonPath('data.enabled',true);
        $this->withToken($token)->putJson($endpoint,['enabled'=>false])
            ->assertOk()->assertJsonPath('data.enabled',false);
        DB::table('circle_members')->where('circle_id',$circle)
            ->where('user_id',$owner->id)->update(['removed_at'=>now()]);
        $this->withToken($token)->putJson($endpoint,['enabled'=>true,'confirm'=>true])
            ->assertNotFound();
        $this->assertDatabaseMissing('circle_permissions',[
            'circle_id'=>$circle,'subject_user_id'=>$other->id,
            'permission_key'=>'ping','enabled'=>true,
        ]);
    }
}
