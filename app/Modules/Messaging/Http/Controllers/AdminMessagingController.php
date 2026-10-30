<?php
namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminMessagingController extends Controller
{
    /**
     * Aggregate operations only; no ciphertext, private reports, conversation
     * titles, participant IDs, private key material or message plaintext.
     */
    public function index(): Response
    {
        $ready=Schema::hasTable('messaging_conversations')
            && Schema::hasTable('messaging_device_keys')
            && Schema::hasTable('messaging_envelopes');
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
                'pending_envelopes'=>DB::table('messaging_envelopes as e')
                    ->join('messaging_messages as m','m.id','=','e.message_id')
                    ->whereNull('e.delivered_at')->where('m.expires_at','>',now())->count(),
                'aggregate_reports_pending'=>DB::table('messaging_reports')
                    ->where('status','pending')->count(),
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
            'retentionDays'=>(int)config('orbit_messaging.undelivered_retention_days',30),
        ])->header('Cache-Control','private, no-store');
    }
    public function reports(): Response
    {
        abort_unless(Schema::hasTable('messaging_reports'),503,'Messaging schema required.');
        $rows=DB::table('messaging_reports')->orderByDesc('created_at')
            ->limit(40)->get(['id','reason','status','evidence_shared','created_at']);
        return response()->view('orbit-admin.messaging-reports',[
            'reports'=>$rows,
        ])->header('Cache-Control','private, no-store');
    }

    public function evidence(Request $r,string $report): Response
    {
        $input=$r->validate(['confirm'=>['required','accepted']]);
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
        // Exactly the text the user knowingly submitted with the report.
        // Never decrypt conversation envelopes or media.
        return response()->view('orbit-admin.messaging-evidence',[
            'report'=>$report,'evidence'=>Crypt::decryptString($row->evidence_enc),
            'reason'=>$row->reason,
        ])->header('Cache-Control','private, no-store')
          ->header('Referrer-Policy','no-referrer');
    }

    public function action(Request $r,string $report): \Illuminate\Http\RedirectResponse
    {
        $input=$r->validate(['action'=>['required',Rule::in(['reviewed','dismissed'])]]);
        $actor=auth()->guard('admin')->user();
        abort_unless($actor,403);
        DB::transaction(function()use($report,$input,$actor):void{
            $row=DB::table('messaging_reports')->where('id',$report)
                ->lockForUpdate()->first();
            abort_unless($row,404,'Report unavailable.');
            DB::table('messaging_reports')->where('id',$report)->update([
                'status'=>$input['action'],'reviewed_at'=>now(),
                'reviewed_by'=>$actor->id,
            ]);
            DB::table('messaging_report_audits')->insert([
                'id'=>(string)Str::uuid(),'report_id'=>$report,
                'admin_user_id'=>$actor->id,'action'=>$input['action'],
                'created_at'=>now(),
            ]);
        });
        return redirect()->route('orbit.admin.messaging.reports');
    }

}
