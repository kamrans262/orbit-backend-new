<?php

namespace App\Modules\Safety\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Safety\Services\SosAuditService;
use App\Modules\Safety\Services\SosDeliveryWorker;
use App\Modules\Safety\Services\SosPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminSosController extends Controller
{
    public function __construct(private readonly SosPolicy $policy,
        private readonly SosDeliveryWorker $worker,
        private readonly SosAuditService $audit) {}

    public function overview(Request $r) {return $this->render($r,'overview');}
    public function incidents(Request $r) {return $this->render($r,'incidents');}
    public function responders(Request $r) {return $this->render($r,'responders');}
    public function delivery(Request $r) {return $this->render($r,'delivery');}
    public function settings(Request $r) {return $this->render($r,'settings');}
    public function audits(Request $r) {return $this->render($r,'audits');}

    private function render(Request $request,string $area)
    {
        $ready=$this->policy->installed();
        $counts=['total'=>0,'active'=>0,'acknowledged'=>0,'cancelled'=>0,
            'resolved'=>0,'pending_jobs'=>0,'failed_jobs'=>0,'trusted_contacts'=>0];
        $incidents=collect();$responders=collect();$jobs=collect();$controls=collect();$audits=collect();
        $states=collect();
        if($ready){
            $counts=[
                'total'=>DB::table('sos_incidents')->count(),
                'active'=>DB::table('sos_incidents')->where('status','active')->count(),
                'acknowledged'=>DB::table('sos_recipients')->where('state','acknowledged')->count(),
                'cancelled'=>DB::table('sos_incidents')->where('status','cancelled')->count(),
                'resolved'=>DB::table('sos_incidents')->where('status','resolved')->count(),
                'pending_jobs'=>DB::table('sos_delivery_jobs')->whereIn('state',['pending','retry'])->count(),
                'failed_jobs'=>DB::table('sos_delivery_jobs')->where('state','failed')->count(),
                'trusted_contacts'=>DB::table('sos_trusted_contacts')->where('state','accepted')->count(),
            ];
            if($area==='incidents') {
                $args=$request->validate(['status'=>['nullable',Rule::in(['active','resolved','cancelled'])],'incident_id'=>['nullable','uuid']]);
                $incidents=DB::table('sos_incidents as i')->select('i.id','i.status',
                    'i.created_at','i.closed_at','i.escalated_at','i.revision')
                    ->selectSub(DB::table('sos_recipients as r')->selectRaw('COUNT(*)')
                        ->whereColumn('r.incident_id','i.id'),'recipient_count')
                    ->when($args['status']??null,fn($q,$v)=>$q->where('i.status',$v))
                    ->when($args['incident_id']??null,fn($q,$id)=>$q->where('i.id',$id))
                    ->orderByDesc('i.created_at')->orderByDesc('i.id')->paginate(25)->withQueryString();
            }
            if($area==='responders')$responders=DB::table('sos_recipients')
                ->select('state','source')->selectRaw('COUNT(*) AS total')
                ->groupBy('state','source')->orderBy('source')->get();
            if($area==='delivery'){
                $jobs=DB::table('sos_delivery_jobs')
                    ->select('id','state','attempts','next_attempt_at',
                        'provider_accepted_at','last_error_code','created_at')
                    ->when($request->filled('state'),fn($q)=>$q->where('state',$request->validate(['state'=>['required','string','max:24']])['state']))
                    ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
                $states=DB::table('sos_delivery_jobs')->select('state')
                    ->selectRaw('COUNT(*) AS total')->groupBy('state')->get();
            }
            if($area==='settings')$controls=DB::table('sos_controls')->orderBy('key')->get();
            if($area==='audits')$audits=DB::table('sos_admin_audits')
                ->select('action','incident_hash','reason','created_at','event_hash')
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        }
        return response()->view('orbit-admin.sos',compact('area','ready','counts',
            'incidents','responders','jobs','controls','audits','states'))
            ->header('Cache-Control','private, no-store');
    }

    private function authorized(Request $request,array $additional=[]): array
    {
        abort_unless($this->policy->installed(),503);
        return $request->validate(array_merge([
            'reason'=>['required','string','min:10','max:500'],
            'confirm'=>['accepted'],
        ],$additional));
    }

    public function saveControl(Request $request)
    {
        $data=$this->authorized($request,[
            'key'=>['required',Rule::in([
                'push_retries_enabled','escalation_enabled','escalation_after_seconds',
            ])],
            'value'=>['required','integer','between:0,1800'],
        ]);
        if($data['key']==='escalation_after_seconds'){
            abort_unless((int)$data['value']>=30,422,'Escalation delay must be at least 30 seconds.');
        }else {
            abort_unless(in_array((int)$data['value'],[0,1],true),422,'Invalid switch value.');
        }
        DB::transaction(function()use($data):void{
            $admin=(string)auth()->guard('admin')->id();
            DB::table('sos_controls')->where('key',$data['key'])->lockForUpdate()->first();
            DB::table('sos_controls')->where('key',$data['key'])->update([
                'value'=>(string)$data['value'],'updated_by'=>$admin,'updated_at'=>now(),
            ]);
            $this->audit->admin($admin,'sos.control_updated',null,
                $data['reason'].' ['.$data['key'].']');
        },3);
        return back()->with('status','SOS operational policy saved and audited. Core SOS activation remains available.');
    }

    public function retry(Request $request,string $job)
    {
        $data=$this->authorized($request);
        DB::transaction(function()use($job,$data):void{
            $row=DB::table('sos_delivery_jobs')->where('id',$job)->lockForUpdate()->first();
            abort_unless($row && $row->state==='failed',409,'Only failed deliveries can be retried.');
            $recipient=DB::table('sos_recipients')->where('id',$row->recipient_id)->first();
            $incident=$recipient?DB::table('sos_incidents')->where('id',$recipient->incident_id)->first():null;
            abort_unless($incident && $incident->status==='active' && $recipient
                && $recipient->state==='pending'
                && $this->policy->recipientEligible($incident,$recipient),409,
                'SOS closed or responder authorization has changed.');
            DB::table('sos_delivery_jobs')->where('id',$job)->update([
                'state'=>'pending','attempts'=>0,'next_attempt_at'=>now(),
                'lease_until'=>null,'last_error_code'=>null,'updated_at'=>now(),
            ]);
            $this->audit->admin((string)auth()->guard('admin')->id(),
                'sos.delivery_retry',$incident->id,$data['reason']);
        },3);
        return back()->with('status','Failed SOS delivery requeued after eligibility check.');
    }

    public function run(Request $request)
    {
        $data=$this->authorized($request);
        $stats=$this->worker->run(30);
        DB::transaction(fn()=>$this->audit->admin((string)auth()->guard('admin')->id(),
            'sos.worker_manual',null,$data['reason']),3);
        return back()->with('status','Bounded SOS worker finished: '.json_encode($stats));
    }
}
