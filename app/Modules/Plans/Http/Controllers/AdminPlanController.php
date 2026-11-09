<?php

namespace App\Modules\Plans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Services\PlanPolicy;
use App\Modules\Plans\Services\PlanReminderWorker;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminPlanController extends Controller
{
    public function __construct(private readonly PlanPolicy $policy,
        private readonly PlanService $plans) {}

    public function directory(Request $r) { return $this->index($r,'directory'); }
    public function attendance(Request $r) { return $this->index($r,'attendance'); }
    public function reminders(Request $r) { return $this->index($r,'reminders'); }
    public function settings(Request $r) { return $this->index($r,'settings'); }
    public function audits(Request $r) { return $this->index($r,'audits'); }

    public function index(Request $r,string $area='overview')
    {
        $ready=$this->policy->installed();
        $counts=['total'=>0,'upcoming'=>0,'cancelled'=>0,'invitees'=>0,'queued'=>0,'events'=>0];
        $directory=collect();$attendance=collect();$queue=collect();$audits=collect();
        $reminderStates=collect();$controls=collect();
        if($ready){
            $counts=[
                'total'=>DB::table('plans')->count(),
                'upcoming'=>DB::table('plans')->where('status','scheduled')
                    ->where('starts_at','>=',now())->count(),
                'cancelled'=>DB::table('plans')->where('status','cancelled')->count(),
                'invitees'=>DB::table('plan_invitees')->count(),
                'queued'=>DB::table('plan_reminder_jobs')->whereIn('state',['pending','retry'])->count(),
                'events'=>DB::table('plan_change_events')->whereNull('projected_at')->count(),
            ];
            if($area==='directory'){
                $args=$r->validate(['status'=>['sometimes',Rule::in(['scheduled','cancelled'])]]);
                $directory=DB::table('plans as p')
                    ->select('p.id','p.status','p.revision','p.created_at','p.starts_at')
                    ->selectSub(DB::table('plan_invitees as i')->selectRaw('count(*)')
                        ->whereColumn('i.plan_id','p.id'),'invited_count')
                    ->when($args['status']??null,fn($q,$s)=>$q->where('p.status',$s))
                    ->orderByDesc('p.created_at')->limit(50)->get();
            }
            if($area==='attendance')$attendance=DB::table('plan_invitees')
                ->select('state')->selectRaw('count(*) as total')->groupBy('state')->get();
            if($area==='reminders'){
                $queue=DB::table('plan_reminder_jobs')->select(
                    'id','state','attempts','due_at','error_code','created_at')
                    ->orderByDesc('created_at')->limit(40)->get();
                $reminderStates=DB::table('plan_reminder_jobs')
                    ->select('state')->selectRaw('count(*) as total')->groupBy('state')->get();
            }
            if($area==='settings')$controls=DB::table('plan_controls')->orderBy('key')->get();
            if($area==='audits')$audits=DB::table('plan_admin_audits')
                ->select('action','plan_hash','reason','created_at')
                ->orderByDesc('created_at')->limit(100)->get();
        }
        return response()->view('orbit-admin.plans',compact(
            'area','ready','counts','directory','attendance','queue','audits','reminderStates','controls'
        ))->header('Cache-Control','private, no-store');
    }

    private function approved(Request $r,array $rules=[]): array
    {
        abort_unless($this->policy->installed(),503);
        return $r->validate(array_merge([
            'reason'=>['required','string','min:10','max:500'],
            'confirm'=>['accepted'],
        ],$rules));
    }

    private function audit(string $action,?string $plan,string $reason): void
    {
        DB::table('plan_admin_audits')->insert([
            'id'=>(string)Str::uuid(),
            'admin_id'=>(string)auth()->guard('admin')->id(),
            'action'=>$action,
            'plan_hash'=>$plan===null?null:hash('sha256',$plan),
            'reason'=>$reason,'created_at'=>now(),
        ]);
    }

    public function saveControl(Request $r)
    {
        $data=$this->approved($r,[
            'key'=>['required',Rule::in(['creation_enabled','reminders_enabled'])],
            'enabled'=>['required','boolean'],
        ]);
        DB::transaction(function()use($data):void{
            DB::table('plan_controls')->where('key',$data['key'])->update([
                'enabled'=>(bool)$data['enabled'],
                'updated_by'=>(string)auth()->guard('admin')->id(),
                'updated_at'=>now(),
            ]);
            $this->audit('control.changed',null,$data['reason'].' ['.$data['key'].']');
        },3);
        return back()->with('status','Plan operational control updated and audited.');
    }

    public function cancel(Request $r,string $plan)
    {
        $data=$this->approved($r);
        $this->plans->moderateCancel($plan,(string)auth()->guard('admin')->id(),$data['reason']);
        return back()->with('status','Plan cancelled. Reminder jobs suppressed and action audited.');
    }

    public function retry(Request $r,string $job)
    {
        $data=$this->approved($r);
        DB::transaction(function()use($job,$data):void{
            $row=DB::table('plan_reminder_jobs')->where('id',$job)->lockForUpdate()->first();
            abort_unless($row && $row->state==='failed',409,'Only failed jobs can be retried.');
            DB::table('plan_reminder_jobs')->where('id',$job)->update([
                'state'=>'pending','attempts'=>0,'due_at'=>now(),
                'lease_until'=>null,'error_code'=>null,'updated_at'=>now(),
            ]);
            $this->audit('reminder.retry',$row->plan_id,$data['reason']);
        },3);
        return back()->with('status','Eligible job requeued; current user consent will be rechecked.');
    }

    public function process(Request $r)
    {
        $data=$this->approved($r);
        $result=app(PlanReminderWorker::class)->run(20);
        DB::transaction(fn()=> $this->audit('worker.manual',null,$data['reason']));
        return back()->with('status','Bounded worker completed: '.json_encode($result));
    }
}
