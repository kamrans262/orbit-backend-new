<?php
namespace App\Modules\Presence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Presence\Services\PresenceRealtimeTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PresenceGatewayController extends Controller
{
    private function respond(array $value): JsonResponse
    {
        return response()->json(['data'=>$value])->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization');
    }
    public function ticket(Request $request, PresenceRealtimeTicketService $service): JsonResponse
    {
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        return $this->respond($service->issue($session));
    }
    /**
     * Backend-to-gateway authorization only. Never call directly from Flutter.
     * Validate the event again BEFORE forwarding it to a websocket recipient.
     */
    public function inbound(Request $request, PresenceRealtimeTicketService $tickets,
        \App\Modules\Presence\Services\PresenceService $presence,
        \App\Modules\Presence\Services\PresenceTypingService $typing): JsonResponse
    {
        $data=$request->validate([
            'ticket'=>['required','string','min:40','max:100'],
            'kind'=>['required','in:presence.update,typing.update'],
            'state'=>['required_if:kind,presence.update','nullable','in:active,idle'],
            'circle_id'=>['required_if:kind,typing.update','nullable','uuid'],
            'typing'=>['required_if:kind,typing.update','nullable','boolean'],
        ]);
        $session=$tickets->introspect(
            (string)$request->header('X-Orbit-Presence-Gateway',''),
            $data['ticket'],[]);
        $viewer=$session['viewer_id'];
        if ($data['kind']==='presence.update') {
            return $this->respond($presence->heartbeat($viewer,$session['device_id'],$data['state']));
        }
        return $this->respond($typing->update($viewer,$data['circle_id'],(bool)$data['typing']));
    }

    public function introspect(Request $request, PresenceRealtimeTicketService $service): JsonResponse
    {
        $payload=$request->validate([
            'ticket'=>['required','string','min:40','max:100'],
            'event'=>['sometimes','array'],
            'event.type'=>['sometimes','string','max:64'],
            'event.circle_id'=>['sometimes','uuid'],
            'event.subject_id'=>['sometimes','uuid'],
        ]);
        return $this->respond($service->introspect(
            (string)$request->header('X-Orbit-Presence-Gateway',''),
            $payload['ticket'],$payload['event']??[]));
    }
}