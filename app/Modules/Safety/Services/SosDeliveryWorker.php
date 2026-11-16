<?php

namespace App\Modules\Safety\Services;

use App\Modules\Notifications\Services\NotificationPushTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local MariaDB worker. In-app availability exists upon incident creation;
 * provider acceptance is tracked separately from user acknowledgement. */
final class SosDeliveryWorker
{
    public function __construct(
        private readonly SosPolicy $policy,
        private readonly SosService $service,
        private readonly SosAuditService $audit,
        private readonly NotificationPushTransport $push,
    ) {}

    public function run(int $limit=30): array
    {
        $stats=['escalated'=>0,'recipients'=>0,'jobs_created'=>0,'provider_accepted'=>0,
            'retry'=>0,'failed'=>0,'suppressed'=>0,'push_available'=>false];
        if(!$this->policy->installed() || config('orbit_sos.enabled')!==true)return $stats;
        $limit=max(1,min(100,$limit));
        $this->reconcile(min($limit*2,100),$stats);
        $this->escalate($limit,$stats);
        $stats['push_available']=$this->push->enabled();
        if(!$stats['push_available'])return $stats;
        $this->seedJobs($limit,$stats);
        $ids=DB::table('sos_delivery_jobs')->where(function($q){
            $q->whereIn('state',['pending','retry'])
                ->where(fn($z)=>$z->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at','<=',now()))
                ->orWhere(fn($z)=>$z->where('state','processing')
                    ->whereNotNull('lease_until')->where('lease_until','<=',now()));
        })->orderBy('created_at')->limit($limit)->pluck('id');
        foreach($ids as $id){
            $job=DB::transaction(function()use($id){
                $row=DB::table('sos_delivery_jobs')->where('id',$id)->lockForUpdate()->first();
                if(!$row || !in_array($row->state,['pending','retry','processing'],true)
                    || ($row->state==='processing' && $row->lease_until && now()->lt($row->lease_until))
                    || ($row->next_attempt_at && now()->lt($row->next_attempt_at)))return null;
                DB::table('sos_delivery_jobs')->where('id',$id)->update([
                    'state'=>'processing','attempts'=>$row->attempts+1,
                    'lease_until'=>now()->addMinutes(2),'updated_at'=>now(),
                ]);
                return $row;
            },3);
            if(!$job)continue;
            $recipient=DB::table('sos_recipients')->where('id',$job->recipient_id)->first();
            $incident=$recipient?DB::table('sos_incidents')->where('id',$recipient->incident_id)->first():null;
            $token=DB::table('notification_device_tokens')->where('device_id',$job->device_id)
                ->whereNull('disabled_at')->first();
            $valid=$incident && $incident->status==='active' && $recipient
                && $recipient->state==='pending'
                && $this->policy->recipientEligible($incident,$recipient)
                && $token && $token->user_id===$recipient->user_id
                && DB::table('devices')->where('id',$job->device_id)
                    ->where('user_id',$recipient->user_id)->where('trust_state','trusted')
                    ->whereNotNull('approved_at')->whereNull('revoked_at')->exists()
                && DB::table('orbit_sessions')->where('user_id',$recipient->user_id)
                    ->where('device_id',$job->device_id)->whereNull('revoked_at')
                    ->where('expires_at','>',now())->exists();
            if(!$valid){
                $this->finish($id,'suppressed','eligibility_changed',null);
                $stats['suppressed']++;continue;
            }
            $outcome=['status'=>'retry','code'=>'local_transport_failure'];
            try{
                $outcome=$this->push->send(Crypt::decryptString($token->token_ciphertext),'sos');
            }catch(\Throwable){}
            $result=$outcome['status']??'retry';
            if($result==='accepted'){
                $this->finish($id,'accepted',null,null);
                $stats['provider_accepted']++;
            } elseif($result==='invalid_token' || $result==='terminal'
                || (int)$job->attempts+1 >= (int)config('orbit_sos.max_push_attempts',6)){
                $this->finish($id,'failed',in_array($result,['invalid_token','terminal'],true)
                    ? 'provider_rejected' : 'attempts_exhausted',null);
                $stats['failed']++;
            } else {
                if($this->policy->control('push_retries_enabled','1')!=='1'){
                    $this->finish($id,'failed','retry_disabled',null);
                    $stats['failed']++;continue;
                }
                $attempt=(int)$job->attempts+1;
                $backoff=min(3600,30*(2**min($attempt,6)))+random_int(0,15);
                $retry=(int)($outcome['retry_after']??0);
                $this->finish($id,'retry','provider_unavailable',now()->addSeconds(max($backoff,$retry)));
                $stats['retry']++;
            }
        }
        return $stats;
    }

    private function reconcile(int $limit,array &$stats): void
    {
        $rows=DB::table('sos_recipients as r')
            ->join('sos_incidents as i','i.id','=','r.incident_id')
            ->where('i.status','active')->where('r.state','pending')
            ->orderByDesc('r.created_at')->limit($limit)->select('r.*','i.initiator_id',
                'i.circle_id','i.status')->get();
        foreach($rows as $row){
            if($this->policy->recipientEligible($row,$row))continue;
            DB::transaction(function()use($row,&$stats):void{
                $incident=DB::table('sos_incidents')->where('id',$row->incident_id)->lockForUpdate()->first();
                $recipient=DB::table('sos_recipients')->where('id',$row->id)->lockForUpdate()->first();
                if(!$incident || !$recipient || $incident->status!=='active'
                    || $recipient->state!=='pending'
                    || $this->policy->recipientEligible($incident,$recipient))return;
                DB::table('sos_recipients')->where('id',$row->id)->update([
                    'state'=>'suppressed','suppressed_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('sos_delivery_jobs')->where('recipient_id',$row->id)
                    ->whereIn('state',['pending','retry','processing'])
                    ->update(['state'=>'suppressed','lease_until'=>null,'updated_at'=>now()]);
                $this->audit->incident($incident->id,null,'sos.recipient_revoked');
                $stats['suppressed']++;
            },3);
        }
    }

    private function escalate(int $limit,array &$stats): void
    {
        if($this->policy->control('escalation_enabled','1')!=='1')return;
        $ids=DB::table('sos_incidents')->where('status','active')
            ->whereNotNull('escalation_due_at')->where('escalation_due_at','<=',now())
            ->whereNull('escalated_at')->orderBy('escalation_due_at')->limit($limit)->pluck('id');
        foreach($ids as $id){
            DB::transaction(function()use($id,&$stats):void{
                $incident=DB::table('sos_incidents')->where('id',$id)->lockForUpdate()->first();
                if(!$incident || $incident->status!=='active' || $incident->escalated_at
                    || !$incident->escalation_due_at || now()->lt($incident->escalation_due_at))return;
                $already=false;
                foreach(DB::table('sos_recipients')->where('incident_id',$id)
                    ->where('state','acknowledged')->get() as $response){
                    if($this->policy->recipientEligible($incident,$response)){
                        $already=true;break;
                    }
                }
                if(!$already){
                    $trusted=DB::table('sos_trusted_contacts')
                        ->where('owner_id',$incident->initiator_id)->where('state','accepted')
                        ->limit(100)->pluck('recipient_id');
                    foreach($trusted as $user){
                        if(!$this->policy->trusted($incident->initiator_id,(string)$user))continue;
                        $this->service->recipient($id,(string)$user,'trusted');
                        $stats['recipients']++;
                    }
                }
                DB::table('sos_incidents')->where('id',$id)
                    ->update(['escalated_at'=>now(),'updated_at'=>now()]);
                $this->audit->incident($id,null,$already?'sos.escalation_unneeded':'sos.escalated');
                $stats['escalated']++;
            },3);
        }
    }

    private function seedJobs(int $limit,array &$stats): void
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('notification_device_tokens'))return;
        $rows=DB::table('sos_recipients as r')->join('sos_incidents as i',
            'i.id','=','r.incident_id')->where('i.status','active')
            ->where('r.state','pending')->orderByDesc('r.created_at')
            ->limit($limit*4)->select('r.*','i.initiator_id','i.circle_id','i.status')->get();
        foreach($rows as $row){
            if(!$this->policy->recipientEligible($row,$row))continue;
            $devices=DB::table('notification_device_tokens as t')
                ->join('devices as d','d.id','=','t.device_id')
                ->where('t.user_id',$row->user_id)->whereNull('t.disabled_at')
                ->whereNull('d.revoked_at')->where('d.trust_state','trusted')
                ->whereNotNull('d.approved_at')->limit(20)->pluck('t.device_id');
            foreach($devices as $device){
                $stats['jobs_created']+=DB::table('sos_delivery_jobs')->insertOrIgnore([
                    'id'=>(string)Str::uuid(),'recipient_id'=>$row->id,
                    'device_id'=>$device,'state'=>'pending','attempts'=>0,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                if($stats['jobs_created']>=$limit)return;
            }
        }
    }

    private function finish(string $id,string $state,?string $code,?\DateTimeInterface $retry): void
    {
        DB::transaction(function()use($id,$state,$code,$retry):void{
            DB::table('sos_delivery_jobs')->where('id',$id)->update([
                'state'=>$state,'last_error_code'=>$code,'lease_until'=>null,
                'next_attempt_at'=>$retry,
                'provider_accepted_at'=>$state==='accepted'?now():null,'updated_at'=>now(),
            ]);
            DB::table('sos_dispatch_attempts')->insert([
                'id'=>(string)Str::uuid(),'job_id'=>$id,'state'=>$state,
                'error_code'=>$code,'created_at'=>now(),
            ]);
        },3);
    }
}
