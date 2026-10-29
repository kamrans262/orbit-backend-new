<?php

namespace App\Modules\Ping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleConsentPolicy;
use App\Modules\Ping\Services\PingAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class PingCircleConsentController extends Controller
{
    public function __construct(
        private readonly PingAccessPolicy $devicePolicy,
        private readonly CircleAccessPolicy $circles,
        private readonly CircleConsentPolicy $consent
    ) {}

    private function owner(Request $r, string $circle): string
    {
        $session = $r->attributes->get('orbit_session');
        abort_unless($session, 401);
        $user = (string) $session->user_id;
        $this->devicePolicy->assertSession($user, (string) $session->device_id);
        abort_unless($this->circles->isMember($circle, $user), 404, 'Circle unavailable.');
        $key = 'ping:consent:'.hash('sha256', $user.'|'.$circle);
        abort_if(RateLimiter::tooManyAttempts($key, 30), 429,
            'Too many Ping permission requests. Please try again.');
        RateLimiter::hit($key, 60);
        return $user;
    }

    private function response(string $circle, string $user): JsonResponse
    {
        $record = DB::table('circle_permissions')
            ->where('circle_id', $circle)->where('scope_key', 'user:'.$user)
            ->where('subject_user_id', $user)->where('permission_key', 'ping')->first();
        $raw = DB::table('orbit_user_preferences')
            ->where('user_id', $user)->value('privacy');
        $privacy = $raw ? json_decode((string) $raw, true) : null;
        $active = $record && $this->consent->consentCurrentlyValid($record, 'ping')
            && is_array($privacy) && ($privacy['ping_eligibility'] ?? 'Nobody') === 'My Circle'
            && config('orbit_ping.enabled') === true
            && config('orbit_circle.consumer_enabled.ping') === true;
        return response()->json(['data' => [
            'enabled' => (bool) $active,
            'circle_id' => $circle,
            'service_available' => config('orbit_ping.enabled') === true
                && config('orbit_circle.consumer_enabled.ping') === true,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $circle): JsonResponse
    {
        return $this->response($circle, $this->owner($r, $circle));
    }

    public function update(Request $r, string $circle): JsonResponse
    {
        $user = $this->owner($r, $circle);
        $data = $r->validate([
            'enabled' => ['required', 'boolean'],
            'confirm' => ['required_if:enabled,true', 'boolean'],
        ]);
        if (!$data['enabled']) {
            $this->consent->revoke($circle, $user, 'ping');
            return $this->response($circle, $user);
        }
        abort_unless(($data['confirm'] ?? false) === true, 422, 'Explicit consent required.');
        $raw = DB::table('orbit_user_preferences')
            ->where('user_id', $user)->value('privacy');
        $privacy = $raw ? json_decode((string) $raw, true) : null;
        abort_unless(is_array($privacy) &&
            ($privacy['ping_eligibility'] ?? 'Nobody') === 'My Circle',
            422, 'Enable Pings for My Circle in Privacy Settings first.');
        DB::table('circle_permissions')->updateOrInsert([
            'circle_id' => $circle,
            'scope_key' => 'user:'.$user,
            'permission_key' => 'ping',
        ], [
            'subject_user_id' => $user,
            'enabled' => true,
            'value_json' => json_encode([
                'reviewed' => true,
                'audience' => 'circle',
                'duration' => 'persistent',
            ]),
            'expires_at' => null,
            'updated_at' => now(),
        ]);
        return $this->response($circle, $user);
    }
}
