<?php
namespace App\Modules\Realtime\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RealtimeHealth
{
    public function snapshot():array
    {
        $out=['Local configuration'=>app(FirebaseRealtimeProvider::class)->readiness(),'Delivery'=>app(RealtimeSessions::class)->enabled()?'Eligible — live verification required':'Disabled'];
        if(!Schema::hasTable('orbit_realtime_sessions'))return $out+['Storage'=>'Not provisioned'];
        $table=DB::table('orbit_realtime_sessions');
        if(Schema::hasTable('orbit_realtime_recovery')){
            $last=DB::table('orbit_realtime_recovery')->where('operation','probe')->whereNotNull('processed_at')->orderByDesc('processed_at')->first();
            $out['Last probe result']=$last?->result??'Not run';
            $out['Last probe time']=$last?->processed_at??'None';
        }
        if(Schema::hasTable('orbit_realtime_auth_receipts')){
            $out['Pending remote cleanup']=DB::table('orbit_realtime_auth_receipts')->whereNotNull('tombstone_after')->orWhereNotNull('auth_after')->count();
        }
        $out+=app(RealtimeMetrics::class)->summary();
        return $out+[
            'Storage'=>'Provisioned',
            'Active leases'=>(clone $table)->where('lease_until_ms','>',now()->getTimestampMs())->count(),
            'Pending updates'=>(clone $table)->where('dirty',true)->count(),
            'Pending expiry cleanup'=>(clone $table)->where('lease_until_ms','<=',now()->getTimestampMs())->count(),
            'Retrying sessions'=>(clone $table)->where('attempts','>',0)->count(),
            'Oldest pending update'=>(clone $table)->where('dirty',true)->min('updated_at')??'None',
            'Last recorded successful publish'=>(clone $table)->max('last_success_at')??'None',
        ];
    }
}
