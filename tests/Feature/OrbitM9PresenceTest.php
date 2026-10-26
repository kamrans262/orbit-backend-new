<?php

namespace Tests\Feature;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM9PresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orbit_presence.enabled',true);
        config()->set('orbit_presence.store','array');
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_circle.consumer_enabled.presence',true);
    }

    private function person(string $prefix): array
    {
        $user=OrbitUser::create([
            'username'=>$prefix.'_'.Str::lower(Str::random(8)),
            'display_name'=>'M9 '.ucfirst($prefix),'status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'M9 test phone','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        return [$user,app(TokenService::class)->issue($user,$device,null)['access_token'],$device];
    }

    private function allowGlobal(string $user): void
    {
        DB::table('orbit_user_preferences')->insert([
            'user_id'=>$user,'privacy'=>json_encode([
                'ghost_mode_enabled'=>false,'presence_visibility'=>'My Circle',
            ],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function circleWithMembers(string $ownerToken,string $member): string
    {
        $id=$this->withToken($ownerToken)->postJson('/api/v1/circles',['name'=>'M9 Family'])
            ->assertCreated()->json('data.id');
        DB::table('circle_members')->insert([
            'circle_id'=>$id,'user_id'=>$member,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    public function test_default_disabled_and_untrusted_requests_fail_closed(): void
    {
        [$user,$token,$device]=$this->person('alpha');
        $this->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertUnauthorized();
        config()->set('orbit_presence.enabled',false);
        $this->withToken($token)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertNotFound();
        config()->set('orbit_presence.enabled',true);
        $device->update(['trust_state'=>'pending']);
        $this->withToken($token)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertForbidden();
    }

    public function test_circle_consent_and_ghost_control_presence_visibility(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        [$stranger,$strangerToken]=$this->person('stranger');
        $circle=$this->circleWithMembers($aliceToken,$bob->id);
        $this->allowGlobal($alice->id);
        $this->withToken($aliceToken)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertOk();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertOk()->assertJsonCount(0,'data.members');
        $this->withToken($aliceToken)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'persistent',
        ])->assertOk();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertOk()->assertJsonPath('data.members.0.user_id',$alice->id)
            ->assertJsonPath('data.members.0.state','active_now')
            ->assertHeader('Cache-Control','private, no-store, max-age=0');
        $this->withToken($strangerToken)->getJson("/api/v1/circles/$circle/presence")->assertNotFound();
        DB::table('orbit_user_preferences')->where('user_id',$alice->id)->update([
            'privacy'=>json_encode(['ghost_mode_enabled'=>true,'presence_visibility'=>'My Circle']),
        ]);
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertOk()->assertJsonCount(0,'data.members');
    }

    public function test_block_and_expiry_revoke_previous_presence_reads(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $circle=$this->circleWithMembers($aliceToken,$bob->id);
        $this->allowGlobal($alice->id);
        $this->withToken($aliceToken)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'persistent',
        ])->assertOk();
        $this->withToken($aliceToken)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertOk();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertJsonCount(1,'data.members');
        $this->withToken($bobToken)->postJson('/api/v1/me/blocks',['user_id'=>$alice->id])->assertOk();
        // A block by the viewer against Circle owner also revokes Circle access.
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertNotFound();
    }

    public function test_expired_90_second_lease_does_not_leave_a_false_online_status(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $circle=$this->circleWithMembers($aliceToken,$bob->id);
        $this->allowGlobal($alice->id);
        $this->withToken($aliceToken)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'persistent',
        ])->assertOk();
        $this->withToken($aliceToken)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertOk();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertOk()->assertJsonCount(1,'data.members');
        $this->travel(91)->seconds();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertOk()->assertJsonCount(0,'data.members');
        $this->travelBack();
    }

    public function test_multiple_devices_are_aggregated_and_revoked_sessions_are_removed(): void
    {
        [$alice,$token1,$device1]=$this->person('alice');
        [$bob,$bobToken]=$this->person('bob');
        $device2=OrbitDevice::create([
            'user_id'=>$alice->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Second trusted device','platform'=>'ios',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $token2=app(TokenService::class)->issue($alice,$device2,null)['access_token'];
        $circle=$this->circleWithMembers($token1,$bob->id);
        $this->allowGlobal($alice->id);
        $this->withToken($token1)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'persistent',
        ])->assertOk();
        $this->withToken($token1)->postJson('/api/v1/presence/heartbeat',['state'=>'active'])->assertOk();
        $this->withToken($token2)->postJson('/api/v1/presence/heartbeat',['state'=>'idle'])->assertOk();
        $this->withToken($token1)->postJson('/api/v1/presence/disconnect')->assertOk();
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertJsonPath('data.members.0.state','idle');
        OrbitSession::query()->where('device_id',$device2->id)->update(['revoked_at'=>now()]);
        $this->withToken($bobToken)->getJson("/api/v1/circles/$circle/presence")
            ->assertJsonCount(0,'data.members');
    }

    public function test_consent_opt_out_and_invalid_expiry(): void
    {
        [$alice,$aliceToken]=$this->person('alice');
        $circle=$this->withToken($aliceToken)->postJson('/api/v1/circles',['name'=>'Privacy'])
            ->assertCreated()->json('data.id');
        $this->withToken($aliceToken)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'until_expiry','expires_at'=>now()->addDays(35)->toIso8601String(),
        ])->assertUnprocessable();
        $this->withToken($aliceToken)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>false,
        ])->assertOk()->assertJsonPath('data.requested',false);
        $this->assertDatabaseHas('circle_permissions',[
            'circle_id'=>$circle,'subject_user_id'=>$alice->id,'permission_key'=>'presence','enabled'=>false,
        ]);
    }

    public function test_gateway_ticket_is_bound_to_live_session_and_secret(): void
    {
        config()->set('orbit_presence.realtime_enabled',true);
        config()->set('orbit_presence.gateway_secret',str_repeat('k',48));
        [$alice,$token,$device]=$this->person('alice');
        $ticket=$this->withToken($token)->postJson('/api/v1/presence/realtime/ticket')
            ->assertOk()->json('data.ticket');
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket],['X-Orbit-Presence-Gateway'=>'wrong'])->assertForbidden();
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket],['X-Orbit-Presence-Gateway'=>str_repeat('k',48)])
            ->assertOk()->assertJsonPath('data.viewer_id',$alice->id);
        OrbitSession::where('user_id',$alice->id)->update(['revoked_at'=>now()]);
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket],['X-Orbit-Presence-Gateway'=>str_repeat('k',48)])
            ->assertForbidden();
    }

    public function test_gateway_rechecks_current_circle_consent_for_every_event(): void
    {
        config()->set('orbit_presence.realtime_enabled',true);
        config()->set('orbit_presence.gateway_secret',str_repeat('x',48));
        [$alice,$token1]=$this->person('alice');
        [$bob,$token2]=$this->person('bob');
        $circle=$this->circleWithMembers($token1,$bob->id);
        $ticket=$this->withToken($token2)->postJson('/api/v1/presence/realtime/ticket')
            ->assertOk()->json('data.ticket');
        $event=['type'=>'presence.changed','circle_id'=>$circle,'subject_id'=>$alice->id];
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket,'event'=>$event],['X-Orbit-Presence-Gateway'=>str_repeat('x',48)])
            ->assertForbidden();
        $this->allowGlobal($alice->id);
        $this->withToken($token1)->putJson("/api/v1/me/presence/circles/$circle",[
            'enabled'=>true,'duration'=>'persistent',
        ])->assertOk();
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket,'event'=>$event],['X-Orbit-Presence-Gateway'=>str_repeat('x',48)])
            ->assertOk()->assertJsonPath('data.viewer_id',$bob->id);
        DB::table('orbit_user_preferences')->where('user_id',$alice->id)->update([
            'privacy'=>json_encode(['ghost_mode_enabled'=>true,'presence_visibility'=>'My Circle']),
        ]);
        $this->postJson('/api/v1/presence/realtime/introspect',
            ['ticket'=>$ticket,'event'=>$event],['X-Orbit-Presence-Gateway'=>str_repeat('x',48)])
            ->assertForbidden();
    }

    public function test_typing_cannot_bypass_message_gate_or_consent(): void
    {
        config()->set('orbit_presence.realtime_enabled',true);
        config()->set('orbit_presence.gateway_secret',str_repeat('x',48));
        [$alice,$token]=$this->person('alice');
        $circle=$this->withToken($token)->postJson('/api/v1/circles',['name'=>'M9 Family'])
            ->assertCreated()->json('data.id');
        $this->allowGlobal($alice->id);
        $this->withToken($token)->postJson("/api/v1/circles/$circle/typing",
            ['typing'=>true])->assertNotFound();
        config()->set('orbit_circle.consumer_enabled.message',true);
        $this->withToken($token)->postJson("/api/v1/circles/$circle/typing",
            ['typing'=>true])->assertForbidden();
    }

    public function test_admin_presence_view_requires_a_secure_admin_session(): void
    {
        $this->get('/admin/presence')->assertRedirect(route('orbit.admin.login'));
    }
}
