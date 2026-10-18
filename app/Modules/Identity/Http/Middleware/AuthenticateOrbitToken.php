<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateOrbitToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $session = $bearer ? app(TokenService::class)->authenticate($bearer) : null;
        if (!$session) return response()->json(['message' => 'Unauthenticated.'], 401);
        $request->attributes->set('orbit_session', $session);
        return $next($request);
    }
}
