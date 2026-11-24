<?php
namespace App\Modules\Realtime\Http\Controllers;

use App\Modules\Realtime\Services\RealtimeSessions;
use Illuminate\Http\Request;

final class RealtimeBootstrapController
{
    public function __invoke(Request $request,RealtimeSessions $sessions)
    {
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        return response()->json(['data'=>$sessions->bootstrap($session)])
            ->header('Cache-Control','private, no-store, max-age=0')->header('Vary','Authorization');
    }
}
