<?php

namespace App\Modules\Advertising\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M20 member-specific erasure is independent of campaign/business records.
 * Called in the existing M3/M2 authorized account-erasure transaction; never
 * deletes another viewer's decision or an unrelated sponsor's campaign.
 */
final class AdvertisingErasureService
{
    public function eraseViewer(string $userId): array
    {
        if (!Schema::hasTable('ad_decisions')
            || !Schema::hasTable('ad_frequency_windows')
            || !Schema::hasTable('ad_measurement_events')) {
            return ['decisions'=>0,'measurement_events'=>0,'frequency_windows'=>0];
        }

        $digest=hash_hmac('sha256',$userId,(string)config('app.key'));
        return DB::transaction(function () use ($digest): array {
            // Keep an aggregate consistent with receipts *after* removing the
            // erased member's measurements. Event dates are acceptance dates.
            $groups=DB::table('ad_measurement_events as m')
                ->join('ad_decisions as d','d.id','=','m.decision_id')
                ->where('d.viewer_digest',$digest)
                ->select('d.campaign_id','d.placement_key')
                ->selectRaw('DATE(m.accepted_at) as day_utc')
                ->selectRaw("SUM(CASE WHEN m.event_type='impression' THEN 1 ELSE 0 END) as impression_count")
                ->selectRaw("SUM(CASE WHEN m.event_type='click' THEN 1 ELSE 0 END) as click_count")
                ->groupBy('d.campaign_id','d.placement_key')
                ->groupByRaw('DATE(m.accepted_at)')->get();

            foreach ($groups as $group) {
                $impressions=(int)$group->impression_count;
                $clicks=(int)$group->click_count;
                DB::table('ad_daily_aggregates')
                    ->where('campaign_id',$group->campaign_id)
                    ->where('placement_key',$group->placement_key)
                    ->where('day_utc',$group->day_utc)
                    ->update([
                        'impressions'=>DB::raw('GREATEST(impressions-'.$impressions.',0)'),
                        'clicks'=>DB::raw('GREATEST(clicks-'.$clicks.',0)'),
                        'updated_at'=>now('UTC'),
                    ]);
            }
            $events=DB::table('ad_measurement_events')->whereIn('decision_id',
                function ($q) use ($digest): void {
                    $q->select('id')->from('ad_decisions')->where('viewer_digest',$digest);
                })->delete();
            $decisions=DB::table('ad_decisions')->where('viewer_digest',$digest)->delete();
            $frequency=DB::table('ad_frequency_windows')->where('viewer_digest',$digest)->delete();
            return [
                'decisions'=>$decisions,
                'measurement_events'=>$events,
                'frequency_windows'=>$frequency,
            ];
        },3);
    }
}
