<?php
namespace App\Modules\Relay\Services;
use App\Models\RemoteConfig;
use Illuminate\Support\Facades\Schema;
use App\Modules\Messaging\Services\MessagingConversationService;

final class RelayPolicy
{
    public function provisioned():bool {
        foreach(['relay_leases','relay_reconciliations','relay_receipts','relay_daily_metrics','remote_configs'] as $table) {
            if(!Schema::hasTable($table)) return false;
        }
        return Schema::hasColumn('relay_leases','roster_hash') && Schema::hasColumn('relay_leases','session_id');
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
    public const PROTOCOL='orbit-relay-direct-v1';
    public const CONTENT='direct_message';
    public const MESSAGE_PROTOCOL='orbit-m13-olm-megolm-v1';
    public function publicKey():?string {
        $secret=$this->signingKey();
        return $secret===null?null:sodium_crypto_sign_publickey_from_secretkey($secret);
    }
    public function keyId():?string {
        $key=$this->publicKey();
        return $key===null?null:hash('sha256',$key);
    }
    public function capabilities():array {
        $s=$this->settings();
        $reason=match(true){
            !$this->provisioned()=>'schema_unavailable',
            config('orbit_relay.enabled')!==true || !$s['enabled']=>'disabled',
            config('orbit_relay.security_reviewed')!==true=>'review_required',
            config('orbit_messaging.e2ee_client_verified')!==true=>'messaging_review_required',
            $this->signingKey()===null=>'signing_unavailable',
            !in_array(self::MESSAGE_PROTOCOL,(array)config('orbit_messaging.approved_protocols',[]),true)
                || !app(MessagingConversationService::class)->ready()=>'messaging_unavailable',
            config('orbit_relay.peer_transport_reviewed')!==true=>'peer_review_required',
            default=>'available',
        };
        $available=$reason==='available';
        return ['enabled'=>$available,'policy_revision'=>$s['revision'],'server_time'=>now()->utc()->toIso8601String(),
            'protocol_versions'=>$available?[self::PROTOCOL]:[],'content_types'=>$available?[self::CONTENT]:[],
            'signing_key'=>$available?['id'=>$this->keyId(),'public_ed25519'=>base64_encode($this->publicKey())]:null,'limits'=>[
                'lease_seconds'=>$s['lease_seconds'],'batch_limit'=>$s['batch_limit'],
                'frame_bytes'=>$s['frame_bytes'],'session_seconds'=>60,'queue_bytes'=>5242880,
            ],'readiness'=>$reason];
    }
}
