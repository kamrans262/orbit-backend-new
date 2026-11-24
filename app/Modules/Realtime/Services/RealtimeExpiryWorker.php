<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Supplement natural HTTPS expiry with one bounded, consent-checked refresh. */
final class RealtimeExpiryWorker
{
    public function run():int
    {
        $sessions=app(RealtimeSessions::class);
        if(!$sessions->enabled('check_in')||config('orbit_check_in.enabled')!==true
            ||!Schema::hasColumn('checkins','realtime_expiry_notified_at'))return 0;
        $ids=DB::table('checkins')->whereNull('deleted_at')->whereNull('realtime_expiry_notified_at')
            ->where('expires_at','<=',now())->where('expires_at','>=',now()->subMinutes(2))
            ->orderBy('expires_at')->limit(20)->pluck('id');
        foreach($ids as $id)DB::transaction(function()use($id,$sessions){
            $r=DB::table('checkins')->where('id',$id)->lockForUpdate()->first();
            if(!$r||$r->deleted_at||$r->realtime_expiry_notified_at||!$r->expires_at||now()->lt($r->expires_at))return;
            $scopes=DB::table('checkin_audience')->where('checkin_id',$id)->where('audience_revision',$r->audience_revision)->get();
            $users=DB::table('checkin_recipients')->where('checkin_id',$id)->where('audience_revision',$r->audience_revision)
                ->whereNull('revoked_at')->limit(1000)->pluck('recipient_user_id')->all();
            $policy=app(\App\Modules\CheckIn\Services\CheckInAccessPolicy::class);
            $eligible=array_filter($users,fn($u)=>$scopes->contains(fn($s)=>$policy->allows($u,$r->creator_id,[
                'type'=>$s->audience_type,'id'=>$s->audience_id===''?null:$s->audience_id,
            ])));
            DB::table('checkins')->where('id',$id)->update(['realtime_expiry_notified_at'=>now()]);
            $sessions->signalUsers('check_in',[$r->creator_id,...$eligible],['kind'=>'clear']);
        },3);
        return $ids->count();
    }
}
