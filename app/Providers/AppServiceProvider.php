<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Modules\Media\Services\MediaObjectStore::class,
            function () {
                return config('orbit_media.driver') === 'hostinger_private'
                    ? new \App\Modules\Media\Services\HostingerPrivateMediaStore()
                    : new \App\Modules\Media\Services\MediaObjectStore();
            });
    }

    public function boot(): void
    {
        // Relay uses operation-specific buckets, so an authenticated
        // capability lookup, key registration or retry on a shared IP cannot
        // consume the entire lease bucket. Preserve the existing global ceiling.
        foreach (['capabilities'=>40,'leases'=>10,'revoke'=>20,'reconcile'=>20,'sync'=>40] as $operation=>$limit) {
            RateLimiter::for('orbit-relay-'.$operation, function(Request $request) use($operation,$limit):array {
                $session=$request->attributes->get('orbit_session');
                return [
                    Limit::perMinute($limit)->by('relay:'.$operation.':device:'.($session?->device_id??'unauth:'.$request->ip())),
                    Limit::perMinute($limit*3)->by('relay:'.$operation.':account:'.($session?->user_id??'unauth:'.$request->ip())),
                    Limit::perMinute($limit*10)->by('relay:'.$operation.':ip:'.$request->ip()),
                ];
            });
        }

        // M5 QR codes are recipient-bound and scanned by authenticated devices.
        // Per-IP-only throttles were locking out distinct trusted accounts
        // behind the same household or office Wi-Fi. Keep strict per-account
        // limits and a separate IP-wide abuse ceiling (not an open endpoint).
        RateLimiter::for('orbit-circle-code-preview', function (Request $request): array {
            $session = $request->attributes->get('orbit_session');
            $userId = $session?->user_id;
            return [
                Limit::perMinute(8)->by('circle-preview:account:'.($userId ?: 'unauth:'.$request->ip())),
                Limit::perMinute(60)->by('circle-preview:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('orbit-circle-code-redeem', function (Request $request): array {
            $session = $request->attributes->get('orbit_session');
            $userId = $session?->user_id;
            return [
                Limit::perMinute(6)->by('circle-redeem:account:'.($userId ?: 'unauth:'.$request->ip())),
                Limit::perMinute(40)->by('circle-redeem:ip:'.$request->ip()),
            ];
        });
    }
}
