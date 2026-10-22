<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5RoleMatrixTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.targeted_invites_enabled',true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',168);
        config()->set('orbit_circle.role_changes_enabled',true);
        config()->set('orbit_circle.join_codes_enabled',true);
    }
    private function person(string $label): array
    {
        $u=OrbitUser::create(['display_name'=>$label,
            'username'=>$label.'_'.Str::random(7),'status'=>'active']);
        $d=OrbitDevice::create([
            'user_id'=>$u->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'test','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$u,app(TokenService::class)->issue($u,$d,null)['access_token']];
    }
    private function friend(string $ta,string $tb,string $id): void
    {
        $r=$this->withToken($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$r.'/accept')->assertOk();
    }

    public function test_admin_only_manages_invites_while_owner_keeps_control(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        [$c,$tc]=$this->person('charlie');
        $this->friend($ta,$tb,$b->id);
        $this->friend($tb,$tc,$c->id);
        $id=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Private'])
            ->assertCreated()->json('data.id');
        $inv=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$inv.'/accept')->assertOk();
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/members/'.$b->id,
            ['role'=>'admin'])->assertOk();
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id)
            ->assertJsonPath('data.capabilities.invite',true)
            ->assertJsonPath('data.capabilities.edit',false)
            ->assertJsonPath('data.capabilities.remove_members',false);
        $this->withToken($tb)->patchJson('/api/v1/circles/'.$id,
            ['name'=>'Unauthorized'])->assertNotFound();
        $this->withToken($tb)->deleteJson('/api/v1/circles/'.$id.'/members/'.$a->id)
            ->assertNotFound();
        $invite=$this->withToken($tb)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$c->id])->assertCreated()->json('data');
        $asAdmin=$this->withToken($tb)->getJson('/api/v1/circles/'.$id.'/invites')
            ->assertOk()->json('data.items');
        $this->assertTrue(collect($asAdmin)->firstWhere('id',$invite['id'])['can_revoke']);
        $this->assertFalse(collect($asAdmin)->firstWhere('id',$inv)['can_revoke']);
        $asOwner=$this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/invites')
            ->assertOk()->json('data.items');
        $this->assertTrue(collect($asOwner)->firstWhere('id',$invite['id'])['can_revoke']);
        $this->withToken($tb)->deleteJson('/api/v1/circles/'.$id.'/invites/'.$invite['id'])
            ->assertOk();
        $this->withToken($tc)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$invite['code'],'confirmation'=>'JOIN'])->assertNotFound();
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/members/'.$b->id,
            ['role'=>'restricted'])->assertOk();
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id)
            ->assertJsonPath('data.capabilities.invite',false);
        $this->withToken($tb)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$c->id])->assertNotFound();
        $this->assertDatabaseCount('circles',1);
    }

    public function test_expired_invite_can_be_reissued_without_duplicate_live_pair(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $this->friend($ta,$tb,$b->id);
        $id=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Family'])
            ->assertCreated()->json('data.id');
        $first=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        DB::table('circle_invites')->where('id',$first['id'])
            ->update(['expires_at'=>now()->subMinute()]);
        // Pending rows beyond TTL must display Expired immediately even
        // before housekeeping or a second invitation changes database status.
        $history=$this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/invites')
            ->assertOk()->json('data.items');
        $this->assertSame('expired',collect($history)->firstWhere('id',$first['id'])['status']);
        $this->assertFalse(collect($history)->firstWhere('id',$first['id'])['can_revoke']);
        $second=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        $this->assertNotEquals($first['code'],$second['code']);
        $this->assertDatabaseHas('circle_invites',['id'=>$first['id'],'status'=>'expired']);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$first['code'],'confirmation'=>'JOIN'])->assertNotFound();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$second['code'],'confirmation'=>'JOIN'])->assertOk();
        $this->assertDatabaseCount('circle_members',2);
    }
}
