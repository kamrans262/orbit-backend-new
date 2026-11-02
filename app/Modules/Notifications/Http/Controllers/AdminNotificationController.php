<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\NotificationDeliveryWorker;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Notifications\Services\NotificationProjector;
use App\Modules\Notifications\Services\NotificationPushTransport;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AdminNotificationController extends Controller
{
    private const AREAS = ['overview','jobs','devices','templates','settings','audits'];

    public function jobs(Request $r) { return $this->index($r,'jobs'); }
    public function devices(Request $r) { return $this->index($r,'devices'); }
    public function templates(Request $r) { return $this->index($r,'templates'); }
    public function settings(Request $r) { return $this->index($r,'settings'); }
    public function audits(Request $r) { return $this->index($r,'audits'); }

    public function __construct(private readonly NotificationPolicy $policy,
                                private readonly NotificationPushTransport $transport) {}

    public function index(Request $request, string $area='overview')
    {
        abort_unless(in_array($area,self::AREAS,true),404);
        $ready=$this->policy->installed();
        $stats=[];$categoryTotals=[];$stateTotals=[];$jobs=null;$devices=null;
        $templates=collect();$controls=collect();$audits=null;$attempts=collect();
        if($ready){
            $stats=[
                'events'=>DB::table('notification_events')->count(),
                'inbox'=>DB::table('notification_inbox')->whereNull('invalidated_at')->count(),
                'unread'=>DB::table('notification_inbox')->whereNull('invalidated_at')
                    ->whereNull('read_at')->count(),
                'devices'=>DB::table('notification_device_tokens')->whereNull('disabled_at')->count(),
                'failed'=>DB::table('notification_jobs')->where('state','failed')->count(),
                'accepted'=>DB::table('notification_jobs')->where('state','accepted')->count(),
            ];
            $categoryTotals=DB::table('notification_inbox')->select('category')
                ->selectRaw('COUNT(*) as total')->groupBy('category')->orderBy('category')->get();
            $stateTotals=DB::table('notification_jobs')->select('state')
                ->selectRaw('COUNT(*) as total')->groupBy('state')->orderBy('state')->get();
            if($area==='jobs'){
                $filters=$request->validate([
                    'state'=>['nullable',Rule::in(['pending','retry','processing','accepted','failed','suppressed'])],
                    'category'=>['nullable',Rule::in(config('orbit_notifications.categories',[]))],
                ]);
                $query=DB::table('notification_jobs as j')
                    ->join('notification_inbox as n','n.id','=','j.notice_id')
                    ->select('j.id','j.state','j.channel','j.attempts','j.last_error_code',
                        'j.created_at','j.next_attempt_at','j.accepted_at','n.category');
                if(isset($filters['state']))$query->where('j.state',$filters['state']);
                if(isset($filters['category']))$query->where('n.category',$filters['category']);
                $jobs=$query->orderByDesc('j.created_at')->paginate(25)->withQueryString();
                $attempts=DB::table('notification_attempts')->select('provider','state','error_code','attempted_at')
                    ->orderByDesc('attempted_at')->limit(20)->get();
            }
            if($area==='devices'){
                $devices=DB::table('notification_device_tokens')
                    ->select('device_id','platform','token_digest','project_id','disabled_at','updated_at')
                    ->orderByDesc('updated_at')->paginate(25);
            }
            if($area==='templates')$templates=DB::table('notification_templates')->orderBy('category')->get();
            if($area==='settings')$controls=DB::table('notification_controls')->pluck('enabled','key');
            if($area==='audits')$audits=DB::table('notification_admin_audits')
                ->select('action','subject_type','subject_id','reason','created_at')
                ->orderByDesc('created_at')->paginate(25);
        }
        return response()->view('orbit-admin.notifications',[
            'area'=>$area,'ready'=>$ready,'stats'=>$stats,'categoryTotals'=>$categoryTotals,
            'stateTotals'=>$stateTotals,'jobs'=>$jobs,'devices'=>$devices,
            'templates'=>$templates,'controls'=>$controls,'audits'=>$audits,
            'attempts'=>$attempts,'pushReady'=>$this->transport->enabled(),
            'pushConfigured'=>config('orbit_notifications.push_enabled')===true,
            'categories'=>config('orbit_notifications.categories',[]),
        ])->header('Cache-Control','private, no-store');
    }

    private function audit(string $action,string $subjectType,?string $subject,
                           string $reason): void
    {
        DB::table('notification_admin_audits')->insert([
            'id'=>(string)Str::uuid(),'admin_id'=>(string)auth()->guard('admin')->id(),
            'action'=>$action,'subject_type'=>$subjectType,'subject_id'=>$subject,
            'reason'=>$reason,'created_at'=>now(),
        ]);
    }

    private function approved(Request $request,array $extra=[]): array
    {
        $this->policy->assertReady();
        return $request->validate(array_merge([
            'reason'=>['required','string','min:10','max:500'],
            'confirm'=>['accepted'],
        ],$extra));
    }

    public function saveControl(Request $request)
    {
        $data=$this->approved($request,[
            'key'=>['required','string','max:64'],
            'enabled'=>['required','boolean'],
        ]);
        $keys=['channel.in_app','channel.push'];
        foreach(config('orbit_notifications.categories',[]) as $category)
            $keys[]='category.'.$category;
        abort_unless(in_array($data['key'],$keys,true),422);
        if($data['key']==='channel.push' && $data['enabled']
            && !$this->transport->enabled())
            return back()->withErrors(['enabled'=>'Provider credentials and release gate must be verified before enabling push.']);
        DB::transaction(function()use($data):void{
            DB::table('notification_controls')->updateOrInsert(['key'=>$data['key']],[
                'enabled'=>(bool)$data['enabled'],
                'updated_by'=>(string)auth()->guard('admin')->id(),
                'updated_at'=>now(),
            ]);
            $this->audit('control.changed','setting',$data['key'],$data['reason']);
        },3);
        return back()->with('status','Notification control updated and audited.');
    }

    public function saveTemplate(Request $request,string $key)
    {
        $data=$this->approved($request,[
            'title'=>['required','string','min:3','max:120','not_regex:/[<>\\{\\}]/'],
            'body'=>['required','string','min:5','max:200','not_regex:/[<>\\{\\}]/'],
            'enabled'=>['required','boolean'],
        ]);
        abort_unless(DB::table('notification_templates')->where('key',$key)->exists(),404);
        DB::transaction(function()use($key,$data):void{
            DB::table('notification_templates')->where('key',$key)->update([
                'title'=>trim($data['title']),'body'=>trim($data['body']),
                'enabled'=>(bool)$data['enabled'],'updated_at'=>now(),
            ]);
            $this->audit('template.changed','template',$key,$data['reason']);
        },3);
        return back()->with('status','Generic notification template updated and audited.');
    }

    public function retry(Request $request,string $job)
    {
        $data=$this->approved($request);
        DB::transaction(function()use($job,$data):void{
            $row=DB::table('notification_jobs')->where('id',$job)->lockForUpdate()->first();
            abort_unless($row && in_array($row->state,['failed','retry'],true),409,
                'Only failed or retryable jobs can be requeued.');
            DB::table('notification_jobs')->where('id',$job)->update([
                'state'=>'retry','attempts'=>0,'next_attempt_at'=>now(),
                'lease_until'=>null,'last_error_code'=>null,'updated_at'=>now(),
            ]);
            $this->audit('job.requeued','delivery_job',$job,$data['reason']);
        },3);
        return back()->with('status','Delivery queued for a new eligibility check.');
    }

    public function disableDevice(Request $request,string $device)
    {
        $data=$this->approved($request);
        DB::transaction(function()use($device,$data):void{
            $updated=DB::table('notification_device_tokens')->where('device_id',$device)
                ->whereNull('disabled_at')->update(['disabled_at'=>now(),'updated_at'=>now()]);
            abort_unless($updated,404,'Active push registration not found.');
            $this->audit('device.disabled','push_device',$device,$data['reason']);
        },3);
        return back()->with('status','Push registration disabled; legacy Moments delivery is separately gated.');
    }

    public function maintenance(Request $request,string $operation)
    {
        abort_unless(in_array($operation,['project','dispatch','expire'],true),404);
        $data=$this->approved($request);
        $result=match($operation){
            'project'=>app(NotificationProjector::class)->run(30),
            'dispatch'=>app(NotificationDeliveryWorker::class)->run(20),
            'expire'=>['invalidated'=>app(NotificationService::class)->invalidateExpired(200)],
        };
        $this->audit('maintenance.'.$operation,'worker',$operation,$data['reason']);
        return back()->with('status','Notification task completed: '.json_encode($result));
    }
}
