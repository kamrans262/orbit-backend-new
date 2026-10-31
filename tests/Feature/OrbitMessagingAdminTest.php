<?php
namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AdminUser;
use App\Models\OrbitUser;
use App\Modules\Config\Services\Totp;
use App\Modules\Messaging\Services\MessagingAdminControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrbitMessagingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions,string $name): AdminUser
    {
        $role=AdminRole::create([
            'name'=>'Messaging '.$name,
            'permissions'=>$permissions,
        ]);
        $actor=AdminUser::create([
            'name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'StrongPassword123!',
            'totp_secret'=>(new Totp())->generateSecret(),'status'=>'active',
        ]);
        $actor->roles()->attach($role->id);
        return $actor;
    }

    public function test_operations_controls_are_bounded_live_and_audited(): void
    {
        $actor=$this->admin(['config.manage'],'Operator');
        $controls=app(MessagingAdminControls::class);
        $this->assertTrue($controls->provisioned());
        $this->actingAs($actor,'admin')->get('/admin/messaging')
            ->assertOk()->assertSee('Messaging Management')
            ->assertDontSee('ciphertext_b64')->assertDontSee('private_key');
        $this->actingAs($actor,'admin')->get('/admin/messaging/settings')->assertOk();
        $this->actingAs($actor,'admin')->post('/admin/messaging/settings',[
            'retention_days'=>14,'send_rate_per_minute'=>12,
            'typing_http_enabled'=>0,
        ])->assertRedirect('/admin/messaging/settings');
        $this->assertSame(14,$controls->retentionDays());
        $this->assertSame(12,$controls->sendLimit());
        $this->assertFalse($controls->typingEnabled());
        $this->assertDatabaseHas('messaging_admin_controls',[
            'key'=>'retention_days','value'=>'14',
        ]);
        $this->assertDatabaseHas('messaging_admin_activity',[
            'admin_user_id'=>$actor->id,'action'=>'settings.updated',
        ]);
        $this->actingAs($actor,'admin')->post('/admin/messaging/settings',[
            'retention_days'=>31,'send_rate_per_minute'=>12,
            'typing_http_enabled'=>0,
        ])->assertSessionHasErrors('retention_days');
        $this->assertSame(14,$controls->retentionDays());
        $this->actingAs($actor,'admin')->post('/admin/messaging/maintenance/prune',[
            'confirm'=>'PRUNE',
        ])->assertRedirect('/admin/messaging/settings');
        $this->assertDatabaseHas('messaging_admin_activity',[
            'action'=>'retention.pruned',
        ]);
    }

    public function test_report_queue_is_rbac_scoped_and_case_notes_are_encrypted(): void
    {
        $reportId=(string)Str::uuid();
        $threadId=(string)Str::uuid();
        $reporter=OrbitUser::create([
            'display_name'=>'Reporting Member',
            'username'=>'member_'.Str::random(8),'status'=>'active',
        ]);
        DB::table('messaging_conversations')->insert([
            'id'=>$threadId,'kind'=>'direct',
            'group_epoch'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('messaging_reports')->insert([
            'id'=>$reportId,'conversation_id'=>$threadId,
            'message_id'=>null,'reporter_id'=>$reporter->id,
            'reason'=>'spam','status'=>'pending',
            'evidence_shared'=>true,
            'evidence_enc'=>Crypt::encryptString('Voluntarily submitted evidence'),
            'created_at'=>now(),
        ]);
        $ops=$this->admin(['config.manage'],'Operations');
        $this->actingAs($ops,'admin')->get('/admin/messaging/reports')
            ->assertForbidden();
        $moderator=$this->admin(['messages.moderate'],'Moderator');
        $this->actingAs($moderator,'admin')->get('/admin/messaging')
            ->assertForbidden();
        $this->actingAs($moderator,'admin')->get(
            '/admin/messaging/reports?status=pending')->assertOk()
            ->assertSee($reportId)->assertDontSee('Voluntarily submitted evidence');
        $this->actingAs($moderator,'admin')->get(
            '/admin/messaging/reports/'.$reportId)->assertOk()
            ->assertDontSee('Voluntarily submitted evidence');
        $this->actingAs($moderator,'admin')->post(
            '/admin/messaging/reports/'.$reportId.'/action',[
                'action'=>'escalated',
                'note'=>'Restricted case follow-up needed',
            ])->assertRedirect('/admin/messaging/reports/'.$reportId);
        $case=DB::table('messaging_reports')->where('id',$reportId)->first();
        $this->assertSame('escalated',$case->status);
        $this->assertNotSame('Restricted case follow-up needed',
            $case->moderator_note_enc);
        $this->assertSame('Restricted case follow-up needed',
            Crypt::decryptString($case->moderator_note_enc));
        $this->assertDatabaseHas('messaging_report_audits',[
            'report_id'=>$reportId,'action'=>'escalated',
        ]);
        $this->actingAs($moderator,'admin')->post(
            '/admin/messaging/reports/'.$reportId.'/evidence',[
                'confirm'=>'1',
            ])->assertOk()->assertSee('Voluntarily submitted evidence');
        $this->assertDatabaseHas('messaging_report_audits',[
            'report_id'=>$reportId,'action'=>'view_user_evidence',
        ]);
    }
}
