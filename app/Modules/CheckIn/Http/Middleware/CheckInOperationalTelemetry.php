<?php

namespace App\Modules\CheckIn\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class CheckInOperationalTelemetry
{
    public function handle(Request $request,Closure $next): Response
    {
        $response=null;
        $failure=null;
        try {
            $response=$next($request);
            return $response;
        } catch (\Throwable $error) {
            $failure=$error;
            throw $error;
        } finally {
            $status=$response?->getStatusCode()
                ?? ($failure instanceof ValidationException ? 422
                    : ($failure instanceof HttpExceptionInterface ? $failure->getStatusCode() : 500));
            try {
            if (Schema::hasTable('checkin_operational_events')) {
                $path=$request->path();
                $action=str_contains($path,'/consent')?'consent'
                    : ($request->isMethod('POST')?'publish'
                    : ($request->isMethod('DELETE')?'withdraw'
                    : ($request->isMethod('PUT')?'audience'
                    : ($request->isMethod('PATCH')?'expiry':'read'))));
                DB::table('checkin_operational_events')->insert([
                    'id'=>(string)Str::uuid(),'action'=>$action,
                    'status_code'=>$status,'created_at'=>now(),
                ]);
            }
            } catch (\Throwable) {
                // Aggregate telemetry must never block a user request.
            }
        }
    }
}
