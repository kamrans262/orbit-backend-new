<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrbitM5OwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.targeted_invites_enabled',true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',168);
    }

    private function makePerson(string $label): array
    {
        $user=OrbitUser::create(['display_name'=>$label,'username'=>$label.'_'.Str::random(6),'status'=>'active']);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'Device','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    public function test_transfer_is_disabled_unless_approved_and_keeps_one_owner(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta]=$this->makePerson('alpha');
        [$b,$tb]=$this->makePerson('bravo');
        $friend=$this->withToken($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$friend.'/accept')->assertOk();
        $id=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Group'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertOk();

        $url='/api/v1/circles/'.$id.'/ownership-transfer';
        $payload=['recipient_id'=>$b->id,'confirmation'=>'TRANSFER'];
        $this->withToken($ta)->postJson($url,$payload)->assertNotFound();
        config()->set('orbit_circle.ownership_transfer_enabled',true);
        $this->withToken($tb)->postJson($url,['recipient_id'=>$a->id,
            'confirmation'=>'TRANSFER'])->assertNotFound();
        $this->withToken($ta)->postJson($url,$payload)->assertOk()->assertJsonPath('data.owner_id',$b->id);
        $this->assertDatabaseHas('circles',['id'=>$id,'owner_id'=>$b->id]);
        $this->assertDatabaseHas('circle_members',['circle_id'=>$id,'user_id'=>$a->id,'role'=>'admin']);
        $this->assertDatabaseHas('circle_members',['circle_id'=>$id,'user_id'=>$b->id,'role'=>'owner']);
        $this->assertSame(1,DB::table('circle_members')->where('circle_id',$id)
            ->where('role','owner')->whereNull('removed_at')->count());
    }
}
