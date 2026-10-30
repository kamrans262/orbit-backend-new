<?php
namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;

final class MessagingEnvelopeService
{
    public function __construct(
        private readonly MessagingConversationService $conversations,
        private readonly MessagingAccessPolicy $access,
        private readonly MessagingKeyService $keys
    ) {}

    private function safeBinary(string $encoded,int $min,int $max): void
    {
        $raw=base64_decode($encoded,true);
        abort_unless($raw!==false && strlen($raw)>=$min && strlen($raw)<=$max
            && base64_encode($raw)===$encoded,422,'Invalid encrypted envelope encoding.');
    }

    public function send(object $conversation,string $sender,string $device,array $payload): array
    {
        $this->conversations->assertReady();
        // Server-side release policy prevents unaudited clients from claiming
        // production E2EE by putting arbitrary base64 bytes in an envelope.
        // This is a release gate, NOT cryptographic proof of client honesty.
        abort_unless(config('orbit_messaging.e2ee_client_verified')===true,
            503,'Secure messaging has not passed release verification.');
        $approved=config('orbit_messaging.approved_protocols',[]);
        abort_unless(is_array($approved) &&
            in_array($payload['protocol'],$approved,true),
            422,'Unapproved encryption protocol.');
        $this->access->assertConversation($conversation,$sender);
        $key='messaging:send:'.hash('sha256',$sender.'|'.$device);
        $old=DB::table('messaging_messages')->where('sender_device_id',$device)
            ->where('client_id',$payload['client_id'])->first();
        $envelopes=$payload['envelopes'];
        abort_unless(count($envelopes)>0 &&
            count($envelopes)<=(int)config('orbit_messaging.max_message_devices',128),
            422,'Encrypted device envelopes required.');
        $sorted=[];
        foreach($envelopes as $env){
            $recipient=$env['device_id'];
            abort_if(isset($sorted[$recipient]),422,'Duplicate device envelope.');
            $this->safeBinary($env['ciphertext_b64'],16,
                (int)config('orbit_messaging.max_envelope_bytes',32768));
            $this->safeBinary($env['protocol_header_b64'],12,
                (int)config('orbit_messaging.max_header_bytes',4096));
            $sorted[$recipient]=[
                'device_id'=>$recipient,
                'ciphertext_b64'=>$env['ciphertext_b64'],
                'protocol_header_b64'=>$env['protocol_header_b64'],
            ];
        }
        ksort($sorted,SORT_STRING);
        $hash=hash('sha256',json_encode([$payload['kind'],$payload['protocol'],
            (int)$payload['group_epoch'],$sorted],JSON_THROW_ON_ERROR));
        if($old){
            abort_unless(hash_equals((string)$old->payload_hash,$hash),409,
                'Encrypted message request ID conflict.');
            $this->access->assertConversation($conversation,$sender);
            abort_unless($old->conversation_id===$conversation->id,409,'Message request ID conflict.');
            return [$this->present($old),false];
        }
        abort_if(RateLimiter::tooManyAttempts($key,
            max(1,(int)config('orbit_messaging.per_minute_send',45))),
            429,'Too many messages; please try again.');
        $result=DB::transaction(function() use($conversation,$sender,$device,
            $payload,$sorted,$hash):array {
            $row=DB::table('messaging_conversations')->where('id',$conversation->id)
                ->lockForUpdate()->first();
            abort_unless($row,404,'Conversation unavailable.');
            $row=$this->conversations->refreshEpoch($row);
            $this->access->assertConversation($row,$sender);
            abort_unless((int)$payload['group_epoch']===(int)$row->group_epoch,
                409,'Conversation key epoch changed; rotate group sender key.');
            $recipients=$this->access->recipients($row,$sender);
            // Each authorized recipient device AND all sender devices need a
            // separate opaque envelope. One own copy supports local history
            // replay without granting the server access to plaintext.
            abort_if(count($recipients)===0,422,'No currently eligible recipients.');
            $targetUsers=array_values(array_unique([$sender,...$recipients]));
            $devices=DB::table('devices as d')
                ->leftJoin('messaging_device_keys as k','k.device_id','=','d.id')
                ->whereIn('d.user_id',$targetUsers)->where('d.trust_state','trusted')
                ->whereNotNull('d.public_key')->whereNull('d.revoked_at')
                ->get(['d.id','d.user_id','k.key_version']);
            $requiredVersions=[];
            $presentUsers=[];
            foreach($devices as $target){
                // Never accept a send if any approved target device cannot
                // decrypt. Require registration instead of silent delivery loss.
                abort_unless($target->key_version!==null,409,
                    'Recipient device needs messaging key setup.');
                $requiredVersions[(string)$target->id]=(int)$target->key_version;
                $presentUsers[(string)$target->user_id]=true;
            }
            foreach($targetUsers as $member){
                abort_unless(isset($presentUsers[$member]),409,
                    'Recipient has no registered messaging device.');
            }
            $required=array_keys($requiredVersions);
            sort($required,SORT_STRING);
            $supplied=array_keys($sorted);
            sort($supplied,SORT_STRING);
            abort_if(count($required)>(int)config('orbit_messaging.max_message_devices',128),
                422,'Too many recipient messaging devices for one encrypted send.');
            abort_unless($required!==[] && in_array($device,$required,true)
                && $required===$supplied,409,
                'Device recipient roster changed; refresh keys and re-encrypt.');
            $old=DB::table('messaging_messages')->where('sender_device_id',$device)
                ->where('client_id',$payload['client_id'])->first();
            if($old){
                abort_unless($old->conversation_id===$row->id
                    && hash_equals((string)$old->payload_hash,$hash),409,
                    'Encrypted message request ID conflict.');
                return [$this->present($old),false];
            }
            $id=(string)Str::uuid();
            $now=now();
            $expiry=$now->copy()->addDays((int)config(
                'orbit_messaging.undelivered_retention_days',30));
            DB::table('messaging_messages')->insert([
                'id'=>$id,'conversation_id'=>$row->id,'sender_id'=>$sender,
                'sender_device_id'=>$device,'client_id'=>$payload['client_id'],
                'kind'=>$payload['kind'],'protocol'=>$payload['protocol'],
                'payload_hash'=>$hash,'group_epoch'=>$row->group_epoch,
                'created_at'=>$now,'expires_at'=>$expiry,
            ]);
            foreach($sorted as $env){
                DB::table('messaging_envelopes')->insert([
                    'id'=>(string)Str::uuid(),'message_id'=>$id,
                    'recipient_device_id'=>$env['device_id'],
                    'recipient_key_version'=>$requiredVersions[$env['device_id']],
                    'ciphertext_b64'=>$env['ciphertext_b64'],
                    'protocol_header_b64'=>$env['protocol_header_b64'],
                    'created_at'=>$now,
                ]);
            }
            DB::table('messaging_conversations')->where('id',$row->id)
                ->update(['updated_at'=>$now]);
            return [['id'=>$id,'conversation_id'=>$row->id,'kind'=>$payload['kind'],
                'group_epoch'=>(int)$row->group_epoch,'accepted'=>true,
                'delivered'=>false,'created_at'=>$now->toIso8601String()],true];
        });
        if($result[1])RateLimiter::hit($key,60);
        return $result;
    }

    private function present(object $record): array
    {
        return ['id'=>$record->id,'conversation_id'=>$record->conversation_id,
            'kind'=>$record->kind,'group_epoch'=>(int)$record->group_epoch,
            'accepted'=>true,'delivered'=>false,
            'created_at'=>(string)$record->created_at];
    }

    public function page(object $conversation,string $user,string $device,
        int $limit): array
    {
        $this->access->assertConversation($conversation,$user);
        $records=DB::table('messaging_envelopes as e')
            ->join('messaging_messages as m','m.id','=','e.message_id')
            ->where('m.conversation_id',$conversation->id)
            ->where('e.recipient_device_id',$device)
            ->whereNull('m.redacted_at')
            ->where('m.expires_at','>',now())
            ->select(['e.id','e.ciphertext_b64','e.recipient_key_version',
                'e.protocol_header_b64','e.delivered_at','e.read_at',
                'm.id as message_id','m.sender_id','m.sender_device_id',
                'm.kind','m.protocol','m.group_epoch','e.created_at'])
            ->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->cursorPaginate(min(max(1,$limit),(int)config('orbit_messaging.sync_max',200)));
        return ['items'=>$records->items(),'next_cursor'=>$records->nextCursor()?->encode(),
            'ciphertext_only'=>true];
    }

    public function sync(object $conversation,string $user,string $device,
        array $input): array
    {
        $this->access->assertConversation($conversation,$user);
        $ids=$input['delivered_envelope_ids'] ?? [];
        if($ids){
            DB::table('messaging_envelopes as e')
                ->join('messaging_messages as m','m.id','=','e.message_id')
                ->where('m.conversation_id',$conversation->id)
                ->where('e.recipient_device_id',$device)
                ->whereIn('e.id',$ids)->whereNull('e.delivered_at')
                ->update(['e.delivered_at'=>now()]);
        }
        return $this->page($conversation,$user,$device,
            (int)($input['limit']??(int)config('orbit_messaging.sync_max',200)));
    }

    public function receipt(object $conversation,string $user,string $device,
        string $envelopeId,bool $read,int $dwellMs=0): array
    {
        $this->access->assertConversation($conversation,$user);
        $env=DB::table('messaging_envelopes as e')
            ->join('messaging_messages as m','m.id','=','e.message_id')
            ->where('e.id',$envelopeId)->where('e.recipient_device_id',$device)
            ->where('m.conversation_id',$conversation->id)
            ->where('m.sender_id','!=',$user)
            ->whereNull('m.redacted_at')->where('m.expires_at','>',now())
            ->first(['e.id','e.delivered_at','e.read_at']);
        abort_unless($env,404,'Encrypted envelope unavailable.');
        if($read){
            abort_unless($this->access->readReceiptsAllowed($user),403,
                'Read receipts are disabled in privacy settings.');
            abort_unless($dwellMs>=600,422,'Read requires at least 600ms visible dwell.');
        }
        $updates=['delivered_at'=>$env->delivered_at??now()];
        if($read)$updates['read_at']=$env->read_at??now();
        DB::table('messaging_envelopes')->where('id',$envelopeId)->update($updates);
        return ['envelope_id'=>$envelopeId,'delivered'=>true,
            'read'=>$read||$env->read_at!==null];
    }

    public function sentReceipts(object $conversation,string $sender,string $message): array
    {
        $this->access->assertConversation($conversation,$sender);
        $row=DB::table('messaging_messages')->where('id',$message)
            ->where('conversation_id',$conversation->id)
            ->where('sender_id',$sender)->first();
        abort_unless($row,404,'Message unavailable.');
        $currentRecipients=$this->access->recipients($conversation,$sender);
        $counts=DB::table('messaging_envelopes as e')
            ->join('devices as d','d.id','=','e.recipient_device_id')
            ->where('e.message_id',$message)->whereIn('d.user_id',$currentRecipients)
            ->where('d.trust_state','trusted')->whereNull('d.revoked_at')
            ->selectRaw('count(*) as total,
                SUM(CASE WHEN e.delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered')
            ->first();
        // Read counts reveal only recipients whose privacy still enables
        // sharing read receipts, and only within the conversation.
        $read=DB::table('messaging_envelopes as e')
            ->join('devices as d','d.id','=','e.recipient_device_id')
            ->where('e.message_id',$message)->whereIn('d.user_id',$currentRecipients)
            ->where('d.trust_state','trusted')->whereNull('d.revoked_at')
            ->whereNotNull('e.read_at')->pluck('d.user_id')->unique()->all();
        $allowed=array_reduce($read,fn(int $n,string $id):int=>
            $n+($this->access->readReceiptsAllowed($id)?1:0),0);
        $visibleReadRecipients=[];
        foreach($read as $recipient) {
            if($this->access->readReceiptsAllowed((string)$recipient)){
                $visibleReadRecipients[]=(string)$recipient;
            }
        }
        return ['message_id'=>$message,
            'recipient_devices'=>(int)($counts->total??0),
            'delivered_devices'=>(int)($counts->delivered??0),
            'read_users'=>$allowed,
            'read_recipient_ids'=>$visibleReadRecipients];
    }

    public function redact(object $conversation,string $sender,string $id): array
    {
        $this->access->assertConversation($conversation,$sender);
        $message=DB::table('messaging_messages')->where('id',$id)
            ->where('conversation_id',$conversation->id)
            ->where('sender_id',$sender)->first();
        abort_unless($message,404,'Message unavailable.');
        DB::transaction(function()use($id):void{
            DB::table('messaging_messages')->where('id',$id)
                ->whereNull('redacted_at')->update(['redacted_at'=>now()]);
            DB::table('messaging_envelopes')->where('message_id',$id)->delete();
        });
        return ['message_id'=>$id,'redacted'=>true];
    }

    public function report(object $conversation,string $reporter,?string $message,
        string $reason,?string $evidence=null): array
    {
        $this->access->assertConversation($conversation,$reporter);
        if($message!==null){
            abort_unless(DB::table('messaging_messages')->where('id',$message)
                ->where('conversation_id',$conversation->id)->exists(),404,'Message unavailable.');
            // Only a recipient device or the sender may report this message.
            $visible=DB::table('messaging_messages')->where('id',$message)
                ->where('sender_id',$reporter)->exists()
                || DB::table('messaging_envelopes as e')
                    ->join('messaging_messages as m','m.id','=','e.message_id')
                    ->join('devices as d','d.id','=','e.recipient_device_id')
                    ->where('e.message_id',$message)->where('d.user_id',$reporter)->exists();
            abort_unless($visible,404,'Message unavailable.');
        }
        $id=(string)Str::uuid();
        DB::table('messaging_reports')->insert([
            'id'=>$id,'conversation_id'=>$conversation->id,
            'message_id'=>$message,'reporter_id'=>$reporter,
            'reason'=>$reason,'status'=>'pending','created_at'=>now(),
            'evidence_shared'=>$evidence!==null,
            'evidence_enc'=>$evidence===null?null:Crypt::encryptString($evidence),
        ]);
        return ['report_id'=>$id,'received'=>true,
            'private_message_content_shared'=>$evidence!==null];
    }

    public function prune(): int
    {
        // Max 30-day ciphertext server retention; client history remains
        // exclusively inside separately encrypted local storage.
        $ids=DB::table('messaging_messages')->where('expires_at','<=',now())
            ->limit(500)->pluck('id');
        if(!$ids->count())return 0;
        DB::table('messaging_messages')->whereIn('id',$ids)->delete();
        return $ids->count();
    }
}
