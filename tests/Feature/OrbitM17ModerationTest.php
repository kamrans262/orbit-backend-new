<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Identity\Services\TokenService;
use App\Modules\Moderation\Services\ModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitM17ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp():void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        config()->set('orbit_moderation.intake_enabled',true);
        config()->set('orbit_moderation.account_report_limit_per_hour',20);
    }

    private function principal():array
    {
        $user=OrbitUser::create([
            'username'=>'m17_'.Str::random(12),'display_name'=>'Moderation member','status'=>'active',
        ]);
        $device=OrbitDevice::create([
            'user_id'=>$user->id,'device_public_id'=>(string)Str::uuid(),
            'name'=>'Moderation test','platform'=>'android',
            'public_key'=>base64_encode(random_bytes(32)),
            'trust_state'=>'trusted','attestation_state'=>'verified','approved_at'=>now(),
        ]);
        $token=app(TokenService::class)->issue($user,$device,null)['access_token'];
        return [$user,$device,$token];
    }

    private function admin(array $permissions):AdminUser
    {
        $role=AdminRole::create([
            'name'=>'M17 '.Str::uuid(),'permissions'=>$permissions,
        ]);
        $admin=AdminUser::create([
            'name'=>'Moderation reviewer','email'=>Str::uuid().'@example.test',
            'password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active',
        ]);
        $admin->roles()->attach($role->id);
        return $admin;
    }

    private function report(string $token,string $target,?string $clientId=null):array
    {
        $payload=['client_id'=>$clientId??(string)Str::uuid(),
            'target_kind'=>'account','target_id'=>$target,
            'reason'=>'harassment','additional_details'=>'Personal and private account report.',
        ];
        $this->withToken($token)->postJson('/api/v1/moderation/reports',$payload)
            ->assertCreated()->assertJsonPath('data.status','received');
        return $payload;
    }

    public function test_account_report_is_idempotent_private_encrypted_and_links_one_case():void
    {
        [$reporter,$device,$token]=$this->principal();
        [$target,$otherDevice,$otherToken]=$this->principal();
        [$outsider,$three,$outsiderToken]=$this->principal();
        $body=$this->report($token,$target->id);
        $this->assertDatabaseCount('moderation_reports',1);
        $this->assertDatabaseCount('moderation_cases',1);
        $this->assertDatabaseCount('moderation_case_sources',1);
        $row=DB::table('moderation_reports')->first();
        $this->assertNotSame($body['additional_details'],$row->details_enc);
        $this->assertSame($body['additional_details'],Crypt::decryptString($row->details_enc));
        $response=$this->withToken($token)->postJson('/api/v1/moderation/reports',$body)
            ->assertOk()->assertJsonPath('data.id',$row->id);
        $response->assertDontSee($reporter->id)->assertDontSee($body['additional_details']);
        $this->assertDatabaseCount('moderation_cases',1);
        $this->withToken($outsiderToken)->getJson('/api/v1/moderation/reports/'.$row->id)
            ->assertNotFound();
        $this->withToken($otherToken)->getJson('/api/v1/me/moderation/reports')
            ->assertOk()->assertExactJson(['data'=>[]]);
        $this->withToken($token)->getJson('/api/v1/moderation/reports/'.$row->id)
            ->assertOk()->assertJsonPath('data.status','received');
        $this->withToken($token)->postJson('/api/v1/moderation/reports',
            [...$body,'reason'=>'spam'])->assertStatus(409);
        $this->withToken($token)->postJson('/api/v1/moderation/reports',
            [...$body,'client_id'=>(string)Str::uuid(),'target_id'=>$reporter->id])
            ->assertUnprocessable();
        $this->assertDatabaseCount('moderation_case_events',1);
    }

    public function test_block_denies_new_account_report_without_target_enumeration():void
    {
        [$a,$da,$ta]=$this->principal();
        [$b,$db,$tb]=$this->principal();
        DB::table('blocks')->insert([
            'blocker_id'=>$b->id,'blocked_id'=>$a->id,'created_at'=>now(),
        ]);
        $this->withToken($ta)->postJson('/api/v1/moderation/reports',[
            'client_id'=>(string)Str::uuid(),
            'target_kind'=>'account','target_id'=>$b->id,'reason'=>'spam',
        ])->assertNotFound();
        $this->assertDatabaseCount('moderation_reports',0);
        $this->withToken($tb)->getJson('/api/v1/moderation/capabilities')->assertOk();
    }

    public function test_source_sync_indexes_m13_reporting_without_copying_evidence():void
    {
        [$reporter,$d,$t]=$this->principal();
        [$target]=$this->principal();
        $thread=(string)Str::uuid();
        $report=(string)Str::uuid();
        DB::table('messaging_conversations')->insert([
            'id'=>$thread,'kind'=>'direct','group_epoch'=>1,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $private='Only selected reporter-supplied content can be read with audit';
        DB::table('messaging_reports')->insert([
            'id'=>$report,'conversation_id'=>$thread,
            'message_id'=>null,'reporter_id'=>$reporter->id,
            'reason'=>'spam','status'=>'pending',
            'evidence_shared'=>true,'evidence_enc'=>Crypt::encryptString($private),
            'created_at'=>now(),
        ]);
        $service=app(ModerationService::class);
        $first=$service->syncSources(20);
        $this->assertSame(1,$first['messaging']);
        $this->assertSame(0,$service->syncSources(20)['messaging']);
        $this->assertDatabaseCount('moderation_cases',1);
        $this->assertDatabaseHas('moderation_case_sources',[
            'source_kind'=>'messaging','source_report_id'=>$report,
        ]);
        $this->assertSame(0,DB::table('moderation_cases')
            ->where('source_kind','messaging')->where('source_report_id',$report)
            ->where('status','actioned')->count());
        $case=DB::table('moderation_cases')->first();
        $this->assertFalse(property_exists($case,'evidence_enc'));
        $this->assertFalse(property_exists($case,'reporter_id'));
        $read=$this->admin(['moderation.read']);
        $this->actingAs($read,'admin')->get('/admin/moderation/cases')
            ->assertOk()->assertDontSee($private);
        $this->actingAs($read,'admin')->post('/admin/moderation/cases/'.$case->id.'/evidence',[
            'ticket'=>'Authorized-reviewed-case','confirm'=>1,
        ])->assertForbidden();
        $reviewer=$this->admin([
            'moderation.read','moderation.evidence','messages.moderate',
        ]);
        $this->actingAs($reviewer,'admin')->post(
            '/admin/moderation/cases/'.$case->id.'/evidence',[
                'ticket'=>'Authorized-reviewed-case','confirm'=>1,
            ])->assertOk()->assertSee($private);
        $this->assertDatabaseCount('moderation_evidence_access',1);
        $this->assertDatabaseHas('messaging_report_audits',[
            'report_id'=>$report,'action'=>'view_user_evidence',
        ]);
    }

    public function test_review_state_is_revision_safe_and_unreviewed_sanctions_are_blocked():void
    {
        [$reporter,$d,$token]=$this->principal();
        [$target]=$this->principal();
        $this->report($token,$target->id);
        $case=DB::table('moderation_cases')->first();
        $read=$this->admin(['moderation.read']);
        $this->actingAs($read,'admin')->get('/admin/moderation')
            ->assertOk()->assertSee('Moderation & Reporting')
            ->assertDontSee('Personal and private account report.');
        $this->actingAs($read,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>1,'action'=>'triage','reason'=>'other','confirm'=>1,
        ])->assertForbidden();
        $ops=$this->admin(['moderation.read','moderation.triage','moderation.decide']);
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>1,'action'=>'triage','reason'=>'abuse','confirm'=>1,
        ])->assertRedirect();
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>1,'action'=>'investigate','reason'=>'abuse','confirm'=>1,
        ])->assertStatus(409);
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>2,'action'=>'investigate','reason'=>'abuse','confirm'=>1,
        ])->assertRedirect();
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/enforce',[
            'revision'=>3,'scope'=>'messaging_send','reason'=>'abuse',
            'duration'=>'24h','confirm'=>1,
        ])->assertForbidden();
        $this->assertDatabaseCount('moderation_actions',0);
        $this->assertDatabaseHas('moderation_cases',['id'=>$case->id,
            'status'=>'investigating','revision'=>3]);
        $this->assertDatabaseCount('moderation_case_events',3);
    }

    public function test_verified_sanction_can_be_appealed_only_by_subject_and_an_independent_reviewer():void
    {
        [$reporter,$d,$token]=$this->principal();
        [$target,$dt,$targetToken]=$this->principal();
        [$outsider,$do,$outsiderToken]=$this->principal();
        $this->report($token,$target->id);
        $case=DB::table('moderation_cases')->first();
        $ops=$this->admin(['moderation.read','moderation.triage',
            'moderation.decide','moderation.manage','moderation.appeals']);
        $this->actingAs($ops,'admin')->post('/admin/moderation/settings',[
            'key'=>'enforcement_enabled','value'=>'1',
            'release_confirmation'=>'ENABLE','reason'=>'Approved controlled test rollout',
            'confirm'=>1,
        ])->assertRedirect();
        $this->assertDatabaseHas('moderation_control_audits',[
            'admin_id'=>$ops->id,'key'=>'enforcement_enabled','new_value'=>'1',
        ]);
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>1,'action'=>'triage','reason'=>'abuse','confirm'=>1,
        ])->assertRedirect();
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/transition',[
            'revision'=>2,'action'=>'investigate','reason'=>'abuse','confirm'=>1,
        ])->assertRedirect();
        $this->actingAs($ops,'admin')->post('/admin/moderation/cases/'.$case->id.'/enforce',[
            'revision'=>3,'scope'=>'messaging_send','reason'=>'abuse',
            'duration'=>'24h','confirm'=>1,
        ])->assertRedirect();
        $action=DB::table('moderation_actions')->first();
        $this->assertDatabaseHas('messaging_send_restrictions',[
            'user_id'=>$target->id,'reason'=>'abuse','updated_by'=>$ops->id,
        ]);
        $payload=['client_id'=>(string)Str::uuid(),'statement'=>'Independent appeal statement'];
        $this->withToken($outsiderToken)->postJson(
            '/api/v1/moderation/actions/'.$action->id.'/appeals',$payload)
            ->assertNotFound();
        $this->withToken($targetToken)->getJson('/api/v1/me/moderation/actions')
            ->assertOk()->assertJsonPath('data.0.id',$action->id);
        $this->withToken($targetToken)->postJson(
            '/api/v1/moderation/actions/'.$action->id.'/appeals',$payload)
            ->assertCreated()->assertJsonPath('data.status','pending');
        $this->withToken($targetToken)->postJson(
            '/api/v1/moderation/actions/'.$action->id.'/appeals',$payload)->assertOk();
        $this->assertDatabaseCount('moderation_appeals',1);
        $appeal=DB::table('moderation_appeals')->first();
        $this->assertNotEquals($payload['statement'],$appeal->statement_enc);
        $this->actingAs($ops,'admin')->post('/admin/moderation/appeals/'.$appeal->id.'/decision',[
            'revision'=>1,'decision'=>'reversed','reason'=>'insufficient_evidence',
            'confirm'=>1,
        ])->assertForbidden();
        $independent=$this->admin(['moderation.read','moderation.appeals']);
        $this->actingAs($independent,'admin')->post(
            '/admin/moderation/appeals/'.$appeal->id.'/decision',[
                'revision'=>1,'decision'=>'reversed',
                'reason'=>'insufficient_evidence','confirm'=>1,
            ])->assertRedirect();
        $this->assertDatabaseHas('moderation_actions',[
            'id'=>$action->id,'status'=>'reversed',
        ]);
        $this->assertDatabaseMissing('messaging_send_restrictions',
            ['user_id'=>$target->id]);
        $this->withToken($targetToken)->getJson('/api/v1/me/moderation/appeals')
            ->assertOk()->assertJsonPath('data.0.status','reversed');
        $this->withToken($outsiderToken)->getJson('/api/v1/me/moderation/appeals')
            ->assertOk()->assertExactJson(['data'=>[]]);
    }

    public function test_case_notes_are_encrypted_reviewer_only_and_revision_guarded():void
    {
        [$reporter,$d,$token]=$this->principal();
        [$target]=$this->principal();
        $this->report($token,$target->id);
        $case=DB::table('moderation_cases')->first();
        $reader=$this->admin(['moderation.read']);
        $this->actingAs($reader,'admin')->get('/admin/moderation/cases/'.$case->id)
            ->assertOk()->assertDontSee('Investigation notes');
        $this->actingAs($reader,'admin')->post(
            '/admin/moderation/cases/'.$case->id.'/notes',[
                'revision'=>1,'note'=>'Confidential review assessment','confirm'=>1,
            ])->assertForbidden();
        $reviewer=$this->admin(['moderation.read','moderation.triage']);
        $this->actingAs($reviewer,'admin')->post(
            '/admin/moderation/cases/'.$case->id.'/notes',[
                'revision'=>1,'note'=>'Confidential review assessment','confirm'=>1,
            ])->assertRedirect();
        $row=DB::table('moderation_case_notes')->first();
        $this->assertNotSame('Confidential review assessment',$row->note_enc);
        $this->assertSame('Confidential review assessment',
            Crypt::decryptString($row->note_enc));
        $this->actingAs($reviewer,'admin')->post(
            '/admin/moderation/cases/'.$case->id.'/notes',[
                'revision'=>1,'note'=>'Confidential new stale note','confirm'=>1,
            ])->assertStatus(409);
        $this->actingAs($reader,'admin')->get('/admin/moderation/cases/'.$case->id)
            ->assertOk()->assertDontSee('Confidential review assessment');
        $this->actingAs($reviewer,'admin')->get('/admin/moderation/cases/'.$case->id)
            ->assertOk()->assertSee('Confidential review assessment');
    }

    public function test_admin_sidebar_and_controls_never_grant_evidence_access():void
    {
        $admin=$this->admin(['moderation.read','moderation.audit']);
        $this->actingAs($admin,'admin')->get('/admin/moderation')
            ->assertOk()->assertSee('Report Queue')->assertSee('Appeals')
            ->assertSee('Moderation & Reporting');
        $this->actingAs($admin,'admin')->get('/admin/moderation/audits')
            ->assertOk();
        $this->actingAs($admin,'admin')->post('/admin/moderation/sync',[
            'reason'=>'Review existing source references','confirm'=>1,
        ])->assertForbidden();
        $this->actingAs($admin,'admin')->post('/admin/moderation/settings',[
            'key'=>'enforcement_enabled','value'=>'1','confirm'=>1,
            'reason'=>'Unapproved setting change',
            'release_confirmation'=>'ENABLE',
        ])->assertForbidden();
        $this->assertDatabaseHas('moderation_controls',[
            'key'=>'enforcement_enabled','value'=>'0',
        ]);
    }
}
