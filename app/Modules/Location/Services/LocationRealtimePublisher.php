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

    private function firebase():bool
    {
        return $this->policy->enabled()&&app(\App\Modules\Realtime\Services\RealtimeSessions::class)->enabled('location');
    }

    public function invalidateViewer(string $viewer): void
    {
        if($this->firebase()){
            app(\App\Modules\Realtime\Services\RealtimeSessions::class)->signalUsers('location',[$viewer]);
            return;
        }
        if (!$this->tickets->enabled()) return;
        try {
            // Opaque, viewer-only notification; never add subject IDs or GPS.
            $channel='orbit:m10:viewer:'.hash_hmac('sha256',$viewer,(string)config('app.key'));
            Redis::connection((string)config('orbit_location.redis_connection','default'))
                ->publish($channel,json_encode(['v'=>1,
                    'type'=>'location.invalidated'],JSON_THROW_ON_ERROR));
        }catch(\Throwable) {
            Log::warning('M10 viewer invalidation unavailable; REST permission checks remain enforced.');
        }
    }

    public function invalidate(string $owner,bool $clear=true): void
    {
        if (!$this->firebase()&&!$this->tickets->enabled()) return;
        // Legacy gateway remains a compatibility path, also strictly post-commit.
        if(!$this->firebase()&&DB::transactionLevel()>0){
            DB::afterCommit(fn()=>$this->publishLegacy($owner,$clear));
            return;
        }
        $this->publishLegacy($owner,$clear);
    }

    private function publishLegacy(string $owner,bool $clear):void
    {
        if(!$this->firebase()&&!$this->tickets->enabled())return;
        try {
            $shares=DB::table('location_shares')->where('owner_id',$owner)
                ->where(function($q): void {
                    $q->where('status','active')
                      ->orWhere(function($r): void {
                          $r->whereIn('status',['revoked','expired'])
                            ->where('updated_at','>=',$this->firebase()?now()->subSeconds(5):now()->subDays(7));
                      });
                })
                ->limit((int)config('orbit_location.max_map_candidates',200)+1)->get();
            $viewers=[];
            foreach($shares as $share) {
                if($this->firebase()&&!$clear&&!$this->policy->active($share))continue;
                if($share->audience_type==='person'){
                    if(!$this->firebase()||$clear||$this->policy->visibleTo($share,(string)$share->audience_id))$viewers[]=(string)$share->audience_id;
                } elseif($share->audience_type==='circle') {
                    $members=DB::table('circle_members')->where('circle_id',$share->audience_id)
                        ->whereNull('removed_at')->limit(201)->pluck('user_id');
                    foreach($members as $member) if(!$this->firebase()||$clear||$this->policy->visibleTo($share,(string)$member))$viewers[]=(string)$member;
                }
            }
            if($this->firebase()){
                app(\App\Modules\Realtime\Services\RealtimeSessions::class)->signalUsers('location',array_values(array_diff(array_unique($viewers),[$owner])),$clear?['kind'=>'clear']:['kind'=>'location','id'=>$owner]);
                return;
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
