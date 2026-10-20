<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Social\Services\SocialGraphService;
use App\Modules\Social\Services\SocialRelationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM4SocialGraphTest extends TestCase
{
    use RefreshDatabase;

    private function identity(string $prefix = 'member'): array
    {
        $user = OrbitUser::create([
            'display_name' => 'Private '.ucfirst($prefix),
            'username' => $prefix.'_'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id, 'device_public_id' => (string) Str::uuid(),
            'name' => 'M4 test phone', 'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)), 'trust_state' => 'trusted',
            'attestation_state' => 'verified',
            'approved_at' => now(),
        ]);
        $token = app(TokenService::class)->issue($user, $device, null)['access_token'];
        return [$user, $token, $device];
    }

    private function auth(string $token): static
    {
        return $this->withToken($token);
    }

    private function admin(array $permissions): AdminUser
    {
        $role = AdminRole::create(['name' => 'M4 role '.Str::uuid(),
            'permissions' => $permissions]);
        $user = AdminUser::create([
            'name' => 'Support Reviewer', 'email' => Str::uuid().'@test.example',
            'password' => 'Secure123!password',
            'totp_secret' => (new Totp())->generateSecret(), 'status' => 'active',
        ]);
        $user->roles()->attach($role->id);
        return $user;
    }

    public function test_unauthenticated_or_untrusted_devices_cannot_use_social_endpoints(): void
    {
        [$a,$token,$device] = $this->identity('security');
        $this->getJson('/api/v1/friends')->assertUnauthorized();
        $this->auth($token)->getJson('/api/v1/friends')->assertOk();
        $device->update(['trust_state' => 'pending']);
        $this->auth($token)->getJson('/api/v1/friends')->assertForbidden();
        $this->auth($token)->postJson('/api/v1/me/blocks', ['user_id'=>(string) Str::uuid()])
            ->assertForbidden();
    }

    public function test_exact_username_discovery_exposes_only_safe_fields_and_no_circles(): void
    {
        [$a,$ta] = $this->identity('discovera');
        [$b] = $this->identity('discoverb');
        $r = $this->auth($ta)->getJson('/api/v1/people/search?username='.$b->username);
        $r->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $b->id)
            ->assertJsonMissingPath('data.items.0.bio')
            ->assertJsonMissingPath('data.items.0.email')
            ->assertJsonMissingPath('data.items.0.location');
        $this->auth($ta)->getJson('/api/v1/people/search?username=discover')->assertJsonCount(1,'data.items');
        $this->auth($ta)->getJson('/api/v1/people/'.$b->id.'/preview')->assertOk()
            ->assertJsonMissingPath('data.circle_memberships');
    }

    public function test_request_accept_and_unfriend_persist_canonical_pair_and_version(): void
    {
        [$a,$ta] = $this->identity('send');
        [$b,$tb] = $this->identity('receive');
        $id = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()
            ->assertJsonPath('data.status','pending')->json('data.id');
        $this->auth($tb)->getJson('/api/v1/friends/requests?direction=incoming')
            ->assertOk()->assertJsonCount(1, 'data.items');
        $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertStatus(409);
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$id.'/accept')->assertOk()
            ->assertJsonPath('data.status','accepted');
        $this->auth($ta)->getJson('/api/v1/friends')
            ->assertOk()->assertJsonPath('data.items.0.id',$b->id);
        $this->auth($tb)->getJson('/api/v1/friends')
            ->assertOk()->assertJsonPath('data.items.0.id',$a->id);
        $this->assertDatabaseCount('friendships',1);
        $this->assertTrue(app(SocialGraphService::class)->isFriend($a->id,$b->id));
        $this->auth($ta)->deleteJson('/api/v1/friends/'.$b->id)->assertOk();
        $this->assertDatabaseCount('friendships',0);
        $this->assertGreaterThan(0,app(SocialGraphService::class)->currentRevision($a->id));
    }

    public function test_only_recipient_can_accept_and_cross_request_is_not_auto_friend(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        [$c,$tc] = $this->identity('c');
        $id = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($ta)->postJson('/api/v1/friends/requests/'.$id.'/accept')->assertNotFound();
        $this->auth($tc)->postJson('/api/v1/friends/requests/'.$id.'/decline')->assertNotFound();
        $this->auth($tb)->postJson('/api/v1/friends/requests',['recipient_id'=>$a->id])->assertStatus(409);
        $this->assertDatabaseCount('friendships',0);
        $this->assertDatabaseCount('friend_requests',1);
    }

    public function test_reject_frees_pair_and_never_creates_friendship(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $id = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$id.'/decline')
            ->assertOk()->assertJsonPath('data.status','declined');
        $this->assertDatabaseMissing('friend_requests', ['id'=>$id,'status'=>'pending']);
        $this->assertDatabaseCount('friendships',0);
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$id.'/accept')->assertStatus(409);
    }

    public function test_block_is_directional_idempotent_private_and_does_not_restore_friendship(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $requestId = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$requestId.'/accept')->assertOk();
        $this->auth($ta)->postJson('/api/v1/me/blocks',
            ['user_id'=>$b->id,'reason_code'=>'privacy'])->assertCreated();
        $this->auth($ta)->postJson('/api/v1/me/blocks',['user_id'=>$b->id])->assertCreated();
        $this->assertDatabaseCount('blocks',1);
        $this->assertDatabaseCount('friendships',0);
        $this->auth($tb)->getJson('/api/v1/people/'.$a->id.'/preview')->assertNotFound();
        $this->auth($tb)->getJson('/api/v1/people/search?username='.$a->username)
            ->assertOk()->assertJsonCount(0,'data.items');
        $this->auth($tb)->postJson('/api/v1/friends/requests',['recipient_id'=>$a->id])->assertNotFound();
        $this->auth($ta)->getJson('/api/v1/me/blocks')->assertOk()->assertJsonCount(1,'data.items');
        $this->auth($ta)->deleteJson('/api/v1/me/blocks/'.$b->id)
            ->assertOk()->assertJsonPath('data.blocked',false);
        $this->assertDatabaseCount('friendships',0);
        $this->assertFalse(app(SocialGraphService::class)->isFriend($a->id,$b->id));
    }

    public function test_self_invitation_and_block_never_write_a_social_row(): void
    {
        [$a,$ta] = $this->identity('a');
        $this->auth($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$a->id])
            ->assertStatus(422);
        $this->auth($ta)->postJson('/api/v1/me/blocks',['user_id'=>$a->id])
            ->assertStatus(422);
        $this->assertDatabaseCount('friend_requests',0);
        $this->assertDatabaseCount('blocks',0);
    }

    public function test_deletion_state_disallows_social_mutations(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b] = $this->identity('b');
        $a->update(['deletion_requested_at' => now()]);
        $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertStatus(409);
        $this->auth($ta)->getJson('/api/v1/friends')->assertStatus(409);
    }

    public function test_admin_social_operations_are_read_only_and_permission_gated(): void
    {
        $admin = $this->admin(['social.read']);
        $this->be($admin, 'admin');
        $this->get('/admin/social-graph')->assertOk()
            ->assertSee('Friends &amp; blocking', false)->assertDontSee('private messages');
        $this->get('/admin/social-graph?user_id=bad')->assertSessionHasErrors();
        $restricted = $this->admin(['users.read']);
        $this->be($restricted, 'admin');
        $this->get('/admin/social-graph')->assertForbidden();
    }

    public function test_social_user_graph_is_removed_by_admin_delete_and_other_users_survive(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $this->auth($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])->assertCreated();
        $this->auth($tb)->postJson('/api/v1/me/blocks',['user_id'=>$a->id])->assertCreated();
        $admin = $this->admin(['users.read','users.delete']);
        $priorRevision = app(SocialGraphService::class)->currentRevision($b->id);
        $this->be($admin, 'admin');
        $this->delete('/admin/users/'.$a->id, [
            'confirmation'=>'DELETE', 'reason'=>'Approved test graph cleanup',
        ])->assertRedirect();
        $this->assertDatabaseMissing('orbit_users',['id'=>$a->id]);
        $this->assertDatabaseHas('orbit_users',['id'=>$b->id]);
        $this->assertDatabaseCount('blocks',0);
        $this->assertDatabaseCount('friend_requests',0);
        $this->assertDatabaseCount('friendships',0);
        $this->assertGreaterThan($priorRevision,
            app(SocialGraphService::class)->currentRevision($b->id));
    }

    public function test_m3_privacy_defaults_block_friends_from_protected_data_until_opt_in(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $policy = app(SocialRelationPolicy::class);
        $this->assertFalse($policy->canInteract($a->id,$b->id,'message'));
        $this->assertFalse($policy->canInteract($a->id,$b->id,'location'));
        $id = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$id.'/accept')->assertOk();
        $this->assertFalse($policy->canInteract($a->id,$b->id,'message'),
            'Friendship must never enable messaging by itself.');
        DB::table('orbit_user_preferences')->insert([
            'user_id'=>$b->id,
            'privacy'=>json_encode([
                'message_eligibility'=>'Friends', 'ping_eligibility'=>'Friends',
                'ghost_mode_enabled'=>true, 'location_visibility'=>'Friends',
                'location_sharing_enabled'=>true,
            ]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->assertTrue($policy->canInteract($a->id,$b->id,'message'));
        $this->assertTrue($policy->canInteract($a->id,$b->id,'ping'));
        $this->assertFalse($policy->canInteract($a->id,$b->id,'location'),
            'Ghost Mode must outrank any accidental sharing preference.');
        DB::table('orbit_user_preferences')->where('user_id',$b->id)->update([
            'privacy'=>json_encode([
                'message_eligibility'=>'Friends', 'ping_eligibility'=>'Friends',
                'ghost_mode_enabled'=>false, 'location_visibility'=>'Friends',
                'location_sharing_enabled'=>true,
            ]),
        ]);
        $this->assertTrue($policy->canInteract($a->id,$b->id,'location'));
        $this->auth($ta)->postJson('/api/v1/me/blocks',['user_id'=>$b->id])->assertCreated();
        $this->assertFalse($policy->canInteract($a->id,$b->id,'message'));
        $this->assertFalse($policy->canInteract($a->id,$b->id,'location'));
        $this->assertFalse($policy->canInteract($b->id,$a->id,'ping'));
        $this->assertFalse($policy->canInteract($a->id,$b->id,'unknown_domain'));
    }

    public function test_bidirectional_blocks_are_independent_and_other_party_cannot_unblock(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $this->auth($ta)->postJson('/api/v1/me/blocks',['user_id'=>$b->id])->assertCreated();
        $this->auth($tb)->postJson('/api/v1/me/blocks',['user_id'=>$a->id])->assertCreated();
        $this->assertDatabaseCount('blocks',2);
        $this->auth($ta)->deleteJson('/api/v1/me/blocks/'.$b->id)->assertOk();
        $this->assertDatabaseHas('blocks',['blocker_id'=>$b->id,'blocked_id'=>$a->id]);
        $this->assertTrue(app(SocialGraphService::class)->isBlockedEitherWay($a->id,$b->id));
        $this->auth($ta)->getJson('/api/v1/people/'.$b->id.'/preview')->assertNotFound();
    }

    public function test_suspended_or_missing_user_is_invisible_even_when_uuid_is_known(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b] = $this->identity('b');
        $b->update(['status'=>'suspended']);
        $this->auth($ta)->getJson('/api/v1/people/'.$b->id.'/preview')->assertNotFound();
        $this->auth($ta)->getJson('/api/v1/people/search?username='.$b->username)
            ->assertOk()->assertJsonCount(0,'data.items');
        $this->auth($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])->assertNotFound();
    }

    public function test_search_by_display_name_or_username_hides_opted_out_people(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta] = $this->identity('a');
        [$b] = $this->identity('b');
        $b->update(['display_name'=>'Fatima Noor']);
        $this->auth($ta)->getJson('/api/v1/people/search?q=Fatima')
            ->assertOk()->assertJsonPath('data.items.0.id',$b->id);
        $this->auth($ta)->getJson('/api/v1/people/search?q=Noor')
            ->assertOk()->assertJsonPath('data.items.0.id',$b->id);
        $this->auth($ta)->getJson('/api/v1/people/search?q='.$b->username)
            ->assertOk()->assertJsonPath('data.items.0.id',$b->id);
        $this->auth($ta)->getJson('/api/v1/people/search?q=f')
            ->assertUnprocessable();
        [$other,$tokenB] = [$b, app(\App\Modules\Identity\Services\TokenService::class)->issue(
            $b, \App\Models\OrbitDevice::query()->where('user_id',$b->id)->firstOrFail(), null)['access_token']];
        $this->auth($tokenB)->patchJson('/api/v1/me/social/discovery',
            ['discoverable_by_name'=>false])->assertOk();
        $this->auth($ta)->getJson('/api/v1/people/search?q=Fatima')
            ->assertOk()->assertJsonCount(0,'data.items');
        $this->auth($ta)->getJson('/api/v1/people/search?q='.$b->username)
            ->assertOk()->assertJsonCount(1,'data.items');
        $this->auth($tokenB)->patchJson('/api/v1/me/social/discovery',
            ['discoverable'=>false])->assertOk();
        $this->auth($ta)->getJson('/api/v1/people/search?q='.$b->username)
            ->assertOk()->assertJsonCount(0,'data.items');
        $this->auth($ta)->getJson('/api/v1/people/'.$b->id.'/preview')->assertNotFound();
        $this->auth($tokenB)->patchJson('/api/v1/me/social/discovery',
            ['allow_friend_requests'=>false])->assertOk();
        $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertNotFound();
    }

    public function test_contact_matching_requires_both_consent_and_target_opt_in_without_storing_contacts(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $email = 'private.contact@orbit.example';
        DB::table('user_identities')->insert([
            'id'=>(string) Str::uuid(), 'user_id'=>$b->id,'provider'=>'email',
            'provider_subject'=>$email, 'email_norm'=>$email,
            'verified_at'=>now(), 'created_at'=>now(),'updated_at'=>now(),
        ]);
        $body = ['consent_confirmed'=>true,'contacts'=>[
            ['type'=>'email','value'=>$email],
        ]];
        $this->auth($ta)->postJson('/api/v1/people/contacts/match',
            [...$body,'consent_confirmed'=>false])->assertUnprocessable();
        $this->auth($ta)->postJson('/api/v1/people/contacts/match',$body)
            ->assertOk()->assertJsonCount(0,'data.items');
        $this->auth($tb)->patchJson('/api/v1/me/social/discovery',
            ['discoverable_by_contacts'=>true])->assertOk();
        $this->auth($ta)->postJson('/api/v1/people/contacts/match',$body)
            ->assertOk()->assertJsonCount(1,'data.items')
            ->assertJsonMissing(['value'=>$email]);
        $this->assertDatabaseCount('social_contact_lookup_quotas',1);
        $this->assertEquals(2,
            DB::table('social_contact_lookup_quotas')->where('user_id',$a->id)
                ->value('contact_count'));
    }

    public function test_expiring_one_time_friend_invitation_and_revoke(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $created = $this->auth($ta)->postJson('/api/v1/friends/invites')->assertCreated()->json('data');
        $this->assertStringContainsString('/invite/friend/', $created['invite_url']);
        $code = $created['invite_code'];
        $this->get('/invite/friend/'.$code)->assertOk()->assertSee('Orbit');
        $this->auth($tb)->postJson('/api/v1/friends/invites/preview',
            ['invite_code'=>$code])->assertOk()->assertJsonPath('data.username',$a->username);
        $id = $this->auth($tb)->postJson('/api/v1/friends/invites/redeem',
            ['invite_code'=>$code])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/invites/redeem',
            ['invite_code'=>$code])->assertNotFound();
        $this->auth($ta)->postJson('/api/v1/friends/requests/'.$id.'/accept')
            ->assertOk()->assertJsonPath('data.status','accepted');
        $revoked = $this->auth($ta)->postJson('/api/v1/friends/invites')->assertCreated()->json('data');
        $this->auth($tb)->deleteJson('/api/v1/friends/invites/'.$revoked['id'])->assertNotFound();
        $this->auth($ta)->deleteJson('/api/v1/friends/invites/'.$revoked['id'])
            ->assertOk()->assertJsonPath('data.revoked',true);
        $this->auth($tb)->postJson('/api/v1/friends/invites/preview',
            ['invite_code'=>$revoked['invite_code']])->assertNotFound();
    }

    public function test_outgoing_cancellation_is_sender_only_and_rejection_has_cooldown(): void
    {
        [$a,$ta] = $this->identity('a');
        [$b,$tb] = $this->identity('b');
        $id = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$id.'/cancel')->assertNotFound();
        $this->auth($ta)->postJson('/api/v1/friends/requests/'.$id.'/cancel')
            ->assertOk()->assertJsonPath('data.status','cancelled');
        $next = $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertCreated()->json('data.id');
        $this->auth($tb)->postJson('/api/v1/friends/requests/'.$next.'/decline')->assertOk();
        $this->auth($ta)->postJson('/api/v1/friends/requests',
            ['recipient_id'=>$b->id])->assertStatus(429);
    }

    public function test_new_account_daily_cap_is_enforced_without_a_global_directory(): void
    {
        config()->set('orbit_social.new_account_daily_limit',1);
        [$a,$ta] = $this->identity('a');
        [$b] = $this->identity('b');
        [$c] = $this->identity('c');
        $this->auth($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$b->id])->assertCreated();
        $this->auth($ta)->postJson('/api/v1/friends/requests',['recipient_id'=>$c->id])
            ->assertStatus(429);
    }
}
