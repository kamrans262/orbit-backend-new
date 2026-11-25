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
        // Reuse OAuth only within a request/worker scope; never cache service tokens in SQL.
        $this->app->scoped(\App\Modules\Realtime\Services\FirebaseRealtimeProvider::class);
        $this->app->scoped(\App\Modules\Realtime\Services\RealtimeSessions::class);
        $this->app->bind(\App\Modules\Media\Services\MediaObjectStore::class,
            function () {
                return config('orbit_media.driver') === 'hostinger_private'
                    ? new \App\Modules\Media\Services\HostingerPrivateMediaStore()
                    : new \App\Modules\Media\Services\MediaObjectStore();
            });
    }

    public function boot(): void
    {
        // Auth uses Orbit's session attribute, not Laravel's default guard.
        // Numeric throttle middleware otherwise shares a domain/IP bucket across
        // unrelated routes (and the outer middleware also charges that bucket).
        RateLimiter::for('orbit-authenticated', function (Request $request): array {
            $account = $request->attributes->get('orbit_session')?->user_id;
            return [
                Limit::perMinute(600)->by('account:'.($account ?: 'unauth:'.$request->ip())),
                Limit::perMinute(600)->by('ip:'.$request->ip()),
            ];
        });

        foreach ([4, 5, 6, 8, 10, 12, 15, 20, 25, 30, 40, 45, 50, 60, 90, 120, 140] as $perMinute) {
            RateLimiter::for('orbit-route-'.$perMinute, function (Request $request) use ($perMinute): array {
                $account = $request->attributes->get('orbit_session')?->user_id;
                // Stable route template, never a user-controlled resource ID.
                $route = $request->method().':'.$request->route()->uri();
                return [
                    Limit::perMinute($perMinute)->by($route.':account:'.($account ?: 'unauth:'.$request->ip())),
                    Limit::perMinute($perMinute)->by($route.':ip:'.$request->ip()),
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
