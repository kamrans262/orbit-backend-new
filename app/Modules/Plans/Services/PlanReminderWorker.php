<?php

namespace App\Modules\Plans\Services;

use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PlanReminderWorker
{
    public function __construct(private readonly PlanPolicy $policy,
        private readonly NotificationPolicy $notifications,
        private readonly NotificationService $service) {}

    private function ready(): bool
    {
        return config('orbit_plans.enabled') === true && $this->policy->installed()
            && config('orbit_notifications.enabled')===true
            && $this->notifications->installed();
    }

    public function run(int $limit=40): array
    {
        $stats=['events'=>0,'reminders'=>0,'suppressed'=>0,'retry'=>0];
        if(!$this->ready())return $stats;
        $limit=max(1,min(100,$limit));
        // Claim outbox records with short leases; recover after worker crash.
        $events=DB::table('plan_change_events')->whereNull('projected_at')
            ->where(fn($q)=>$q->whereNull('lease_until')->orWhere('lease_until','<=',now()))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        foreach($events as $id){
            $event=DB::transaction(function()use($id){
                $row=DB::table('plan_change_events')->where('id',$id)->lockForUpdate()->first();
                if(!$row || $row->projected_at || ($row->lease_until
                    && now()->lt($row->lease_until)))return null;
                DB::table('plan_change_events')->where('id',$id)->update([
                    'lease_until'=>now()->addMinutes(2),'attempts'=>$row->attempts+1,
                    'updated_at'=>now(),
                ]);
                return $row;
            },3);
            if(!$event)continue;
            try {
                $allowed=$this->policy->canView($event->plan_id,$event->recipient_user_id);
                if($allowed){
                    $type=match($event->event_type){
                        'invited'=>'plan.invited','cancelled'=>'plan.cancelled',
                        'rsvp'=>'plan.rsvp',default=>'plan.updated',
                    };
                    $this->service->publish('plan_event',$id,'plans',$type,
                        $event->plan_id,[$event->recipient_user_id],null,null,
                        $event->created_at);
                }
                DB::table('plan_change_events')->where('id',$id)->update([
                    'projected_at'=>now(),'lease_until'=>null,'updated_at'=>now(),
                ]);
                $stats[$allowed?'events':'suppressed']++;
            } catch(\Throwable) {
                DB::table('plan_change_events')->where('id',$id)->update([
                    'lease_until'=>now()->addMinutes(min(60,2**min(6,$event->attempts+1))),
                    'updated_at'=>now(),
                ]);
                $stats['retry']++;
            }
        }

        if(!$this->policy->control('reminders_enabled'))return $stats;
        $ids=DB::table('plan_reminder_jobs')
            ->where(fn($q)=>$q->whereIn('state',['pending','retry'])
                ->orWhere(fn($w)=>$w->where('state','processing')
                    ->where('lease_until','<=',now())))
            ->where('due_at','<=',now())->orderBy('due_at')->limit($limit)->pluck('id');
        foreach($ids as $id) {
            $job=DB::transaction(function()use($id){
                $row=DB::table('plan_reminder_jobs')->where('id',$id)
                    ->lockForUpdate()->first();
                if(!$row || !in_array($row->state,['pending','retry','processing'],true)
                    || ($row->state==='processing' && $row->lease_until
                        && now()->lt($row->lease_until)))return null;
                DB::table('plan_reminder_jobs')->where('id',$id)->update([
                    'state'=>'processing','attempts'=>$row->attempts+1,
                    'lease_until'=>now()->addMinutes(2),'updated_at'=>now(),
                ]);
                return $row;
            },3);
            if(!$job)continue;
            $plan=DB::table('plans')->where('id',$job->plan_id)->first();
            $canSend=$plan && $plan->status==='scheduled'
                && (int)$plan->revision===(int)$job->plan_revision
                && Carbon::parse($job->due_at)->gte(now()->subMinutes(5))
                && $this->policy->canViewRecord($plan,$job->user_id)
                && ($plan->organizer_id===$job->user_id
                    || DB::table('plan_invitees')->where('plan_id',$plan->id)
                        ->where('user_id',$job->user_id)->where('state','joined')->exists())
                && $this->policy->control('reminders_enabled')
                && $this->notifications->categoryEnabled($job->user_id,'plans');
            if(!$canSend){
                DB::table('plan_reminder_jobs')->where('id',$id)->update([
                    'state'=>'suppressed','lease_until'=>null,'updated_at'=>now(),
                ]);
                $stats['suppressed']++;continue;
            }
            try {
                $this->service->publish('plan_reminder',$id,'plans','plan.reminder',
                    $plan->id,[$job->user_id],null,null,$job->due_at);
                DB::table('plan_reminder_jobs')->where('id',$id)->update([
                    'state'=>'sent','delivered_at'=>now(),'lease_until'=>null,'updated_at'=>now(),
                ]);
                $stats['reminders']++;
            } catch(\Throwable) {
                $attempts=(int)$job->attempts+1;
                DB::table('plan_reminder_jobs')->where('id',$id)->update([
                    'state'=>$attempts>=8?'failed':'retry',
                    'error_code'=>'notification_projection_error',
                    'lease_until'=>null,
                    'due_at'=>now()->addMinutes(min(60,2**min(6,$attempts))),
                    'updated_at'=>now(),
                ]);
                $stats['retry']++;
            }
        }
        return $stats;
    }
}
