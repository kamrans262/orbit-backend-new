<?php

namespace App\Modules\CheckIn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CheckIn\Services\CheckInAccessPolicy;
use App\Modules\CheckIn\Services\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

final class CheckInController extends Controller
{
    public function __construct(
        private readonly CheckInService $checkins,
        private readonly CheckInAccessPolicy $policy
    ) {}

    private function actor(Request $request): string
    {
        $session = $request->attributes->get('orbit_session');
        abort_unless($session, 401, 'Unauthenticated.');
        $actor = (string) $session->user_id;
        $this->policy->assertSession($actor, (string) $session->device_id);
        $key = 'checkin:api:'.hash('sha256', $actor.'|'.$session->device_id);
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429, 'Too many Check-In requests.');
        RateLimiter::hit($key, 60);
        return $actor;
    }

    private function result(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')->header('Vary', 'Authorization');
    }

    public function capabilities(Request $r): JsonResponse
    {
        $this->actor($r);
        return $this->result([
            'available' => $this->checkins->installed() && config('orbit_check_in.enabled') === true,
            'presets' => CheckInService::PRESETS,
            'custom_max_characters' => (int) config('orbit_check_in.custom_max_characters', 160),
            'allowed_audiences' => ['only_me','friends','selected_friend','circle'],
            'expires_at_options' => [], // No approved product TTL choices.
            'max_expiry_days' => (int) config('orbit_check_in.max_expiry_days', 30),
            'location_reference_supported' => false,
            'delivery_transport' => 'foreground_http',
            'push_available' => false,
            'realtime_available' => false,
            'circle_permission_required' => true,
        ]);
    }

    private function audienceRules(): array
    {
        return [
            'audience' => ['required','array','min:1','max:'.(int) config('orbit_check_in.max_audience_scopes', 25)],
            'audience.*' => ['required','array:type,id'],
            'audience.*.type' => ['required',Rule::in(['only_me','friends','selected_friend','circle'])],
            'audience.*.id' => ['nullable','uuid'],
        ];
    }

    public function publish(Request $r): JsonResponse
    {
        $actor = $this->actor($r);
        $input = $r->validate(array_merge([
            'client_id' => ['required','uuid'],
            'type' => ['required',Rule::in([...CheckInService::PRESETS,'custom'])],
            'text' => ['required_if:type,custom','prohibited_unless:type,custom','nullable','string',
                'max:'.(int) config('orbit_check_in.custom_max_characters',160)],
            'expires_at' => ['sometimes','nullable','date','after:now'],
            // No raw coordinates and no M10 shortcut before a reviewed integration.
            'place_ref' => ['prohibited'],
        ], $this->audienceRules()));
        [$record,$created] = $this->checkins->create($actor,$input);
        return $this->result($record,$created ? 201 : 200);
    }

    public function feed(Request $r): JsonResponse
    {
        $actor = $this->actor($r);
        $input = $r->validate(['limit'=>['sometimes','integer','min:1','max:30'],
            'cursor'=>['sometimes','string','max:500']]);
        return $this->result($this->checkins->page($actor,'feed',(int)($input['limit'] ?? 20)));
    }

    public function mine(Request $r): JsonResponse
    {
        $actor = $this->actor($r);
        $input = $r->validate(['limit'=>['sometimes','integer','min:1','max:30'],
            'cursor'=>['sometimes','string','max:500']]);
        return $this->result($this->checkins->page($actor,'mine',(int)($input['limit'] ?? 20)));
    }

    public function detail(Request $r,string $checkin): JsonResponse
    {
        $actor = $this->actor($r);
        return $this->result($this->checkins->present($this->checkins->find($checkin,$actor),$actor));
    }

    public function audience(Request $r,string $checkin): JsonResponse
    {
        $actor = $this->actor($r);
        $input = $r->validate(array_merge(['expected_revision'=>['required','integer','min:1']],
            $this->audienceRules()));
        return $this->result($this->checkins->changeAudience($checkin,$actor,$input));
    }

    public function expiry(Request $r,string $checkin): JsonResponse
    {
        $actor = $this->actor($r);
        $input = $r->validate(['expires_at'=>['present','nullable','date','after:now']]);
        return $this->result($this->checkins->changeExpiry($checkin,$actor,$input['expires_at']));
    }

    public function withdraw(Request $r,string $checkin): JsonResponse
    {
        $actor = $this->actor($r);
        $this->checkins->withdraw($checkin,$actor);
        return response()->json(null,204)->header('Cache-Control','private, no-store');
    }
}
