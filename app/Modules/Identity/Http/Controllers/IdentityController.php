<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class IdentityController extends Controller
{
    public function requestOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'recipient' => ['required', 'string', 'max:190'],
        ]);
        if (!$otp->normalized($data['channel'], $data['recipient'])) {
            return response()->json(['message' => 'Enter a valid email or E.164 international phone number.'], 422);
        }
        $result = $otp->request($data['channel'], $data['recipient'], (string) $request->ip());
        if ($result === 'rate_limited') return response()->json(['message' => 'Too many requests. Please try later.'], 429);
        if ($result === 'delivery_unavailable') return response()->json(['message' => 'Verification service is unavailable. Please try later.'], 503);
        return response()->json(['message' => 'If the destination can receive messages, a verification code has been sent.',
            'expires_in' => (int) config('orbit_identity.otp_minutes', 5) * 60], 202)->header('Cache-Control', 'no-store');
    }

    public function verifyOtp(Request $request, OtpService $otp, TokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'recipient' => ['required', 'string', 'max:190'],
            'code' => ['required', 'digits:6'],
            'device_public_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['android','ios'])],
            'device_name' => ['required', 'string', 'min:1', 'max:80'],
        ]);
        $result = $otp->verify($data['channel'], $data['recipient'], $data['code'],
            $data['device_public_id'], $data['platform'], $data['device_name'], $tokens, $request->ip());
        if (!$result) return response()->json(['message' => 'Invalid or expired code.'], 422)->header('Cache-Control', 'no-store');
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request, TokenService $tokens): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:1000']]);
        $result = $tokens->rotate($data['refresh_token']);
        if (!$result) return response()->json(['message' => 'Invalid or revoked session.'], 401);
        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, TokenService $tokens): JsonResponse
    {
        /** @var OrbitSession $session */
        $session = $request->attributes->get('orbit_session');
        $session->update(['revoked_at' => now()]);
        $tokens->event($session->user_id, $session->device_id, 'auth.logout');
        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        $user = OrbitUser::findOrFail($session->user_id);
        return response()->json(['data' => [
            'id' => $user->id, 'display_name' => $user->display_name, 'username' => $user->username,
            'status' => $user->status,
            'verified_methods' => $user->identities()->pluck('provider')->all(),
            'device_id' => $session->device_id,
        ]])->header('Cache-Control', 'no-store');
    }

    public function updateProfileBasics(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'alpha_dash:ascii', 'min:3', 'max:32', Rule::unique('orbit_users', 'username')
                ->ignore($request->attributes->get('orbit_session')->user_id)],
        ]);
        $session = $request->attributes->get('orbit_session');
        $user = OrbitUser::findOrFail($session->user_id);
        $user->update($data);
        return response()->json(['data' => ['id' => $user->id, 'display_name' => $user->display_name, 'username' => $user->username]]);
    }

    public function listDevices(Request $request): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        $data = OrbitDevice::query()->where('user_id', $session->user_id)->orderByDesc('last_seen_at')->get()
            ->map(fn (OrbitDevice $d): array => $this->deviceData($d, $session->device_id));
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function registerDevice(Request $request, TokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:200'],
            'push_token' => ['nullable', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'min:1', 'max:80'],
        ]);
        $bytes = base64_decode($data['public_key'], true);
        if (!is_string($bytes) || strlen($bytes) !== 32) {
            return response()->json(['message' => 'Expected a 32-byte base64 X25519 public key.'], 422);
        }
        $session = $request->attributes->get('orbit_session');
        $device = OrbitDevice::query()->where('id', $session->device_id)->where('user_id', $session->user_id)->firstOrFail();
        if ($device->public_key && !hash_equals($device->public_key, $data['public_key'])) {
            return response()->json(['message' => 'Device key changes require a new verified registration.'], 409);
        }
        $device->update(array_filter([
            'public_key' => $data['public_key'], 'push_token_enc' => $data['push_token'] ?? null,
            'name' => $data['device_name'] ?? null, 'last_seen_at' => now(),
        ], fn ($v) => $v !== null));
        $tokens->event($device->user_id, $device->id, 'device.registered');
        return response()->json(['data' => $this->deviceData($device->fresh(), $device->id)]);
    }

    public function renameDevice(Request $request, OrbitDevice $device): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        abort_unless($device->user_id === $session->user_id && !$device->revoked_at, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $device->update(['name' => $data['name']]);
        return response()->json(['data' => $this->deviceData($device->fresh(), $session->device_id)]);
    }

    public function approveDevice(Request $request, OrbitDevice $device, TokenService $tokens): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        $approver = OrbitDevice::findOrFail($session->device_id);
        abort_unless($device->user_id === $session->user_id && $approver->user_id === $session->user_id, 404);
        abort_unless($approver->trust_state === 'trusted' && $approver->public_key && !$approver->revoked_at, 403);
        abort_unless($device->trust_state === 'pending' && !$device->revoked_at && $device->public_key, 409);
        $device->update(['trust_state' => 'trusted', 'approved_at' => now()]);
        $tokens->event($device->user_id, $device->id, 'device.approved');
        return response()->json(['data' => $this->deviceData($device->fresh(), $session->device_id)]);
    }

    public function revokeDevice(Request $request, OrbitDevice $device, TokenService $tokens): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        abort_unless($device->user_id === $session->user_id && !$device->revoked_at, 404);
        $tokens->revokeDevice($device, 'user_revoke');
        return response()->json(['message' => 'Device and associated sessions revoked.']);
    }


    public function listSessions(Request $request): JsonResponse
    {
        $current = $request->attributes->get('orbit_session');
        $sessions = OrbitSession::query()->where('user_id', $current->user_id)
            ->orderByDesc('created_at')->limit(100)->get();
        $devices = OrbitDevice::query()->whereIn('id', $sessions->pluck('device_id'))->get()->keyBy('id');
        return response()->json(['data' => $sessions->map(function (OrbitSession $session) use ($current, $devices): array {
            $device = $devices->get($session->device_id);
            return [
                'id' => $session->id, 'device_id' => $session->device_id,
                'device_name' => $device?->name ?? 'Unknown device',
                'is_current' => $session->id === $current->id,
                'created_at' => $session->created_at?->toIso8601String(),
                'expires_at' => $session->expires_at?->toIso8601String(),
                'last_used_at' => $session->last_used_at?->toIso8601String(),
                'revoked' => (bool) $session->revoked_at,
            ];
        })->values()])->header('Cache-Control', 'no-store');
    }

    public function revokeSession(Request $request, OrbitSession $session, TokenService $tokens): JsonResponse
    {
        $current = $request->attributes->get('orbit_session');
        abort_unless($session->user_id === $current->user_id, 404);
        if (!$session->revoked_at) {
            $session->update(['revoked_at' => now()]);
            $tokens->event($session->user_id, $session->device_id, 'session.revoked', 'user_revoke');
        }
        return response()->json(['message' => 'Session revoked.']);
    }

    private function deviceData(OrbitDevice $device, string $currentId): array
    {
        return [
            'id' => $device->id, 'name' => $device->name, 'platform' => $device->platform,
            'is_current' => $device->id === $currentId, 'trust_state' => $device->trust_state,
            'key_registered' => (bool) $device->public_key, 'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
        ];
    }
}
