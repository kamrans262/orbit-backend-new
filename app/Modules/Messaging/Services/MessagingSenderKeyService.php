<?php
namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

final class MessagingSenderKeyService
{
    public function __construct(
        private readonly MessagingConversationService $conversations,
        private readonly MessagingAccessPolicy $access
    ) {}

    private function assertProtocol(string $protocol): void
    {
        abort_unless(config('orbit_messaging.e2ee_client_verified')===true,
            503,'Verified Circle encryption is required.');
        $allowed=config('orbit_messaging.approved_protocols',[]);
        abort_unless(is_array($allowed) && in_array($protocol,$allowed,true),
            422,'Unapproved sender-key protocol.');
    }

    private function binary(string $encoded,int $min,int $max): void
    {
        $raw=base64_decode($encoded,true);
        abort_unless($raw!==false && strlen($raw)>=$min &&
            strlen($raw)<=$max && base64_encode($raw)===$encoded,
            422,'Invalid encrypted sender-key encoding.');
    }

    private function present(object $row): array
    {
        return [
            'id'=>$row->id,'conversation_id'=>$row->conversation_id,
            'group_epoch'=>(int)$row->group_epoch,
            'stored'=>true,'recipient_delivered'=>false,
            'ciphertext_only'=>true,
        ];
    }

    public function publish(object $thread,string $user,string $device,
        array $input): array
    {
        $this->conversations->assertReady();
        $this->assertProtocol($input['protocol']);
        $this->access->assertConversation($thread,$user);
        abort_unless($thread->kind==='circle',422,'Circle conversation required.');
        $sorted=[];
        foreach($input['envelopes'] as $env){
            abort_if(isset($sorted[$env['device_id']]),422,
                'Duplicate sender-key device envelope.');
            $this->binary($env['ciphertext_b64'],16,32768);
            $this->binary($env['protocol_header_b64'],12,4096);
            $sorted[$env['device_id']]=[
                'device_id'=>$env['device_id'],
                'ciphertext_b64'=>$env['ciphertext_b64'],
                'protocol_header_b64'=>$env['protocol_header_b64'],
            ];
        }
        abort_unless($sorted!==[] && count($sorted)<=128,422,
            'Opaque recipient envelopes are required.');
        ksort($sorted,SORT_STRING);
        $hash=hash('sha256',json_encode([
            $input['protocol'],(int)$input['group_epoch'],$sorted
        ],JSON_THROW_ON_ERROR));

        // Idempotency is checked inside the fresh, locked Circle epoch. An
        // old client ID can never make a revoked roster look distributed.
        return DB::transaction(function()use($thread,$user,$device,$input,
            $sorted,$hash):array {
            $row=DB::table('messaging_conversations')
                ->where('id',$thread->id)->lockForUpdate()->first();
            abort_unless($row && $row->kind==='circle',404,
                'Circle conversation unavailable.');
            $row=$this->conversations->refreshEpoch($row);
            $this->access->assertConversation($row,$user);
            abort_unless((int)$row->group_epoch===
                (int)$input['group_epoch'],409,
                'Circle membership changed; derive a fresh sender key.');

            $members=$this->access->recipients($row,$user);
            abort_unless($members!==[],409,
                'No consented Circle recipients for sender keys.');
            $targetUsers=array_values(array_unique([$user,...$members]));
            $devices=DB::table('devices as d')
                ->leftJoin('messaging_device_keys as k',
                    'k.device_id','=','d.id')
                ->whereIn('d.user_id',$targetUsers)
                ->where('d.trust_state','trusted')
                ->whereNotNull('d.public_key')
                ->whereNull('d.revoked_at')
                ->get(['d.id','d.user_id','k.key_version']);
            $versions=[];$present=[];
            foreach($devices as $d){
                abort_unless($d->key_version!==null,409,
                    'Recipient device requires verified messaging keys.');
                $versions[(string)$d->id]=(int)$d->key_version;
                $present[(string)$d->user_id]=true;
            }
            foreach($targetUsers as $member){
                abort_unless(isset($present[$member]),409,
                    'Recipient device key registration is required.');
            }
            $required=array_keys($versions);sort($required,SORT_STRING);
            $supplied=array_keys($sorted);sort($supplied,SORT_STRING);
            abort_unless($required!==[] &&
                in_array($device,$required,true) &&
                count($required)<=128 &&
                $required===$supplied,409,
                'Circle recipient roster changed; rotate and redistribute keys.');

            $old=DB::table('messaging_sender_key_batches')
                ->where('sender_device_id',$device)
                ->where('client_id',$input['client_id'])->first();
            if($old){
                abort_unless($old->conversation_id===$row->id &&
                    (int)$old->group_epoch===(int)$row->group_epoch &&
                    hash_equals((string)$old->payload_hash,$hash),
                    409,'Sender-key client ID conflict or revoked epoch.');
                return [$this->present($old),false];
            }

            $id=(string)Str::uuid();
            $now=now();
            DB::table('messaging_sender_key_batches')->insert([
                'id'=>$id,'conversation_id'=>$row->id,
                'group_epoch'=>$row->group_epoch,
                'sender_id'=>$user,'sender_device_id'=>$device,
                'client_id'=>$input['client_id'],
                'protocol'=>$input['protocol'],
                'payload_hash'=>$hash,'created_at'=>$now,
            ]);
            foreach($sorted as $env){
                DB::table('messaging_sender_key_envelopes')->insert([
                    'id'=>(string)Str::uuid(),'batch_id'=>$id,
                    'recipient_device_id'=>$env['device_id'],
                    'recipient_key_version'=>$versions[$env['device_id']],
                    'ciphertext_b64'=>$env['ciphertext_b64'],
                    'protocol_header_b64'=>$env['protocol_header_b64'],
                    'created_at'=>$now,
                ]);
            }
            return [[
                'id'=>$id,'conversation_id'=>$row->id,
                'group_epoch'=>(int)$row->group_epoch,
                'stored'=>true,'recipient_delivered'=>false,
                'ciphertext_only'=>true,
            ],true];
        });
    }

    public function forDevice(object $thread,string $viewer,
        string $device,int $limit,?string $encodedCursor=null): array
    {
        $cursor=$encodedCursor===null?null:Cursor::fromEncoded($encodedCursor);
        abort_unless($encodedCursor===null||$cursor!==null,422,
            'Invalid encrypted sender-key cursor.');
        $this->conversations->assertReady();
        $this->access->assertConversation($thread,$viewer);
        abort_unless($thread->kind==='circle',422,
            'Circle conversation required.');
        $row=$this->conversations->refreshEpoch($thread);
        $this->access->assertConversation($row,$viewer);
        $records=DB::table('messaging_sender_key_envelopes as e')
            ->join('messaging_sender_key_batches as b',
                'b.id','=','e.batch_id')
            ->where('e.recipient_device_id',$device)
            ->where('b.conversation_id',$row->id)
            ->where('b.group_epoch',$row->group_epoch)
            ->where('b.created_at','>=',now()->subDays(30))
            ->select([
                'e.id','e.recipient_key_version',
                'e.ciphertext_b64','e.protocol_header_b64',
                'e.created_at','b.sender_id','b.sender_device_id',
                'b.protocol','b.group_epoch',
            ])->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->cursorPaginate(min(max(1,$limit),100),
                ['*'],'cursor',$cursor);
        return [
            'items'=>$records->items(),
            'next_cursor'=>$records->nextCursor()?->encode(),
            'group_epoch'=>(int)$row->group_epoch,
            'ciphertext_only'=>true,
        ];
    }

    public function prune(): int
    {
        $ids=DB::table('messaging_sender_key_batches')
            ->where('created_at','<=',now()->subDays(30))
            ->limit(500)->pluck('id');
        if($ids->isEmpty())return 0;
        DB::table('messaging_sender_key_batches')
            ->whereIn('id',$ids)->delete();
        return $ids->count();
    }
}
