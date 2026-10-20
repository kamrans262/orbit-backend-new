<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Services\TokenService;
use App\Models\OrbitDevice;
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
        $device = OrbitDevice::query()->find($session->device_id);
        if (!$device) return response()->json(['message' => 'Unauthenticated.'], 401);
        $registration = !$device->public_key;
        $approval = $device->trust_state !== 'trusted';
        if (($registration || $approval) && !$request->is('api/v1/devices/register', 'api/v1/devices/registration-status', 'api/v1/devices/recovery/request', 'api/v1/devices/recovery/complete', 'api/v1/auth/logout')) {
            return response()->json([
                'message' => 'Device registration or trusted-device approval required.',
                'code' => 'device_verification_required',
                'device_requires_registration' => $registration,
                'device_requires_approval' => $approval,
            ], 403)->header('Cache-Control', 'no-store');
        }
        $user = \App\Models\OrbitUser::query()->find($session->user_id);
        if ($user && $user->deletion_requested_at
            && !$request->is('api/v1/me/deletion-status', 'api/v1/me/deletion-cancel',
                'api/v1/auth/logout')) {
            return response()->json([
                'code' => 'account_deletion_pending',
                'message' => 'Your account is pending deletion. Sign in on a trusted device to review or cancel the request.',
            ], 409)->header('Cache-Control', 'no-store');
        }
        $request->attributes->set('orbit_session', $session);
        return $next($request);
    }
}
