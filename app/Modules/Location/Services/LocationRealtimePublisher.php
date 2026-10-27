<?php
namespace App\Modules\Location\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/** Viewer-specific invalidations; never publishes GPS or user identifiers. */
final class LocationRealtimePublisher
{
    public function __construct(private readonly LocationRealtimeTicketService $tickets,
        private readonly LocationPolicy $policy) {}

    public function invalidate(string $owner): void
    {
        if (!$this->tickets->enabled()) return;
        try {
            $shares=DB::table('location_shares')->where('owner_id',$owner)
                ->whereIn('status',['active','revoked','expired'])
                ->where('created_at','>=',now()->subDays(7))
                ->limit((int)config('orbit_location.max_shares_per_user',60)+1)->get();
            $viewers=[];
            foreach($shares as $share) {
                if($share->audience_type==='person'){
                    $viewers[]=(string)$share->audience_id;
                } elseif($share->audience_type==='circle') {
                    $members=DB::table('circle_members')->where('circle_id',$share->audience_id)
                        ->whereNull('removed_at')->limit(201)->pluck('user_id');
                    foreach($members as $member) $viewers[]=(string)$member;
                }
            }
            foreach(array_unique($viewers) as $viewer) {
                if($viewer===$owner)continue;
                // No subject or coordinates in payload; every receiver gets
                // a fresh viewer-authenticated REST projection.
                $channel='orbit:m10:viewer:'.hash_hmac('sha256',$viewer,(string)config('app.key'));
                Redis::connection((string)config('orbit_location.redis_connection','default'))
                    ->publish($channel,json_encode(['v'=>1,
                        'type'=>'location.invalidated'],JSON_THROW_ON_ERROR));
            }
        }catch(\Throwable) {
            // No GPS or recipient IDs in logs.
            Log::warning('M10 realtime invalidation unavailable; REST authorization remains enforced.');
        }
    }
}
