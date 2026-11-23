<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Http\Controllers\AdminMessagingController;
use App\Modules\Moderation\Services\ModerationService;
use App\Modules\Moments\Http\Controllers\AdminMomentController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class AdminModerationController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    private function auth(): \App\Models\AdminUser
    {
        return auth()->guard('admin')->user()??abort(403);
    }

    private function output(string $area,?string $caseId=null): Response
    {
        $ready=$this->moderation->installed();
        $counts=['total'=>0,'pending'=>0,'triaged'=>0,'investigating'=>0,
            'actioned'=>0,'dismissed'=>0,'appeals'=>0,'active_actions'=>0];
        $cases=collect();$events=collect();$actions=collect();$appeals=collect();
        $case=null;$controls=collect();$assignable=collect();$sources=collect();
        $controlAudits=collect();
        if($ready){
            foreach(DB::table('moderation_cases')->select('status')
                ->selectRaw('COUNT(*) as total')->groupBy('status')->get() as $entry){
                $counts[$entry->status]=(int)$entry->total;
                $counts['total']+=(int)$entry->total;
            }
            $counts['appeals']=DB::table('moderation_appeals')->where('status','pending')->count();
            $counts['active_actions']=DB::table('moderation_actions')->where('status','active')->count();
            if(in_array($area,['queue','cases'],true)){
                $filter=request()->validate([
                    'status'=>['nullable',Rule::in(['pending','triaged','investigating','actioned','dismissed','reopened'])],
                    'source'=>['nullable',Rule::in(['account','moment','messaging'])],
                    'priority'=>['nullable',Rule::in(['normal','urgent'])],
                ]);
                $query=DB::table('moderation_cases');
                if($area==='queue')$query->whereIn('status',['pending','triaged','investigating','reopened']);
                if($filter['status']??null)$query->where('status',$filter['status']);
                if($filter['source']??null)$query->where('source_kind',$filter['source']);
                if($filter['priority']??null)$query->where('priority',$filter['priority']);
                $cases=$query->orderByDesc('created_at')->paginate(25,[
                    'id','source_kind','status','priority','revision','created_at',
                ])->withQueryString();
            }
            if($area==='detail'){
                $case=$this->moderation->requireCase((string)$caseId);
                $events=DB::table('moderation_case_events')->where('case_id',$case->id)
                    ->orderByDesc('id')->limit(40)->get([
                        'action','reason_code','revision_after','created_at',
                    ]);
                $sources=DB::table('moderation_case_sources')->where('case_id',$case->id)
                    ->get(['source_kind','source_report_id','linked_at']);
                $actions=DB::table('moderation_actions')->where('case_id',$case->id)
                    ->orderByDesc('created_at')->limit(15)->get([
                        'id','scope','status','reason_code','ends_at','created_at',
                    ]);
                $assignable=\App\Models\AdminUser::query()->where('status','active')
                    ->orderBy('name')->get()->filter(fn($u)=>$u->canPerform('moderation.triage'));
            }
            if($area==='actions')$actions=DB::table('moderation_actions')
                ->orderByDesc('created_at')->limit(60)->get([
                    'id','case_id','scope','reason_code','status','ends_at','created_at',
                ]);
            if($area==='appeals')$appeals=DB::table('moderation_appeals as p')
                ->join('moderation_actions as a','a.id','=','p.action_id')
                ->select('p.id','p.status','p.revision','p.created_at',
                    'p.decision_code','p.decided_at','a.case_id','a.scope')
                ->orderByDesc('p.created_at')->limit(60)->get();
            if($area==='settings')$controls=DB::table('moderation_controls')->orderBy('key')->get();
            if($area==='audits')$controlAudits=DB::table('moderation_control_audits')
                ->orderByDesc('created_at')->limit(50)->get([
                    'key','old_value','new_value','created_at',
                ]);
            if($area==='audits')$events=DB::table('moderation_case_events')
                ->orderByDesc('id')->limit(80)->get([
                    'case_id','action','reason_code','revision_after','created_at',
                ]);
        }
        return response()->view('orbit-admin.moderation',compact(
            'area','ready','counts','cases','case','events','actions','appeals',
            'controls','assignable','sources','controlAudits'))
            ->header('Cache-Control','private, no-store');
    }

    public function overview():Response{return $this->output('overview');}
    public function queue():Response{return $this->output('queue');}
    public function cases():Response{return $this->output('cases');}
    public function detail(string $case):Response{return $this->output('detail',$case);}
    public function actions():Response{return $this->output('actions');}
    public function appeals():Response{return $this->output('appeals');}
    public function settings():Response{return $this->output('settings');}
    public function audits():Response{return $this->output('audits');}

    private function redirectCase(string $case,string $note):RedirectResponse
    {
        return redirect()->route('orbit.admin.moderation.cases.show',['case'=>$case])
            ->with('status',$note);
    }

    public function transition(Request $r,string $case):RedirectResponse
    {
        $d=$r->validate([
            'revision'=>['required','integer','min:1'],
            'action'=>['required',Rule::in(['triage','investigate','dismiss','reopen'])],
            'reason'=>['required',Rule::in(['spam','harassment','fraud',
                'abuse','safety','reviewed_safe','other'])],
            'assign_to'=>['sometimes','nullable','uuid'],
            'confirm'=>['required','accepted'],
        ]);
        $this->moderation->transition($case,$this->auth()->id,(int)$d['revision'],
            $d['action'],$d['reason'],$d['assign_to']??null);
        return $this->redirectCase($case,'Case decision recorded and audited.');
    }

    public function enforce(Request $r,string $case):RedirectResponse
    {
        $d=$r->validate([
            'revision'=>['required','integer','min:1'],
            'scope'=>['required',Rule::in(['warning','messaging_send'])],
            'reason'=>['required',Rule::in(['spam','harassment','fraud','abuse','other'])],
            'duration'=>['required',Rule::in(['1h','24h','7d','30d','indefinite'])],
            'confirm'=>['required','accepted'],
        ]);
        $this->moderation->enforce($case,$this->auth()->id,(int)$d['revision'],
            $d['scope'],$d['reason'],$d['duration']);
        return $this->redirectCase($case,'Reviewed action recorded. Scope is limited.');
    }

    public function decideAppeal(Request $r,string $appeal):RedirectResponse
    {
        $d=$r->validate([
            'revision'=>['required','integer','min:1'],
            'decision'=>['required',Rule::in(['upheld','reversed'])],
            'reason'=>['required',Rule::in(['confirmed_violation','insufficient_evidence',
                'proportionality','procedural_error','other'])],
            'confirm'=>['required','accepted'],
        ]);
        $this->moderation->decideAppeal($appeal,$this->auth()->id,
            (int)$d['revision'],$d['decision'],$d['reason']);
        return redirect()->route('orbit.admin.moderation.appeals')
            ->with('status','Appeal reviewed by independent moderator.');
    }

    public function evidence(Request $r,string $case):Response
    {
        $d=$r->validate([
            'ticket'=>['required','string','min:10','max:80'],
            'confirm'=>['required','accepted'],
        ]);
        $c=$this->moderation->requireCase($case);
        $admin=$this->auth();
        // Native source authorization is ADDITIONAL to M17 privilege.
        // No generic decryption/export route may be introduced.
        if($c->source_kind==='moment'){
            abort_unless($admin->canPerform('moments.moderate'),403);
            $source=DB::table('moment_reports')->where('id',$c->source_report_id)->first();
            abort_unless($source && $source->evidence_consent
                && $source->consent_evidence_ciphertext,404,'Volunteered evidence unavailable.');
            $response=app(AdminMomentController::class)->evidence($r,$c->source_report_id);
        }elseif($c->source_kind==='messaging'){
            abort_unless($admin->canPerform('messages.moderate'),403);
            $source=DB::table('messaging_reports')->where('id',$c->source_report_id)->first();
            abort_unless($source && $source->evidence_shared && $source->evidence_enc,
                404,'Volunteered evidence unavailable.');
            $response=app(AdminMessagingController::class)->evidence($r,$c->source_report_id);
        }else{abort(404,'No shareable evidence for this case.');}
        DB::table('moderation_evidence_access')->insert([
            'id'=>(string)Str::uuid7(),'case_id'=>$c->id,
            'admin_id'=>$admin->id,'ticket_digest'=>hash('sha256',$d['ticket']),
            'source_kind'=>$c->source_kind,'created_at'=>now(),
        ]);
        return $response;
    }

    public function sync(Request $r):RedirectResponse
    {
        $r->validate(['confirm'=>['required','accepted'],
            'reason'=>['required','string','min:10','max:300']]);
        $stats=$this->moderation->syncSources(40);
        return redirect()->route('orbit.admin.moderation.overview')
            ->with('status','Legacy report sync: '.json_encode($stats).'. No private evidence copied.');
    }

    public function saveControl(Request $r):RedirectResponse
    {
        $d=$r->validate([
            'key'=>['required',Rule::in(['enforcement_enabled'])],
            'value'=>['required',Rule::in(['0','1'])],
            'reason'=>['required','string','min:12','max:300'],
            'confirm'=>['required','accepted'],
        ]);
        // Enabling rollout is deliberate, traced and opt-in. Never use this
        // control to disable blocking, reporting, SOS or evidence restrictions.
        if($d['value']==='1')abort_unless($r->input('release_confirmation')==='ENABLE',422,
            'Type ENABLE to authorize restricted enforcement.');
        DB::transaction(function()use($d):void{
            $row=DB::table('moderation_controls')->where('key',$d['key'])
                ->lockForUpdate()->first()??abort(503);
            DB::table('moderation_controls')->where('key',$d['key'])->update([
                'value'=>$d['value'],'updated_by'=>$this->auth()->id,
                'updated_at'=>now(),
            ]);
            DB::table('moderation_control_audits')->insert([
                'id'=>(string)Str::uuid7(),'admin_id'=>$this->auth()->id,
                'key'=>$d['key'],'old_value'=>$row->value,'new_value'=>$d['value'],
                'reason_digest'=>hash('sha256',trim($d['reason'])),'created_at'=>now(),
            ]);
        },3);
        return redirect()->route('orbit.admin.moderation.settings')
            ->with('status','Moderation release setting changed with an audit receipt.');
    }
}
