<?php
namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MessagingAdminControls;
use App\Modules\Messaging\Services\MessagingConversationService;
use App\Modules\Messaging\Services\MessagingEnvelopeService;
use App\Modules\Messaging\Services\MessagingSenderKeyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminMessagingController extends Controller
{
    /**
     * Aggregate monitoring only. No admin endpoint returns unreported chat
     * text, raw ciphertext envelopes, keys or private participant lists.
     */
    public function index(MessagingAdminControls $controls): Response
    {
        $ready=Schema::hasTable('messaging_conversations')
            && Schema::hasTable('messaging_device_keys')
            && Schema::hasTable('messaging_envelopes')
            && Schema::hasTable('messaging_messages')
            && Schema::hasTable('messaging_reports');
        $stats=null;
        if($ready){
            $since=now()->subDay();
            $stats=[
                'direct_conversations'=>DB::table('messaging_conversations')
                    ->where('kind','direct')->count(),
                'circle_conversations'=>DB::table('messaging_conversations')
                    ->where('kind','circle')->count(),
                'trusted_registered_devices'=>DB::table('messaging_device_keys as k')
                    ->join('devices as d','d.id','=','k.device_id')
                    ->where('d.trust_state','trusted')->whereNull('d.revoked_at')->count(),
                'accepted_messages_24h'=>DB::table('messaging_messages')
                    ->where('created_at','>=',$since)->count(),
                'message_kinds_24h'=>DB::table('messaging_messages')
                    ->where('created_at','>=',$since)->select('kind')
                    ->selectRaw('COUNT(*) as total')->groupBy('kind')
                    ->pluck('total','kind')->all(),
                'pending_envelopes'=>DB::table('messaging_envelopes as e')
                    ->join('messaging_messages as m','m.id','=','e.message_id')
                    ->whereNull('e.delivered_at')->where('m.expires_at','>',now())->count(),
                'delivered_envelopes_24h'=>DB::table('messaging_envelopes')
                    ->whereNotNull('delivered_at')->where('delivered_at','>=',$since)->count(),
                'read_envelopes_24h'=>DB::table('messaging_envelopes')
                    ->whereNotNull('read_at')->where('read_at','>=',$since)->count(),
                'aggregate_reports_pending'=>DB::table('messaging_reports')
                    ->where('status','pending')->count(),
                'aggregate_reports_escalated'=>DB::table('messaging_reports')
                    ->where('status','escalated')->count(),
                'rejections_24h'=>Schema::hasTable('messaging_operational_events')
                    ?DB::table('messaging_operational_events')->where('status_code','>=',400)
                        ->where('created_at','>=',$since)->count():null,
            ];
        }
        return response()->view('orbit-admin.messaging',[
            'ready'=>$ready,'stats'=>$stats,
            'backendEnabled'=>config('orbit_messaging.enabled')===true,
            'circleEnabled'=>config('orbit_circle.consumer_enabled.message')===true,
            'clientVerified'=>config('orbit_messaging.e2ee_client_verified')===true,
            'retentionDays'=>$controls->retentionDays(),
            'controlsReady'=>$controls->provisioned(),
            'sendLimit'=>$controls->sendLimit(),
            'typingEnabled'=>$controls->typingEnabled(),
        ])->header('Cache-Control','private, no-store');
    }

    public function settings(MessagingAdminControls $controls): Response
    {
        $audits=$controls->provisioned()
            ?DB::table('messaging_admin_activity as audit')
                ->leftJoin('admin_users as actor','actor.id','=','audit.admin_user_id')
                ->orderByDesc('audit.id')->limit(35)
                ->get(['audit.action','audit.details','audit.created_at',
                    'actor.name as actor_name'])
            :collect();
        return response()->view('orbit-admin.messaging-settings',[
            'ready'=>$controls->provisioned(),
            'values'=>$controls->values(),
            'audits'=>$audits,
            'verified'=>config('orbit_messaging.e2ee_client_verified')===true,
            'websocket'=>config('orbit_messaging.realtime_enabled')===true,
            'push'=>config('orbit_messaging.push_enabled')===true,
        ])->header('Cache-Control','private, no-store');
    }

    public function saveSettings(Request $r,MessagingAdminControls $controls): RedirectResponse
    {
        $input=$r->validate([
            'retention_days'=>['required','integer','between:1,30'],
            'send_rate_per_minute'=>['required','integer','between:5,120'],
            'typing_http_enabled'=>['required','boolean'],
        ]);
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        $controls->update($input,$actor->id);
        return redirect()->route('orbit.admin.messaging.settings')
            ->with('status','Messaging operational settings saved and audited.');
    }

    /**
     * Idempotent bounded maintenance: only already-expired envelopes and
     * sender-key packets are deleted. Never operate on readable messages.
     */
    public function prune(Request $r,MessagingAdminControls $controls): RedirectResponse
    {
        $r->validate(['confirm'=>['required','in:PRUNE']]);
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        abort_unless(app(MessagingConversationService::class)->ready()
            && $controls->provisioned(),503,'Messaging schema required.');
        $expired=0;
        $senderKeys=0;
        for($i=0;$i<3;$i++){
            $number=app(MessagingEnvelopeService::class)->prune();
            $expired+=$number;
            if($number<500)break;
        }
        for($i=0;$i<3;$i++){
            $number=app(MessagingSenderKeyService::class)->prune();
            $senderKeys+=$number;
            if($number<500)break;
        }
        $controls->audit($actor->id,'retention.pruned',[
            'expired_encrypted_messages'=>$expired,
            'expired_sender_key_packets'=>$senderKeys,
        ]);
        return redirect()->route('orbit.admin.messaging.settings')
            ->with('status','Expired ciphertext maintenance completed and audited.');
    }

    public function restrictions(Request $r,
        \App\Modules\Messaging\Services\MessagingSendRestrictionService $policy): Response
    {
        abort_unless($policy->provisioned(),503,
            'Messaging moderation migration is required.');
        $input=$r->validate([
            'query'=>['sometimes','nullable','string','max:60',
                'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);
        $search=trim((string)($input['query']??''));
        $query=DB::table('orbit_users as u')
            ->leftJoin('messaging_send_restrictions as restriction',
                'restriction.user_id','=','u.id')
            ->select('u.id','u.username','u.display_name','u.status',
                'restriction.reason','restriction.ends_at');
        if($search!==''){
            $query->where(function($q)use($search):void {
                $q->where('u.username','like',$search.'%');
                if(Str::isUuid($search))$q->orWhere('u.id',$search);
            });
        }else{
            // Default to sanctioned accounts; do not enumerate ordinary
            // private-chat participants in a moderation operations view.
            $query->whereNotNull('restriction.user_id');
        }
        $users=$query->orderBy('u.username')->paginate(20)->withQueryString();
        $audit=DB::table('messaging_send_restriction_audits as a')
            ->leftJoin('admin_users as actor',
                'actor.id','=','a.admin_user_id')
            ->orderByDesc('a.id')->limit(25)
            ->get(['a.action','a.user_id','a.reason','a.ends_at',
                'a.created_at','actor.name as actor_name']);
        return response()->view('orbit-admin.messaging-restrictions',[
            'users'=>$users,'search'=>$search,'audit'=>$audit,
        ])->header('Cache-Control','private, no-store');
    }

    public function updateRestriction(Request $r,string $user,
        \App\Modules\Messaging\Services\MessagingSendRestrictionService $policy): RedirectResponse
    {
        $input=$r->validate([
            'action'=>['required',Rule::in(['suspend','restore'])],
            'duration'=>['sometimes','required_if:action,suspend',
                Rule::in(['1h','24h','7d','30d','indefinite'])],
            'reason'=>['sometimes','required_if:action,suspend',
                Rule::in(['spam','harassment','fraud','abuse','other'])],
        ]);
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        $policy->update($user,$actor->id,$input);
        return redirect()->route('orbit.admin.messaging.restrictions',[
            'query'=>$user])->with('status',
            'Messaging access updated; the action was audited.');
    }

    public function reports(Request $r): Response
    {
        abort_unless(Schema::hasTable('messaging_reports'),503,'Messaging schema required.');
        $input=$r->validate([
            'status'=>['sometimes','nullable',Rule::in(['pending','escalated','reviewed','dismissed'])],
            'reason'=>['sometimes','nullable','string','max:32','regex:/^[a-z0-9_-]+$/i'],
            'reference'=>['sometimes','nullable','uuid'],
        ]);
        $query=DB::table('messaging_reports');
        if(!empty($input['status']))$query->where('status',$input['status']);
        if(!empty($input['reason']))$query->where('reason',$input['reason']);
        if(!empty($input['reference']))$query->where('id',$input['reference']);
        $rows=$query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25,['id','reason','status','evidence_shared',
                'created_at','reviewed_at'])->withQueryString();
        $counts=DB::table('messaging_reports')->selectRaw(
            'status, count(*) as total')->groupBy('status')->pluck('total','status');
        return response()->view('orbit-admin.messaging-reports',[
            'reports'=>$rows,'filters'=>$input,'counts'=>$counts,
        ])->header('Cache-Control','private, no-store');
    }

    public function report(string $report): Response
    {
        $row=DB::table('messaging_reports')->where('id',$report)->first([
            'id','reason','status','evidence_shared','moderator_note_enc',
            'subject_user_id','created_at','reviewed_at','reviewed_by',
        ]);
        abort_unless($row,404,'Report unavailable.');
        $history=DB::table('messaging_report_audits as audit')
            ->leftJoin('admin_users as actor','actor.id','=','audit.admin_user_id')
            ->where('audit.report_id',$report)
            ->orderByDesc('audit.created_at')->limit(35)
            ->get(['audit.action','audit.created_at',
                'actor.name as actor_name']);
        return response()->view('orbit-admin.messaging-report-detail',[
            'report'=>$row,'history'=>$history,
            'note'=>$row->moderator_note_enc
                ?Crypt::decryptString($row->moderator_note_enc):null,
        ])->header('Cache-Control','private, no-store');
    }

    public function evidence(Request $r,string $report): Response
    {
        $r->validate(['confirm'=>['required','accepted']]);
        $row=DB::table('messaging_reports')->where('id',$report)->first();
        abort_unless($row && (bool)$row->evidence_shared && $row->evidence_enc,
            404,'No user-shared report evidence.');
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        DB::table('messaging_report_audits')->insert([
            'id'=>(string)Str::uuid(),'report_id'=>$report,
            'admin_user_id'=>$actor->id,'action'=>'view_user_evidence',
            'created_at'=>now(),
        ]);
        return response()->view('orbit-admin.messaging-evidence',[
            'report'=>$report,'evidence'=>Crypt::decryptString($row->evidence_enc),
            'reason'=>$row->reason,
        ])->header('Cache-Control','private, no-store')
          ->header('Referrer-Policy','no-referrer');
    }

    public function action(Request $r,string $report): RedirectResponse
    {
        $input=$r->validate([
            'action'=>['required',Rule::in(['reviewed','dismissed','escalated'])],
            'note'=>['sometimes','nullable','string','max:1000'],
        ]);
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        DB::transaction(function()use($report,$input,$actor):void{
            $row=DB::table('messaging_reports')->where('id',$report)
                ->lockForUpdate()->first();
            abort_unless($row,404,'Report unavailable.');
            $data=[
                'status'=>$input['action'],'reviewed_at'=>now(),
                'reviewed_by'=>$actor->id,
            ];
            if(isset($input['note']) && trim($input['note'])!==''){
                $data['moderator_note_enc']=Crypt::encryptString(
                    trim($input['note']));
            }
            DB::table('messaging_reports')->where('id',$report)->update($data);
            DB::table('messaging_report_audits')->insert([
                'id'=>(string)Str::uuid(),'report_id'=>$report,
                'admin_user_id'=>$actor->id,'action'=>$input['action'],
                'created_at'=>now(),
            ]);
        });
        return redirect()->route('orbit.admin.messaging.reports.show',$report)
            ->with('status','Moderation case updated and audited.');
    }
}
