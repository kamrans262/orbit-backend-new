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
        if((int)$request->session()->get('orbit_admin_session_revision',0)!==(int)$guard->user()->session_revision){
            $guard->logout();$request->session()->invalidate();$request->session()->regenerateToken();
            return redirect()->route('orbit.admin.login')->withErrors(['email'=>'Administrator access changed. Sign in again.']);
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
        // Prevent shared intermediaries and the browser back-cache from retaining
        // protected administrative response bodies after a session is revoked.
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }
}
