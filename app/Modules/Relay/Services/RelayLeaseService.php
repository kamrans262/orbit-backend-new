<?php
namespace App\Modules\Relay\Services;

use App\Models\RemoteConfig;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use App\Modules\Messaging\Services\MessagingConversationService;
use App\Modules\Messaging\Services\MessagingSendRestrictionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

final class RelayLeaseService
{
    public function __construct(private readonly RelayPolicy $policy,private readonly MessagingAccessPolicy $access,
        private readonly MessagingConversationService $conversations,private readonly RelayMetrics $metrics){}
    public static function bytes(string $encoded,int $length):string {
        $raw=base64_decode($encoded,true);
        abort_unless(is_string($raw) && strlen($raw)===$length && base64_encode($raw)===$encoded,422,'Invalid binary encoding.');
        return $raw;
    }
    private static function b64url(string $value):string {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }
    public function lockPolicy():array {
        abort_unless($this->policy->provisioned(),503,'Relay unavailable.');
        // Serialize issuance/reconciliation with Admin policy changes.
        abort_unless(RemoteConfig::query()->where('key','relay.operations')->lockForUpdate()->first(),503);
        $cap=$this->policy->capabilities();
        abort_unless($cap['enabled'],503,'Relay unavailable.');
        return $cap;
    }
    public function context(object $session,string $scope,string $peer):array {
        $user=(string)$session->user_id;$device=(string)$session->device_id;
        $this->access->assertSession($user,$device);
        abort_unless(DB::table('orbit_sessions')->where('id',$session->id)->where('user_id',$user)
            ->where('device_id',$device)->whereNull('revoked_at')->where('expires_at','>',now())->exists(),403,'Session unavailable.');
        $thread=$this->conversations->get($scope,$user);
        abort_unless($thread->kind==='direct' && $peer!==$device,404,'Relay scope unavailable.');
        $recipients=$this->access->recipients($thread,$user);
        abort_unless(count($recipients)===1,404,'Relay scope unavailable.');
        $other=$recipients[0];
        app(MessagingSendRestrictionService::class)->assertMaySend($user);
        $this->access->assertDirect($user,$other);
        $this->access->assertSession($other,$peer);
        $rows=DB::table('devices as d')->leftJoin('messaging_device_keys as k','k.device_id','=','d.id')
            ->whereIn('d.user_id',[$user,$other])->where('d.trust_state','trusted')
            ->whereNotNull('d.public_key')->whereNull('d.revoked_at')->orderBy('d.id')
            ->get(['d.id','d.user_id','k.user_id as key_owner','k.key_version','k.identity_ed25519']);
        $keys=[];$fingerprints=[];
        abort_unless($rows->count()>=2 && $rows->count()<=(int)config('orbit_messaging.max_direct_devices',12),409,'Relay device roster unavailable.');
        foreach($rows as $row){
            abort_unless($row->key_owner===$row->user_id && (int)$row->key_version>=1 && is_string($row->identity_ed25519),409,'Messaging keys unavailable.');
            self::bytes($row->identity_ed25519,32);
            $keys[$row->id]=$row;
            $fingerprints[]=[$row->id,(int)$row->key_version,hash('sha256',$row->identity_ed25519)];
        }
        abort_unless(isset($keys[$device],$keys[$peer]) && $keys[$peer]->user_id===$other,404,'Relay scope unavailable.');
        return ['thread'=>$thread,'keys'=>$keys,'sender'=>$keys[$device],'peer'=>$keys[$peer],
            'roster_hash'=>hash('sha256',json_encode($fingerprints,JSON_THROW_ON_ERROR))];
    }
    public function assertCurrent(object $lease,object $session,array $cap):array {
        abort_unless($lease->user_id===$session->user_id && $lease->device_id===$session->device_id
            && $lease->session_id===$session->id,404,'Lease unavailable.');
        abort_unless($lease->revoked_at===null,409,'Lease revoked.');
        abort_if(Carbon::parse($lease->expires_at)->lte(now()),410,'Lease expired.');
        abort_unless((int)$lease->policy_revision===$cap['policy_revision'] && $lease->signing_key_id===$this->policy->keyId()
            && $lease->protocol_version===RelayPolicy::PROTOCOL && $lease->content_type===RelayPolicy::CONTENT,409,'Lease policy changed.');
        $ctx=$this->context($session,$lease->scope_ref,$lease->peer_device_id);
        abort_unless((int)$lease->key_version===(int)$ctx['sender']->key_version
            && (int)$lease->peer_key_version===(int)$ctx['peer']->key_version
            && (int)$lease->source_epoch===(int)$ctx['thread']->group_epoch
            && is_string($lease->roster_hash) && hash_equals($lease->roster_hash,$ctx['roster_hash']),409,'Lease keys or roster changed.');
        return $ctx;
    }
    public function issue(object $session,array $input):array {
        return DB::transaction(function()use($session,$input):array {
            $cap=$this->lockPolicy();
            $ctx=$this->context($session,$input['scope_ref'],$input['peer_device_ref']);
            abort_unless((int)$input['device_key_id']===(int)$ctx['sender']->key_version,409,'Device key changed.');
            $nonce=hash('sha256',$input['client_nonce']);
            $old=DB::table('relay_leases')->where('device_id',$session->device_id)->where('nonce_hash',$nonce)->lockForUpdate()->first();
            if($old){
                abort_unless($old->scope_ref===$input['scope_ref'] && $old->peer_device_id===$input['peer_device_ref'],409,'Lease nonce conflict.');
                $ctx=$this->assertCurrent($old,$session,$cap);
                return $this->present($old,$ctx);
            }
            abort_if(DB::table('relay_leases')->where('device_id',$session->device_id)->whereNull('revoked_at')
                ->where('expires_at','>',now())->count()>=20,429,'Too many active leases.');
            $now=now()->startOfSecond();
            $id=(string)Str::uuid7();
            $row=['id'=>$id,'user_id'=>$session->user_id,'device_id'=>$session->device_id,
                'peer_device_id'=>$input['peer_device_ref'],'scope_ref'=>$input['scope_ref'],
                'content_type'=>RelayPolicy::CONTENT,'protocol_version'=>RelayPolicy::PROTOCOL,
                'signing_key_id'=>$this->policy->keyId(),'key_version'=>(int)$ctx['sender']->key_version,
                'peer_key_version'=>(int)$ctx['peer']->key_version,'policy_revision'=>$cap['policy_revision'],
                'session_id'=>$session->id,'roster_hash'=>$ctx['roster_hash'],'source_epoch'=>(int)$ctx['thread']->group_epoch,
                'nonce_hash'=>$nonce,'issued_at'=>$now,'expires_at'=>$now->copy()->addSeconds($cap['limits']['lease_seconds']),
                'revoked_at'=>null,'reason'=>null];
            DB::table('relay_leases')->insert($row);
            $this->metrics->record('lease_issued');
            return $this->present((object)$row,$ctx);
        },3);
    }
    private function present(object $lease,array $ctx):array {
        // Token is transmitted only to its authenticated owner; never advertise
        // it over discovery. The recipient must verify using a separately trusted key.
        $claims=['v'=>1,'kid'=>$lease->signing_key_id,'lease_id'=>$lease->id,'sender_device'=>$lease->device_id,
            'peer_device'=>$lease->peer_device_id,'scope_ref'=>$lease->scope_ref,'content_type'=>$lease->content_type,
            'protocol_version'=>$lease->protocol_version,'sender_key_version'=>(int)$lease->key_version,
            'peer_key_version'=>(int)$lease->peer_key_version,'sender_ed25519'=>$ctx['sender']->identity_ed25519,
            'peer_ed25519'=>$ctx['peer']->identity_ed25519,'source_epoch'=>(int)$lease->source_epoch,
            'roster_hash'=>$lease->roster_hash,'policy_revision'=>(int)$lease->policy_revision,
            'issued_at'=>Carbon::parse($lease->issued_at)->timestamp,'expires_at'=>Carbon::parse($lease->expires_at)->timestamp];
        $json=json_encode($claims,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $secret=$this->policy->signingKey();
        abort_unless($secret!==null,503,'Relay unavailable.');
        $sig=sodium_crypto_sign_detached('orbit-relay-lease-v1|'.$json,$secret);
        return ['lease'=>self::b64url($json).'.'.self::b64url($sig),'lease_id'=>$lease->id,
            'expires_at'=>Carbon::parse($lease->expires_at)->utc()->toIso8601String()];
    }
}
