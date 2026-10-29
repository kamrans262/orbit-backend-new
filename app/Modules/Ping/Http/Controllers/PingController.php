<?php

namespace App\Modules\Ping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ping\Services\PingAccessPolicy;
use App\Modules\Ping\Services\PingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\RateLimiter;

final class PingController extends Controller
{
    public function __construct(
        private readonly PingService $pings,
        private readonly PingAccessPolicy $policy
    ) {}

    private function identity(Request $request): object
    {
        $session = $request->attributes->get('orbit_session');
        abort_unless($session, 401, 'Unauthenticated.');
        $this->policy->assertSession((string) $session->user_id, (string) $session->device_id);
        // Per-device throttling preserves fairness for members sharing an IP.
        $key = 'ping:api:'.hash('sha256', (string) $session->user_id.'|'.(string) $session->device_id);
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429,
            'Too many Ping requests. Please try again.');
        RateLimiter::hit($key, 60);
        return $session;
    }

    private function result(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Authorization');
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->identity($request);
        return $this->result([
            'available' => config('orbit_ping.enabled') === true
                && \Illuminate\Support\Facades\Schema::hasTable('pings')
                && \Illuminate\Support\Facades\Schema::hasTable('ping_receipts')
                && \Illuminate\Support\Facades\Schema::hasTable('ping_responses'),
            'presets' => PingService::PRESETS,
            'custom_supported' => true,
            'custom_max_characters' => (int) config('orbit_ping.custom_max_characters', 160),
            'delivery_transport' => 'foreground_http',
            'push_available' => false,
            'realtime_available' => false,
            'circle_permission_required' => true,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $session = $this->identity($request);
        $input = $request->validate([
            'client_id' => ['required', 'uuid'],
            'recipient_id' => ['required', 'uuid'],
            'context' => ['required', 'array'],
            'context.type' => ['required', Rule::in(['friend', 'circle'])],
            'context.id' => ['required_if:context.type,circle', 'nullable', 'uuid'],
            'type' => ['required', Rule::in([...PingService::PRESETS, 'custom'])],
            'custom_text' => ['required_if:type,custom', 'prohibited_unless:type,custom',
                'nullable', 'string', 'max:'.(int) config('orbit_ping.custom_max_characters', 160)],
        ]);
        if ($input['type'] === 'custom') {
            abort_if(trim($input['custom_text'] ?? '') === '', 422, 'Custom Ping cannot be blank.');
        }
        [$data, $created] = $this->pings->send((string) $session->user_id, $input);
        return $this->result($data, $created ? 201 : 200);
    }

    public function inbox(Request $request): JsonResponse
    {
        $session = $this->identity($request);
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);
        return $this->result($this->pings->page((string) $session->user_id, 'inbox', (int) ($input['limit'] ?? 20)));
    }

    public function outbox(Request $request): JsonResponse
    {
        $session = $this->identity($request);
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);
        return $this->result($this->pings->page((string) $session->user_id, 'outbox', (int) ($input['limit'] ?? 20)));
    }

    public function detail(Request $request, string $ping): JsonResponse
    {
        $session = $this->identity($request);
        return $this->result($this->pings->present(
            $this->pings->find($ping, (string) $session->user_id), (string) $session->user_id));
    }

    public function delivered(Request $request, string $ping): JsonResponse
    {
        return $this->acknowledge($request, $ping, 'delivered');
    }

    public function seen(Request $request, string $ping): JsonResponse
    {
        return $this->acknowledge($request, $ping, 'seen');
    }

    private function acknowledge(Request $request, string $ping, string $kind): JsonResponse
    {
        $session = $this->identity($request);
        return $this->result($this->pings->receipt(
            $ping, (string) $session->user_id, (string) $session->device_id, $kind));
    }

    public function respond(Request $request, string $ping): JsonResponse
    {
        $session = $this->identity($request);
        $input = $request->validate([
            'client_reply_id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(['hey'])],
        ]);
        return $this->result($this->pings->respond(
            $ping, (string) $session->user_id, $input['client_reply_id']));
    }
}
