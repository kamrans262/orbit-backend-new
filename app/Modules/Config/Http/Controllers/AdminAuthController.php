<?php

namespace App\Modules\Config\Http\Controllers;

use App\Models\AdminUser;
use App\Modules\Config\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class AdminAuthController extends Controller
{
    public function loginForm() { return view('orbit-admin.login'); }
    public function mfaForm(Request $request)
    {
        if (!$request->session()->has('orbit_pending_admin')) return redirect()->route('orbit.admin.login');
        return view('orbit-admin.mfa');
    }

    public function password(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'string']]);
        $key = 'orbit-admin-login:'.hash('sha256', strtolower($input['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again later.']);
        }
        $admin = AdminUser::query()->where('email', strtolower($input['email']))->first();
        if (!$admin || $admin->status !== 'active' || !Hash::check($input['password'], $admin->password)) {
            RateLimiter::hit($key, 300);
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('orbit_pending_admin', $admin->id);
        $request->session()->put('orbit_pending_started', time());
        return redirect()->route('orbit.admin.mfa');
    }

    public function verifyMfa(Request $request, Totp $totp)
    {
        $input = $request->validate(['code' => ['required', 'digits:6']]);
        $adminId = $request->session()->get('orbit_pending_admin');
        if (!$adminId || time() - (int) $request->session()->get('orbit_pending_started', 0) > 300) {
            $request->session()->forget(['orbit_pending_admin', 'orbit_pending_started']);
            return redirect()->route('orbit.admin.login')->withErrors(['email' => 'Sign-in expired.']);
        }
        $key = 'orbit-admin-mfa:'.$adminId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) return back()->withErrors(['code' => 'Too many attempts.']);
        $admin = AdminUser::find($adminId);
        if (!$admin || $admin->status !== 'active' || !$totp->verify($admin->totp_secret, $input['code'])) {
            RateLimiter::hit($key, 300);
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }
        RateLimiter::clear($key);
        auth()->guard('admin')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget(['orbit_pending_admin', 'orbit_pending_started']);
        $request->session()->put('orbit_admin_activity', time());
        $admin->update(['last_login_at' => now()]);
        return redirect()->route('orbit.admin.dashboard');
    }

    public function logout(Request $request)
    {
        auth()->guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('orbit.admin.login');
    }
}
