<?php
namespace App\Modules\Relay\Services;

use App\Modules\Messaging\Services\MessagingEnvelopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class RelayReconciliationService
{
    public function __construct(private readonly RelayLeaseService $leases,private readonly RelayMetrics $metrics,
        private readonly MessagingEnvelopeService $messages){}
    // Fixed order and canonical standard base64 are checked before digesting.
    // Match M13's exact sorted payload hash; additionally scope and bind IDs in
    // the signed receipt. Stable client ID is the authoritative source ID.
    public static function digest(string $scope,string $client,array $source):string {
        $sorted=[];
        foreach($source['envelopes'] as $env){
            $sorted[$env['device_id']]=['device_id'=>$env['device_id'],'ciphertext_b64'=>$env['ciphertext_b64'],
                'protocol_header_b64'=>$env['protocol_header_b64']];
        }
        ksort($sorted,SORT_STRING);
        $canonical=[$scope,$client,$source['kind'],$source['protocol'],(int)$source['group_epoch'],array_values($sorted)];
        return hash('sha256',json_encode($canonical,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
    public static function receiptInput(object $lease,array $item):string {
        return implode('|',['orbit-relay-receipt-v1',$lease->id,$lease->scope_ref,$lease->device_id,
            $lease->peer_device_id,$item['envelope_id'],$item['source_client_id'],$item['digest'],'received']);
    }
    private function result(array $item,string $state,string $reason,?string $source=null,bool $peer=false):array {
        return ['envelope_id'=>$item['envelope_id'],'status'=>$state,'reason_code'=>$reason,
            'source_ref'=>$source,'peer_receipt_verified'=>$peer];
    }
    private function validateSource(array $item,object $lease,array $ctx,array $cap):array {
        $source=$item['source_envelope'];
        abort_unless($source['client_id']===$item['source_client_id'] &&
            $source['protocol']===RelayPolicy::MESSAGE_PROTOCOL &&
            (int)$source['group_epoch']===(int)$lease->source_epoch &&
            in_array($source['kind'],['text','emoji','sticker','reaction','reply'],true),422,'Unsupported source.');
        $seen=[];
        foreach($source['envelopes'] as $env){
            $device=$env['device_id'];
            abort_if(isset($seen[$device]) || !isset($ctx['keys'][$device]),422,'Invalid source roster.');
            $seen[$device]=true;
            foreach(['ciphertext_b64'=>[16,$cap['limits']['frame_bytes']],'protocol_header_b64'=>[12,(int)config('orbit_messaging.max_header_bytes',4096)]] as $field=>[$min,$max]){
                $value=$env[$field];$raw=base64_decode($value,true);
                abort_unless(is_string($raw) && strlen($raw)>=$min && strlen($raw)<=$max
                    && base64_encode($raw)===$value,422,'Invalid ciphertext encoding.');
            }
        }
        abort_unless(count($seen)===count($ctx['keys']),409,'Source device roster changed.');
        abort_unless(hash_equals(self::digest($lease->scope_ref,$item['source_client_id'],$source),$item['digest']),422,'Digest mismatch.');
        return $source;
    }
    private function proof(object $lease,array $item,array $ctx):?string {
        if(!isset($item['receipt']))return null;
        $signature=RelayLeaseService::bytes($item['receipt']['signature_b64'],64);
        $public=RelayLeaseService::bytes($ctx['peer']->identity_ed25519,32);
        abort_unless(sodium_crypto_sign_verify_detached($signature,self::receiptInput($lease,$item),$public),422,'Invalid recipient proof.');
        return hash('sha256',$signature);
    }
    public function reconcile(object $session,array $items):array {
        $results=[];
        foreach($items as $item){
            try{
                $results[]=DB::transaction(function()use($session,$item):array {
                    $cap=$this->leases->lockPolicy();
                    $lease=DB::table('relay_leases')->where('id',$item['lease_id'])->where('device_id',$session->device_id)
                        ->where('user_id',$session->user_id)->lockForUpdate()->first();
                    abort_unless($lease,404,'Lease unavailable.');
                    $ctx=$this->leases->assertCurrent($lease,$session,$cap);
                    $source=$this->validateSource($item,$lease,$ctx,$cap);
                    $proof=$this->proof($lease,$item,$ctx);
                    $query=DB::table('relay_reconciliations')->where('device_id',$session->device_id)
                        ->where(function($q)use($item){
                            $q->where('envelope_id',$item['envelope_id'])->orWhere(function($q)use($item){
                                $q->where('content_type',RelayPolicy::CONTENT)->where('source_client_id',$item['source_client_id']);
                            });
                        })->lockForUpdate()->get();
                    abort_if($query->count()>1,409,'Conflicting relay identity.');
                    $old=$query->first();
                    if($old)abort_unless($old->lease_id===$lease->id && $old->envelope_id===$item['envelope_id']
                        && $old->source_client_id===$item['source_client_id'] && hash_equals($old->digest,$item['digest']),409,'Conflicting relay identity.');
                    // Use source-authoritative deduplication, roster checks,
                    // moderation, notifications and message retention.
                    [$message,$created]=$this->messages->send($ctx['thread'],(string)$session->user_id,(string)$session->device_id,$source);
                    $sourceId=$message['id'];
                    $live=DB::table('messaging_messages')->where('id',$sourceId)->where('conversation_id',$lease->scope_ref)
                        ->where('sender_device_id',$session->device_id)->whereNull('redacted_at')->where('expires_at','>',now())->exists();
                    abort_unless($live,410,'Source message expired.');
                    if($old)abort_unless($old->source_ref===$sourceId,409,'Source conflict.');
                    $id=$old?->id??(string)Str::uuid7();
                    if(!$old) DB::table('relay_reconciliations')->insert(['id'=>$id,'lease_id'=>$lease->id,
                        'device_id'=>$session->device_id,'envelope_id'=>$item['envelope_id'],
                        'source_client_id'=>$item['source_client_id'],'content_type'=>RelayPolicy::CONTENT,
                        'digest'=>$item['digest'],'status'=>'accepted','reason_code'=>'source_accepted',
                        'source_ref'=>$sourceId,'created_at'=>now(),'updated_at'=>now()]);
                    $verified=DB::table('relay_receipts')->where('reconciliation_id',$id)
                        ->where('recipient_device_id',$lease->peer_device_id)->where('kind','received')->first();
                    if($proof!==null && !$verified){
                        $env=DB::table('messaging_envelopes')->where('message_id',$sourceId)
                            ->where('recipient_device_id',$lease->peer_device_id)
                            ->where('recipient_key_version',$lease->peer_key_version)->first();
                        abort_unless($env,409,'Recipient envelope unavailable.');
                        // A signed receipt authenticates device receipt only.
                        // Existing M13 delivery semantics; never mark a read.
                        $this->messages->receipt($ctx['thread'],(string)$ctx['peer']->user_id,$lease->peer_device_id,$env->id,false);
                        DB::table('relay_receipts')->insert(['id'=>(string)Str::uuid7(),'reconciliation_id'=>$id,
                            'recipient_device_id'=>$lease->peer_device_id,'digest'=>$item['digest'],'proof_hash'=>$proof,
                            'kind'=>'received','received_at'=>now()]);
                        $this->metrics->record('verified_receipt');
                    }elseif($proof!==null && $verified){
                        abort_unless(hash_equals($verified->proof_hash,$proof),409,'Receipt conflict.');
                    }
                    $duplicate=$old!==null || !$created;
                    $this->metrics->record($duplicate?'duplicate':'accepted');
                    return $this->result($item,$duplicate?'duplicate':'accepted',$duplicate?'already_accepted':'source_accepted',$sourceId,$proof!==null||$verified!==null);
                },3);
            }catch(HttpExceptionInterface $e){
                $status=$e->getStatusCode();
                $state=match($status){410=>'expired',429,503=>'retryable',default=>'rejected'};
                $reason=match($status){410=>'expired',429=>'rate_limited',503=>'unavailable',409=>'conflict_or_revoked',422=>'invalid_envelope',default=>'not_authorized'};
                $this->metrics->record($state);
                $results[]=$this->result($item,$state,$reason);
            }
        }
        return $results;
    }
}
