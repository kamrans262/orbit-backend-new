<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\OrbitUser;
use App\Models\OrbitSession;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;

final class AdminIdentityController extends Controller
{
    public function index()
    {
        $users = OrbitUser::query()->withCount(['identities','devices','sessions'])
            ->orderByDesc('created_at')->paginate(25);
        return view('orbit-admin.users', [
            'users' => $users,
            'activeUsers' => OrbitUser::where('status','active')->count(),
            'pendingDevices' => \App\Models\OrbitDevice::where('trust_state','pending')->whereNull('revoked_at')->count(),
        ]);
    }

    public function show(OrbitUser $user)
    {
        $user->load(['identities','devices']);
        return view('orbit-admin.user-detail', ['orbitUser'=>$user]);
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
