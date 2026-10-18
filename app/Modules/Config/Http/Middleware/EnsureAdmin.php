<?php

namespace App\Modules\Config\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth()->guard('admin');
        if (!$guard->check() || $guard->user()?->status !== 'active') {
            $guard->logout();
            return redirect()->route('orbit.admin.login');
        }
        // A separate, short-lived admin web session; MFA is required before login().
        $last = (int) $request->session()->get('orbit_admin_activity', 0);
        if ($last && time() - $last > 900) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('orbit.admin.login')->withErrors(['email' => 'Admin session expired.']);
        }
        $request->session()->put('orbit_admin_activity', time());
        return $next($request);
    }
}
