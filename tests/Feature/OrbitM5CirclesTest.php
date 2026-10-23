<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Config\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM5CirclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_circle.enabled', true);
        config()->set('orbit_circle.targeted_invites_enabled', true);
        config()->set('orbit_circle.targeted_invite_lifetime_hours',168);
        config()->set('orbit_circle.role_changes_enabled', true);
    }

    public function test_unapproved_rollout_gate_rejects_public_usage_and_admin_access(): void
    {
        [$a,$ta] = $this->person('alpha');
        config()->set('orbit_circle.enabled', false);
        $this->withToken($ta)->getJson('/api/v1/circles')->assertNotFound();
        $this->withToken($ta)->postJson('/api/v1/circles',['name'=>'Private'])->assertNotFound();
        $this->assertDatabaseCount('circles',0);
        $admin = $this->admin(['circles.read']);
        $this->be($admin,'admin');
        $this->get('/admin/circles')->assertNotFound();
    }

    private function person(string $name): array
    {
        $user = OrbitUser::create([
            'username'=>$name.'_'.Str::lower(Str::random(8)),
            'display_name'=>ucfirst($name),'status'=>'active',
        ]);
        $device = OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string) Str::uuid(),
            'name'=>'M5 device','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $token = app(TokenService::class)->issue($user,$device,null)['access_token'];
        return [$user,$token,$device];
    }

    private function createCircle(string $token, string $name = 'Trusted family'): string
    {
        return (string) $this->withToken($token)
            ->postJson('/api/v1/circles',['name'=>$name])
            ->assertCreated()->assertJsonPath('data.sharing_enabled',false)->json('data.id');
    }

    private function beFriends(string $a, string $b, string $bId): void
    {
        $request = $this->withToken($a)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$bId])->assertCreated()->json('data.id');
        $this->withToken($b)->postJson('/api/v1/friends/requests/'.$request.'/accept')->assertOk();
    }

    public function test_trusted_auth_required_and_untrusted_denied(): void
    {
        [$a,$ta,$device] = $this->person('alpha');
        $this->getJson('/api/v1/circles')->assertUnauthorized();
        $this->withToken($ta)->getJson('/api/v1/circles')->assertOk()->assertJsonCount(0,'data.items');
        $device->update(['trust_state'=>'pending']);
        $this->withToken($ta)->postJson('/api/v1/circles',['name'=>'A'])->assertForbidden();
    }

    public function test_create_list_owner_and_no_default_sharing(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $id = $this->createCircle($ta);
        $this->assertDatabaseHas('circle_members',['circle_id'=>$id,'user_id'=>$a->id,'role'=>'owner']);
        $this->withToken($ta)->getJson('/api/v1/circles')
            ->assertOk()->assertJsonPath('data.items.0.id',$id);
        $this->withToken($tb)->getJson('/api/v1/circles')->assertJsonCount(0,'data.items');
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id)->assertNotFound();
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/permissions')
            ->assertOk()->assertJsonPath('data.sharing_active',false);
        $this->assertDatabaseCount('circle_permissions',0);
    }

    public function test_name_validation_and_multiple_private_circles(): void
    {
        [$a,$ta] = $this->person('alpha');
        $this->withToken($ta)->postJson('/api/v1/circles',['name'=>' '])->assertUnprocessable();
        $this->withToken($ta)->postJson('/api/v1/circles',['name'=>str_repeat('a',81)])->assertUnprocessable();
        $this->createCircle($ta,'Friends');
        $this->createCircle($ta,'Family');
        $this->withToken($ta)->getJson('/api/v1/circles')->assertJsonCount(2,'data.items');
        $this->assertDatabaseCount('circle_members',2);
    }

    public function test_idempotency_key_replays_same_circle_not_another(): void
    {
        [$a,$ta] = $this->person('alpha');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $id = $this->withToken($ta)->withHeader('Idempotency-Key','orbit-m5-onboarding-001')
            ->postJson('/api/v1/circles',['name'=>'Trusted family'])->assertCreated()->json('data.id');
        $replay = $this->withToken($ta)->withHeader('Idempotency-Key','orbit-m5-onboarding-001')
            ->postJson('/api/v1/circles',['name'=>'Trusted family'])->assertCreated()->json('data.id');
        $this->assertSame($id,$replay);
        $this->withToken($ta)->withHeader('Idempotency-Key','orbit-m5-onboarding-001')
            ->postJson('/api/v1/circles',['name'=>'Different'])->assertStatus(409);
        $this->assertDatabaseCount('circles',1);
    }

    public function test_circle_access_policy_cannot_grant_protected_data_or_subscriptions(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $id = $this->createCircle($ta);
        $acl = app(\App\Modules\Circle\Services\CircleAccessPolicy::class);
        $this->assertTrue($acl->isMember($id,$a->id));
        $this->assertFalse($acl->isMember($id,$b->id));
        $this->assertFalse($acl->canAccessProtected($id,$a->id,$b->id,'location'));
        $this->assertFalse($acl->canSubscribe($id,$a->id));
        $this->beFriends($ta,$tb,$b->id);
        $invite = $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertOk();
        $this->assertTrue($acl->isMember($id,$b->id));
        $this->assertFalse($acl->canAccessProtected($id,$a->id,$b->id,'location'));
        $this->assertFalse($acl->canAccessProtected($id,$a->id,$b->id,'message'));
    }

    public function test_owner_can_rename_but_nonmember_cannot_read_or_edit(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $id = $this->createCircle($ta);
        $this->withToken($tb)->patchJson('/api/v1/circles/'.$id,['name'=>'Leaked'])->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id.'/members')->assertNotFound();
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id,['name'=>'New name'])
            ->assertOk()->assertJsonPath('data.name','New name');
        $this->assertDatabaseHas('circles',['id'=>$id,'name'=>'New name']);
    }

    public function test_invite_requires_friend_and_acceptance_is_explicit(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $id = $this->createCircle($ta);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertNotFound();
        $this->beFriends($ta,$tb,$b->id);
        $invite = $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertStatus(409);
        $this->withToken($tb)->getJson('/api/v1/circles')->assertJsonCount(0,'data.items');
        $this->withToken($tb)->getJson('/api/v1/circles/invites/incoming')
            ->assertOk()->assertJsonPath('data.items.0.id',$invite);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')
            ->assertOk()->assertJsonPath('data.status','accepted');
        $this->withToken($tb)->getJson('/api/v1/circles')
            ->assertOk()->assertJsonPath('data.items.0.id',$id);
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertStatus(409);
        $this->assertDatabaseCount('circle_members',2);
    }

    public function test_recipient_only_can_accept_and_inviter_can_revoke(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        [$c,$tc] = $this->person('charlie');
        $this->beFriends($ta,$tb,$b->id);
        $id = $this->createCircle($ta);
        $invite = $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tc)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertNotFound();
        $this->withToken($ta)->deleteJson('/api/v1/circles/'.$id.'/invites/'.$invite)->assertOk();
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertStatus(409);
        $this->assertDatabaseCount('circle_members',1);
    }

    public function test_block_prevents_invitation_and_exposes_no_private_circle(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $this->beFriends($ta,$tb,$b->id);
        $id = $this->createCircle($ta);
        $this->withToken($tb)->postJson('/api/v1/me/blocks',['user_id'=>$a->id])->assertCreated();
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',['invitee_id'=>$b->id])->assertNotFound();
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id)->assertNotFound();
    }

    public function test_owner_cannot_leave_and_member_cannot_become_owner(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $id = $this->createCircle($ta);
        $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/leave')->assertStatus(409);
        $this->assertDatabaseHas('circle_members',['circle_id'=>$id,'user_id'=>$a->id,'role'=>'owner']);
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/members/'.$b->id,['role'=>'owner'])
            ->assertUnprocessable();
    }

    public function test_only_owner_can_change_roles_and_member_can_leave(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $this->beFriends($ta,$tb,$b->id);
        $id = $this->createCircle($ta);
        $inv = $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$inv.'/accept')->assertOk();
        $this->withToken($tb)->patchJson('/api/v1/circles/'.$id,['name'=>'Escalation'])->assertNotFound();
        $this->withToken($tb)->patchJson('/api/v1/circles/'.$id.'/members/'.$a->id,
            ['role'=>'member'])->assertNotFound();
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/members/'.$b->id,
            ['role'=>'restricted'])->assertOk();
        $this->withToken($tb)->postJson('/api/v1/circles/'.$id.'/leave')->assertOk();
        $this->withToken($tb)->getJson('/api/v1/circles/'.$id)->assertNotFound();
        $this->assertDatabaseHas('circle_members',['circle_id'=>$id,'user_id'=>$b->id,'role'=>'restricted']);
    }

    public function test_permission_grant_is_denied_until_approved_but_revocation_saves(): void
    {
        [$a,$ta] = $this->person('alpha');
        $id = $this->createCircle($ta);
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/permissions',
            ['permission_key'=>'location','enabled'=>true])->assertUnprocessable();
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$id.'/permissions',
            ['permission_key'=>'location','enabled'=>false])->assertOk()->assertJsonPath('data.enabled',false);
        $this->assertDatabaseHas('circle_permissions',['circle_id'=>$id,'enabled'=>0]);
        $this->withToken($ta)->getJson('/api/v1/circles/'.$id.'/permissions')
            ->assertJsonPath('data.sharing_active',false);
    }

    public function test_future_consent_resolver_is_scope_bound_and_cannot_enable_unfinished_consumers(): void
    {
        [$a,$ta] = $this->person('alpha');
        $one=$this->createCircle($ta);
        $two=$this->createCircle($ta);
        $record=[
            'circle_id'=>$one,'scope_key'=>'user:'.$a->id,
            'subject_user_id'=>$a->id,'permission_key'=>'location',
            'enabled'=>true,
            'value_json'=>json_encode(['reviewed'=>true,'precision'=>'approximate']),
            'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now(),
        ];
        DB::table('circle_permissions')->insert($record);
        $consent=app(\App\Modules\Circle\Services\CircleConsentPolicy::class);
        $savedOne=$consent->preview($one,$a->id);
        $savedTwo=$consent->preview($two,$a->id);
        $this->assertFalse($savedOne['sharing_active']);
        $this->assertFalse($savedOne['grants_enabled']);
        $this->assertCount(1,$savedOne['saved']);
        $this->assertCount(0,$savedTwo['saved']);
        $this->assertFalse($savedOne['saved'][0]['enabled']);
        $this->assertTrue($savedOne['saved'][0]['requested']);
        $acl=app(\App\Modules\Circle\Services\CircleAccessPolicy::class);
        $this->assertFalse($acl->canSubscribe($one,$a->id));
        $this->assertFalse($acl->canAccessProtected($one,$a->id,$a->id,'location'));
        $this->withToken($ta)->patchJson('/api/v1/circles/'.$one.'/permissions',[
            'permission_key'=>'location','enabled'=>false,
        ])->assertOk()->assertJsonPath('data.enabled',false);
        $this->assertFalse((bool)DB::table('circle_permissions')
            ->where('circle_id',$one)->where('subject_user_id',$a->id)->value('enabled'));
        $this->assertNull(DB::table('circle_permissions')
            ->where('circle_id',$one)->where('subject_user_id',$a->id)->value('value_json'));
    }

    public function test_member_account_removal_invalidates_surviving_circle_revision(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $this->beFriends($ta,$tb,$b->id);
        $id = $this->createCircle($ta);
        $invite = $this->withToken($ta)->postJson('/api/v1/circles/'.$id.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        $this->withToken($tb)->postJson('/api/v1/circles/invites/'.$invite.'/accept')->assertOk();
        $old = DB::table('circles')->where('id',$id)->value('revision');
        $admin = $this->admin(['users.delete','users.read']);
        app(\App\Modules\Identity\Services\DeleteOrbitUser::class)->execute(
            $b->id,$admin,'Approved deletion of a non-owning Circle member',null,null,
        );
        $this->assertDatabaseHas('orbit_users',['id'=>$a->id]);
        $this->assertDatabaseMissing('circle_members',['circle_id'=>$id,'user_id'=>$b->id]);
        $this->assertGreaterThan($old,DB::table('circles')->where('id',$id)->value('revision'));
        $this->assertDatabaseHas('circle_event_outbox',[
            'circle_id'=>$id,'event_type'=>'circle.member_account_removed',
        ]);
    }

    public function test_account_deletion_does_not_cascade_away_shared_circle(): void
    {
        [$a,$ta] = $this->person('alpha');
        $id = $this->createCircle($ta);
        $admin = $this->admin(['users.delete','users.read']);
        $this->expectException(\DomainException::class);
        app(\App\Modules\Identity\Services\DeleteOrbitUser::class)->execute(
            $a->id,$admin,'M5 owner deletion must be blocked',null,null,
        );
    }

    private function admin(array $permissions): AdminUser
    {
        $role = AdminRole::create(['name'=>'M5 role '.Str::uuid(),'permissions'=>$permissions]);
        $admin = AdminUser::create([
            'name'=>'M5 Operator','email'=>Str::uuid().'@example.test',
            'password'=>'Secure-Password!123',
            'totp_secret'=>(new Totp())->generateSecret(),
            'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_admin_pending_invites_excludes_expired_credentials(): void
    {
        [$a,$ta] = $this->person('alpha');
        [$b,$tb] = $this->person('bravo');
        $this->beFriends($ta,$tb,$b->id);
        $circle=$this->createCircle($ta);
        $inv=$this->withToken($ta)->postJson('/api/v1/circles/'.$circle.'/invites',
            ['invitee_id'=>$b->id])->assertCreated()->json('data.id');
        DB::table('circle_invites')->where('id',$inv)->update([
            'expires_at'=>now()->subSecond(),
        ]);
        $admin=$this->admin(['circles.read']);
        $this->be($admin,'admin');
        $this->get('/admin/circles?circle_id='.$circle)->assertOk()
            ->assertViewHas('metrics',fn ($v) => $v['pending_invites'] === 0)
            ->assertViewHas('summary',fn ($v) => $v['pending'] === 0);
        $this->assertDatabaseHas('circle_invites',['id'=>$inv,'status'=>'pending']);
    }

    public function test_admin_circle_counters_are_read_only_and_permission_gated(): void
    {
        [$a,$ta] = $this->person('alpha');
        $this->createCircle($ta);
        $allowed = $this->admin(['circles.read']);
        $this->be($allowed,'admin');
        $this->get('/admin/circles')->assertOk()->assertSee('Circle Management')
            ->assertDontSee('Trusted family');
        $denied = $this->admin(['social.read']);
        $this->be($denied,'admin');
        $this->get('/admin/circles')->assertForbidden();
    }
}
