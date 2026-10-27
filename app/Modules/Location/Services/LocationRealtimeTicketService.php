<?php
namespace App\Modules\Location\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class LocationRealtimeTicketService
{
    public function enabled(): bool
    {
        return config('orbit_location.enabled') === true
            && config('orbit_location.realtime_enabled') === true
            && config('orbit_location.realtime_verified') === true
            && strlen((string)config('orbit_location.gateway_secret','')) >= 32
            && str_starts_with((string)config('orbit_location.wss_url',''),'wss://');
    }

    private function key(string $ticket): string
    {
        return 'orbit:m10:ticket:'.hash_hmac('sha256',$ticket,(string)config('app.key'));
    }

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string)config('orbit_location.realtime_store','redis'));
    }

    private function valid(?OrbitSession $session): bool
    {
        if (!$session || $session->revoked_at !== null
            || $session->expires_at->isPast()) return false;
        if (!app(LocationPolicy::class)->enabled()) return false;
        if (!\App\Models\OrbitUser::query()->whereKey($session->user_id)
            ->where('status','active')->whereNull('deletion_requested_at')->exists()) return false;
        return OrbitDevice::query()->whereKey($session->device_id)
            ->where('user_id',$session->user_id)->where('trust_state','trusted')
            ->whereNull('revoked_at')->whereNotNull('public_key')->exists();
    }

    public function issue(OrbitSession $session): array
    {
        abort_unless($this->enabled(),404);
        abort_unless($this->valid($session),403);
        $ticket=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        try {
            $this->store()->put($this->key($ticket),[
                'session_id'=>$session->id,'user_id'=>$session->user_id,
                'device_id'=>$session->device_id,
            ],60);
        } catch (\Throwable) {
            abort(503,'Realtime ticket store unavailable.');
        }
        return ['ticket'=>$ticket,'expires_in'=>60,
            'url'=>(string)config('orbit_location.wss_url')];
    }

    public function introspect(string $secret,string $ticket): array
    {
        abort_unless($this->enabled(),404);
        $expected=(string)config('orbit_location.gateway_secret','');
        abort_unless(strlen($expected)>=32 && hash_equals($expected,$secret),403);
        abort_unless(strlen($ticket)>=40 && strlen($ticket)<=100,403);
        try {$lease=$this->store()->get($this->key($ticket));}
        catch (\Throwable) {abort(503,'Realtime ticket unavailable.');}
        abort_unless(is_array($lease),403);
        $session=OrbitSession::query()->whereKey($lease['session_id']??'')->first();
        abort_unless($this->valid($session)
            && (string)$session->user_id===($lease['user_id']??'')
            && (string)$session->device_id===($lease['device_id']??''),403);
        $viewer=(string)$session->user_id;
        return ['authorized'=>true,'viewer_id'=>$viewer,
            'channel'=>'orbit:m10:viewer:'.hash_hmac('sha256',$viewer,(string)config('app.key')),
            'ticket_expires_in_max'=>60];
    }
}
