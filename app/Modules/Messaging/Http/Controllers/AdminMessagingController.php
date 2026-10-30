<?php
namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdminMessagingController extends Controller
{
    /**
     * Aggregate operations only; no ciphertext, private reports, conversation
     * titles, participant IDs, private key material or message plaintext.
     */
    public function index(): Response
    {
        $ready=Schema::hasTable('messaging_conversations')
            && Schema::hasTable('messaging_device_keys')
            && Schema::hasTable('messaging_envelopes');
        $stats=null;
        if($ready){
            $since=now()->subDay();
            $stats=[
                'direct_conversations'=>DB::table('messaging_conversations')
                    ->where('kind','direct')->count(),
                'circle_conversations'=>DB::table('messaging_conversations')
                    ->where('kind','circle')->count(),
                'trusted_registered_devices'=>DB::table('messaging_device_keys as k')
                    ->join('devices as d','d.id','=','k.device_id')
                    ->where('d.trust_state','trusted')->whereNull('d.revoked_at')->count(),
                'accepted_messages_24h'=>DB::table('messaging_messages')
                    ->where('created_at','>=',$since)->count(),
                'pending_envelopes'=>DB::table('messaging_envelopes as e')
                    ->join('messaging_messages as m','m.id','=','e.message_id')
                    ->whereNull('e.delivered_at')->where('m.expires_at','>',now())->count(),
                'aggregate_reports_pending'=>DB::table('messaging_reports')
                    ->where('status','pending')->count(),
                'rejections_24h'=>Schema::hasTable('messaging_operational_events')
                    ?DB::table('messaging_operational_events')->where('status_code','>=',400)
                        ->where('created_at','>=',$since)->count():null,
            ];
        }
        return response()->view('orbit-admin.messaging',[
            'ready'=>$ready,'stats'=>$stats,
            'backendEnabled'=>config('orbit_messaging.enabled')===true,
            'circleEnabled'=>config('orbit_circle.consumer_enabled.message')===true,
            'clientVerified'=>config('orbit_messaging.e2ee_client_verified')===true,
            'retentionDays'=>(int)config('orbit_messaging.undelivered_retention_days',30),
        ])->header('Cache-Control','private, no-store');
    }
}
