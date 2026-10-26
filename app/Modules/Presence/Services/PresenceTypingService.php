<?php
namespace App\Modules\Presence\Services;

use App\Modules\Circle\Services\CircleConsentPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Ephemeral typing: five-second expiry, three-second throttle; no content. */
final class PresenceTypingService
{
    public function __construct(private readonly PresenceAccessPolicy $policy,
        private readonly PresenceRealtimePublisher $realtime) {}

    public function update(string $user,string $circle,bool $active): array
    {
        abort_unless($this->realtime->enabled()
            && config('orbit_circle.consumer_enabled.message') === true,404);
        abort_unless($this->policy->member($circle,$user),404);
        $privacy=$this->policy->globalPrivacy($user);
        abort_unless(!$privacy['ghost_mode_enabled']
            && $privacy['presence_visibility']==='My Circle',403);
        $row=DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('scope_key','user:'.$user)->where('subject_user_id',$user)
            ->where('permission_key','message')->first();
        abort_unless($row && app(CircleConsentPolicy::class)->consentCurrentlyValid($row,'message'),403);
        $key='orbit:m9:typing:'.hash_hmac('sha256',$circle.'|'.$user,(string)config('app.key'));
        try {
            $cache=Cache::store((string)config('orbit_presence.store','redis'));
            if (!$active) {
                $cache->forget($key);
                $this->realtime->typing($circle,$user,false);
                return ['accepted'=>true,'expires_in'=>0];
            }
            if (!$cache->add($key.':throttle',true,
                (int)config('orbit_presence.typing_throttle_seconds',3))) {
                return ['accepted'=>false,'expires_in'=>5];
            }
            $cache->put($key,true,(int)config('orbit_presence.typing_ttl_seconds',5));
            $this->realtime->typing($circle,$user,true);
            return ['accepted'=>true,'expires_in'=>(int)config('orbit_presence.typing_ttl_seconds',5)];
        } catch (\Throwable $e) {
            throw new ServiceUnavailableHttpException(null,'Typing transport unavailable.',$e);
        }
    }
}