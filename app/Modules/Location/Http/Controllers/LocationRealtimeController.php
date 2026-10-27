<?php
namespace App\Modules\Location\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Location\Services\LocationRealtimeTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LocationRealtimeController extends Controller
{
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data'=>$data])
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization');
    }

    public function ticket(Request $request, LocationRealtimeTicketService $service): JsonResponse
    {
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        return $this->respond($service->issue($session));
    }

    /** Gateway only: revalidate trusted device/session at every event. */
    public function introspect(Request $request, LocationRealtimeTicketService $service): JsonResponse
    {
        $data=$request->validate(['ticket'=>['required','string','min:40','max:100']]);
        return $this->respond($service->introspect(
            (string)$request->header('X-Orbit-Location-Gateway',''),
            $data['ticket']));
    }
}
