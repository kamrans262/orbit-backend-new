<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\OrbitUser;
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
}
