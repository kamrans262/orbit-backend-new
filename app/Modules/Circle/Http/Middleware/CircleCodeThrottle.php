<?php

namespace App\Modules\Circle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply existing named QR limiters after the trusted Orbit session is attached.
 * The outer authenticated route group's generic IP throttle remains unchanged.
 */
final class CircleCodeThrottle
{
    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        abort_unless(in_array($limiter, ['orbit-circle-code-preview', 'orbit-circle-code-redeem'], true), 500);
        if (!$request->attributes->get('orbit_session')?->user_id) {
            return response()->json(['message' => 'Unauthenticated.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        return app(ThrottleRequests::class)->handle($request, $next, $limiter);
    }
}
