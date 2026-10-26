<?php

namespace App\Modules\Presence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** M9 operational switches only. Never expose raw user presence to Admin. */
final class AdminPresenceController extends Controller
{
    public function index(): Response
    {
        abort_unless(config('orbit_presence.enabled') === true,404);
        return response()->view('orbit-admin.presence', [
            'lease'=>(int) config('orbit_presence.lease_seconds',90),
            'store'=>config('orbit_presence.store','redis') === 'redis' ? 'Redis configured' : 'Nonproduction adapter',
            'gateway'=>config('orbit_presence.realtime_enabled') === true,
            'consumer'=>config('orbit_circle.consumer_enabled.presence') === true,
        ])->header('Cache-Control','private, no-store');
    }
}
