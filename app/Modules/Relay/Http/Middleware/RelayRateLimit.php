<?php
namespace App\Modules\Relay\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class RelayRateLimit
{
    public function handle(Request $request,Closure $next,string $operation):Response {
        // This custom middleware runs after AuthenticateOrbitToken. Laravel's
        // generic ThrottleRequests priority can precede custom authentication;
        // never silently fall back to IP when the device session is missing.
        $session=$request->attributes->get('orbit_session');
        abort_unless($session && $session->device_id && $session->user_id,401,'Unauthenticated.');
        $limit=['capabilities'=>40,'leases'=>10,'revoke'=>20,'reconcile'=>20,'sync'=>40][$operation]??null;
        abort_unless($limit,500,'Unknown Relay operation.');
        $prefix='relay:'.$operation.':';
        $buckets=[
            $prefix.'device:'.hash('sha256',(string)$session->device_id)=>$limit,
            $prefix.'account:'.hash('sha256',(string)$session->user_id)=>$limit*3,
            $prefix.'ip:'.hash('sha256',(string)$request->ip())=>$limit*10,
        ];
        $retry=0;
        foreach($buckets as $key=>$max){
            if(RateLimiter::tooManyAttempts($key,$max))$retry=max($retry,RateLimiter::availableIn($key));
        }
        if($retry>0)return response()->json(['message'=>'Too many Relay requests. Retry later.',
            'code'=>'rate_limited'],429)->header('Retry-After',(string)$retry)->header('Cache-Control','no-store');
        foreach($buckets as $key=>$max)RateLimiter::hit($key,60);
        return $next($request);
    }
}
