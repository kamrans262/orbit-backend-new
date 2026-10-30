<?php

namespace App\Modules\CheckIn\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminCheckInController extends Controller
{
    /** Operations only. Never query or render message text or member identifiers. */
    public function index(): Response
    {
        $ready=Schema::hasTable('checkins') && Schema::hasTable('checkin_audience')
            && Schema::hasTable('checkin_recipients');
        $metrics=null;
        if ($ready) {
            $since=now()->subDay();
            $metrics=[
                'accepted_24h'=>DB::table('checkins')->where('created_at','>=',$since)->count(),
                'withdrawn_24h'=>DB::table('checkins')->whereNotNull('deleted_at')
                    ->where('deleted_at','>=',$since)->count(),
                'expired_total'=>DB::table('checkins')->whereNull('deleted_at')
                    ->whereNotNull('expires_at')->where('expires_at','<=',now())->count(),
                'active_total'=>DB::table('checkins')->whereNull('deleted_at')
                    ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->count(),
                'rejected_24h'=>Schema::hasTable('checkin_operational_events')
                    ? DB::table('checkin_operational_events')->where('action','publish')
                        ->where('status_code','>=',400)->where('created_at','>=',$since)->count()
                    : null,
            ];
        }
        return response()->view('orbit-admin.check-in',[
            'ready'=>$ready,'enabled'=>config('orbit_check_in.enabled')===true,
            'circleEnabled'=>config('orbit_circle.consumer_enabled.check_in')===true,
            'metrics'=>$metrics,'maxExpiryDays'=>(int)config('orbit_check_in.max_expiry_days',30),
            'maxPerMinute'=>(int)config('orbit_check_in.sender_per_minute',10),
        ])->header('Cache-Control','private, no-store');
    }
}
