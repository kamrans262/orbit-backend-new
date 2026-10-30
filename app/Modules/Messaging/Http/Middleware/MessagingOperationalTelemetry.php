<?php
namespace App\Modules\Messaging\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class MessagingOperationalTelemetry
{
    public function handle(Request $request,Closure $next): Response
    {
        $response=$next($request);
        // Operational aggregates without users, conversation IDs, URLs,
        // plaintext, ciphertext, remote IPs or personal identifiers.
        if(Schema::hasTable('messaging_operational_events')){
            try{
                DB::table('messaging_operational_events')->insert([
                    'action'=>$request->isMethod('POST')?'write':'read',
                    'status_code'=>$response->getStatusCode(),'created_at'=>now(),
                ]);
            }catch(\Throwable){}
        }
        return $response;
    }
}
