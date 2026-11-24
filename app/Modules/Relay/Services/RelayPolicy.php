<?php
namespace App\Modules\Relay\Services;
use App\Models\RemoteConfig;
use Illuminate\Support\Facades\Schema;

final class RelayPolicy
{
    public function provisioned():bool {
        foreach(['relay_leases','relay_reconciliations','relay_receipts','relay_daily_metrics','remote_configs'] as $table) {
            if(!Schema::hasTable($table)) return false;
        }
        return true;
    }
    public function settings():array {
        $record=$this->provisioned()?RemoteConfig::query()->where('key','relay.operations')->where('published',true)->first():null;
        $v=(array)($record?->value??[]);
        return [
            'enabled'=>($v['enabled']??false)===true,
            'lease_seconds'=>max(60,min(900,(int)($v['lease_seconds']??900))),
            'batch_limit'=>max(1,min(20,(int)($v['batch_limit']??20))),
            'frame_bytes'=>max(1024,min(32768,(int)($v['frame_bytes']??32768),(int)config('orbit_messaging.max_envelope_bytes',32768))),
            'revision'=>(int)($record?->version??0),
        ];
    }
    public function signingKey():?string {
        if(!function_exists('sodium_crypto_sign_detached')) return null;
        $value=config('orbit_relay.signing_secret');
        if(!is_string($value)) return null;
        $key=base64_decode($value,true);
        if($key===false || strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || base64_encode($key)!==$value) return null;
        return $key;
    }
    public function capabilities():array {
        $s=$this->settings();
        $reason=match(true){
            !$this->provisioned()=>'schema_unavailable',
            config('orbit_relay.enabled')!==true || !$s['enabled']=>'disabled',
            config('orbit_relay.security_reviewed')!==true=>'review_required',
            config('orbit_messaging.e2ee_client_verified')!==true=>'messaging_review_required',
            $this->signingKey()===null=>'signing_unavailable',
            default=>'adapter_unavailable',
        };
        // No protocol or adapter may be advertised until actual reconciliation
        // and peer interoperability are implemented and independently verified.
        return ['enabled'=>false,'policy_revision'=>$s['revision'],'server_time'=>now()->utc()->toIso8601String(),
            'protocol_versions'=>[],'content_types'=>[],'limits'=>[
                'lease_seconds'=>$s['lease_seconds'],'batch_limit'=>$s['batch_limit'],
                'frame_bytes'=>$s['frame_bytes'],'session_seconds'=>60,'queue_bytes'=>5242880,
            ],'readiness'=>$reason];
    }
}
