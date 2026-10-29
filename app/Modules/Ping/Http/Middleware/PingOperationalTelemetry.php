<?php

namespace App\Modules\Ping\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** No user IDs, device IDs, IPs, recipients, custom messages or payloads. */
final class PingOperationalTelemetry
{
    public function handle(Request $request, Closure $next): Response
    {
        $id=(string) Str::uuid();
        $action=$this->action($request);
        try {
            $response=$next($request);
        } catch (Throwable $error) {
            $status=$error instanceof HttpExceptionInterface ? $error->getStatusCode()
                : ($error instanceof ValidationException ? 422 : 500);
            $this->record($id,$action,$status);
            throw $error;
        }
        $response->headers->set('X-Orbit-Request-ID',$id);
        if($response->getStatusCode()>=400) {
            $this->record($id,$action,$response->getStatusCode());
        }
        return $response;
    }

    private function action(Request $request): string
    {
        if($request->isMethod('POST')&&$request->is('api/v1/pings'))return 'send';
        if($request->is('api/v1/pings/*/respond'))return 'response';
        if($request->is('api/v1/pings/*/delivered')||
            $request->is('api/v1/pings/*/seen'))return 'receipt';
        return 'read';
    }

    private function reason(int $status): string
    {
        return match($status) {
            400,422 => 'validation',401 => 'authentication',403 => 'authorization',
            404 => 'unavailable',409 => 'conflict',429 => 'rate_limit',
            408,502,503,504 => 'transport',default => 'server_error',
        };
    }

    private function record(string $id,string $action,int $status): void
    {
        // Telemetry must never make the actual Ping API fail.
        try {
            if(!Schema::hasTable('ping_operational_events'))return;
            DB::table('ping_operational_events')->insert([
                'id'=>(string) Str::uuid(),'request_id'=>$id,'action'=>$action,
                'status_code'=>$status,'reason_code'=>$this->reason($status),
                'created_at'=>now(),
            ]);
        } catch(Throwable $ignored) {
            Log::warning('Ping operational telemetry unavailable',[
                'request_id'=>$id,'action'=>$action,'status_code'=>$status,
            ]);
        }
    }
}
