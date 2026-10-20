<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5SocialInvalidationTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.targeted_invites_enabled',true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',168);
    }
    private function person(string $label): array
    {
        $u=OrbitUser::create(['display_name'=>$label,
            'username'=>$label.'_'.Str::lower(Str::random(8)),'status'=>'active']);
        $d=OrbitDevice::create([
            'user_id'=>$u->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'test','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$u,app(TokenService::class)->issue($u,$d,null)['access_token']];
    }
    public function test_block_revokes_pending_invite_even_before_join(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $r=$this->withToken($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$r.'/accept')->assertOk();
        $id=$this->withToken($ta)->postJson('/api/v1/circles',
            ['name'=>'Private'])->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        $this->withToken($ta)->postJson('/api/v1/me/blocks',
            ['user_id'=>$b->id])->assertCreated();
        $this->assertDatabaseHas('circle_invites',
            ['id'=>$invite['id'],'status'=>'revoked']);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite['id'].'/accept')
            ->assertStatus(409);
        $this->withToken($ta)->deleteJson('/api/v1/me/blocks/'.$b->id)->assertOk();
        $this->assertDatabaseCount('circle_members',1);
    }

    public function test_block_and_unblock_change_circle_revision_but_never_grant_sharing(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $f=$this->withToken($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$f.'/accept')->assertOk();
        $circle=$this->withToken($ta)->postJson('/api/v1/circles',
            ['name'=>'Private'])->assertCreated()->json('data.id');
        $i=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$i.'/accept')->assertOk();
        $base=DB::table('circles')->where('id',$circle)->value('revision');
        $this->withToken($ta)->postJson('/api/v1/me/blocks',
            ['user_id'=>$b->id])->assertCreated();
        $blocked=DB::table('circles')->where('id',$circle)->value('revision');
        $this->assertGreaterThan($base,$blocked);
        $this->withToken($tb)->getJson('/api/v1/circles/'.$circle)->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle.'/members')
            ->assertJsonCount(1,'data.items');
        $this->withToken($ta)->deleteJson('/api/v1/me/blocks/'.$b->id)->assertOk();
        $this->assertGreaterThan($blocked,
            DB::table('circles')->where('id',$circle)->value('revision'));
        $this->withToken($tb)->getJson('/api/v1/circles/'.$circle)
            ->assertOk()->assertJsonPath('data.sharing_enabled',false);
        $this->assertDatabaseHas('circle_event_outbox',
            ['circle_id'=>$circle,'event_type'=>'circle.relationship_blocked']);
    }
}
