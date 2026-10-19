<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Privacy-limited M3 operational metadata. No raw user preferences or content. */
final class AdminProfileController extends Controller
{
    public function deletionRequests(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'cancelled', 'completed'])],
        ]);
        $requests = DB::table('orbit_account_deletion_requests as d')
            ->join('orbit_users as u', 'u.id', '=', 'd.user_id')
            ->select([
                'd.user_id', 'd.request_id', 'd.status', 'd.requested_at',
                'd.eligible_for_erasure_at', 'd.cancelled_at', 'd.completed_at',
                'u.username', 'u.display_name',
            ])
            ->when(isset($filters['status']), fn ($query) =>
                $query->where('d.status', $filters['status']))
            ->orderByDesc('d.requested_at')->orderByDesc('d.request_id')
            ->paginate(25)->withQueryString();
        $pendingCount = DB::table('orbit_account_deletion_requests')
            ->where('status', 'pending')->count();
        $eligibleCount = DB::table('orbit_account_deletion_requests')
            ->where('status', 'pending')
            ->where('eligible_for_erasure_at', '<=', now())->count();
        // Receipt is intentionally detached from the removed OrbitUser.
        // It shows exactly which current-schema cleanup completed, not a
        // misleading whole-product erasure claim.
        $completedReceipts = DB::table('orbit_erasure_receipts')
            ->select(['request_id', 'scope', 'completed_at'])
            ->orderByDesc('completed_at')->limit(25)->get();
        return response()->view('orbit-admin.deletion-requests', compact(
            'requests', 'pendingCount', 'eligibleCount', 'completedReceipts'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function settingsActivity(Request $request): Response
    {
        $actions = [
            'settings.profile_updated', 'settings.privacy_updated',
            'settings.notifications_updated', 'settings.general_updated',
        ];
        $filters = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'action' => ['nullable', Rule::in($actions)],
        ]);
        $events = DB::table('orbit_identity_events')
            ->select(['id', 'user_id', 'action', 'reason_code', 'created_at'])
            ->whereIn('action', $actions)
            ->when(isset($filters['user_id']), fn ($query) =>
                $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['action']), fn ($query) =>
                $query->where('action', $filters['action']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        return response()->view('orbit-admin.settings-activity', compact(
            'events', 'actions'
        ))->header('Cache-Control', 'private, no-store');
    }
}
