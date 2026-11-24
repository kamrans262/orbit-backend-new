<?php
namespace App\Modules\Billing\Services;
use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Carbon\CarbonImmutable;

final class BillingService{
    public function __construct(private readonly BillingPolicy $policy,private readonly StoreBillingProvider $store){}
    private function provider():BillingProvider{return app()->bound(BillingProvider::class)?app(BillingProvider::class):$this->store;}
    private function query(string $table){return DB::table($table)->where($table.'.environment',BillingPolicy::ENV);}
    private function activeUser(string $owner):void{
        abort_unless(OrbitUser::whereKey($owner)->where('status','active')->whereNull('deletion_requested_at')->lockForUpdate()->first(),403,'Account unavailable.');
    }
    private function uuid():string{return (string)Str::uuid7();}
    private function eligible(object $row):bool{
        return in_array($row->state,['active','grace'],true)&&$row->starts_at!==null&&$row->ends_at!==null
            &&now('UTC')->gte($row->starts_at)&&now('UTC')->lt($row->ends_at);
    }
    public function catalog(string $provider):array{
        $ready=$this->policy->enabled()&&$this->provider()->ready($provider);
        $rows=$this->policy->installed()?$this->query('billing_products')->where('provider',$provider)->orderBy('tier')->get():collect();
        return ['environment'=>BillingPolicy::ENV,'revision'=>(int)($rows->max('revision')??0),'purchase_enabled'=>$ready&&$rows->contains(fn($r)=>(bool)$r->enabled),
            'provider'=>$provider,'products'=>$rows->map(fn($r)=>['id'=>$r->id,'tier'=>$r->tier,'product_id'=>$r->product_id,'base_plan_id'=>$r->base_plan_id?:null,'interval'=>$r->interval,
                'available'=>$ready&&(bool)$r->enabled,'capabilities'=>BillingPolicy::BENEFITS])->values()->all(),
            'production_enabled'=>false];
    }
    /** Recompute under owner row lock on both reads and writes. Expiry needs no
     * scheduled worker to be correct. Disabled/unapproved paid benefits never
     * relax source authorization. Sandbox results are explicitly labelled. */
    public function entitlement(string $owner):array{
        if(!$this->policy->installed())return $this->unknown();
        return DB::transaction(function()use($owner){
            $this->activeUser($owner);
            return $this->recompute($owner);
        },3);
    }
    private function unknown():array{
        return ['environment'=>BillingPolicy::ENV,'tier'=>null,'state'=>'unknown','effective_until'=>null,'auto_renew'=>null,
            'revision'=>0,'verified_at'=>null,'management_providers'=>[],'capabilities'=>BillingPolicy::BENEFITS];
    }
    private function recompute(string $owner):array{
        $rows=$this->query('billing_subscriptions')->where('user_id',$owner)->join('billing_products as p','p.id','=','billing_subscriptions.product_id')
            ->select('billing_subscriptions.*','p.tier')->get();
        $eligible=$rows->filter(fn($r)=>$this->eligible($r))->sortByDesc(fn($r)=>($r->tier==='plus'?2:1))->values();
        // Stable tie-break within the same tier.
        $winner=$eligible->isEmpty()?null:$eligible->where('tier',$eligible[0]->tier)->sortByDesc('ends_at')->first();
        // Unresolved subscriptions block fresh checkout even if a later
        // terminal record exists. Keep the management route consistent.
        $unresolved=$rows->filter(fn($r)=>in_array($r->state,['pending','hold','paused','unknown'],true)&&!$r->replaced_by);
        $latest=$rows->sortByDesc('verified_at')->first();
        $selected=$winner??$unresolved->sortByDesc('verified_at')->first()??$latest;
        $management=$eligible->concat($unresolved)->pluck('provider')->unique()->sort()->values()->all();
        $state=$winner?->state??($selected?->state??($this->policy->enabled()?'free':'unknown'));
        if(!$winner&&in_array($state,['active','grace'],true))$state='expired';
        $tier=$winner?->tier??($state==='unknown'?null:'free');
        if(!$this->policy->enabled()){$tier=null;$state='unknown';$winner=null;$management=[];}
        $old=$this->query('billing_entitlements')->where('user_id',$owner)->first();
        $values=['tier'=>$tier,'state'=>$state,'effective_until'=>$winner?->ends_at,'auto_renew'=>$selected?->auto_renew,
            'verified_at'=>$selected?->verified_at];
        $changed=!$old;
        foreach($values as $k=>$v)if($old&&$old->$k!=$v)$changed=true;
        $rev=$old?(int)$old->revision+($changed?1:0):1;
        if($changed)DB::table('billing_entitlements')->updateOrInsert(['user_id'=>$owner,'environment'=>BillingPolicy::ENV],
            $values+['id'=>$old?->id??$this->uuid(),'revision'=>$rev,'created_at'=>$old?->created_at??now(),'updated_at'=>now()]);
        return ['environment'=>BillingPolicy::ENV,'tier'=>$tier,'state'=>$state,'effective_until'=>$winner?CarbonImmutable::parse($winner->ends_at,'UTC')->toIso8601String():null,
            'auto_renew'=>$selected?->auto_renew===null?null:(bool)$selected->auto_renew,'revision'=>$rev,
            'verified_at'=>$selected?CarbonImmutable::parse($selected->verified_at,'UTC')->toIso8601String():null,'management_providers'=>$management,'capabilities'=>BillingPolicy::BENEFITS];
    }
    public function context(string $owner,array $input):array{
        abort_unless($this->policy->enabled()&&$this->provider()->ready($input['provider']),503,'Billing unavailable.');
        return DB::transaction(function()use($owner,$input){
            $this->activeUser($owner);
            $product=$this->query('billing_products')->where('id',$input['product_mapping_id'])->where('provider',$input['provider'])->lockForUpdate()->first();
            abort_unless($product&&$product->enabled,422,'Product unavailable.');
            $hash=hash('sha256',$input['provider'].'|'.$product->id);
            $binding=$this->policy->binding($owner,$input['provider']);
            $row=DB::table('billing_purchase_contexts')->where('user_id',$owner)->where('client_request_id',$input['client_request_id'])->first();
            if($row){
                abort_unless(hash_equals($row->body_hash,$hash),409,'Purchase request conflict.');
                abort_if(now()->gte($row->expires_at),409,'Purchase context expired.');
            }else{
                $existing=$this->query('billing_subscriptions')->where('user_id',$owner)->get();
                // Existing subscription changes must use store-managed replacement;
                // this initial contract does not create a second active purchase.
                abort_if($existing->contains(fn($r)=>$this->eligible($r)||in_array($r->state,['pending','hold','paused','unknown'],true)),409,'Manage or restore the existing subscription.');
                abort_if(DB::table('billing_purchase_contexts')->where('user_id',$owner)->where('expires_at','>',now())->exists(),409,'Another purchase is pending.');
                $id=$this->uuid();$expires=now()->addMinutes(15);
                DB::table('billing_purchase_contexts')->insert(['id'=>$id,'user_id'=>$owner,'product_id'=>$product->id,'client_request_id'=>$input['client_request_id'],
                    'body_hash'=>$hash,'binding_hash'=>$this->policy->digest('binding:'.$product->provider,$binding),'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
                $row=DB::table('billing_purchase_contexts')->where('id',$id)->first();
            }
            return ['context_id'=>$row->id,'account_binding'=>$binding,'expires_at'=>CarbonImmutable::parse($row->expires_at,'UTC')->toIso8601String(),
                'product_id'=>$product->product_id,'base_plan_id'=>$product->base_plan_id?:null,'environment'=>BillingPolicy::ENV];
        },3);
    }
    private function recordEvent(string $p,string $event,string $body,?string $ref,?string $owner):object{
        $digest=$this->policy->digest('event:'.$p,$event);
        $client=str_starts_with($event,'client:');
        return DB::transaction(function()use($p,$digest,$body,$ref,$owner,$client){
            DB::table('billing_events')->insertOrIgnore(['id'=>$this->uuid(),'provider'=>$p,'environment'=>BillingPolicy::ENV,'event_hash'=>$digest,
                'body_hash'=>$body,'reference_hash'=>$ref===null?null:$this->policy->digest('lineage:'.$p,$ref),'reference_cipher'=>$ref===null?null:Crypt::encryptString($ref),'user_id'=>$owner,'state'=>$client?'client_pending':($ref===null?'processed':'queued'),
                'next_attempt_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            $row=$this->query('billing_events')->where('provider',$p)->where('event_hash',$digest)->lockForUpdate()->first();
            abort_unless($row&&hash_equals($row->body_hash,$body)&&(!$client||$row->user_id===$owner),409,'Billing event conflict.');
            return $row;
        },3);
    }
    public function verify(string $owner,array $input):array{
        $p=$input['provider'];abort_unless($this->policy->enabled()&&$this->provider()->ready($p),503,'Billing unavailable.');
        $ctx=null;
        if($input['mode']==='purchase'){
            $ctx=DB::table('billing_purchase_contexts as c')->join('billing_products as p','p.id','=','c.product_id')->where('c.id',$input['context_id']??'')
                ->where('c.user_id',$owner)->where('p.provider',$p)->where('p.environment',BillingPolicy::ENV)
                ->select('c.*','p.product_id as store_product','p.base_plan_id')->first();
            abort_unless($ctx,409,'Purchase context unavailable.');
        }
        $ref=$this->provider()->reference($p,$input['proof']);
        $hash=$this->policy->digest('verify-body',json_encode([$owner,$p,$input['mode'],$ctx?->id,$ref],JSON_THROW_ON_ERROR));
        $event=$this->recordEvent($p,'client:'.$owner.':'.$input['client_request_id'],$hash,null,$owner);
        $e=$this->provider()->lookup($p,$ref);
        if($ctx)abort_unless($e['product']===$ctx->store_product&&$e['base']===$ctx->base_plan_id,409,'Purchase product mismatch.');
        // A delayed purchase may arrive after checkout context expiry. The
        // stable binding, verified product, and original account still apply.
        $result=$this->apply($p,$ref,$e,$owner);
        $this->query('billing_events')->where('id',$event->id)->update(['state'=>'processed','reference_cipher'=>null,'error_code'=>null,'updated_at'=>now()]);
        return $result;
    }
    public function notice(string $p,array $body,?string $bearer):void{
        abort_unless($this->policy->enabled()&&$this->provider()->ready($p),503,'Billing unavailable.');
        $notice=$this->provider()->notice($p,$body,$bearer);
        // Only canonical reference + authenticated event ID retained, not full notification.
        $owner=$notice['reference']===null?null:$this->query('billing_subscriptions')->where('provider',$p)->where('lineage_hash',$this->policy->digest('lineage:'.$p,$notice['reference']))->value('user_id');
        $this->recordEvent($p,'provider:'.$notice['event'],$this->policy->digest('notice-body',(string)($notice['reference']??'')),$notice['reference'],$owner);
    }
    private function apply(string $p,string $ref,array $e,?string $owner):array{
        $lineage=$this->policy->digest('lineage:'.$p,$ref);
        $existing=$this->query('billing_subscriptions')->where('provider',$p)->where('lineage_hash',$lineage)->first();
        if($owner===null){
            $owner=$existing?->user_id;
            if(!$owner&&is_string($e['binding']??null)&&$e['binding']!==''){
                $bindingHash=$this->policy->digest('binding:'.$p,$e['binding']);
                $owner=DB::table('billing_purchase_contexts as c')->join('billing_products as p','p.id','=','c.product_id')->where('p.provider',$p)
                    ->where('p.environment',BillingPolicy::ENV)->where('c.binding_hash',$bindingHash)->value('c.user_id');
            }
        }
        abort_unless($owner&&is_string($e['binding']??null)&&hash_equals($this->policy->binding($owner,$p),$e['binding']),409,'Store ownership requires review.');
        $product=$this->policy->product($p,$e['product'],$e['base']);
        abort_unless($product,422,'Store product is not mapped.');
        // Mapping may be disabled for new sales; existing verified renewals
        // remain valid. First-time unknown mapping must not create a sale.
        abort_unless($existing||$product->enabled,422,'Store product unavailable.');
        try{
            $result=DB::transaction(function()use($owner,$p,$ref,$lineage,$e,$product){
                $this->activeUser($owner);
                $row=$this->query('billing_subscriptions')->where('provider',$p)->where('lineage_hash',$lineage)->lockForUpdate()->first();
                abort_if($row&&$row->user_id!==$owner,409,'Store ownership requires review.');
                $linked=null;
                if(is_string($e['linked']??null)&&$e['linked']!==''&&$e['linked']!==$ref){
                    $linked=$this->query('billing_subscriptions')->where('provider',$p)->where('lineage_hash',$this->policy->digest('lineage:'.$p,$e['linked']))->lockForUpdate()->first();
                    abort_if($linked&&$linked->user_id!==$owner,409,'Store ownership requires review.');
                }
                abort_unless(in_array($e['state'],['pending','active','grace','hold','paused','expired','revoked','unknown'],true),422);
                if(!$row||(!$row->replaced_by&&strcmp($e['observed'],$row->verified_at)>=0)){
                    $id=$row?->id??$this->uuid();
                    DB::table('billing_subscriptions')->updateOrInsert(['id'=>$id],[
                        'user_id'=>$owner,'product_id'=>$product->id,'provider'=>$p,'environment'=>BillingPolicy::ENV,'lineage_hash'=>$lineage,
                        'reference_cipher'=>Crypt::encryptString($ref),'state'=>$e['state'],'starts_at'=>$e['starts'],'ends_at'=>$e['ends'],
                        'auto_renew'=>$e['renew'],'ack_state'=>$e['ack'],'verified_at'=>$e['observed'],'next_check_at'=>now()->addMinutes($e['ack']==='pending'?2:30),
                        'revision'=>($row?->revision??0)+1,'created_at'=>$row?->created_at??now(),'updated_at'=>now()]);
                    if($linked&&in_array($e['state'],['active','grace'],true)&&$e['starts']&&now()->gte($e['starts'])){
                        $this->query('billing_subscriptions')->where('id',$linked->id)->update(['state'=>'expired','replaced_by'=>$id,'ends_at'=>now(),'next_check_at'=>now()->addDay(),'updated_at'=>now()]);
                    }
                }
                $saved=$this->query('billing_subscriptions')->where('provider',$p)->where('lineage_hash',$lineage)->first();
                return ['row'=>$saved,'entitlement'=>$this->recompute($owner)];
            },3);
        }catch(QueryException){abort(409,'Billing verification conflict; retry.');}
        $row=$result['row'];$ent=$result['entitlement'];
        $finish=$this->eligible($row);
        if($p==='google'&&$finish&&$row->ack_state==='pending'){
            try{
                $this->provider()->acknowledge($p,$ref,$product->product_id);
                $this->query('billing_subscriptions')->where('id',$row->id)->where('revision',$row->revision)->update(['ack_state'=>'acknowledged','updated_at'=>now()]);
            }catch(\Throwable){/* durable pending acknowledgement, worker retries */}
        }
        return ['status'=>$row->state==='pending'?'pending':'verified','entitlement'=>$ent,'finish_permitted'=>$finish];
    }
    public function subscriberCounts():array{
        if(!$this->policy->installed())return [];
        $rank=DB::table('billing_subscriptions as s')->join('billing_products as p','p.id','=','s.product_id')
            ->join('orbit_users as u','u.id','=','s.user_id')
            ->where('s.environment',BillingPolicy::ENV)->where('u.status','active')->whereNull('u.deletion_requested_at')
            ->whereNull('s.replaced_by')->whereIn('s.state',['active','grace'])->where('s.starts_at','<=',now('UTC'))->where('s.ends_at','>',now('UTC'))
            ->groupBy('s.user_id')->select('s.user_id',DB::raw("MAX(CASE WHEN p.tier = 'plus' THEN 2 ELSE 1 END) AS tier_rank"));
        $counts=DB::query()->fromSub($rank,'eligible_accounts')->select('tier_rank',DB::raw('COUNT(*) AS total'))->groupBy('tier_rank')->pluck('total','tier_rank');
        return ['lite'=>(int)($counts[1]??0),'plus'=>(int)($counts[2]??0)];
    }
    public function work(int $limit=20):array{
        $stats=['processed'=>0,'retry'=>0,'review'=>0,'reconciled'=>0];
        if(!$this->policy->installed())return $stats;
        // Sandbox proof retention only; no financial record preservation promise.
        $old=$this->query('billing_events')->where('created_at','<',now()->subDays(7))->orderBy('created_at')->limit(100)->pluck('id');
        if($old->isNotEmpty())$this->query('billing_events')->whereIn('id',$old)->delete();
        if(!$this->policy->enabled())return $stats;
        $limit=max(1,min(20,$limit));
        // Claim using CAS with a bounded lease; crashed processing is retried.
        $events=$this->query('billing_events')->whereIn('state',['queued','retry','processing'])->where('next_attempt_at','<=',now())->orderBy('next_attempt_at')->limit($limit)->get();
        foreach($events as $event){
            $claimed=$this->query('billing_events')->where('id',$event->id)->where('next_attempt_at','<=',now())->where('attempts',$event->attempts)
                ->update(['state'=>'processing','attempts'=>$event->attempts+1,'next_attempt_at'=>now()->addMinutes(5),'updated_at'=>now()]);
            if(!$claimed)continue;
            try{
                $ref=Crypt::decryptString($event->reference_cipher);
                $this->apply($event->provider,$ref,$this->provider()->lookup($event->provider,$ref),$event->user_id);
                $owner=$this->query('billing_subscriptions')->where('provider',$event->provider)->where('lineage_hash',$this->policy->digest('lineage:'.$event->provider,$ref))->value('user_id');
                $this->query('billing_events')->where('id',$event->id)->where('attempts',$event->attempts+1)->update(['state'=>'processed','user_id'=>$owner,'reference_cipher'=>null,'error_code'=>null,'updated_at'=>now()]);
                $stats['processed']++;
            }catch(\Throwable $error){
                $code=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():503;
                $review=in_array($code,[401,403,409,422],true)||$event->attempts>=7;
                $this->query('billing_events')->where('id',$event->id)->where('attempts',$event->attempts+1)->update(['state'=>$review?'review':'retry',
                    'error_code'=>$review?'verification_review':'provider_unavailable','next_attempt_at'=>now()->addMinutes(min(360,2**min(8,$event->attempts+1))),'updated_at'=>now()]);
                $stats[$review?'review':'retry']++;
            }
        }
        $rows=$this->query('billing_subscriptions')->whereNull('replaced_by')->where('next_check_at','<=',now())->orderBy('next_check_at')->limit($limit)->get();
        foreach($rows as $row){
            if(!$this->query('billing_subscriptions')->where('id',$row->id)->where('next_check_at','<=',now())->update(['next_check_at'=>now()->addMinutes(30)]))continue;
            try{
                $ref=Crypt::decryptString($row->reference_cipher);
                $this->apply($row->provider,$ref,$this->provider()->lookup($row->provider,$ref),$row->user_id);$stats['reconciled']++;
            }catch(\Throwable){$stats['retry']++;}
        }
        return $stats;
    }
}
