<?php
namespace App\Modules\Moments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Privacy-filtered, opt-in HTTPS inbox. This does not send push notifications
 * and MUST NOT mark a device message delivered without an actual transport.
 */
final class MomentNotificationOutboxWorker
{
    public function run(int $limit=30):array
    {
        $stats=['processed'=>0,'notices_created'=>0,'skipped'=>0,'errors'=>0];
        if(config('orbit_moments.enabled')!==true || config('orbit_media.enabled')!==true)
            return $stats;
        $policy=app(MomentPolicy::class);
        $events=DB::table('moment_events_outbox')->where('status','pending')
            ->orderBy('created_at')->limit(max(1,min(100,$limit)))->get();
        foreach($events as $event){
            try {
                DB::transaction(function()use($event,$policy,&$stats):void{
                    $locked=DB::table('moment_events_outbox')->where('id',$event->id)
                        ->lockForUpdate()->first();
                    if(!$locked || $locked->status!=='pending')return;
                    $moment=DB::table('moments')->where('id',$locked->moment_id)->first();
                    if(in_array($locked->event_type,[
                        'moment.deleted.v1','moment.expired.v1','moment.restricted.v1',
                    ],true)){
                        DB::table('moment_notification_inbox')
                            ->where('moment_id',$locked->moment_id)->delete();
                    }elseif($moment && $policy->active($moment) &&
                        in_array($locked->event_type,[
                            'moment.published.v1','moment.audience.changed.v1',
                        ],true) && (int)$locked->audience_revision===(int)$moment->audience_revision){
                        $recipients=DB::table('moment_recipients')
                            ->where('moment_id',$moment->id)
                            ->where('version',$moment->audience_revision)
                            ->whereNull('revoked_at')
                            ->limit(150)->get(['viewer_id','context_id']);
                        foreach($recipients as $recipient){
                            if($recipient->viewer_id===$moment->creator_id)continue;
                            $prefs=DB::table('orbit_user_preferences')
                                ->where('user_id',$recipient->viewer_id)->value('notifications');
                            $options=is_string($prefs)?json_decode($prefs,true):null;
                            if(!is_array($options) ||
                                ($options['moments_notifications_enabled']??false)!==true){
                                $stats['skipped']++;continue;
                            }
                            if($policy->context($moment,$recipient->viewer_id,
                                $recipient->context_id)!==$recipient->context_id){
                                $stats['skipped']++;continue;
                            }
                            // Only a generic private reference. No captions, geo,
                            // Circle rosters, usernames or recipient counts.
                            DB::table('moment_notification_inbox')->insertOrIgnore([
                                'id'=>(string)Str::uuid7(),'moment_id'=>$moment->id,
                                'event_id'=>$locked->id,
                                'recipient_user_id'=>$recipient->viewer_id,
                                'context_id'=>$recipient->context_id,
                                'created_at'=>now(),
                            ]);
                            $stats['notices_created']++;
                        }
                    }else{$stats['skipped']++;}
                    DB::table('moment_events_outbox')->where('id',$locked->id)
                        ->update(['status'=>'processed','processed_at'=>now()]);
                    $stats['processed']++;
                },3);
            }catch(\Throwable){$stats['errors']++;}
        }
        DB::table('moment_notification_inbox')->where('created_at','<',now()->subDays(7))->delete();
        return $stats;
    }
}
