<?php
namespace App\Modules\Relay\Services;
use Illuminate\Support\Facades\DB;

final class RelayMetrics
{
    public function record(string $outcome):void {
        if(!in_array($outcome,['lease_issued','lease_revoked','accepted','duplicate','rejected','expired','retryable','verified_receipt'],true)) return;
        $key=['day'=>now()->utc()->toDateString(),'outcome'=>$outcome];
        DB::table('relay_daily_metrics')->insertOrIgnore($key+['total'=>0]);
        DB::table('relay_daily_metrics')->where($key)->increment('total');
    }

    public function prune():array {
        if(!app(RelayPolicy::class)->provisioned()) return ['leases'=>0,'aggregates'=>0];
        $cutoff=now()->subDays(max(1,min(7,(int)config('orbit_relay.metadata_retention_days',7))));
        $days=max(7,min(30,(int)config('orbit_relay.aggregate_retention_days',30)));
        // Bounded batches, only Relay metadata; FK cascade removes its receipts.
        // Never delete a source message, attachment, account or source audit.
        $leases=DB::table('relay_leases')->where('expires_at','<=',$cutoff)
            ->orderBy('expires_at')->limit(500)->pluck('id');
        $count=DB::table('relay_leases')->whereIn('id',$leases)->where('expires_at','<=',$cutoff)->delete();
        $aggregates=DB::table('relay_daily_metrics')->where('day','<',now()->utc()->subDays($days)->toDateString())->delete();
        return ['leases'=>$count,'aggregates'=>$aggregates];
    }
}
