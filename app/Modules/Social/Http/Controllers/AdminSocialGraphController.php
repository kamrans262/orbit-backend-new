<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** M4 read-only operational health. No social content or contact-book data. */
final class AdminSocialGraphController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user_id' => ['nullable','uuid'],
            'action' => ['nullable', Rule::in([
                'social.request_sent','social.request_accepted','social.request_declined',
                'social.unfriended','social.blocked','social.unblocked',
                'social.request_cancelled', 'social.discovery_updated',
                'social.privacy_changed',
            ])],
        ]);
        $userId = $filters['user_id'] ?? null;
        $account = $userId ? DB::table('orbit_users')->where('id', $userId)
            ->first(['id','username','status']) : null;
        $stats = [
            'pending' => DB::table('friend_requests')->where('status', 'pending')->count(),
            'friends' => DB::table('friendships')->count(),
            'blocks' => DB::table('blocks')->count(),
            'requests_24h' => DB::table('friend_requests')->where('created_at', '>=', now()->subDay())->count(),
            'active_invites' => DB::table('social_friend_invites')
                ->whereNull('revoked_at')->whereNull('redeemed_at')
                ->where('expires_at','>',now())->count(),
            'contact_opted_in' => DB::table('social_discovery_settings')
                ->where('discoverable',true)->where('discoverable_by_contacts',true)->count(),
        ];
        $userCounts = null;
        if ($account) {
            $userCounts = [
                'friends' => DB::table('friendships')->where(fn ($q) =>
                    $q->where('user_low_id', $userId)->orWhere('user_high_id', $userId))->count(),
                'sent_pending' => DB::table('friend_requests')->where('sender_id', $userId)
                    ->where('status', 'pending')->count(),
                'received_pending' => DB::table('friend_requests')->where('recipient_id', $userId)
                    ->where('status', 'pending')->count(),
                'blocks_made' => DB::table('blocks')->where('blocker_id', $userId)->count(),
            ];
        }
        $events = DB::table('orbit_identity_events')
            ->select(['id','user_id','action','created_at'])
            ->where('action', 'like', 'social.%')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return response()->view('orbit-admin.social-graph', compact(
            'account','stats','userCounts','events'
        ))->header('Cache-Control', 'private, no-store');
    }
}
