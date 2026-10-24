<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AdminMomentController extends Controller
{
    public function index(Request $r):Response
    {
        abort_unless(config('orbit_moments.enabled')===true,404);
        $d=$r->validate(['moment_id'=>['nullable','uuid']]);
        $metrics=[
            'published'=>DB::table('moments')->where('state','published')->count(),
            'expired'=>DB::table('moments')->where('state','expired')->count(),
            'restricted'=>DB::table('moments')->where('state','restricted')->count(),
            'deleted'=>DB::table('moments')->where('state','deleted')->count(),
            'created_24h'=>DB::table('moments')->where('created_at','>=',now()->subDay())->count(),
            'pending_reports'=>DB::table('moment_reports')->where('status','pending')->count(),
            'queued_cleanup'=>DB::table('moment_lifecycle_jobs')->where('state','pending')->where('job_type','purge')->count(),
        ];
        $moment=null;
        if(!empty($d['moment_id']))$moment=DB::table('moments')->where('id',$d['moment_id'])
            ->first(['id','state','audience_kind','published_at','rail_expires_at','view_expires_at','purge_due_at','audience_revision']);
        // No reporter identity, plaintext, encrypted bytes, key, filename, viewer or audience roster.
        $reports=DB::table('moment_reports')->where('status','pending')
            ->orderBy('created_at')->limit(25)->get(['id','reason','status','created_at']);
        return response()->view('orbit-admin.moments',compact('metrics','moment','reports'))
            ->header('Cache-Control','private, no-store');
    }

    public function action(Request $r,string $report):\Illuminate\Http\RedirectResponse
    {
        abort_unless(config('orbit_moments.enabled')===true,404);
        $data=$r->validate([
            'action'=>['required','in:dismiss,restrict'],
            'ticket'=>['required','string','min:10','max:80'],
            'reason_code'=>['required','in:reviewed_safe,privacy_violation,abusive_content,spam,other'],
            'confirm'=>['required','in:REVIEWED'],
        ]);
        // Restricting E2EE content needs separately approved evidence review;
        // the launch flag is OFF until Trust/Security signs off the process.
        if($data['action']==='restrict') {
            abort_unless(config('orbit_moments.moderation_restrict_enabled')===true,403,
                'Moment restriction requires approved evidence-review policy.');
        }
        $admin=auth()->guard('admin')->user();abort_unless($admin,403);
        DB::transaction(function()use($report,$data,$admin):void{
            $record=DB::table('moment_reports')->where('id',$report)->lockForUpdate()->first();
            abort_unless($record,404);
            abort_unless($record->status==='pending',409,'Report already reviewed.');
            $moment=DB::table('moments')->where('id',$record->moment_id)->lockForUpdate()->first();
            abort_unless($moment,404);
            DB::table('moment_reports')->where('id',$report)->update(['status'=>$data['action']==='dismiss'?'dismissed':'actioned','updated_at'=>now()]);
            if($data['action']==='restrict' && $moment->state==='published'){
                DB::table('moments')->where('id',$moment->id)->update([
                    'state'=>'restricted','audience_revision'=>$moment->audience_revision+1,'updated_at'=>now(),
                ]);
                $media=DB::table('moment_media')->where('moment_id',$moment->id)->pluck('media_id');
                DB::table('media_audience_grants')->whereIn('media_id',$media)->whereNull('revoked_at')
                    ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('media_device_key_envelopes')->whereIn('media_id',$media)->whereNull('revoked_at')
                    ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                DB::table('moment_recipients')->where('moment_id',$moment->id)->whereNull('revoked_at')
                    ->update(['revoked_at'=>now()]);
                DB::table('moment_events_outbox')->insert([
                    'id'=>(string)Str::uuid7(),'moment_id'=>$moment->id,
                    'event_type'=>'moment.restricted.v1','audience_revision'=>$moment->audience_revision+1,
                    'status'=>'pending','created_at'=>now()]);
            }
            DB::table('moment_moderation_actions')->insert([
                'id'=>(string)Str::uuid7(),'report_id'=>$report,'operator_id'=>$admin->id,
                'action'=>$data['action'],'ticket'=>$data['ticket'],'reason_code'=>$data['reason_code'],
                'created_at'=>now()]);
        },3);
        return redirect()->route('orbit.admin.moments.index')->with('status','Moderation decision recorded.');
    }
}
