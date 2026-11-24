<?php
namespace App\Modules\Realtime\Services;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RealtimeRecovery
{
    public function request(AdminUser $actor,string $id,string $operation,string $reason):void
    {
        abort_unless($actor->canPerform('config.manage'),403);
        abort_unless(in_array($operation,['retry','probe'],true)&&Str::isUuid($id),422);
        abort_unless(app(RealtimeSessions::class)->cleanupEnabled(),409,'Provider deployment is disabled or unverified.');
        Cache::store('orbit_ephemeral')->lock('orbit:m25:recovery',15)->block(2,function()use($actor,$id,$operation,$reason){
            DB::transaction(function()use($actor,$id,$operation,$reason){
                if(DB::table('orbit_realtime_recovery')->where('id',$id)->exists())return;
                abort_if(DB::table('orbit_realtime_recovery')->where('created_at','>',now()->subMinute())->exists(),429,'Recovery is rate limited.');
                $affected=$operation==='retry'?app(RealtimeSessions::class)->requestRetry():0;
                DB::table('orbit_realtime_recovery')->insert(['id'=>$id,'operation'=>$operation,'affected'=>$affected,
                    'result'=>$operation==='retry'?'Scheduled':null,'processed_at'=>$operation==='retry'?now():null,'created_at'=>now()]);
                $prev=DB::table('admin_audit_log')->orderByDesc('created_at')->lockForUpdate()->first()?->event_hash;
                $target='realtime:'.$operation;
                $after=hash('sha256',json_encode([$id,$operation,$affected],JSON_THROW_ON_ERROR));
                DB::table('admin_audit_log')->insert([
                    'id'=>(string)Str::uuid7(),'actor_id'=>$actor->id,'action'=>'realtime.recovery','target'=>$target,
                    'reason'=>$reason,'request_id'=>$id,'ip_address'=>request()->ip(),'before_hash'=>null,'after_hash'=>$after,
                    'previous_hash'=>$prev,'event_hash'=>hash('sha256',implode('|',[$prev??'',$actor->id,$target,$after,$reason])),
                    'created_at'=>now(),
                ]);
            });
        });
    }
    public function processProbe():void
    {
        if(!app(RealtimeSessions::class)->cleanupEnabled()||!Schema::hasTable('orbit_realtime_recovery'))return;
        Cache::store('orbit_ephemeral')->lock('orbit:m25:probe',20)->get(function(){
            $row=DB::table('orbit_realtime_recovery')->where('operation','probe')->whereNull('processed_at')
                ->orderBy('created_at')->first();
            if(!$row)return;
            $result=app(FirebaseRealtimeProvider::class)->probe()?'Reachable':'Unavailable';
            DB::table('orbit_realtime_recovery')->where('id',$row->id)->whereNull('processed_at')
                ->update(['result'=>$result,'processed_at'=>now()]);
        });
    }
}
