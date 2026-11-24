<?php

namespace App\Modules\Presence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** M9 operational switches only. Never expose raw user presence to Admin. */
final class AdminPresenceController extends Controller
{
    public function index(): Response
    {
        return response()->view('orbit-admin.presence', [
            'moduleEnabled'=>config('orbit_presence.enabled') === true,
            'lease'=>(int) config('orbit_presence.lease_seconds',90),
            'store'=>config('orbit_presence.store')==='redis'&&config('orbit_realtime.enabled')!==true?'Legacy Redis configured':'Database-backed ephemeral leases',
            'gateway'=>app(\App\Modules\Presence\Services\PresenceRealtimePublisher::class)->enabled(),
            'consumer'=>config('orbit_circle.consumer_enabled.presence') === true,
        ])->header('Cache-Control','private, no-store');
    }
}
