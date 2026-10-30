<?php
namespace App\Modules\Messaging\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MessagingOperationalTelemetry
{
    public function handle(Request $request,Closure $next): Response
    {
        $status=500;
        try{
            $response=$next($request);
            $status=$response->getStatusCode();
            return $response;
        }catch(\Throwable $e){
            $status=$e instanceof ValidationException ?422
                :($e instanceof HttpExceptionInterface?$e->getStatusCode():500);
            throw $e;
        }finally{
            // Error counts too; never log message content, IDs, request URLs,
            // ciphertext, auth tokens, user addresses, device identifiers or IP.
            try{
                if(Schema::hasTable('messaging_operational_events')){
                    DB::table('messaging_operational_events')->insert([
                        'action'=>$request->isMethod('POST')?'write':'read',
                        'status_code'=>$status,'created_at'=>now(),
                    ]);
                }
            }catch(\Throwable){}
        }
    }
}
