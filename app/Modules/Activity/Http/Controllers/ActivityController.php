<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Services\ActivityFeedService;
use App\Modules\Activity\Services\ActivityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ActivityController extends Controller
{
    public function __construct(
        private readonly ActivityPolicy $policy,
        private readonly ActivityFeedService $feed
    ) {}

    private function actor(Request $request): string
    {
        $session = $request->attributes->get('orbit_session');
        abort_unless($session, 401, 'Unauthenticated.');
        $user = (string)$session->user_id;
        $this->policy->assertTrusted($user, (string)$session->device_id);
        return $user;
    }

    private function reply(array $data): JsonResponse
    {
        return response()->json(['data' => $data])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')->header('Vary', 'Authorization');
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->actor($request);
        return $this->reply([
            'available' => $this->policy->installed()
                && config('orbit_activity.enabled') === true,
            'sources' => [
                'moment' => $this->policy->sourceEnabled('moment'),
                'check_in' => $this->policy->sourceEnabled('check_in'),
            ],
            'sync_mode' => 'authoritative_refresh',
            'realtime_available' => false,
            'page_size_max' => (int)config('orbit_activity.feed_page_max', 30),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $viewer = $this->actor($request);
        $input = $request->validate([
            'limit' => ['sometimes','integer','min:1','max:30'],
            'cursor' => ['sometimes','string','max:500'],
        ]);
        return $this->reply($this->feed->page($viewer,
            (int)($input['limit'] ?? 20), $input['cursor'] ?? null));
    }

    public function changes(Request $request): JsonResponse
    {
        $viewer = $this->actor($request);
        $request->validate(['since' => ['sometimes','string','max:500']]);
        return $this->reply($this->feed->changes($viewer));
    }

    public function show(Request $request, string $activity): JsonResponse
    {
        $viewer = $this->actor($request);
        return $this->reply($this->feed->item($viewer, $activity));
    }
}
