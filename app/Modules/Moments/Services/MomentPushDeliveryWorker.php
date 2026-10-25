<?php
namespace App\Modules\Moments\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Config-gated, device-scoped FCM delivery, with independent retry receipts.
 * Does not acknowledge device display or read status, and never leaks content.
 */
final class MomentPushDeliveryWorker
{
    public function run(int $limit=30):array
    {
        $stats=['attempted'=>0,'accepted_by_fcm'=>0,'failed'=>0,'skipped'=>0];
        $transport=app(MomentFcmTransport::class);
        if(!$transport->enabled())return $stats;
        $policy=app(MomentPolicy::class);
        $notices=DB::table('moment_notification_inbox as n')
            ->join('moment_push_devices as t','t.user_id','=','n.recipient_user_id')
            ->join('devices as d',function($join):void{
                $join->on('d.id','=','t.device_id')
                    ->on('d.user_id','=','t.user_id');
            })
            ->leftJoin('moment_push_deliveries as receipt',function($join):void{
                $join->on('receipt.notice_id','=','n.id')
                    ->on('receipt.device_id','=','t.device_id');
            })
            ->where(function($q):void{
                $q->whereNull('receipt.state')
                    ->orWhere(function($retry):void{
                        $retry->where('receipt.state','retry')
                            ->where('receipt.next_attempt_at','<=',now());
                    });
            })
            ->whereNull('t.disabled_at')->whereNull('d.revoked_at')
            ->where('d.trust_state','trusted')->whereNotNull('d.approved_at')
            ->where('n.created_at','>',now()->subDays(7))
            ->orderBy('n.created_at')->limit(max(1,min($limit,100)))
            ->get(['n.id','n.moment_id','n.context_id','n.recipient_user_id',
                't.device_id','t.token_ciphertext']);
        foreach($notices as $notice){
            $where=['notice_id'=>$notice->id,'device_id'=>$notice->device_id];
            $record=DB::table('moment_push_deliveries')->where($where)->first();
            if($record && (in_array($record->state,['accepted','failed'],true)
                || ($record->next_attempt_at && now()->lt($record->next_attempt_at)))){
                $stats['skipped']++;continue;
            }
            $raw=DB::table('orbit_user_preferences')->where('user_id',$notice->recipient_user_id)
                ->value('notifications');
            $p=is_string($raw)?json_decode($raw,true):null;
            $moment=DB::table('moments')->where('id',$notice->moment_id)->first();
            if(!is_array($p) || ($p['moments_notifications_enabled']??false)!==true
                || ($p['push_notifications_enabled']??false)!==true
                || !$moment || $policy->context($moment,$notice->recipient_user_id,
                    $notice->context_id)!==$notice->context_id){
                $stats['skipped']++;continue;
            }
            if(str_starts_with($notice->context_id,'circle:') &&
                DB::table('moment_notification_muted_circles')
                    ->where('user_id',$notice->recipient_user_id)
                    ->where('circle_id',substr($notice->context_id,7))->exists()){
                $stats['skipped']++;continue;
            }
            $stats['attempted']++;
            $attempt=(int)($record->attempts??0)+1;
            try{
                $token=Crypt::decryptString($notice->token_ciphertext);
                $accepted=$transport->sendGeneric($token);
            }catch(\Throwable){$accepted=false;}
            $state=$accepted?'accepted':($attempt>=8?'failed':'retry');
            $delay=min(86400,30*(2**min(10,$attempt)));
            DB::table('moment_push_deliveries')->updateOrInsert($where,[
                'state'=>$state,'attempts'=>$attempt,
                'next_attempt_at'=>$accepted||$attempt>=8?null:now()->addSeconds($delay),
                'delivered_at'=>$accepted?now():null,
                'last_error_code'=>$accepted?null:'fcm_unavailable_or_rejected',
                'updated_at'=>now(),
            ]);
            if($accepted)$stats['accepted_by_fcm']++;else $stats['failed']++;
        }
        return $stats;
    }
}
