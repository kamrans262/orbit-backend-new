<?php

namespace App\Modules\Circle\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCircleM5Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('orbit_circle.enabled') !== true) {
            return response()->json(['message'=>'Feature unavailable.'],404)
                ->header('Cache-Control','no-store');
        }
        return $next($request);
    }
}
