<?php
namespace App\Modules\Billing\Http\Controllers;
use App\Modules\Billing\Services\BillingPolicy;
use App\Modules\Billing\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminBillingController{
    public function __construct(private readonly BillingPolicy $policy,private readonly BillingService $billing){}
    private function page(string $area,array $data=[]){
        return response()->view('orbit-admin.billing',['area'=>$area,'installed'=>$this->policy->installed(),'enabled'=>$this->policy->enabled()]+$data)->header('Cache-Control','private, no-store');
    }
    public function products(){
        return $this->page('products',['products'=>$this->policy->installed()?DB::table('billing_products')->where('environment',BillingPolicy::ENV)->orderBy('tier')->paginate(25):null,
            'catalogs'=>[$this->billing->catalog('apple'),$this->billing->catalog('google')]]);
    }
    public function subscriptions(Request $r){
        $p=$r->validate(['state'=>'nullable|in:pending,active,grace,hold,paused,expired,revoked,unknown','provider'=>'nullable|in:apple,google','user'=>'nullable|uuid']);
        $q=DB::table('billing_subscriptions as s')->join('billing_products as p','p.id','=','s.product_id')->where('s.environment',BillingPolicy::ENV)
            ->select('s.id','s.user_id','s.provider','p.tier','s.state','s.ends_at','s.auto_renew','s.verified_at','s.ack_state');
        foreach(['state','provider','user'] as $key)if(!empty($p[$key]))$q->where('s.'.($key==='user'?'user_id':$key),$p[$key]);
        return $this->page('subscriptions',['subscriptions'=>$this->policy->installed()?$q->orderByDesc('s.verified_at')->paginate(25)->withQueryString():null,'filters'=>$p]);
    }
    public function operations(){
        $audit=auth()->guard('admin')->user()?->canPerform('billing.audit');
        return $this->page('operations',['events'=>$this->policy->installed()?DB::table('billing_events')->where('environment',BillingPolicy::ENV)
            ->select('id','provider','state','attempts','error_code','next_attempt_at','created_at')->orderByDesc('created_at')->paginate(25):null,
            'counts'=>$this->policy->installed()?DB::table('billing_subscriptions')->where('environment',BillingPolicy::ENV)->select('state',DB::raw('count(*) as total'))->groupBy('state')->pluck('total','state'):collect(),
            'audits'=>$audit?DB::table('admin_audit_log')->where('target','like','billing:%')->select('actor_id','action','target','reason','created_at')->orderByDesc('created_at')->limit(30)->get():collect()]);
    }
    private function audit(Request $r,string $action,string $target,string $reason,?array $before,array $after):void{
        $prev=DB::table('admin_audit_log')->orderByDesc('created_at')->lockForUpdate()->first()?->event_hash;
        $actor=$r->user('admin')->id;$id=(string)Str::uuid7();
        $bh=$before===null?null:hash('sha256',json_encode($before,JSON_THROW_ON_ERROR));$ah=hash('sha256',json_encode($after,JSON_THROW_ON_ERROR));
        $target='billing:'.$target;
        DB::table('admin_audit_log')->insert(['id'=>$id,'actor_id'=>$actor,'action'=>$action,'target'=>$target,'reason'=>$reason,
            'request_id'=>$r->header('X-Request-ID',$id),'ip_address'=>$r->ip(),'before_hash'=>$bh,'after_hash'=>$ah,'previous_hash'=>$prev,
            'event_hash'=>hash('sha256',implode('|',[$prev??'',$actor,$action,$target,$bh??'',$ah,$reason,$id])),'created_at'=>now()]);
    }
    public function save(Request $r){
        abort_unless($this->policy->installed(),503);
        $d=$r->validate(['id'=>'nullable|uuid','revision'=>'required|integer|min:0','tier'=>'required|in:lite,plus','provider'=>'required|in:apple,google',
            'product_id'=>'required|string|max:150|regex:/^[A-Za-z0-9._-]+$/D','base_plan_id'=>'nullable|string|max:100|regex:/^[a-z0-9-]+$/D',
            'enabled'=>'required|boolean','reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        $base=$d['base_plan_id']??'';
        abort_unless(($d['provider']==='apple'&&$base==='')||($d['provider']==='google'&&$base!==''),422,'Invalid platform product mapping.');
        DB::transaction(function()use($r,$d,$base){
            $old=isset($d['id'])?DB::table('billing_products')->where('environment',BillingPolicy::ENV)->where('id',$d['id'])->lockForUpdate()->first():null;
            abort_if(isset($d['id'])&&!$old,404);
            abort_unless((int)$d['revision']===(int)($old?->revision??0),409,'Product changed; reload.');
            // Immutable product identity/tier after creation, including disabled mappings.
            if($old)abort_unless($old->provider===$d['provider']&&$old->tier===$d['tier']&&$old->product_id===$d['product_id']&&$old->base_plan_id===$base,409,'Create a separate mapping instead.');
            $id=$old?->id??(string)Str::uuid7();
            $duplicate=DB::table('billing_products')->where(['provider'=>$d['provider'],'environment'=>BillingPolicy::ENV,'product_id'=>$d['product_id'],'base_plan_id'=>$base])->where('id','!=',$id)->exists();
            abort_if($duplicate,409,'Product already mapped.');
            $values=['tier'=>$d['tier'],'provider'=>$d['provider'],'environment'=>BillingPolicy::ENV,'product_id'=>$d['product_id'],'base_plan_id'=>$base,'interval'=>'month','enabled'=>(bool)$d['enabled'],
                'revision'=>($old?->revision??0)+1,'created_at'=>$old?->created_at??now(),'updated_at'=>now()];
            DB::table('billing_products')->updateOrInsert(['id'=>$id],$values);
            $this->audit($r,'billing.product.save',$id,$d['reason'],$old?(array)$old:null,$values);
        },3);
        return redirect()->route('orbit.admin.billing.products')->with('status','Sandbox mapping saved and audited.');
    }
    public function retry(Request $r,string $event){
        $d=$r->validate(['reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        DB::transaction(function()use($r,$event,$d){
            $row=DB::table('billing_events')->where('environment',BillingPolicy::ENV)->where('id',$event)->lockForUpdate()->first();
            abort_unless($row&&in_array($row->state,['retry','review'],true)&&$row->reference_cipher!==null,409,'Event cannot be retried.');
            DB::table('billing_events')->where('id',$event)->update(['state'=>'queued','attempts'=>0,'next_attempt_at'=>now(),'error_code'=>null,'updated_at'=>now()]);
            $this->audit($r,'billing.event.retry',$event,$d['reason'],['state'=>$row->state],['state'=>'queued']);
        },3);
        return back()->with('status','Provider verification queued.');
    }
}
