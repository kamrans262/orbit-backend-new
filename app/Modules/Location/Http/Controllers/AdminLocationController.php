<?php

namespace App\Modules\Location\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10 configuration and aggregate health ONLY. No GPS, recipients, account
 * names, sample payload, per-user track or debug export can reach Admin.
 */
final class AdminLocationController extends Controller
{
    public function index(): Response
    {
        $installed=Schema::hasTable('location_shares') &&
            Schema::hasTable('location_latest') && Schema::hasTable('location_consent_audits');
        return response()->view('orbit-admin.location',[
            'enabled'=>config('orbit_location.enabled')===true,
            'consumer'=>config('orbit_circle.consumer_enabled.location')===true,
            'realtime'=>false, // No approved location WSS implementation in Stage 2.
            'schemaInstalled'=>$installed,
            'activeShares'=>$installed ? DB::table('location_shares')->where('status','active')
                ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->count() : null,
            'freshSamples'=>$installed ? DB::table('location_latest')
                ->where('expires_at','>',now())->count() : null,
            'ttl'=>(int)config('orbit_location.latest_ttl_seconds',120),
        ])->header('Cache-Control','private, no-store');
    }
}
