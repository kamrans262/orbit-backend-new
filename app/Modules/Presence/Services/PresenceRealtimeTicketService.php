<?php
namespace App\Modules\Presence\Services;
use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class PresenceRealtimeTicketService
{
    public function __construct(private readonly PresenceAccessPolicy $policy) {}
    private function key(string $token): string
    {
        return 'orbit:m9:ticket:'.hash_hmac('sha256',$token,(string)config('app.key'));
    }
    private function repository(): \Illuminate\Contracts\Cache\Repository
    {
        try { return Cache::store((string)config('orbit_presence.store','redis')); }
        catch (\Throwable) { throw new ServiceUnavailableHttpException(null,'Realtime ticket store unavailable.'); }
    }
    private function enabled(): void
    {
        abort_unless($this->policy->enabled() && config('orbit_presence.realtime_enabled') === true
            && strlen((string)config('orbit_presence.gateway_secret','')) >= 32,404);
    }
    public function issue(OrbitSession $session): array
    {
        $this->enabled();
        abort_unless($this->sessionValid($session),403);
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        try {
            $this->repository()->put($this->key($token),[
                'user_id'=>$session->user_id,'session_id'=>$session->id,
                'device_id'=>$session->device_id,
            ],60);
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null,'Realtime ticket store unavailable.');
        }
        return ['ticket'=>$token,'expires_in'=>60];
    }
    private function sessionValid(?OrbitSession $session): bool
    {
        if (!$session || $session->revoked_at !== null || $session->expires_at->isPast()
            || !$this->policy->userActive((string)$session->user_id)) return false;
        return OrbitDevice::query()->whereKey($session->device_id)
            ->where('user_id',$session->user_id)
            ->where('trust_state','trusted')->whereNull('revoked_at')->exists();
    }
    public function introspect(string $providedSecret,string $token,array $event): array
    {
        $this->enabled();
        $secret=(string)config('orbit_presence.gateway_secret','');
        abort_unless(strlen($secret)>=32 && hash_equals($secret,$providedSecret),403);
        abort_unless(strlen($token)>=40 && strlen($token)<=100,403);
        try { $ticket=$this->repository()->get($this->key($token)); }
        catch (\Throwable) { throw new ServiceUnavailableHttpException(null,'Realtime ticket unavailable.'); }
        abort_unless(is_array($ticket),403);
        $session=OrbitSession::query()->whereKey($ticket['session_id']??'')->first();
        abort_unless($this->sessionValid($session)
            && $session->device_id===($ticket['device_id']??'')
            && $session->user_id===($ticket['user_id']??''),403);
        $viewer=(string)$session->user_id;
        if ($event !== []) {
            $kind=(string)($event['type']??'');
            $circle=(string)($event['circle_id']??'');
            $subject=(string)($event['subject_id']??'');
            abort_unless(Str::isUuid($circle)
                && in_array($kind,['presence.changed','presence.clear','presence.typing','presence.circle_clear'],true),403);
            if ($kind!=='presence.circle_clear') {
                abort_unless(Str::isUuid($subject)
                    && $this->policy->canView($circle,$viewer,$subject),403);
                if ($kind==='presence.typing') {
                    abort_unless(config('orbit_circle.consumer_enabled.message') === true
                        && app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                            ->canAccessProtected($circle,$viewer,$subject,'message'),403);
                }
            }
        }
        return ['viewer_id'=>$viewer,'device_id'=>$session->device_id,'channel'=>'orbit:m9:viewer:'.hash_hmac('sha256',$viewer,(string)config('app.key')),
            'authorized'=>true,'ticket_expires_in_max'=>60];
    }
}