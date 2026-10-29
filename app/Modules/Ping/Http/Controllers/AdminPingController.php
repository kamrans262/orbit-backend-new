<?php

namespace App\Modules\Ping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminPingController extends Controller
{
    /** Privacy-safe operational aggregates. Never load or render Ping text. */
    public function index(): Response
    {
        $ready = Schema::hasTable('pings') && Schema::hasTable('ping_receipts')
            && Schema::hasTable('ping_responses');
        $metrics = null;
        if ($ready) {
            $since = now()->subDay();
            $delivered = DB::table('pings')->whereNotNull('delivered_at')
                ->where('delivered_at', '>=', $since);
            $examples = (clone $delivered)->select(['created_at', 'delivered_at'])
                ->orderByDesc('delivered_at')->limit(200)->get();
            $lag = $examples->isEmpty() ? null : (int) round($examples->avg(
                fn ($ping) => max(0, Carbon::parse($ping->created_at)->diffInSeconds(Carbon::parse($ping->delivered_at), false))
            ));
            $metrics = [
                'accepted_24h' => DB::table('pings')->where('created_at', '>=', $since)->count(),
                'confirmed_delivery_24h' => (clone $delivered)->count(),
                'viewed_24h' => DB::table('pings')->whereNotNull('seen_at')->where('seen_at', '>=', $since)->count(),
                'replies_24h' => DB::table('ping_responses')->where('created_at', '>=', $since)->count(),
                'awaiting_receipt' => DB::table('pings')->whereNull('delivered_at')->count(),
                'delivery_lag_seconds' => $lag,
                'rejected_24h' => Schema::hasTable('ping_operational_events')
                    ? DB::table('ping_operational_events')->where('action','send')
                        ->where('status_code','>=',400)->where('created_at','>=',$since)->count()
                    : null,
            ];
        }
        return response()->view('orbit-admin.ping', [
            'ready' => $ready,
            'enabled' => config('orbit_ping.enabled') === true,
            'circleEnabled' => config('orbit_circle.consumer_enabled.ping') === true,
            'metrics' => $metrics,
            'perMinute' => config('orbit_ping.sender_per_minute', 12),
            'perPair' => config('orbit_ping.pair_per_hour', 6),
        ])->header('Cache-Control', 'private, no-store');
    }
}
