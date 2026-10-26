<?php
namespace App\Modules\Presence\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

final class PresenceRealtimePublisher
{
    public function __construct(private readonly PresenceAccessPolicy $policy, private readonly PresenceLeaseStore $leases) {}
    public function enabled(): bool
    {
        return $this->policy->enabled() && config('orbit_presence.realtime_enabled') === true
            && strlen((string)config('orbit_presence.gateway_secret','')) >= 32;
    }
    private function emit(string $viewer, array $event): void
    {
        $channel='orbit:m9:viewer:'.hash_hmac('sha256',$viewer,(string)config('app.key'));
        Redis::connection((string)config('orbit_presence.redis_connection','default'))
            ->publish($channel,json_encode(['v'=>1,'event'=>$event,'issued_at'=>now()->toIso8601String()],JSON_THROW_ON_ERROR));
    }
    public function changed(string $subject, bool $urgent = false): void
    {
        if (!$this->enabled()) return;
        if (!$urgent) {
            // Source coalescing target: <=1 ordinary state broadcast / 5s.
            // Redis add is atomic across Laravel workers and expires automatically.
            $gate='orbit:m9:fanout:'.hash_hmac('sha256',$subject,(string)config('app.key'));
            if (!Cache::store((string)config('orbit_presence.store','redis'))
                ->add($gate,true,(int)config('orbit_presence.broadcast_throttle_seconds',5))) return;
        }
        $circleIds=DB::table('circle_members')->where('user_id',$subject)->whereNull('removed_at')
            ->limit(100)->pluck('circle_id')->all();
        $state=$this->leases->snapshot($subject);
        foreach ($circleIds as $circle) {
            if (!$this->policy->member($circle,$subject)) continue;
            $viewers=DB::table('circle_members')->where('circle_id',$circle)->whereNull('removed_at')
                ->limit(101)->pluck('user_id')->all();
            if (count($viewers) > (int)config('orbit_presence.max_circle_members',50)) continue;
            foreach ($viewers as $viewer) {
                if (!$this->policy->canView($circle,$viewer,$subject)) continue;
                $this->emit($viewer,['type'=>$state ? 'presence.changed' : 'presence.clear',
                    'circle_id'=>$circle,'subject_id'=>$subject,'snapshot'=>$state]);
            }
        }
    }
    public function clearCircle(string $circle): void
    {
        if (!$this->enabled()) return;
        $users=DB::table('circle_members')->where('circle_id',$circle)->limit(1000)
            ->pluck('user_id')->unique()->all();
        foreach ($users as $viewer) $this->emit($viewer,['type'=>'presence.circle_clear','circle_id'=>$circle]);
    }
    public function clearUser(string $user): void
    {
        if (!$this->enabled()) return;
        $ids=DB::table('circle_members')->where('user_id',$user)->limit(100)->pluck('circle_id');
        foreach ($ids as $id) $this->clearCircle($id);
    }
    public function safelyClearCircle(string $circle): void
    {
        if (!$this->enabled()) return;
        try { $this->clearCircle($circle); }
        catch (\Throwable) { Log::warning('M9 privacy clear could not reach realtime gateway.'); }
    }
    public function safelyClearUser(string $user): void
    {
        if (!$this->enabled()) return;
        try { $this->clearUser($user); }
        catch (\Throwable) { Log::warning('M9 privacy clear could not reach realtime gateway.'); }
    }
    public function typing(string $circle,string $subject,bool $active): void
    {
        if (!$this->enabled() || config('orbit_circle.consumer_enabled.message') !== true) return;
        $viewers=DB::table('circle_members')->where('circle_id',$circle)->whereNull('removed_at')
            ->limit(101)->pluck('user_id')->all();
        if (count($viewers)>(int)config('orbit_presence.max_circle_members',50)) return;
        foreach ($viewers as $viewer) {
            if (!$this->policy->canView($circle,$viewer,$subject)) continue;
            if (!app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                ->canAccessProtected($circle,$viewer,$subject,'message')) continue;
            $this->emit($viewer,['type'=>'presence.typing','circle_id'=>$circle,
                'subject_id'=>$subject,'typing'=>$active,
                'expires_at'=>$active ? now()->addSeconds(5)->toIso8601String() : null]);
        }
    }
}