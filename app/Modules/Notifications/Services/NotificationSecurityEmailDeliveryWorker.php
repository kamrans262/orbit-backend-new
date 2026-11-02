<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Verified transactional security mail only. No arbitrary Admin recipients,
 * marketing, on-device message previews or uncontrolled retry loops.
 */
final class NotificationSecurityEmailDeliveryWorker
{
    public function __construct(private readonly NotificationPolicy $policy,
                                private readonly NotificationSecurityEmailTransport $mail) {}

    public function run(int $limit=30): array
    {
        $stats=['created'=>0,'accepted'=>0,'retry'=>0,'failed'=>0,'suppressed'=>0];
        if(!$this->policy->installed()
            || !Schema::hasTable('notification_security_email_jobs')
            || !$this->mail->enabled())return $stats;

        $limit=max(1,min(50,$limit));
        $notices=DB::table('notification_inbox')->where('category','security')
            ->whereNull('invalidated_at')
            ->where('created_at','>=',now()->subMinutes(30))
            ->orderBy('created_at')->limit($limit*5)->get();
        foreach($notices as $notice){
            if($stats['created']>=$limit)break;
            if(!$this->policy->canView($notice,$notice->recipient_user_id))continue;
            $stats['created']+=DB::table('notification_security_email_jobs')
                ->insertOrIgnore([
                    'id'=>(string)Str::uuid(),'notice_id'=>$notice->id,
                    'state'=>'pending','attempts'=>0,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
        }

        $jobs=DB::table('notification_security_email_jobs')
            ->where(fn($q)=>$q->whereIn('state',['pending','retry'])
                ->where(fn($s)=>$s->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at','<=',now()))
                ->orWhere(fn($s)=>$s->where('state','processing')
                    ->where('lease_until','<=',now())))
            ->orderBy('created_at')->limit($limit)->pluck('id');

        foreach($jobs as $id){
            $locked=DB::transaction(function()use($id):?object{
                $job=DB::table('notification_security_email_jobs')->where('id',$id)
                    ->lockForUpdate()->first();
                if(!$job || !in_array($job->state,['pending','retry','processing'],true)
                    || ($job->state==='processing' && $job->lease_until
                        && now()->lt($job->lease_until))
                    || ($job->next_attempt_at && now()->lt($job->next_attempt_at)))return null;
                DB::table('notification_security_email_jobs')->where('id',$id)->update([
                    'state'=>'processing','attempts'=>$job->attempts+1,
                    'lease_until'=>now()->addSeconds(120),'updated_at'=>now(),
                ]);
                return $job;
            },3);
            if(!$locked)continue;
            $outcome=$this->mail->sendNotice($locked->notice_id);
            $status=$outcome['status']??'retry';
            $tries=$locked->attempts+1;
            $state=match(true){
                $status==='accepted_by_mailer'=>'accepted',
                $status==='disabled' || $status==='suppressed'=>'suppressed',
                $tries>=8=>'failed',
                default=>'retry',
            };
            $next=null;
            if($state==='retry'){
                $delay=min(86400,30*2**min($tries,10));
                $next=now()->addSeconds($delay+random_int(0,min(60,intdiv($delay,5))));
            }
            DB::table('notification_security_email_jobs')->where('id',$id)->update([
                'state'=>$state,'last_error_code'=>$state==='accepted'?null:
                    ($state==='suppressed'?'eligibility_changed':'provider_unavailable'),
                'next_attempt_at'=>$next,'lease_until'=>null,
                'accepted_at'=>$state==='accepted'?now():null,'updated_at'=>now(),
            ]);
            $stats[$state]++;
        }
        return $stats;
    }
}
