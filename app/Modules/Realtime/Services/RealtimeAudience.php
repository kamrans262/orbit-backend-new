<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Reuse domain read policies for narrowly scoped generic invalidation audiences. */
final class RealtimeAudience
{
    public function __construct(private readonly RealtimeSessions $sessions){}
    public function checkInUsers(string $id,bool $activity=false):array
    {
        if(!$this->sessions->enabled($activity?'activity':'check_in'))return [];
        $row=DB::table('checkins')->where('id',$id)->first();
        if(!$row)return [];
        $users=DB::table('checkin_recipients')->where('checkin_id',$id)->where('audience_revision',$row->audience_revision)
            ->whereNull('revoked_at')->limit(1000)->pluck('recipient_user_id')->all();
        $policy=app(\App\Modules\CheckIn\Services\CheckInAccessPolicy::class);
        return array_values(array_unique([$row->creator_id,...array_filter($users,fn($u)=>$policy->canRead($row,$u))]));
    }
    public function momentUsers(string $id,bool $clear=false):array
    {
        $row=DB::table('moments')->where('id',$id)->first();
        if(!$row)return [];
        $users=DB::table('moment_recipients')->where('moment_id',$id)
            ->where('version',$row->audience_revision)->whereNull('revoked_at')->limit(1000)->pluck('viewer_id')->all();
        $policy=app(\App\Modules\Moments\Services\MomentPolicy::class);
        $allowed=array_filter($users,fn($u)=>$policy->context($row,$u)!==null);
        // Explicit mutation-time cache clears may include the immediately prior grant version.
        if($clear){
            $removed=DB::table('moment_recipients')->where('moment_id',$id)
                ->whereIn('version',[$row->audience_revision,max(1,$row->audience_revision-1)])
                ->where('revoked_at','>=',now()->subSeconds(5))->limit(1000)->pluck('viewer_id')->all();
            $allowed=array_merge($allowed,$removed);
        }
        return array_values(array_unique([$row->creator_id,...$allowed]));
    }
    public function moment(string $id,bool $clear=false):void
    {
        if(!$this->sessions->enabled('moments'))return;
        $current=$this->momentUsers($id);
        $this->sessions->signalUsers('moments',$current,['kind'=>'moment','id'=>$id]);
        if($clear)$this->sessions->signalUsers('moments',array_values(array_diff($this->momentUsers($id,true),$current)),['kind'=>'clear']);
    }
    /** At expiry/restriction, only the owner and current grant snapshot receive a bounded cache clear. */
    public function momentClear(object $moment):void
    {
        if(!$this->sessions->enabled('moments'))return;
        $users=$this->momentUsers($moment->id);
        // Natural expiry is already enforced by the domain clock; after expiry only the owner is signaled.
        $this->sessions->signalUsers('moments',$users,['kind'=>'clear']);
    }

    public function activity(string $type,string $id):void
    {
        if(!$this->sessions->enabled('activity'))return;
        $event=DB::table('activity_events')->where('source_type',$type)->where('source_id',$id)->first();
        if(!$event)return;
        $policy=app(\App\Modules\Activity\Services\ActivityPolicy::class);
        $users=$type==='moment'?$this->momentUsers($id):$this->checkInUsers($id,true);
        $this->sessions->signalUsers('activity',array_values(array_filter($users,fn($u)=>$policy->allows($event,$u))),['kind'=>'activity','id'=>$event->id]);
    }
    public function sos(string $incident,?array $only=null):void
    {
        if(!$this->sessions->enabled('sos'))return;
        try{
            $row=DB::table('sos_incidents')->where('id',$incident)->first();
            if(!$row)return;
            $q=DB::table('sos_recipients')->where('incident_id',$incident);
            if($only!==null)$q->whereIn('user_id',$only);
            $policy=app(\App\Modules\Safety\Services\SosPolicy::class);
            $users=$q->limit(1000)->get()->filter(fn($r)=>$policy->recipientEligible($row,$r))->pluck('user_id')->all();
            $this->sessions->signalUsers('sos',[$row->initiator_id,...$users],['kind'=>'sos','id'=>$incident]);
        }catch(\Throwable){Log::warning('M25 supplemental SOS signal deferred.');}
    }
}
