<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Social\Services\SocialFriendInviteService;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SocialInviteController extends Controller
{
    private function send(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store');
    }

    public function create(Request $request, SocialFriendInviteService $links): JsonResponse
    {
        $session = $request->attributes->get('orbit_session');
        return $this->send($links->create($session->user_id), 201);
    }

    public function listing(Request $request, SocialFriendInviteService $links): JsonResponse
    {
        return $this->send(['items'=>$links->listing($request->attributes->get('orbit_session')->user_id)]);
    }

    public function preview(Request $request, SocialFriendInviteService $links,
        SocialGraphService $graph): JsonResponse
    {
        $input = $request->validate(['invite_code' => ['required','string','max:100']]);
        return $this->send($links->preview(
            $request->attributes->get('orbit_session')->user_id, $input['invite_code'], $graph));
    }

    public function redeem(Request $request, SocialFriendInviteService $links,
        SocialGraphService $graph): JsonResponse
    {
        $input = $request->validate(['invite_code' => ['required','string','max:100']]);
        $session = $request->attributes->get('orbit_session');
        return $this->send($links->redeem(
            $session->user_id, $input['invite_code'], $session->device_id, $graph), 201);
    }

    public function revoke(Request $request, SocialFriendInviteService $links, string $id): JsonResponse
    {
        $links->revoke($request->attributes->get('orbit_session')->user_id, $id);
        return $this->send(['revoked'=>true]);
    }
}
