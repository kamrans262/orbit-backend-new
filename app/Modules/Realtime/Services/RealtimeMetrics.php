<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Anonymous hourly aggregate counters; never store user or source references. */
final class RealtimeMetrics
{
    public function record(string $kind,int $bytes,int $ms):void
    {
        if(!in_array($kind,['published','failed'],true)||!Schema::hasTable('orbit_realtime_metrics'))return;
        try{
            $hour=now()->startOfHour()->toDateTimeString();
            DB::table('orbit_realtime_metrics')->insertOrIgnore(['hour'=>$hour]);
            DB::table('orbit_realtime_metrics')->where('hour',$hour)->incrementEach([
                $kind=>1,'payload_bytes'=>max(0,$bytes),'total_ms'=>max(0,$ms),
            ]);
        }catch(\Throwable){} // Never change domain behavior for optional metrics.
    }
    public function summary():array
    {
        if(!Schema::hasTable('orbit_realtime_metrics'))return [];
        $r=DB::table('orbit_realtime_metrics')->where('hour','>=',now()->subHours(24)->startOfHour())
            ->selectRaw('COALESCE(SUM(published),0) AS published, COALESCE(SUM(failed),0) AS failed, COALESCE(SUM(payload_bytes),0) AS bytes, COALESCE(SUM(total_ms),0) AS ms')->first();
        $n=(int)$r->published+(int)$r->failed;
        return ['Successful signal writes (24h)'=>(int)$r->published,'Failed signal attempts (24h)'=>(int)$r->failed,
            'Signal payload bytes (24h)'=>(int)$r->bytes,'Mean publish attempt (ms)'=>$n?round($r->ms/$n):'None',
            'Persistent delivery failures'=>DB::table('orbit_realtime_sessions')->where('attempts','>=',20)->count()];
    }
    public function prune():void
    {
        if(Schema::hasTable('orbit_realtime_metrics'))DB::table('orbit_realtime_metrics')->where('hour','<',now()->subDays(7))->delete();
    }
}
