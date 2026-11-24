<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;

/** Delivery-time authorization of short-lived internal descriptors.
 * No descriptor or source ID is ever sent to RTDB.
 */
final class RealtimeSignalPolicy
{
    public function allows(array $scope,string $viewer,string $device):bool
    {
        try{
            $id=$scope['id']??null;
            switch($scope['kind']??''){
                case 'clear':
                    // Explicit mutation-time cache invalidation, not a new
                    // protected state. The publisher enforces a five-second lifetime.
                    return true;
                case 'self': return $id===$viewer;
                case 'circle':
                    return app(\App\Modules\Circle\Services\CircleAccessPolicy::class)->isMember($id,$viewer); // M25 replaces the legacy M5 transport gate, not membership policy.
                case 'presence':
                    $policy=app(\App\Modules\Presence\Services\PresenceAccessPolicy::class);
                    if(!$policy->canView($id,$viewer,$scope['subject']))return false;
                    return !($scope['typing']??false)||app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                        ->canAccessProtected($id,$viewer,$scope['subject'],'message');
                case 'location':
                    $policy=app(\App\Modules\Location\Services\LocationPolicy::class);
                    return DB::table('location_shares')->where('owner_id',$id)->where('status','active')
                        ->limit(200)->get()->contains(fn($r)=>$policy->visibleTo($r,$viewer));
                case 'ping':
                    $r=DB::table('pings')->where('id',$id)->first();
                    return $r&&in_array($viewer,[$r->sender_id,$r->recipient_id],true)
                        &&app(\App\Modules\Ping\Services\PingAccessPolicy::class)->allows($r->sender_id,$r->recipient_id,$r->context_type,$r->context_id);
                case 'check_in':
                    $r=DB::table('checkins')->where('id',$id)->first();
                    return $r&&config('orbit_check_in.enabled')===true&&app(\App\Modules\CheckIn\Services\CheckInAccessPolicy::class)->canRead($r,$viewer);
                case 'moment':
                    $r=DB::table('moments')->where('id',$id)->first();
                    return $r&&app(\App\Modules\Moments\Services\MomentPolicy::class)->context($r,$viewer)!==null;
                case 'conversation':
                    $r=DB::table('messaging_conversations')->where('id',$id)->first();
                    if(!$r||config('orbit_messaging.enabled')!==true)return false;
                    $policy=app(\App\Modules\Messaging\Services\MessagingAccessPolicy::class);
                    $subject=$scope['subject']??null;
                    $policy->assertConversation($r,$viewer);
                    if(($scope['typing']??false)&&(!is_string($subject)||!app(\App\Modules\Messaging\Services\MessagingTypingService::class)->allowed($r,$subject)))return false;
                    return $subject===$viewer||($subject!==null&&in_array($viewer,$policy->recipients($r,$subject),true));
                case 'notice':
                    $r=DB::table('notification_inbox')->where('id',$id)->where('recipient_user_id',$viewer)->first();
                    return $r&&config('orbit_notifications.enabled')===true&&app(\App\Modules\Notifications\Services\NotificationPolicy::class)->canView($r,$viewer);
                case 'plan':
                    return config('orbit_plans.enabled')===true&&app(\App\Modules\Plans\Services\PlanPolicy::class)->canView($id,$viewer);
                case 'sos':
                    $r=DB::table('sos_incidents')->where('id',$id)->first();
                    return $r&&config('orbit_sos.enabled')===true&&app(\App\Modules\Safety\Services\SosPolicy::class)->canView($r,$viewer);
                case 'activity':
                    $r=DB::table('activity_events')->where('id',$id)->first();
                    return $r&&app(\App\Modules\Activity\Services\ActivityPolicy::class)->allows($r,$viewer);
                default:return false;
            }
        }catch(\Throwable){return false;}
    }
}
