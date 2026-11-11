<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Plans\Services\PlanReminderWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM15PlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_plans.enabled',true);
        config()->set('orbit_notifications.enabled',true);
        config()->set('orbit_notifications.push_enabled',false);
        config()->set('orbit_circle.consumer_enabled.plans',true);
    }

    private function principal(): array
    {
        $user=OrbitUser::create(['username'=>'plan_'.Str::random(12),
            'display_name'=>'Private participant','status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,
            'device_public_id'=>(string)Str::uuid(),'name'=>'M15 test device',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$user,$device,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create(['name'=>'Plans Operator '.Str::uuid(),
            'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'Plans Operator',
            'email'=>Str::uuid().'@example.test','password'=>'Secure123!password',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function friend(string $a,string $b): void
    {
        $ids=[$a,$b];sort($ids,SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id'=>$ids[0],'user_high_id'=>$ids[1],'created_at'=>now(),
        ]);
    }

    private function sample(array $invitees=[]): array
    {
        return [
            'client_id'=>(string)Str::uuid(),'title'=>'Private test plan',
            'description'=>'Only visible to invited people.',
            'starts_at'=>now()->addDays(2)->toIso8601String(),
            'ends_at'=>now()->addDays(2)->addHours(2)->toIso8601String(),
            'place_label'=>'Private destination',
            'invitee_ids'=>$invitees,
            'circle_ids'=>[],
        ];
    }

    public function test_create_idempotency_rsvp_cancel_and_access_scope(): void
    {
        [$organizer,$device,$token]=$this->principal();
        [$guest,$guestDevice,$guestToken]=$this->principal();
        [$outsider,$unused,$outsiderToken]=$this->principal();
        $this->friend($organizer->id,$guest->id);
        $body=$this->sample([$guest->id]);
        $this->withToken($token)->postJson('/api/v1/plans',$body)
            ->assertCreated()->assertJsonPath('data.title','Private test plan');
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        $this->assertNotNull($plan);
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertOk();
        $this->assertDatabaseCount('plans',1);
        $this->withToken($token)->postJson('/api/v1/plans',
            array_merge($body,['title'=>'Changed key reuse']))->assertStatus(409);
        $this->withToken($outsiderToken)->getJson('/api/v1/plans/'.$plan->id)->assertNotFound();
        $this->withToken($guestToken)->getJson('/api/v1/plans/'.$plan->id)
            ->assertOk()->assertJsonPath('data.my_rsvp','invited');
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/rsvp',[
            'state'=>'joined',
        ])->assertOk()->assertJsonPath('data.my_rsvp','joined');
        $this->withToken($token)->patchJson('/api/v1/plans/'.$plan->id,[
            'revision'=>99,'title'=>'Wrong revision',
        ])->assertStatus(409);
        $this->withToken($token)->patchJson('/api/v1/plans/'.$plan->id,[
            'revision'=>1,'title'=>'Updated for participants',
        ])->assertOk()->assertJsonPath('data.revision',2);
        $this->withToken($token)->postJson('/api/v1/plans/'.$plan->id.'/cancel',[
            'revision'=>2,
        ])->assertOk()->assertJsonPath('data.status','cancelled');
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/leave')
            ->assertStatus(409);
    }

    public function test_revocation_blocking_and_private_in_app_notification_projection(): void
    {
        [$organizer,$device,$token]=$this->principal();
        [$guest,$guestDevice,$guestToken]=$this->principal();
        $this->friend($organizer->id,$guest->id);
        $body=$this->sample([$guest->id]);
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        app(PlanReminderWorker::class)->run(20);
        $this->withToken($guestToken)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items.0.category','plans');
        DB::table('blocks')->insert([
            'blocker_id'=>$guest->id,'blocked_id'=>$organizer->id,'created_at'=>now(),
        ]);
        $this->withToken($guestToken)->getJson('/api/v1/plans/'.$plan->id)
            ->assertNotFound();
        $this->withToken($guestToken)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
    }

    public function test_reminder_preferences_and_join_leave_policy(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$guest,$guestDevice,$guestToken]=$this->principal();
        $this->friend($owner->id,$guest->id);
        $this->withToken($token)->getJson('/api/v1/me/plans/reminder-preferences')
            ->assertOk()->assertJsonPath('data.enabled',true);
        $this->withToken($token)->putJson('/api/v1/me/plans/reminder-preferences',[
            'enabled'=>true,'offsets_minutes'=>[15,60],
        ])->assertOk();
        $this->withToken($guestToken)->putJson('/api/v1/me/plans/reminder-preferences',[
            'enabled'=>true,'offsets_minutes'=>[45],
        ])->assertUnprocessable();
        $body=$this->sample([$guest->id]);
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/rsvp',[
            'state'=>'joined',
        ])->assertOk();
        $this->assertDatabaseHas('plan_invitees',[
            'plan_id'=>$plan->id,'user_id'=>$guest->id,'state'=>'joined',
        ]);
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/leave')
            ->assertOk()->assertJsonPath('data.my_rsvp','left');
        $this->withToken($guestToken)->getJson('/api/v1/plans/'.$plan->id)
            ->assertNotFound();
        $this->assertDatabaseHas('plan_invitees',[
            'plan_id'=>$plan->id,'user_id'=>$guest->id,'state'=>'left',
        ]);
    }

    public function test_admin_is_separate_and_write_actions_need_permissions_and_audit(): void
    {
        $this->get('/admin/plans')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['plans.read']),'admin');
        $this->get('/admin/plans')->assertOk()->assertSee('Plans Management')
            ->assertSee('Plan Directory')->assertSee('Reminder Operations');
        $this->get('/admin/plans/directory')->assertOk();
        $this->get('/admin/plans/audits')->assertForbidden();
        $this->post('/admin/plans/settings',[
            'key'=>'creation_enabled','enabled'=>'0',
            'reason'=>'Testing operator permission','confirm'=>'1',
        ])->assertForbidden();

        $this->be($this->admin(['plans.read','plans.manage','plans.audit']),'admin');
        $this->get('/admin/plans/audits')->assertOk();
        $this->post('/admin/plans/settings',[
            'key'=>'creation_enabled','enabled'=>'0',
            'reason'=>'Temporarily pause creation',
        ])->assertSessionHasErrors('confirm');
        $this->post('/admin/plans/settings',[
            'key'=>'creation_enabled','enabled'=>'0',
            'reason'=>'Temporarily pause creation','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('plan_controls',['key'=>'creation_enabled','enabled'=>0]);
        $this->assertDatabaseHas('plan_admin_audits',['action'=>'control.changed']);
    }
    public function test_due_reminder_is_projected_once_and_respects_disabling_preference(): void
    {
        [$owner,$device,$token]=$this->principal();
        $body=$this->sample();
        $body['starts_at']=now()->addMinutes(70)->toIso8601String();
        $body['ends_at']=now()->addMinutes(120)->toIso8601String();
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        $this->assertDatabaseHas('plan_reminder_jobs',[
            'plan_id'=>$plan->id,'user_id'=>$owner->id,
            'offset_minutes'=>60,'state'=>'pending',
        ]);
        $this->travel(10)->minutes();
        $first=app(PlanReminderWorker::class)->run(30);
        $this->assertSame(1,$first['reminders']);
        $this->assertDatabaseHas('notification_events',[
            'category'=>'plans','type'=>'plan.reminder','target_id'=>$plan->id,
        ]);
        $again=app(PlanReminderWorker::class)->run(30);
        $this->assertSame(0,$again['reminders']);
        $this->withToken($token)->putJson('/api/v1/me/plans/reminder-preferences',[
            'enabled'=>false,'offsets_minutes'=>[15,60],
        ])->assertOk();
        $this->assertDatabaseMissing('plan_reminder_jobs',[
            'plan_id'=>$plan->id,'offset_minutes'=>15,'state'=>'pending',
        ]);
        $this->travelBack();
    }

    public function test_worker_rechecks_preferences_after_a_reminder_was_queued(): void
    {
        [$owner,$device,$token]=$this->principal();
        $body=$this->sample();
        $body['starts_at']=now()->addMinutes(70)->toIso8601String();
        $body['ends_at']=now()->addMinutes(120)->toIso8601String();
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        $this->assertDatabaseHas('plan_reminder_jobs',[
            'plan_id'=>$plan->id,'offset_minutes'=>60,'state'=>'pending',
        ]);
        // Emulate a preference update racing the worker lease. It must not
        // publish a stale reminder even though a pending row still exists.
        DB::table('plan_reminder_preferences')->updateOrInsert(
            ['user_id'=>$owner->id],
            ['enabled'=>false,'offsets_json'=>json_encode([60]),
                'created_at'=>now(),'updated_at'=>now()]
        );
        $this->travel(10)->minutes();
        $stats=app(PlanReminderWorker::class)->run(30);
        $this->assertSame(0,$stats['reminders']);
        $this->assertDatabaseHas('plan_reminder_jobs',[
            'plan_id'=>$plan->id,'offset_minutes'=>60,'state'=>'suppressed',
        ]);
        $this->travelBack();
    }

    public function test_idempotent_retry_remains_safe_after_the_original_start_time(): void
    {
        [$owner,$device,$token]=$this->principal();
        $body=$this->sample();
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $this->assertDatabaseCount('plans',1);
        $this->travel(4)->days();
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertOk();
        $this->assertDatabaseCount('plans',1);
        $this->travelBack();
    }

    public function test_non_organizer_cannot_edit_cancel_or_mutate_past_plan(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$guest,$guestDevice,$guestToken]=$this->principal();
        $this->friend($owner->id,$guest->id);
        $body=$this->sample([$guest->id]);
        $this->withToken($token)->postJson('/api/v1/plans',$body)->assertCreated();
        $plan=DB::table('plans')->where('client_id',$body['client_id'])->first();
        $this->withToken($guestToken)->patchJson('/api/v1/plans/'.$plan->id,[
            'revision'=>1,'title'=>'Cannot hijack plan',
        ])->assertForbidden();
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/cancel',[
            'revision'=>1,
        ])->assertForbidden();
        $this->travel(3)->days();
        $this->withToken($token)->patchJson('/api/v1/plans/'.$plan->id,[
            'revision'=>1,'title'=>'No past edits',
        ])->assertStatus(409);
        $this->withToken($guestToken)->postJson('/api/v1/plans/'.$plan->id.'/rsvp',[
            'state'=>'joined',
        ])->assertStatus(409);
        $this->travelBack();
    }

    public function test_m15_erasure_contract_whitelists_only_cascade_backed_plan_owners(): void
    {
        [$owner,$device,$token]=$this->principal();
        $request=(string)Str::uuid();
        DB::table('orbit_account_deletion_requests')->insert([
            'request_id'=>$request,'user_id'=>$owner->id,'status'=>'pending',
            'requested_at'=>now()->subDays(31),
            'eligible_for_erasure_at'=>now()->subDay(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $preview=app(\App\Modules\Profile\Services\M3ErasureProcessor::class)
            ->preview($request);
        $unregistered=$preview['unregistered_tables'];
        foreach(['plans','plan_invitees','plan_reminder_preferences',
            'plan_reminder_jobs','plan_change_events'] as $table){
            $this->assertNotContains($table,$unregistered,
                'M15 user FK must have approved cascade erasure contract: '.$table);
        }
        // Do not whitelist unrelated M8-M14 ownership relationships until
        // their deletion and encrypted-media retention contracts are audited.
    }

}
