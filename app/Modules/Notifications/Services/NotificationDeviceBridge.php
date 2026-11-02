<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent, privacy-enforced M7 -> M14 encrypted FCM token compatibility.
 * Does not activate Firebase, create permission consent or duplicate an
 * existing shared device registration. M7 ownership remains independent
 * until old clients are retired after verification.
 */
final class NotificationDeviceBridge
{
    public function __construct(private readonly NotificationPolicy $policy,
                                private readonly NotificationPushTransport $transport) {}

    private function active(string $user,string $device): bool
    {
        return DB::table('orbit_users')->where('id',$user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists()
            && DB::table('devices')->where('id',$device)
                ->where('user_id',$user)->where('trust_state','trusted')
                ->whereNotNull('approved_at')->whereNull('revoked_at')->exists()
            && DB::table('orbit_sessions')->where('user_id',$user)
                ->where('device_id',$device)->whereNull('revoked_at')
                ->where('expires_at','>',now())->exists()
            && $this->policy->preference($user,'push_notifications_enabled');
    }

    public function bridge(int $limit=50): array
    {
        $result=['bridged'=>0,'skipped'=>0];
        if(!$this->policy->installed()
            || !Schema::hasTable('moment_push_devices')
            || !$this->transport->enabled()
            || !$this->policy->enabled('channel.push'))return $result;
        $rows=DB::table('moment_push_devices as m')
            ->whereNull('m.disabled_at')
            ->whereNotExists(fn($q)=>$q->selectRaw('1')
                ->from('notification_device_tokens as t')
                ->whereColumn('t.device_id','m.device_id'))
            ->orderBy('m.updated_at')->limit(max(1,min($limit,100)))
            ->get(['m.device_id','m.user_id','m.platform',
                'm.token_digest','m.token_ciphertext']);
        $project=(string)config('orbit_moments.fcm.project_id','');
        if($project==='')return $result;
        foreach($rows as $row){
            if(!$this->active($row->user_id,$row->device_id)){
                $result['skipped']++;continue;
            }
            $result['bridged']+=DB::table('notification_device_tokens')
                ->insertOrIgnore([
                    'device_id'=>$row->device_id,'user_id'=>$row->user_id,
                    'platform'=>$row->platform,'project_id'=>$project,
                    'token_ciphertext'=>$row->token_ciphertext,
                    'token_digest'=>$row->token_digest,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
        }
        return $result;
    }

    public function reconcile(int $limit=100): array
    {
        $result=['disabled'=>0,'purged'=>0];
        if(!$this->policy->installed())return $result;
        $tokens=DB::table('notification_device_tokens')
            ->whereNull('disabled_at')->orderBy('updated_at')
            ->limit(max(1,min($limit,200)))->get(['device_id','user_id']);
        foreach($tokens as $row){
            if(!$this->active($row->user_id,$row->device_id))
                $result['disabled']+=DB::table('notification_device_tokens')
                    ->where('device_id',$row->device_id)->whereNull('disabled_at')
                    ->update(['disabled_at'=>now(),'updated_at'=>now()]);
        }
        // Expired encrypted tokens have no reason to be retained forever.
        $result['purged']=DB::table('notification_device_tokens')
            ->where('disabled_at','<',now()->subDays(30))
            ->orderBy('disabled_at')->limit(100)->delete();
        return $result;
    }
}
