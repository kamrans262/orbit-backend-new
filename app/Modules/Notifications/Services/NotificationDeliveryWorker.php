<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationDeliveryWorker
{
    public function __construct(private readonly NotificationPolicy $policy,
                                private readonly NotificationPushTransport $transport) {}

    public function run(int $limit=40): array
    {
        $stats=['created'=>0,'accepted'=>0,'retry'=>0,'terminal'=>0,'suppressed'=>0];
        if(!$this->policy->installed() || !$this->transport->enabled()
            || !$this->policy->enabled('channel.push'))return $stats;
        $limit=max(1,min(100,$limit));
        // Bridge previously authorized legacy Moment tokens only while the
        // shared provider is independently enabled. Existing M7 records stay.
        app(NotificationDeviceBridge::class)->bridge(min(50,$limit));
        $notices=DB::table('notification_inbox')->whereNull('invalidated_at')
            ->whereNull('read_at')
            ->where('created_at','>=',now()->subMinutes(
                (int)config('orbit_notifications.push_max_age_minutes',30)))
            ->orderByDesc('created_at')->limit($limit*5)->get();
        foreach($notices as $notice){
            if($stats['created']>=$limit)break;
            // M14 owns all notification categories, including Moments.
            // Keep the legacy ORBIT_M7_FCM_ENABLED switch OFF to avoid
            // duplicate sends through the older M7-only delivery worker.
            if(!$this->policy->pushAllowed($notice,$notice->recipient_user_id))continue;
            $devices=DB::table('notification_device_tokens as t')
                ->join('devices as d','d.id','=','t.device_id')
                ->where('t.user_id',$notice->recipient_user_id)
                ->whereNull('t.disabled_at')->whereNull('d.revoked_at')
                ->where('d.trust_state','trusted')->whereNotNull('d.approved_at')
                ->limit(20)->pluck('t.device_id');
            foreach($devices as $device){
                if(!DB::table('orbit_sessions')->where('user_id',$notice->recipient_user_id)
                    ->where('device_id',$device)->whereNull('revoked_at')
                    ->where('expires_at','>',now())->exists())continue;
                $stats['created']+=DB::table('notification_jobs')->insertOrIgnore([
                    'id'=>(string)Str::uuid(),'notice_id'=>$notice->id,
                    'device_id'=>$device,'channel'=>'push','state'=>'pending',
                    'attempts'=>0,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
        $candidates=DB::table('notification_jobs')
            ->where(function($q){
                $q->whereIn('state',['pending','retry'])
                    ->where(fn($d)=>$d->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at','<=',now()))
                ->orWhere(fn($d)=>$d->where('state','processing')
                    ->where('lease_until','<=',now()));
            })->orderBy('created_at')->limit($limit)->pluck('id');
        foreach($candidates as $id){
            $claimed=DB::transaction(function()use($id):?object{
                $job=DB::table('notification_jobs')->where('id',$id)->lockForUpdate()->first();
                if(!$job || !in_array($job->state,['pending','retry','processing'],true)
                    || ($job->state==='processing' && $job->lease_until && now()->lt($job->lease_until))
                    || ($job->next_attempt_at && now()->lt($job->next_attempt_at)))return null;
                DB::table('notification_jobs')->where('id',$id)->update([
                    'state'=>'processing','attempts'=>$job->attempts+1,
                    'lease_until'=>now()->addSeconds((int)config('orbit_notifications.worker_lease_seconds',120)),
                    'updated_at'=>now(),
                ]);
                return $job;
            },3);
            if(!$claimed)continue;
            $notice=DB::table('notification_inbox')->where('id',$claimed->notice_id)->first();
            $token=DB::table('notification_device_tokens')->where('device_id',$claimed->device_id)
                ->whereNull('disabled_at')->first();
            $valid=$notice && $token && $token->user_id===$notice->recipient_user_id
                && $this->policy->pushAllowed($notice,$notice->recipient_user_id)
                && DB::table('devices')->where('id',$claimed->device_id)
                    ->where('user_id',$notice->recipient_user_id)->where('trust_state','trusted')
                    ->whereNotNull('approved_at')->whereNull('revoked_at')->exists()
                && DB::table('orbit_sessions')->where('device_id',$claimed->device_id)
                    ->where('user_id',$notice->recipient_user_id)->whereNull('revoked_at')
                    ->where('expires_at','>',now())->exists()
                && $this->transport->enabled()
                && $this->policy->enabled('channel.push');
            if(!$valid){
                $this->finish($id,'suppressed','eligibility_changed',null);
                $stats['suppressed']++;continue;
            }
            try{
                $outcome=$this->transport->send(
                    Crypt::decryptString($token->token_ciphertext),
                    $notice->category);
            }catch(\Throwable) {
                $outcome=['status'=>'retry','code'=>'local_transport_failure'];
            }
            $attempt=(int)$claimed->attempts+1;
            $status=$outcome['status']??'retry';
            if($status==='invalid_token'){
                // Unregistered tokens may not continue retrying. A later
                // verified token refresh can register the new token.
                DB::table('notification_device_tokens')
                    ->where('device_id',$claimed->device_id)
                    ->where('user_id',$notice->recipient_user_id)
                    ->whereNull('disabled_at')
                    ->update(['disabled_at'=>now(),'updated_at'=>now()]);
                $this->finish($id,'failed','token_rejected',null);
                $stats['terminal']++;
                continue;
            }
            if($status==='accepted'){
                $this->finish($id,'accepted',null,null);
                $stats['accepted']++;
                continue;
            }
            $terminal=$status==='terminal'
                || $attempt>=(int)config('orbit_notifications.max_attempts',8);
            $next=null;
            if(!$terminal){
                $base=min(86400,30*2**min($attempt,10));
                $jitter=random_int(0,max(1,min(60,intdiv($base,5))));
                $provider=(int)($outcome['retry_after']??0);
                $next=now()->addSeconds(max($provider,$base+$jitter));
            }
            $error=in_array($outcome['code']??'',[
                'provider_off','network_or_oauth','transient_provider',
                'provider_rejected','category_unsupported',
                'local_transport_failure',
            ],true)?$outcome['code']:'provider_failure';
            $this->finish($id,$terminal?'failed':'retry',$error,$next);
            $stats[$terminal?'terminal':'retry']++;
        }
        return $stats;
    }

    private function finish(string $id,string $state,?string $error,?\DateTimeInterface $retry): void
    {
        DB::transaction(function()use($id,$state,$error,$retry):void{
            DB::table('notification_jobs')->where('id',$id)->update([
                'state'=>$state,'last_error_code'=>$error,
                'accepted_at'=>$state==='accepted'?now():null,
                'next_attempt_at'=>$retry,'lease_until'=>null,'updated_at'=>now(),
            ]);
            DB::table('notification_attempts')->insert([
                'id'=>(string)Str::uuid(),'job_id'=>$id,'provider'=>'fcm',
                'state'=>$state,'error_code'=>$error,'attempted_at'=>now(),
            ]);
        });
    }
}
