<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5CodesAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.targeted_invites_enabled',true);
        config()->set('orbit_circle.join_codes_enabled',true);
        config()->set('orbit_circle.archive_enabled',true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',168);
    }

    private function identity(string $label): array
    {
        $person=OrbitUser::create(['display_name'=>ucfirst($label),
            'username'=>$label.'_'.Str::lower(Str::random(8)),'status'=>'active']);
        $device=OrbitDevice::create([
            'user_id'=>$person->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'M5 test','platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$person,app(TokenService::class)->issue($person,$device,null)['access_token']];
    }

    private function friendship(string $aToken, string $bToken, string $bId): void
    {
        $id=$this->withToken($aToken)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$bId])->assertCreated()->json('data.id');
        $this->withToken($bToken)->postJson('/api/v1/friends/requests/'.$id.'/accept')->assertOk();
    }

    public function test_code_is_recipient_bound_not_auto_join_and_one_use(): void
    {
        [$a,$ta]=$this->identity('alpha');
        [$b,$tb]=$this->identity('bravo');
        [$c,$tc]=$this->identity('charlie');
        $this->friendship($ta,$tb,$b->id);
        $circle=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Trusted Group'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        $code=$invite['code'];
        $this->assertSame(40,strlen($code));
        $this->assertDatabaseMissing('circle_invites',['id'=>$invite['id'],'code_hash'=>$code]);
        $this->withToken($tc)->postJson('/api/v1/circles/invites/code/preview',
            ['code'=>$code])->assertNotFound();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/preview',
            ['code'=>$code])->assertOk()->assertJsonPath('data.circle_name','Trusted Group');
        $this->withToken($tb)->getJson('/api/v1/circles')->assertJsonCount(0,'data.items');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$code,'confirmation'=>'JOIN'])->assertOk()
            ->assertJsonPath('data.status','accepted');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$code,'confirmation'=>'JOIN'])->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/circles')
            ->assertJsonPath('data.items.0.id',$circle);
        $this->assertDatabaseCount('circle_members',2);
    }

    public function test_expired_codes_and_blocking_deny_preview_and_redeem(): void
    {
        [$a,$ta]=$this->identity('alpha');
        [$b,$tb]=$this->identity('bravo');
        $this->friendship($ta,$tb,$b->id);
        $circle=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Group'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        DB::table('circle_invites')->where('id',$invite['id'])->update(['expires_at'=>now()->subSecond()]);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/preview',
            ['code'=>$invite['code']])->assertNotFound();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$invite['code'],'confirmation'=>'JOIN'])->assertNotFound();
    }

    public function test_archive_is_owner_only_reversible_and_revokes_pending_invites(): void
    {
        [$a,$ta]=$this->identity('alpha');
        [$b,$tb]=$this->identity('bravo');
        $this->friendship($ta,$tb,$b->id);
        $id=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Family'])
            ->assertCreated()->json('data.id');
        $invite=$this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data');
        $this->withToken($tb)->postJson('/api/v1/circles/'.$id.'/archive',
            ['confirmation'=>'ARCHIVE'])->assertNotFound();
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/archive',
            ['confirmation'=>'ARCHIVE'])->assertOk()->assertJsonPath('data.archived',true);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id)->assertNotFound();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$invite['code'],'confirmation'=>'JOIN'])->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/circles/archived')
            ->assertJsonPath('data.items.0.id',$id);
        $this->assertDatabaseHas('circle_invites',['id'=>$invite['id'],'status'=>'revoked']);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/restore',
            ['confirmation'=>'RESTORE'])->assertOk()->assertJsonPath('data.sharing_enabled',false);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id)->assertOk();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/redeem',
            ['code'=>$invite['code'],'confirmation'=>'JOIN'])->assertNotFound();
        $this->assertDatabaseCount('circles',1);
    }

    public function test_temporary_circle_stays_read_only_for_72_hours_then_archives_without_deletion(): void
    {
        config()->set('orbit_circle.temporary_circles_enabled',true);
        [$owner,$token]=$this->identity('alpha');
        $expires=now()->addMinutes(5);
        $id=$this->withToken($token)->postJson('/api/v1/circles',[
            'name'=>'Temporary Circle',
            'expires_at'=>$expires->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.lifecycle_state','active')->json('data.id');
        $this->assertDatabaseHas('circles',['id'=>$id,'owner_id'=>$owner->id]);
        $this->travel(6)->minutes();
        $this->withToken($token)->getJson('/api/v1/circles/'.$id)
            ->assertOk()->assertJsonPath('data.lifecycle_state','read_only')
            ->assertJsonPath('data.capabilities.edit',false)
            ->assertJsonPath('data.capabilities.invite',false);
        $this->withToken($token)->patchJson('/api/v1/circles/'.$id,[
            'name'=>'Unsafe Rename',
        ])->assertStatus(409);
        $this->withToken($token)->getJson('/api/v1/circles')
            ->assertOk()->assertJsonPath('data.items.0.id',$id);

        $this->travel(73)->hours();
        $this->withToken($token)->getJson('/api/v1/circles/'.$id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/circles')
            ->assertOk()->assertJsonCount(0,'data.items');
        $archived=app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class)
            ->archiveDue();
        $this->assertSame(1,$archived);
        $this->assertSame(0,app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class)->archiveDue());
        $this->assertDatabaseCount('circles',1);
        $this->assertDatabaseHas('circles',['id'=>$id]);
        $this->withToken($token)->getJson('/api/v1/circles/archived')
            ->assertOk()->assertJsonPath('data.items.0.id',$id);
        $this->withToken($token)->postJson('/api/v1/circles/'.$id.'/restore',[
            'confirmation'=>'RESTORE',
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/circles/'.$id)
            ->assertOk()->assertJsonPath('data.lifecycle_state','active');
        $this->assertNull(\Illuminate\Support\Facades\DB::table('circles')
            ->where('id',$id)->value('expires_at'));
    }

    public function test_temporary_expiry_requires_separate_feature_gate(): void
    {
        config()->set('orbit_circle.temporary_circles_enabled',false);
        [$owner,$token]=$this->identity('alpha');
        $this->withToken($token)->postJson('/api/v1/circles',[
            'name'=>'Unavailable Temporary',
            'expires_at'=>now()->addDay()->toIso8601String(),
        ])->assertNotFound();
        $this->assertDatabaseCount('circles',0);
    }

    public function test_unapproved_code_or_archive_features_remain_inaccessible(): void
    {
        [$a,$ta]=$this->identity('alpha');
        [$b,$tb]=$this->identity('bravo');
        $this->friendship($ta,$tb,$b->id);
        $id=$this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Group'])
            ->assertCreated()->json('data.id');
        config()->set('orbit_circle.join_codes_enabled',false);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->assertJsonPath('data.code',null);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/code/preview',
            ['code'=>str_repeat('A',40)])->assertNotFound();
        config()->set('orbit_circle.targeted_invites_enabled',false);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id)
            ->assertOk()->assertJsonPath('data.capabilities.invite',false);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertNotFound();
        config()->set('orbit_circle.archive_enabled',false);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/archive',
            ['confirmation'=>'ARCHIVE'])->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/circles/archived')->assertNotFound();
    }
}
