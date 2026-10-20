<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5RosterPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.targeted_invites_enabled',true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',48);
    }

    private function person(string $name): array
    {
        $user=OrbitUser::create(['display_name'=>$name,'username'=>$name.'_'.Str::random(7),'status'=>'active']);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'M5 QA','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    public function test_invite_list_and_member_count_hide_blocked_identity(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $f=$this->withToken($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$f.'/accept')->assertOk();
        $circle=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Private circle'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle.'/invites')
            ->assertOk()->assertJsonPath('data.items.0.person.id',$b->id)
            ->assertJsonMissingPath('data.items.0.person.email');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertOk();
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle)
            ->assertJsonPath('data.member_count',2);
        $this->withToken($ta)->postJson('/api/v1/me/blocks',['user_id'=>$b->id])->assertCreated();
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle)
            ->assertJsonPath('data.member_count',1);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle.'/members')
            ->assertJsonCount(1,'data.items');
        $this->withToken($ta)->getJson('/api/v1/circles/'.$circle.'/invites')
            ->assertJsonCount(0,'data.items');
        $this->withToken($tb)->getJson('/api/v1/circles/'.$circle)->assertNotFound();
    }

    public function test_expired_invite_never_creates_membership(): void
    {
        [$a,$ta]=$this->person('alpha');
        [$b,$tb]=$this->person('bravo');
        $f=$this->withToken($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])
            ->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/friends/requests/'.$f.'/accept')->assertOk();
        $circle=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Private circle'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        \Illuminate\Support\Facades\DB::table('circle_invites')->where('id',$invite)
            ->update(['expires_at'=>now()->subMinute()]);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')
            ->assertStatus(409);
        $this->withToken($tb)->getJson('/api/v1/circles/invites/incoming')
            ->assertJsonCount(0,'data.items');
        $this->assertDatabaseMissing('circle_members',['circle_id'=>$circle,'user_id'=>$b->id]);
    }
}
