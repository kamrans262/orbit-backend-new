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

    /** Exact username lookup only until product signs off public discoverability. */
    public function search(Request $request, SocialGraphService $social): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9._]+$/D'],
        ]);
        [$viewer] = $this->identity($request);
        $username = strtolower($data['username']);
        $user = OrbitUser::query()->where('username', $username)
            ->where('status', 'active')->whereNull('deletion_requested_at')->first();
        if (!$user || $user->id === $viewer || $social->isBlockedEitherWay($viewer, $user->id)) {
            return $this->send([]);
        }
        return $this->send([[
            'id' => $user->id, 'username' => $user->username,
            'display_name' => $user->display_name,
            'relationship' => $social->relationship($viewer, $user->id),
        ]]);
    }

    public function preview(Request $request, SocialGraphService $social, string $user): JsonResponse
    {
        [$viewer] = $this->identity($request);
        $subject = $social->assertVisible($viewer, $user);
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
