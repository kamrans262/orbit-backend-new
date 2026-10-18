<?php

namespace App\Modules\Config\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission = 'config.manage'): Response
    {
        abort_unless(auth()->guard('admin')->user()?->canPerform($permission), 403);
        return $next($request);
    }
}
