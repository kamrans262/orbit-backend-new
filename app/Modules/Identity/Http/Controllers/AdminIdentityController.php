<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\AdminUser;
use App\Modules\Identity\Services\DeleteOrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;

final class AdminIdentityController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'query' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'status' => ['nullable', 'in:active,suspended'],
            'device_status' => ['nullable', 'in:pending,trusted,revoked'],
        ]);
        $users = OrbitUser::query()->withCount(['identities','devices','sessions']);
        if (!empty($filters['query'])) {
            // Search only public username or exact UUID; no unmasked contact
            // identifiers are placed in GET queries or access logs.
            $search = $filters['query'];
            $users->where(fn ($q) => $q->where('id', $search)
                ->orWhere('username', 'like', addcslashes($search, '%_').'%', 'and'));
        }
        if (!empty($filters['status'])) {
            $users->where('status', $filters['status']);
        }
        if (!empty($filters['device_status'])) {
            $deviceStatus = $filters['device_status'];
            $users->whereHas('devices', function ($q) use ($deviceStatus): void {
                if ($deviceStatus === 'revoked') {
                    $q->whereNotNull('revoked_at');
                } else {
                    $q->where('trust_state', $deviceStatus)->whereNull('revoked_at');
                }
            });
        }
        $users = $users->orderByDesc('created_at')->paginate(25)->withQueryString();
        return view('orbit-admin.users', [
            'users' => $users,
            'activeUsers' => OrbitUser::where('status','active')->count(),
            'pendingDevices' => \App\Models\OrbitDevice::where('trust_state','pending')->whereNull('revoked_at')->count(),
        ]);
    }

    public function show(OrbitUser $user)
    {
        $user->load(['identities','devices']);
        $activeSessionsByDevice = OrbitSession::query()
            ->where('user_id', $user->id)->whereNull('revoked_at')
            ->where('expires_at', '>', now())->groupBy('device_id')
            ->select('device_id')->selectRaw('COUNT(*) as active_sessions')
            ->pluck('active_sessions', 'device_id');
        $trustedDevicesCount = OrbitDevice::query()->where('user_id', $user->id)
            ->where('trust_state', 'trusted')->whereNull('revoked_at')->count();
        // The last ten identity security event types are sufficient for support;
        // never expose OTP challenges, codes, tokens or contact identifiers.
        $identityEvents = DB::table('orbit_identity_events')
            ->where('user_id', $user->id)
            ->where(function ($q): void {
                $q->where('action', 'like', 'auth.%')
                  ->orWhere('action', 'like', 'device.%')
                  ->orWhere('action', 'like', 'session.%');
            })->orderByDesc('created_at')->limit(10)
            ->get(['action', 'created_at']);
        // Support can inspect non-sensitive account metadata but never raw
        // notification/privacy values, precise location, or private bio.
        $preferences = DB::table('orbit_user_preferences')->where('user_id', $user->id)
            ->first(['notifications', 'privacy', 'created_at', 'updated_at']);
        $deletion = auth()->guard('admin')->user()?->canPerform('deletions.read')
            ? DB::table('orbit_account_deletion_requests')
                ->where('user_id', $user->id)
                ->first(['status', 'requested_at', 'eligible_for_erasure_at', 'completed_at'])
            : null;
        return response()->view('orbit-admin.user-detail', [
            'orbitUser' => $user, 'preferenceRecord' => $preferences,
            'deletionRecord' => $deletion,
            'activeSessionsByDevice' => $activeSessionsByDevice,
            'identityEvents' => $identityEvents,
            'trustedDevicesCount' => $trustedDevicesCount,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, OrbitUser $user, DeleteOrbitUser $deletion)
    {
        $data = $request->validate([
            'confirmation' => ['required', 'in:DELETE'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = auth()->guard('admin')->user();
        abort_unless($actor?->canPerform('users.delete'), 403);
        // A pending self-service request has grace-period and legal-hold
        // controls. A generic admin Delete User action must not bypass them.
        abort_if(DB::table('orbit_account_deletion_requests')
            ->where('user_id', $user->id)->where('status', 'pending')->exists(),
            409, 'This account is pending reviewed deletion; use the erasure review workflow.');
        $deletion->execute(
            $user->id,
            $actor,
            $data['reason'],
            $request->ip(),
            $request->header('X-Request-ID'),
        );
        return redirect()->route('orbit.admin.users.index')
            ->with('status', 'User account and current database-linked devices, sessions and identities permanently deleted. Audit entry recorded.');
    }

    /** A safety operator may revoke compromised devices, never approve new ones. */
    public function revokeDevice(Request $request, OrbitUser $user,
        OrbitDevice $device, TokenService $tokens)
    {
        $data = $request->validate([
            'confirmation' => ['required', 'in:REVOKE'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = auth()->guard('admin')->user();
        abort_unless($actor?->canPerform('users.manage'), 403);
        abort_unless($device->user_id === $user->id, 404);
        DB::transaction(function () use ($actor, $user, $device, $tokens, $data): void {
            // Serialize revocations for this user so two concurrent requests
            // cannot each revoke one of the final two trusted devices.
            OrbitUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $target = OrbitDevice::query()->whereKey($device->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($target->user_id === $user->id, 404);
            abort_if($target->revoked_at !== null, 409, 'Device already revoked.');
            // The existing recovery flow requires an enrolled trusted
            // device. Do not strand a user by revoking their final one.
            if ($target->trust_state === 'trusted') {
                $otherTrusted = OrbitDevice::query()->where('user_id', $user->id)
                    ->where('id', '!=', $target->id)
                    ->where('trust_state', 'trusted')->whereNull('revoked_at')
                    ->exists();
                abort_unless($otherTrusted, 409,
                    'Cannot revoke the only trusted device. Use account suspension when necessary.');
            }
            // This existing service revokes ALL sessions for the device and
            // triggers the established identity/presence integrity hooks.
            $tokens->revokeDevice($target, 'admin_revoke');
            $last = DB::table('admin_audit_log')->orderByDesc('created_at')
                ->lockForUpdate()->first();
            $prev = $last?->event_hash;
            $beforeHash = hash('sha256', $target->trust_state);
            $afterHash = hash('sha256', 'revoked');
            $eventHash = hash('sha256', implode('|', [$prev ?? '', $actor->id,
                'devices.revoke', $target->id, $beforeHash, $afterHash, $data['reason']]));
            DB::table('admin_audit_log')->insert([
                'id' => (string) Str::uuid7(), 'actor_id' => $actor->id,
                'action' => 'devices.revoke', 'target' => 'device:'.$target->id,
                'reason' => $data['reason'], 'ip_address' => request()->ip(),
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid7()),
                'before_hash' => $beforeHash, 'after_hash' => $afterHash,
                'previous_hash' => $prev, 'event_hash' => $eventHash,
                'created_at' => now(),
            ]);
        });
        return redirect()->route('orbit.admin.users.show', $user)
            ->with('status', 'Device and its active sessions revoked. Action audited.');
    }

    public function updateStatus(Request $request, OrbitUser $user)
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $actor = auth()->guard('admin')->user();
        DB::transaction(function () use ($actor, $user, $data): void {
            $target = OrbitUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $target->status;
            if ($before === $data['status']) return;
            $target->update(['status' => $data['status']]);
            if ($data['status'] === 'suspended') {
                OrbitSession::where('user_id', $target->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            // Audit entries are append-only via the admin service role; do not log identity PII.
            $last = DB::table('admin_audit_log')->orderByDesc('created_at')->lockForUpdate()->first();
            $prev = $last?->event_hash;
            $beforeHash = hash('sha256', $before);
            $afterHash = hash('sha256', $data['status']);
            $eventHash = hash('sha256', implode('|', [$prev ?? '', $actor->id, 'users.status', $target->id,
                $beforeHash, $afterHash, $data['reason']]));
            DB::table('admin_audit_log')->insert([
                'id' => (string) Str::uuid7(), 'actor_id' => $actor->id,
                'action' => 'users.status', 'target' => 'user:'.$target->id,
                'reason' => $data['reason'], 'ip_address' => request()->ip(),
                'request_id' => request()->header('X-Request-ID', (string) Str::uuid7()),
                'before_hash' => $beforeHash, 'after_hash' => $afterHash,
                'previous_hash' => $prev, 'event_hash' => $eventHash,
                'created_at' => now(),
            ]);
        });
        return redirect()->route('orbit.admin.users.show', $user)->with('status', 'Account status updated and audited.');
    }
}
