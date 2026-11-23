<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Safety\Services\SosDeliveryWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM16SosSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_sos.enabled',true);
        config()->set('orbit_circle.enabled',true);
        config()->set('orbit_notifications.push_enabled',false);
    }

    private function principal(): array
    {
        $user=OrbitUser::create(['username'=>'sos_'.Str::random(12),
            'display_name'=>'Emergency user','status'=>'active']);
        $device=OrbitDevice::create(['user_id'=>$user->id,
            'device_public_id'=>(string)Str::uuid(), 'name'=>'Safety test device',
            'platform'=>'android','public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now()]);
        return [$user,$device,app(TokenService::class)->issue($user,$device,null)['access_token']];
    }

    private function friend(string $first,string $second): void
    {
        $ids=[$first,$second];sort($ids,SORT_STRING);
        DB::table('friendships')->insert([
            'user_low_id'=>$ids[0],'user_high_id'=>$ids[1],'created_at'=>now(),
        ]);
    }

    private function trusted(string $owner,string $contact,string $ownerToken,string $contactToken): void
    {
        $this->friend($owner,$contact);
        $this->withToken($ownerToken)->postJson('/api/v1/sos/trusted-contacts',[
            'recipient_user_id'=>$contact,
        ])->assertOk();
        $id=DB::table('sos_trusted_contacts')->where('owner_id',$owner)
            ->where('recipient_id',$contact)->value('id');
        $this->withToken($contactToken)->postJson('/api/v1/sos/trusted-contacts/'.$id.'/decision',[
            'state'=>'accepted',
        ])->assertOk();
    }

    private function payload(array $extra=[]): array
    {
        return array_merge(['client_id'=>(string)Str::uuid(),
            'confirm_sos'=>true,'share_location'=>false],$extra);
    }

    private function admin(array $permissions): AdminUser
    {
        $role=AdminRole::create(['name'=>'Safety '.Str::uuid(),'permissions'=>$permissions]);
        $admin=AdminUser::create(['name'=>'Safety operator',
            'email'=>Str::uuid().'@example.test','password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active']);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    public function test_trusted_contact_requires_mutual_acceptance_and_incident_is_idempotent(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$contact,$otherDevice,$contactToken]=$this->principal();
        [$outsider,$third,$outsiderToken]=$this->principal();
        $this->friend($owner->id,$contact->id);
        $this->withToken($token)->postJson('/api/v1/sos/trusted-contacts',[
            'recipient_user_id'=>$contact->id,
        ])->assertOk();
        $body=$this->payload();
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$body)
            ->assertCreated()->assertJsonPath('data.server_accepted',true)
            ->assertJsonPath('data.emergency_services_contacted',false)
            ->assertJsonPath('data.in_app_available_for_eligible_responders',false);
        $contactId=DB::table('sos_trusted_contacts')->where('owner_id',$owner->id)->value('id');
        $this->withToken($contactToken)->postJson('/api/v1/sos/trusted-contacts/'.$contactId.'/decision',[
            'state'=>'accepted',
        ])->assertOk();
        $other=$this->payload();
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$other)
            ->assertCreated()->assertJsonPath('data.in_app_available_for_eligible_responders',true);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$other)->assertOk();
        $this->assertDatabaseCount('sos_incidents',2);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',
            array_merge($other,['recording_consent'=>true]))->assertStatus(409);
        $incident=DB::table('sos_incidents')->where('client_id',$other['client_id'])->first();
        $this->withToken($outsiderToken)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertNotFound();
        $this->withToken($contactToken)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertOk()->assertJsonPath('data.my_role','responder');
        $this->withToken($contactToken)->postJson('/api/v1/sos/incidents/'.$incident->id.'/acknowledge')
            ->assertOk()->assertJsonPath('data.my_response','acknowledged');
        $this->withToken($token)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertJsonPath('data.remote_responder_acknowledged',true);
        $this->withToken($contactToken)->postJson('/api/v1/sos/incidents/'.$incident->id.'/resolve',[
            'revision'=>1,
        ])->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/sos/incidents/'.$incident->id.'/resolve',[
            'revision'=>1,
        ])->assertOk()->assertJsonPath('data.location_sharing_active',false);
    }

    public function test_explicit_fresh_encrypted_location_and_immediate_revocation(): void
    {
        [$owner,$d,$token]=$this->principal();
        [$contact,$dc,$contactToken]=$this->principal();
        $this->trusted($owner->id,$contact->id,$token,$contactToken);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$this->payload([
            'share_location'=>true,'latitude'=>30.2,'longitude'=>71.4,
            'location_captured_at'=>now()->subMinutes(20)->toIso8601String(),
        ]))->assertUnprocessable();
        $data=$this->payload([
            'share_location'=>true,'latitude'=>30.2,'longitude'=>71.4,
            'location_captured_at'=>now()->toIso8601String(),
        ]);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$data)->assertCreated();
        $incident=DB::table('sos_incidents')->where('client_id',$data['client_id'])->first();
        $this->assertNotNull($incident->location_ciphertext);
        $this->assertStringNotContainsString('30.2',(string)$incident->location_ciphertext);
        $this->withToken($contactToken)->getJson('/api/v1/sos/incidents/'.$incident->id.'/location')
            ->assertOk()->assertJsonPath('data.latitude',30.2);
        DB::table('blocks')->insert(['blocker_id'=>$contact->id,
            'blocked_id'=>$owner->id,'created_at'=>now()]);
        $this->withToken($contactToken)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertNotFound();
        $this->withToken($contactToken)->getJson('/api/v1/sos/incidents/'.$incident->id.'/location')
            ->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/sos/incidents/'.$incident->id.'/cancel',[
            'revision'=>1,
        ])->assertOk();
        $this->assertNull(DB::table('sos_incidents')->where('id',$incident->id)
            ->value('location_ciphertext'));
    }

    public function test_circle_responders_and_trusted_escalation_remain_separate(): void
    {
        [$owner,$d,$token]=$this->principal();
        [$circlePerson,$dc,$circleToken]=$this->principal();
        [$trusted,$dt,$trustedToken]=$this->principal();
        $this->trusted($owner->id,$trusted->id,$token,$trustedToken);
        $circleId=(string)Str::uuid();
        DB::table('circles')->insert([
            'id'=>$circleId,'owner_id'=>$owner->id,'name'=>'Private test circle',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach([$owner->id,$circlePerson->id] as $who){
            DB::table('circle_members')->insert([
                'circle_id'=>$circleId,'user_id'=>$who,'role'=>'member',
                'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $body=$this->payload(['circle_id'=>$circleId]);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$body)->assertCreated();
        $incident=DB::table('sos_incidents')->where('client_id',$body['client_id'])->first();
        $this->assertDatabaseHas('sos_recipients',[
            'incident_id'=>$incident->id,'user_id'=>$circlePerson->id,'source'=>'circle',
        ]);
        $this->assertDatabaseMissing('sos_recipients',[
            'incident_id'=>$incident->id,'user_id'=>$trusted->id,
        ]);
        DB::table('sos_incidents')->where('id',$incident->id)
            ->update(['escalation_due_at'=>now()->subSecond()]);
        $stats=app(SosDeliveryWorker::class)->run(20);
        $this->assertSame(1,$stats['escalated']);
        $this->assertFalse($stats['push_available']);
        $this->assertDatabaseHas('sos_recipients',[
            'incident_id'=>$incident->id,'user_id'=>$trusted->id,'source'=>'trusted',
        ]);
    }

    public function test_admin_is_separate_permission_scoped_and_no_private_location_in_views(): void
    {
        [$owner,$d,$token]=$this->principal();
        $this->get('/admin/safety')->assertRedirect(route('orbit.admin.login'));
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$this->payload())
            ->assertCreated();
        $this->be($this->admin(['safety.read']),'admin');
        $this->get('/admin/safety')->assertOk()
            ->assertSee('SOS & Safety Management')
            ->assertSee('Incident Directory')->assertSee('Delivery Operations');
        $this->get('/admin/safety/incidents')->assertOk();
        $this->get('/admin/safety/audits')->assertForbidden();
        $this->post('/admin/safety/settings',[
            'key'=>'escalation_enabled','value'=>'0',
            'reason'=>'Reviewed emergency escalation operations','confirm'=>'1',
        ])->assertForbidden();
        $this->be($this->admin(['safety.read','safety.manage','safety.audit']),'admin');
        $this->post('/admin/safety/settings',[
            'key'=>'escalation_after_seconds','value'=>'120',
            'reason'=>'Verified local escalation settings','confirm'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseHas('sos_admin_audits',['action'=>'sos.control_updated']);
        $this->get('/admin/safety/audits')->assertOk();
        $this->assertSame(120,(int)DB::table('sos_controls')
            ->where('key','escalation_after_seconds')->value('value'));
    }
    public function test_empty_circle_falls_back_to_trusted_contact_without_delay(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$trusted,$trustedDevice,$trustedToken]=$this->principal();
        $this->trusted($owner->id,$trusted->id,$token,$trustedToken);
        $circle=(string)Str::uuid();
        DB::table('circles')->insert([
            'id'=>$circle,'owner_id'=>$owner->id,'name'=>'Owner-only safety circle',
            'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('circle_members')->insert([
            'circle_id'=>$circle,'user_id'=>$owner->id,'role'=>'member',
            'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $payload=$this->payload(['circle_id'=>$circle]);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$payload)
            ->assertCreated()->assertJsonPath(
                'data.in_app_available_for_eligible_responders',true);
        $id=DB::table('sos_incidents')->where('client_id',$payload['client_id'])->value('id');
        $this->assertDatabaseHas('sos_recipients',[
            'incident_id'=>$id,'user_id'=>$trusted->id,'source'=>'trusted',
        ]);
        $this->assertNull(DB::table('sos_incidents')->where('id',$id)
            ->value('escalation_due_at'));
    }

    public function test_sos_erasure_guard_blocks_incident_evidence_but_not_contact_only_accounts(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$recipient,$deviceTwo,$recipientToken]=$this->principal();
        $this->trusted($owner->id,$recipient->id,$token,$recipientToken);

        $guard=app(\App\Modules\Safety\Services\SosErasurePolicy::class);
        // Requests/accepted contacts alone do not force an indefinite hold.
        $guard->assertErasable($owner->id);
        $guard->assertErasable($recipient->id);

        $payload=$this->payload();
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$payload)->assertCreated();
        $id=DB::table('sos_incidents')->where('client_id',$payload['client_id'])->value('id');

        $operator=$this->admin(['users.delete']);
        try {
            app(\App\Modules\Identity\Services\DeleteOrbitUser::class)
                ->execute($owner->id,$operator,'Account erasure audit test',null,null);
            $this->fail('Direct account deletion must be blocked while SOS evidence exists.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SOS incident evidence', $e->getMessage());
        }
        $this->assertDatabaseHas('orbit_users',['id'=>$owner->id]);
        $this->assertDatabaseHas('sos_incidents',['id'=>$id,'initiator_id'=>$owner->id]);

        try {
            $guard->assertErasable($recipient->id);
            $this->fail('A responder cannot silently erase SOS incident evidence.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SOS incident evidence',$e->getMessage());
        }
    }

    public function test_stage4_responders_receive_authorized_initiator_name_and_owner_sees_contact_name(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$responder,$otherDevice,$responderToken]=$this->principal();
        $owner->update(['display_name'=>'Verified Owner']);
        $responder->update(['display_name'=>'Verified Responder']);
        $this->trusted($owner->id,$responder->id,$token,$responderToken);
        $body=$this->payload();
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$body)
            ->assertCreated()->assertJsonPath('data.initiator_name','Verified Owner')
            ->assertJsonPath('data.recipients.0.display_name','Verified Responder');
        $incident=DB::table('sos_incidents')->where('client_id',$body['client_id'])->first();
        $this->withToken($responderToken)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertOk()->assertJsonPath('data.initiator_name','Verified Owner')
            ->assertJsonMissingPath('data.recipients');
        [$outsider,$third,$otherToken]=$this->principal();
        $this->withToken($otherToken)->getJson('/api/v1/sos/incidents/'.$incident->id)
            ->assertNotFound();
    }

    public function test_stage4_expired_encrypted_location_is_purged_even_when_sos_disabled(): void
    {
        [$owner,$device,$token]=$this->principal();
        $request=$this->payload([
            'share_location'=>true,'latitude'=>30.2,'longitude'=>71.4,
            'location_captured_at'=>now()->toIso8601String(),
        ]);
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$request)
            ->assertCreated();
        $id=DB::table('sos_incidents')->where('client_id',$request['client_id'])->value('id');
        DB::table('sos_incidents')->where('id',$id)
            ->update(['location_expires_at'=>now()->subSecond()]);
        config()->set('orbit_sos.enabled',false);
        $result=app(SosDeliveryWorker::class)->run(20);
        $this->assertSame(1,$result['expired_locations_removed']);
        $this->assertNull(DB::table('sos_incidents')->where('id',$id)
            ->value('location_ciphertext'));
        $this->assertNull(DB::table('sos_incidents')->where('id',$id)
            ->value('location_expires_at'));
        $this->assertDatabaseHas('sos_audit_events',[
            'incident_id'=>$id,'action'=>'sos.location_expired',
        ]);
    }

    public function test_stage4_late_push_result_cannot_reopen_suppressed_or_revoked_job(): void
    {
        [$owner,$device,$token]=$this->principal();
        [$contact,$contactDevice,$contactToken]=$this->principal();
        $this->trusted($owner->id,$contact->id,$token,$contactToken);
        $body=$this->payload();
        $this->withToken($token)->postJson('/api/v1/sos/incidents',$body)
            ->assertCreated();
        $incident=DB::table('sos_incidents')->where('client_id',$body['client_id'])->first();
        $recipient=DB::table('sos_recipients')->where('incident_id',$incident->id)->first();
        $id=(string)Str::uuid();
        DB::table('sos_delivery_jobs')->insert([
            'id'=>$id,'recipient_id'=>$recipient->id,'device_id'=>$contactDevice->id,
            'state'=>'processing','attempts'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->withToken($token)->postJson('/api/v1/sos/incidents/'.$incident->id.'/cancel',
            ['revision'=>1])->assertOk();
        $finish=new \ReflectionMethod(SosDeliveryWorker::class,'finish');
        $accepted=$finish->invoke(app(SosDeliveryWorker::class),$id,'accepted',null,null);
        $this->assertFalse($accepted);
        $this->assertDatabaseHas('sos_delivery_jobs',[
            'id'=>$id,'state'=>'suppressed','provider_accepted_at'=>null,
        ]);
        $this->assertDatabaseMissing('sos_dispatch_attempts',[
            'job_id'=>$id,'state'=>'accepted',
        ]);
    }

}
