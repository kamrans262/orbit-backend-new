<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrbitUser;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SocialGraphController extends Controller
{
    private function identity(Request $request): array
    {
        $session = $request->attributes->get('orbit_session');
        return [$session->user_id, $session->device_id];
    }

    private function send(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store');
    }

    /** Bounded, optional public discovery by username or display name. */
    public function search(Request $request, SocialGraphService $social,
        \App\Modules\Social\Services\SocialDiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:80'],
            // Backward compatible with the M4 draft client query shape.
            'username' => ['nullable', 'string', 'min:2', 'max:32'],
            'page' => ['nullable', 'integer', 'between:1,20'],
        ]);
        $query = trim((string) ($data['q'] ?? $data['username'] ?? ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['message' => 'Search requires at least two characters.'], 422);
        }
        [$viewer] = $this->identity($request);
        // Per-account limiter complements the outer per-IP middleware and
        // cannot be bypassed by changing a device or network address.
        $rateKey = 'orbit:social:search:'.$viewer;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, (int) config('orbit_social.search_per_account_per_minute', 60))) {
            return response()->json(['message'=>'Search limit reached. Please retry later.'], 429)
                ->header('Cache-Control','no-store');
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);
        return $this->send($discovery->find($viewer, $query,
            (int) ($data['page'] ?? 1), $social));
    }

    public function discovery(Request $request,
        \App\Modules\Social\Services\SocialDiscoveryService $discovery): JsonResponse
    {
        [$viewer] = $this->identity($request);
        return $this->send($discovery->settings($viewer));
    }

    public function updateDiscovery(Request $request,
        \App\Modules\Social\Services\SocialDiscoveryService $discovery): JsonResponse
    {
        $updates = $request->validate([
            'discoverable' => ['sometimes','required','boolean'],
            'discoverable_by_name' => ['sometimes','required','boolean'],
            'discoverable_by_contacts' => ['sometimes','required','boolean'],
            'allow_friend_requests' => ['sometimes','required','boolean'],
        ]);
        [$viewer] = $this->identity($request);
        return $this->send($discovery->update($viewer, $updates));
    }

    public function preview(Request $request, SocialGraphService $social, string $user): JsonResponse
    {
        [$viewer] = $this->identity($request);
        $subject = $social->assertVisible($viewer, $user);
        $discovery = app(\App\Modules\Social\Services\SocialDiscoveryService::class);
        if (!$discovery->visibleForSearch($viewer, $subject->id)
            && !$social->isFriend($viewer, $subject->id)
            && $social->relationship($viewer, $subject->id) === 'none') {
            abort(404, 'Person unavailable.');
        }
        // Bio, contact details, location, presence, Circle lists and mutual counts stay private.
        return $this->send([
            'id' => $subject->id, 'display_name' => $subject->display_name,
            'username' => $subject->username,
            'relationship' => $social->relationship($viewer, $subject->id),
        ]);
    }

    public function friends(Request $request, SocialGraphService $social): JsonResponse
    {
        [$viewer] = $this->identity($request);
        $page = DB::table('friendships')->where(function ($q) use ($viewer): void {
            $q->where('user_low_id', $viewer)->orWhere('user_high_id', $viewer);
        })->orderByDesc('created_at')
            ->paginate(20);
        $ids = $page->getCollection()->map(fn ($r) =>
            $r->user_low_id === $viewer ? $r->user_high_id : $r->user_low_id)->all();
        $users = OrbitUser::query()->whereIn('id', $ids)->where('status', 'active')
            ->whereNull('deletion_requested_at')->get(['id','username','display_name'])->keyBy('id');
        $result = [];
        foreach ($ids as $id) {
            if (!$users->has($id) || $social->isBlockedEitherWay($viewer, $id)) continue;
            $u = $users->get($id);
            $result[] = ['id' => $u->id, 'username' => $u->username,
                'display_name' => $u->display_name, 'relationship' => 'friend'];
        }
        return $this->send(['items' => $result, 'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(), 'revision' => $social->currentRevision($viewer)]);
    }

    public function requests(Request $request, SocialGraphService $social): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['incoming','outgoing'])],
        ]);
        [$viewer] = $this->identity($request);
        $incoming = $data['direction'] === 'incoming';
        $column = $incoming ? 'recipient_id' : 'sender_id';
        $page = DB::table('friend_requests')->where($column, $viewer)
            ->where('status', 'pending')->orderByDesc('created_at')
            ->paginate(20);
        $ids = $page->getCollection()->map(fn ($r) =>
            $incoming ? $r->sender_id : $r->recipient_id)->all();
        $users = OrbitUser::query()->whereIn('id', $ids)->where('status', 'active')
            ->whereNull('deletion_requested_at')->get(['id','username','display_name'])->keyBy('id');
        $result = [];
        foreach ($page->items() as $row) {
            $other = $incoming ? $row->sender_id : $row->recipient_id;
            if (!$users->has($other) || $social->isBlockedEitherWay($viewer, $other)) continue;
            $person = $users->get($other);
            $result[] = ['id' => $row->id, 'status' => 'pending',
                'created_at' => $row->created_at, 'person' => [
                    'id' => $person->id, 'username' => $person->username,
                    'display_name' => $person->display_name,
                ]];
        }
        return $this->send(['items' => $result, 'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(), 'revision' => $social->currentRevision($viewer)]);
    }

    public function sendRequest(Request $request, SocialGraphService $social): JsonResponse
    {
        $data = $request->validate(['recipient_id' => ['required','uuid']]);
        [$viewer, $device] = $this->identity($request);
        return $this->send($social->request($viewer, $data['recipient_id'], $device), 201);
    }

    public function accept(Request $request, SocialGraphService $social, string $id): JsonResponse
    {
        [$viewer, $device] = $this->identity($request);
        return $this->send($social->respond($viewer, $id, true, $device));
    }

    public function decline(Request $request, SocialGraphService $social, string $id): JsonResponse
    {
        [$viewer, $device] = $this->identity($request);
        return $this->send($social->respond($viewer, $id, false, $device));
    }

    public function cancel(Request $request, SocialGraphService $social, string $id): JsonResponse
    {
        [$viewer, $device] = $this->identity($request);
        return $this->send($social->cancel($viewer, $id, $device));
    }

    public function removeFriend(Request $request, SocialGraphService $social, string $user): JsonResponse
    {
        [$viewer, $device] = $this->identity($request);
        $social->unfriend($viewer, $user, $device);
        return $this->send(['removed' => true]);
    }

    public function blocks(Request $request, SocialGraphService $social): JsonResponse
    {
        [$viewer] = $this->identity($request);
        $page = DB::table('blocks')->where('blocker_id', $viewer)
            ->orderByDesc('created_at')->paginate(20);
        $ids = $page->getCollection()->pluck('blocked_id')->all();
        $users = OrbitUser::query()->whereIn('id', $ids)->get(['id','display_name','username'])->keyBy('id');
        $items = [];
        foreach ($page->items() as $row) {
            $u = $users->get($row->blocked_id);
            $items[] = ['id' => $row->blocked_id,
                'display_name' => $u?->display_name,
                'username' => $u?->username, 'blocked_at' => $row->created_at];
        }
        return $this->send(['items' => $items, 'page' => $page->currentPage(),
            'has_more' => $page->hasMorePages(), 'revision' => $social->currentRevision($viewer)]);
    }

    public function block(Request $request, SocialGraphService $social): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required','uuid'],
            'reason_code' => ['nullable', Rule::in(['safety','spam','harassment','privacy','other'])],
        ]);
        [$viewer, $device] = $this->identity($request);
        $social->block($viewer, $data['user_id'], $device, $data['reason_code'] ?? null);
        return $this->send(['blocked' => true], 201);
    }

    public function unblock(Request $request, SocialGraphService $social, string $user): JsonResponse
    {
        [$viewer, $device] = $this->identity($request);
        $social->unblock($viewer, $user, $device);
        return $this->send(['blocked' => false]);
    }
}
