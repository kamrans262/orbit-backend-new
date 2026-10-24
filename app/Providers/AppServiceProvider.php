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
        //
    }

    public function boot(): void
    {
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
