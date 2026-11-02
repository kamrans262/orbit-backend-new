<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Notifications\Services\NotificationDeliveryWorker;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Notifications\Services\NotificationProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM14NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_notifications.enabled',true);
        config()->set('orbit_notifications.push_enabled',false);
        config()->set('orbit_moments.fcm.enabled',false);
    }

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create(['name'=>'Notification Inspector '.Str::uuid(),
            'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'Notification Inspector',
            'email'=>Str::uuid().'@example.test','password'=>'Secure123!password',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function principal(): array
    {
        $user=OrbitUser::create(['username'=>'notif_'.Str::random(12),
            'display_name'=>'Private user','status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,
            'device_public_id'=>(string)Str::uuid(),'name'=>'M14 test device',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$user,$device,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    public function test_notifications_is_a_separate_permission_gated_admin_section(): void
    {
        $this->get('/admin/notifications')->assertRedirect(route('orbit.admin.login'));
        $this->be($this->admin(['notifications.read']),'admin');
        $page=$this->get('/admin/notifications')->assertOk()
            ->assertSee('Notification Management')
            ->assertSee('Delivery Queue')
            ->assertSee('Push Devices')
            ->assertSee('Audit Trail');
        $this->assertStringContainsString('no-store',(string)$page->headers->get('Cache-Control'));
        $this->get('/admin/notifications/jobs')->assertOk();
        $this->post('/admin/notifications/settings',[
            'key'=>'category.pings','enabled'=>'0','reason'=>'Testing permissions only',
            'confirm'=>'1',
        ])->assertForbidden();
    }

    public function test_users_or_config_readers_cannot_see_notification_operations(): void
    {
        $this->be($this->admin(['config.manage','users.read']),'admin');
        $this->get('/admin/notifications')->assertForbidden();
        $this->get('/admin/notifications/devices')->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('Delivery Queue');
    }

    public function test_admin_controls_are_explicitly_confirmed_and_audited(): void
    {
        $this->be($this->admin(['notifications.read','notifications.manage']),'admin');
        $this->post('/admin/notifications/settings',[
            'key'=>'category.pings','enabled'=>'0','reason'=>'Temporarily pause Ping notices',
        ])->assertSessionHasErrors('confirm');
        $this->post('/admin/notifications/settings',[
            'key'=>'category.pings','enabled'=>'0','reason'=>'Temporarily pause Ping notices',
            'confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('notification_controls',[
            'key'=>'category.pings','enabled'=>0,
        ]);
        $this->assertDatabaseHas('notification_admin_audits',[
            'action'=>'control.changed','subject_id'=>'category.pings',
        ]);
        $this->post('/admin/notifications/settings',[
            'key'=>'channel.push','enabled'=>'1','reason'=>'Cannot skip provider release gate',
            'confirm'=>'1',
        ])->assertSessionHasErrors('enabled');
    }

    public function test_unified_inbox_is_empty_for_authorized_user_and_fails_closed_for_untrusted_target(): void
    {
        [$user,$device,$token]=$this->principal();
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
        $this->withToken($token)->getJson('/api/v1/me/notifications/unread')
            ->assertOk()->assertJsonPath('data.count',0);
        $event=(string)Str::uuid();$notice=(string)Str::uuid();
        DB::table('notification_events')->insert([
            'id'=>$event,'source_type'=>'friend','source_id'=>(string)Str::uuid(),
            'category'=>'friends','type'=>'friend.request','target_id'=>(string)Str::uuid(),
            'created_at'=>now(),
        ]);
        DB::table('notification_inbox')->insert([
            'id'=>$notice,'event_id'=>$event,'recipient_user_id'=>$user->id,
            'category'=>'friends','type'=>'friend.request','target_id'=>(string)Str::uuid(),
            'template_key'=>'friends.updated','created_at'=>now(),
        ]);
        // No approved Friends producer/access verifier in Stage 2: records
        // must not leak merely because they are present in the table.
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
        $this->withToken($token)->getJson('/api/v1/me/notifications/unread')
            ->assertOk()->assertJsonPath('data.count',0);
        $this->withToken($token)->postJson('/api/v1/me/notifications/'.$notice.'/read')
            ->assertNotFound();
        $this->assertDatabaseHas('notification_inbox',['id'=>$notice,'read_at'=>null]);
    }

    public function test_push_is_inert_when_unprovisioned_even_if_a_worker_runs(): void
    {
        [$user,$device,$token]=$this->principal();
        Http::fake();
        $this->withToken($token)->getJson('/api/v1/me/notifications/push-bootstrap')
            ->assertOk()->assertJsonPath('data.enabled',false);
        $this->withToken($token)->postJson('/api/v1/me/notifications/push-devices',[
            'token'=>str_repeat('T',40),'platform'=>'android',
        ])->assertStatus(503);
        $this->assertDatabaseCount('notification_device_tokens',0);
        $this->assertSame(0,app(NotificationDeliveryWorker::class)->run()['accepted']);
        Http::assertNothingSent();
    }

    public function test_friend_requests_are_private_idempotent_and_hidden_after_block(): void
    {
        [$sender,$senderDevice,$senderToken]=$this->principal();
        [$recipient,$recipientDevice,$recipientToken]=$this->principal();
        $id=(string)Str::uuid();
        DB::table('friend_requests')->insert([
            'id'=>$id,'sender_id'=>$sender->id,'recipient_id'=>$recipient->id,
            'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        $first=app(NotificationProjector::class)->run(20);
        $this->assertSame(1,$first['friends']);
        $second=app(NotificationProjector::class)->run(20);
        $this->assertSame(0,$second['friends']);
        $this->assertDatabaseCount('notification_inbox',1);
        $this->withToken($recipientToken)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items.0.type','friend.requested');
        $this->withToken($senderToken)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
        // Revocation must be enforced at read, not just at publish time.
        DB::table('blocks')->insert([
            'blocker_id'=>$sender->id,'blocked_id'=>$recipient->id,'created_at'=>now(),
        ]);
        $this->withToken($recipientToken)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
        $this->withToken($recipientToken)->getJson('/api/v1/me/notifications/unread')
            ->assertOk()->assertJsonPath('data.count',0);
    }

    public function test_selected_security_event_projects_without_exposing_reason_or_device_details(): void
    {
        [$user,$device,$token]=$this->principal();
        $event=(string)Str::uuid();
        DB::table('orbit_identity_events')->insert([
            'id'=>$event,'user_id'=>$user->id,'device_id'=>$device->id,
            'action'=>'auth.password_reset','reason_code'=>'verified_otp',
            'created_at'=>now(),
        ]);
        $stats=app(NotificationProjector::class)->run(20);
        $this->assertSame(1,$stats['security']);
        $response=$this->withToken($token)->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('data.items.0.category','security')
            ->assertJsonPath('data.items.0.type','security.notice');
        $this->assertStringNotContainsString('verified_otp',$response->getContent());
        $this->assertStringNotContainsString($device->id,$response->getContent());
    }

    public function test_inactive_accounts_cannot_project_new_or_read_existing_private_notices(): void
    {
        [$sender,$senderDevice,$senderToken]=$this->principal();
        [$recipient,$device,$token]=$this->principal();
        $source=(string)Str::uuid();
        DB::table('friend_requests')->insert([
            'id'=>$source,'sender_id'=>$sender->id,'recipient_id'=>$recipient->id,
            'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        app(NotificationProjector::class)->run(10);
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonCount(1,'data.items');
        DB::table('orbit_users')->where('id',$recipient->id)
            ->update(['status'=>'suspended']);
        // Existing trusted session, persisted notification and older push
        // registration must never keep a suspended owner eligible.
        $this->assertFalse(app(NotificationPolicy::class)->targetAllowed(
            'friends',$source,$recipient->id,null,'friend.requested'));
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertStatus(403);
    }

    public function test_inbox_skips_revoked_rows_without_dropping_cursor(): void
    {
        [$sender,$senderDevice,$senderToken]=$this->principal();
        [$recipient,$device,$token]=$this->principal();
        $ids=[];
        for($i=0;$i<8;$i++){
            $source=(string)Str::uuid();
            DB::table('friend_requests')->insert([
                'id'=>$source,'sender_id'=>$sender->id,
                'recipient_id'=>$recipient->id,'status'=>'pending',
                'created_at'=>now()->subSeconds($i),'updated_at'=>now(),
            ]);
            $ids[]=$source;
        }
        app(NotificationProjector::class)->run(20);
        $page=$this->withToken($token)
            ->getJson('/api/v1/me/notifications?limit=2')->assertOk();
        $this->assertCount(2,$page->json('data.items'));
        $this->assertNotNull($page->json('data.next_cursor'));
        DB::table('blocks')->insert([
            'blocker_id'=>$sender->id,'blocked_id'=>$recipient->id,'created_at'=>now(),
        ]);
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonPath('data.items',[]);
    }

    public function test_backfill_keeps_original_event_age_instead_of_becoming_new_push(): void
    {
        [$sender,$senderDevice,$senderToken]=$this->principal();
        [$recipient,$device,$token]=$this->principal();
        $id=(string)Str::uuid();
        $occurred=now()->subDays(2)->startOfMinute();
        DB::table('friend_requests')->insert([
            'id'=>$id,'sender_id'=>$sender->id,'recipient_id'=>$recipient->id,
            'status'=>'pending','created_at'=>$occurred,'updated_at'=>$occurred,
        ]);
        $stats=app(NotificationProjector::class)->run(20);
        $this->assertSame(1,$stats['friends']);
        $notice=DB::table('notification_inbox')->where('recipient_user_id',$recipient->id)->first();
        $this->assertNotNull($notice);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($notice->created_at)
            ->lt(now()->subDay()));
        // Backfilled notices stay available to the inbox, not eligible for a
        // fresh push in the configured 30-minute delivery window.
        $this->withToken($token)->getJson('/api/v1/me/notifications')
            ->assertOk()->assertJsonCount(1,'data.items');
        $this->assertDatabaseCount('notification_jobs',0);
    }

    public function test_source_outbox_is_atomic_with_source_transactions(): void
    {
        $outbox=app(\App\Modules\Notifications\Services\NotificationSourceOutbox::class);
        $source=(string)Str::uuid();
        try {
            DB::transaction(function()use($outbox,$source){
                $outbox->record('ping',$source);
                throw new \RuntimeException('source transaction rollback');
            });
        }catch(\RuntimeException){}
        $this->assertDatabaseMissing('notification_source_outbox',[
            'source_type'=>'ping','source_id'=>$source,
        ]);
        DB::transaction(function()use($outbox,$source){
            $outbox->record('ping',$source);
            $outbox->record('ping',$source);
        });
        $this->assertDatabaseCount('notification_source_outbox',1);
        $outbox->record('invented_event',$source);
        $this->assertDatabaseCount('notification_source_outbox',1);
    }

    public function test_security_mail_and_fcm_stay_inert_without_credentials(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->assertFalse(app(
            \App\Modules\Notifications\Services\NotificationSecurityEmailTransport::class
        )->enabled());
        $stats=app(
            \App\Modules\Notifications\Services\NotificationSecurityEmailDeliveryWorker::class
        )->run(5);
        $this->assertSame(0,$stats['accepted']);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->assertDatabaseCount('notification_security_email_jobs',0);
    }

    public function test_revoked_device_token_is_disabled_without_contacting_fcm(): void
    {
        [$user,$device,$token]=$this->principal();
        DB::table('notification_device_tokens')->insert([
            'device_id'=>$device->id,'user_id'=>$user->id,
            'platform'=>'android','project_id'=>'orbit-test',
            'token_ciphertext'=>\Illuminate\Support\Facades\Crypt::encryptString(
                'not-a-real-device-token'),
            'token_digest'=>hash('sha256','not-a-real-device-token'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('devices')->where('id',$device->id)->update([
            'revoked_at'=>now(),'trust_state'=>'revoked',
        ]);
        $stats=app(
            \App\Modules\Notifications\Services\NotificationDeviceBridge::class
        )->reconcile(10);
        $this->assertSame(1,$stats['disabled']);
        $this->assertDatabaseHas('notification_device_tokens',[
            'device_id'=>$device->id,
        ]);
        $this->assertNotNull(DB::table('notification_device_tokens')
            ->where('device_id',$device->id)->value('disabled_at'));
    }

    public function test_context_mutes_are_owner_scoped_and_need_current_membership(): void
    {
        [$user,$device,$token]=$this->principal();
        $this->withToken($token)->getJson(
            '/api/v1/me/notifications/context-mutes?category=messages'
        )->assertOk()->assertJsonPath('data.items',[]);
        $missing=(string)Str::uuid();
        $this->withToken($token)->putJson(
            '/api/v1/me/notifications/context-mutes/messages/conversation/'.$missing,
            ['muted'=>true]
        )->assertNotFound();
        $this->withToken($token)->putJson(
            '/api/v1/me/notifications/context-mutes/moments/circle/'.$missing,
            ['muted'=>true]
        )->assertNotFound();
        $this->assertDatabaseCount('notification_context_mutes',0);
    }

    public function test_supplemental_friend_and_message_preferences_never_disable_security(): void
    {
        [$user,$device,$token]=$this->principal();
        $path='/api/v1/me/notifications/category-preferences';
        $this->withToken($token)->getJson($path)->assertOk()
            ->assertJsonPath('data.messages',true)
            ->assertJsonPath('data.friends',true)
            ->assertJsonPath('data.security_required',true);
        $this->withToken($token)->putJson($path.'/messages',['enabled'=>false])
            ->assertOk()->assertJsonPath('data.enabled',false);
        $this->withToken($token)->putJson($path.'/friends',['enabled'=>false])
            ->assertOk()->assertJsonPath('data.enabled',false);
        $this->assertDatabaseHas('notification_category_preferences',[
            'user_id'=>$user->id,'category'=>'friends','enabled'=>0,
        ]);
        $policy=app(NotificationPolicy::class);
        $this->assertFalse($policy->categoryEnabled($user->id,'messages'));
        $this->assertFalse($policy->categoryEnabled($user->id,'friends'));
        $this->assertTrue($policy->categoryEnabled($user->id,'security'));
        $this->withToken($token)->putJson($path.'/security',['enabled'=>false])
            ->assertNotFound();
        $this->withToken($token)->putJson($path.'/messages',['enabled'=>true])
            ->assertOk()->assertJsonPath('data.enabled',true);
        $this->assertTrue($policy->categoryEnabled($user->id,'messages'));
    }

    public function test_notification_errors_are_typed_and_do_not_expose_details(): void
    {
        [$user,$device,$token]=$this->principal();
        $this->withToken($token)
            ->getJson('/api/v1/me/notifications?limit=2000')
            ->assertStatus(422)
            ->assertJsonPath('error.code','validation_error');
        $this->withToken($token)
            ->putJson('/api/v1/me/notifications/category-preferences/messages',
                ['enabled'=>'not-a-boolean'])
            ->assertStatus(422)->assertJsonPath('error.code','validation_error');
        config()->set('orbit_notifications.enabled',false);
        $response=$this->withToken($token)
            ->getJson('/api/v1/me/notifications')
            ->assertStatus(503)
            ->assertJsonPath('error.code','service_unavailable');
        $this->assertStringNotContainsString('stack',$response->getContent());
        $this->assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
    }

    public function test_additive_notification_tables_are_present_and_owner_scoped(): void
    {
        foreach([
            'notification_events','notification_inbox','notification_device_tokens',
            'notification_jobs','notification_attempts','notification_controls',
            'notification_templates','notification_context_mutes',
            'notification_category_preferences','notification_source_outbox',
            'notification_circle_changes','notification_security_email_jobs',
        ] as $table){
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table),
                'M14 migration table missing: '.$table);
        }
        [$first,$device,$token]=$this->principal();
        [$other,$otherDevice,$otherToken]=$this->principal();
        $this->withToken($token)
            ->putJson('/api/v1/me/notifications/category-preferences/friends',
                ['enabled'=>false])->assertOk();
        $this->withToken($otherToken)
            ->getJson('/api/v1/me/notifications/category-preferences')
            ->assertOk()->assertJsonPath('data.friends',true);
    }

    public function test_existing_moment_read_state_survives_shared_inbox_cutover(): void
    {
        [$owner,$device,$ownerToken]=$this->principal();
        [$creator,$creatorDevice,$creatorToken]=$this->principal();
        $moment=(string)Str::uuid();
        $legacy=(string)Str::uuid();
        $legacyEvent=(string)Str::uuid();
        $sharedEvent=(string)Str::uuid();
        $sharedNotice=(string)Str::uuid();
        $readAt=now()->subMinutes(3)->startOfSecond();
        DB::table('moments')->insert([
            'id'=>$moment,'creator_id'=>$creator->id,
            'client_id'=>(string)Str::uuid(),
            'request_key_hash'=>hash('sha256','moment-test-request'),
            'request_payload_hash'=>hash('sha256','moment-test-payload'),
            'audience_kind'=>'friends','published_at'=>now()->subHour(),
            'rail_expires_at'=>now()->addHour(),
            'view_expires_at'=>now()->addDay(),
            'purge_due_at'=>now()->addDays(7),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('moment_events_outbox')->insert([
            'id'=>$legacyEvent,'moment_id'=>$moment,
            'event_type'=>'moment.published','audience_revision'=>1,
            'created_at'=>now(),
        ]);
        DB::table('moment_notification_inbox')->insert([
            'id'=>$legacy,'moment_id'=>$moment,'event_id'=>$legacyEvent,
            'recipient_user_id'=>$owner->id,'context_id'=>'friend',
            'created_at'=>now()->subHour(),'read_at'=>$readAt,
        ]);
        DB::table('notification_events')->insert([
            'id'=>$sharedEvent,'source_type'=>'moment_notice','source_id'=>$legacy,
            'category'=>'moments','type'=>'moment.available',
            'target_id'=>$moment,'created_at'=>now()->subHour(),
        ]);
        DB::table('notification_inbox')->insert([
            'id'=>$sharedNotice,'event_id'=>$sharedEvent,
            'recipient_user_id'=>$owner->id,'category'=>'moments',
            'type'=>'moment.available','target_id'=>$moment,
            'context_id'=>'friend','template_key'=>'moments.available',
            'created_at'=>now()->subHour(),
        ]);
        $synced=app(\App\Modules\Notifications\Services\NotificationService::class)
            ->syncLegacyMomentReads($owner->id);
        $this->assertSame(1,$synced);
        $this->assertSame(
            \Illuminate\Support\Carbon::parse($readAt)->toDateTimeString(),
            \Illuminate\Support\Carbon::parse(
                DB::table('notification_inbox')->where('id',$sharedNotice)
                    ->value('read_at')
            )->toDateTimeString()
        );
        $this->assertDatabaseHas('moment_notification_inbox',[
            'id'=>$legacy,'recipient_user_id'=>$owner->id,
        ]);
        $this->assertSame(0,app(\App\Modules\Notifications\Services\NotificationService::class)
            ->syncLegacyMomentReads($owner->id));
    }

    public function test_legacy_device_audit_does_not_migrate_or_contact_firebase(): void
    {
        [$user,$device,$token]=$this->principal();
        \Illuminate\Support\Facades\Http::fake();
        DB::table('moment_push_devices')->insert([
            'device_id'=>$device->id,'user_id'=>$user->id,
            'platform'=>'android','token_digest'=>hash('sha256','legacy-push-value'),
            'token_ciphertext'=>\Illuminate\Support\Facades\Crypt::encryptString('legacy-push-value'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $bridge=app(\App\Modules\Notifications\Services\NotificationDeviceBridge::class);
        $audit=$bridge->audit();
        $this->assertSame(1,$audit['legacy_scanned']);
        $this->assertFalse($audit['push_delivery_enabled']);
        $this->assertSame(0,$bridge->bridge()['bridged']);
        $this->assertDatabaseCount('notification_device_tokens',0);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_reprojection_is_idempotent_without_fake_source_records(): void
    {
        $service=app(NotificationProjector::class);
        $one=$service->run(5);$two=$service->run(5);
        foreach(['moments','pings','check_ins','messages','friends','circles','security'] as $category){
            $this->assertSame(0,$one[$category]);
            $this->assertSame(0,$two[$category]);
        }
        $this->assertTrue(app(NotificationPolicy::class)->installed());
        $this->assertDatabaseCount('notification_events',0);
        $this->assertDatabaseCount('notification_inbox',0);
    }
}
