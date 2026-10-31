<?php
namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use App\Modules\Messaging\Services\MessagingConversationService;
use App\Modules\Messaging\Services\MessagingEnvelopeService;
use App\Modules\Messaging\Services\MessagingKeyService;
use App\Modules\Messaging\Services\MessagingTypingService;
use App\Modules\Messaging\Services\MessagingSenderKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\RateLimiter;

final class MessagingController extends Controller
{
    public function __construct(
        private readonly MessagingAccessPolicy $access,
        private readonly MessagingConversationService $conversations,
        private readonly MessagingEnvelopeService $messages,
        private readonly MessagingKeyService $keys,
        private readonly MessagingTypingService $typing,
        private readonly MessagingSenderKeyService $senderKeys
    ) {}

    private function session(Request $r): object
    {
        $session=$r->attributes->get('orbit_session');
        abort_unless($session,401,'Authentication required.');
        $this->access->assertSession((string)$session->user_id,(string)$session->device_id);
        return $session;
    }

    private function response(array $data,int $code=200): JsonResponse
    {
        return response()->json(['data'=>$data],$code)
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization');
    }

    public function capabilities(Request $r): JsonResponse
    {
        $this->session($r);
        return $this->response([
            'available'=>$this->conversations->ready(),
            'https_ciphertext_mailbox'=>$this->conversations->ready(),
            'key_directory'=>$this->conversations->ready(),
            'direct_enabled'=>$this->conversations->ready(),
            'circle_enabled'=>$this->conversations->ready()
                && config('orbit_circle.consumer_enabled.message')===true,
            'e2ee_client_verified'=>config('orbit_messaging.e2ee_client_verified')===true,
            'realtime_available'=>false,'push_available'=>false,
            'typing_http_available'=>config('orbit_messaging.typing_http_enabled')===true,
            'server_content_readable'=>false,
            'delivery_transport'=>'foreground_https',
            'one_time_prekeys'=>true,'per_device_envelopes'=>true,
            'message_retention_days'=>(int)config('orbit_messaging.undelivered_retention_days',30),
            'client_local_history_required'=>true,
        ]);
    }

    public function myDevices(Request $r): JsonResponse
    {
        $session=$this->session($r);
        $this->conversations->assertReady();
        return $this->response($this->keys->mine((string)$session->user_id,(string)$session->device_id));
    }

    public function registerKeys(Request $r): JsonResponse
    {
        $session=$this->session($r);
        $this->conversations->assertReady();
        abort_if(array_diff(array_keys($r->all()),[
            'identity_ed25519','signed_prekey_x25519','signed_prekey_signature',
            'signed_prekey_id','confirm_key_rotation','one_time_prekeys'])!==[],
            422,'Only device public key material may be uploaded.');
        foreach($r->input('one_time_prekeys',[]) as $key) {
            abort_if(!is_array($key) ||
                array_diff(array_keys($key),['key_id','public_x25519'])!==[],
                422,'Private messaging key fields are prohibited.');
        }
        $input=$r->validate([
            'identity_ed25519'=>['required','string','max:64'],
            'signed_prekey_x25519'=>['required','string','max:64'],
            'signed_prekey_signature'=>['required','string','max:128'],
            'signed_prekey_id'=>['required','integer','between:1,2147483647'],
            'confirm_key_rotation'=>['sometimes','boolean'],
            'one_time_prekeys'=>['sometimes','array','max:100'],
            'one_time_prekeys.*.key_id'=>['required','integer','between:1,2147483647'],
            'one_time_prekeys.*.public_x25519'=>['required','string','max:64'],
        ]);
        return $this->response($this->keys->register((string)$session->user_id,
            (string)$session->device_id,$input));
    }

    public function discoverKeys(Request $r,string $recipient): JsonResponse
    {
        $session=$this->session($r);
        $this->conversations->assertReady();
        $options=$r->validate([
            'claim_one_time_prekey'=>['sometimes','boolean'],
            'device_id'=>['sometimes','uuid'],
        ]);
        $claim=!array_key_exists('claim_one_time_prekey',$options)
            || (bool)$options['claim_one_time_prekey'];
        return $this->response($this->keys->discover(
            (string)$session->user_id,$recipient,$claim,
            $options['device_id']??null));
    }

    public function listing(Request $r): JsonResponse
    {
        $session=$this->session($r);
        $p=$r->validate(['page'=>['sometimes','integer','between:1,100']]);
        return $this->response($this->conversations->listing(
            (string)$session->user_id,(string)$session->device_id,(int)($p['page']??1)));
    }

    public function openDirect(Request $r): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate(['recipient_id'=>['required','uuid']]);
        return $this->response($this->conversations->direct(
            (string)$session->user_id,$input['recipient_id']));
    }

    public function circle(Request $r,string $circle): JsonResponse
    {
        $session=$this->session($r);
        return $this->response($this->conversations->circle(
            $circle,(string)$session->user_id));
    }

    public function envelopes(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'limit'=>['sometimes','integer','between:1,100'],
            'cursor'=>['sometimes','string','max:500'],
        ]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->page($thread,
            (string)$session->user_id,(string)$session->device_id,
            (int)($input['limit']??50),$input['cursor']??null));
    }

    public function senderKeyInbox(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'limit'=>['sometimes','integer','between:1,100'],
            'cursor'=>['sometimes','string','max:500'],
        ]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->senderKeys->forDevice(
            $thread,(string)$session->user_id,(string)$session->device_id,
            (int)($input['limit']??50),$input['cursor']??null));
    }

    public function distributeSenderKey(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        abort_if(array_diff(array_keys($r->all()),[
            'client_id','protocol','group_epoch','envelopes'])!==[],
            422,'Only encrypted sender-key distribution is accepted.');
        $input=$r->validate([
            'client_id'=>['required','uuid'],
            'protocol'=>['required','string','min:3','max:48',
                'regex:/^[a-z0-9._-]+$/D'],
            'group_epoch'=>['required','integer','between:1,2147483647'],
            'envelopes'=>['required','array','min:1','max:128'],
            'envelopes.*.device_id'=>['required','uuid'],
            'envelopes.*.ciphertext_b64'=>['required','string','min:24','max:45000'],
            'envelopes.*.protocol_header_b64'=>['required','string','min:16','max:5600'],
        ]);
        foreach($input['envelopes'] as $env){
            abort_if(array_diff(array_keys($env),[
                'device_id','ciphertext_b64','protocol_header_b64'])!==[],
                422,'Sender-key envelope cannot include private key material.');
        }
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        [$result,$created]=$this->senderKeys->publish(
            $thread,(string)$session->user_id,(string)$session->device_id,
            $input);
        return $this->response($result,$created?201:200);
    }

    private function encryptedSend(Request $r,object $session,string $conversation): JsonResponse
    {
        // No plaintext fallback, demo payload or location coordinates.
        // Unknown keys are rejected rather than accidentally accepted into
        // future request logging or a passthrough serializer.
        abort_if(array_diff(array_keys($r->all()),
            ['client_id','kind','protocol','group_epoch','envelopes'])!==[],
            422,'Only opaque encrypted-envelope fields are accepted.');
        $input=$r->validate([
            'client_id'=>['required','uuid'],
            'kind'=>['required',Rule::in(['text','emoji','sticker','image','video','voice','file','reaction','reply'])],
            'protocol'=>['required','string','min:3','max:48','regex:/^[a-z0-9._-]+$/D'],
            'group_epoch'=>['required','integer','between:1,2147483647'],
            'envelopes'=>['required','array','min:1','max:128'],
            'envelopes.*.device_id'=>['required','uuid'],
            'envelopes.*.ciphertext_b64'=>['required','string','min:24','max:45000'],
            'envelopes.*.protocol_header_b64'=>['required','string','min:16','max:5600'],
        ]);
        foreach($r->input('envelopes',[]) as $env){
            abort_if(!is_array($env) || array_diff(array_keys($env),
                ['device_id','ciphertext_b64','protocol_header_b64'])!==[],
                422,'Encrypted envelopes contain prohibited fields.');
        }
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        [$result,$created]=$this->messages->send($thread,
            (string)$session->user_id,(string)$session->device_id,$input);
        return $this->response($result,$created?201:200);
    }

    public function send(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        return $this->encryptedSend($r,$session,$conversation);
    }

    public function circleEnvelopes(Request $r,string $circle): JsonResponse
    {
        $session=$this->session($r);
        $record=$this->conversations->circle($circle,(string)$session->user_id);
        return $this->envelopesFor($r,$record['id']);
    }
    private function envelopesFor(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'limit'=>['sometimes','integer','between:1,100'],
            'cursor'=>['sometimes','string','max:500'],
        ]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->page($thread,
            (string)$session->user_id,(string)$session->device_id,
            (int)($input['limit']??50),$input['cursor']??null));
    }
    public function circleSend(Request $r,string $circle): JsonResponse
    {
        $session=$this->session($r);
        $record=$this->conversations->circle($circle,(string)$session->user_id);
        return $this->encryptedSend($r,$session,$record['id']);
    }

    public function deliveredReceipt(Request $r,string $conversation): JsonResponse
    {
        $data=$r->validate(['envelope_id'=>['required','uuid']]);
        return $this->delivered($r,$conversation,$data['envelope_id']);
    }

    public function readReceipt(Request $r,string $conversation): JsonResponse
    {
        $data=$r->validate([
            'envelope_id'=>['required','uuid'],
            'visible_dwell_ms'=>['required','integer','between:600,600000'],
        ]);
        return $this->read($r,$conversation,$data['envelope_id']);
    }

    public function typing(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        abort_unless(config('orbit_messaging.typing_http_enabled')===true,503);
        $input=$r->validate(['active'=>['required','boolean']]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->typing->update(
            $thread,(string)$session->user_id,(bool)$input['active']));
    }

    public function currentTyping(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        abort_unless(config('orbit_messaging.typing_http_enabled')===true,503);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->typing->current(
            $thread,(string)$session->user_id));
    }

    public function circleTyping(Request $r,string $circle): JsonResponse
    {
        $session=$this->session($r);
        $this->conversations->circle($circle,(string)$session->user_id);
        $input=$r->validate(['enabled'=>['required','boolean']]);
        return $this->response($this->typing->circleSetting(
            $circle,(string)$session->user_id,(bool)$input['enabled']));
    }

    public function sync(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'delivered_envelope_ids'=>['sometimes','array','max:200'],
            'delivered_envelope_ids.*'=>['required','uuid','distinct'],
            'limit'=>['sometimes','integer','between:1,200'],
            'cursor'=>['sometimes','string','max:500'],
        ]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->sync($thread,
            (string)$session->user_id,(string)$session->device_id,$input));
    }

    public function delivered(Request $r,string $conversation,string $envelope): JsonResponse
    {
        $session=$this->session($r);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->receipt($thread,
            (string)$session->user_id,(string)$session->device_id,$envelope,false));
    }
    public function read(Request $r,string $conversation,string $envelope): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'visible_dwell_ms'=>['required','integer','between:600,600000'],
        ]);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->receipt($thread,
            (string)$session->user_id,(string)$session->device_id,$envelope,true,
            (int)$input['visible_dwell_ms']));
    }
    public function receipts(Request $r,string $conversation,string $message): JsonResponse
    {
        $session=$this->session($r);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->sentReceipts($thread,
            (string)$session->user_id,$message));
    }
    public function redact(Request $r,string $conversation,string $message): JsonResponse
    {
        $session=$this->session($r);
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->messages->redact($thread,
            (string)$session->user_id,$message));
    }
    public function leave(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $user=(string)$session->user_id;
        $thread=$this->conversations->get($conversation,$user);
        if($thread->kind==='circle') {
            // Leaving a Circle chat invokes M5 Circle leave rather than
            // simulating departure while retaining access to the group.
            app(\App\Modules\Circle\Services\CircleService::class)
                ->leave((string)$thread->circle_id,$user);
            return $this->response(['left'=>true,'left_circle'=>true]);
        }
        $this->conversations->preferences($thread,$user,['archive'=>true]);
        return $this->response(['left'=>true,'archived'=>true,
            'left_circle'=>false]);
    }

    public function preferences(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $input=$r->validate([
            'mute'=>['sometimes','boolean'],'archive'=>['sometimes','boolean'],
        ]);
        abort_if($input===[],422,'Choose a conversation preference.');
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        return $this->response($this->conversations->preferences($thread,
            (string)$session->user_id,$input));
    }
    public function report(Request $r,string $conversation): JsonResponse
    {
        $session=$this->session($r);
        $rateKey='messaging:report:'.hash('sha256',(string)$session->user_id);
        abort_if(RateLimiter::tooManyAttempts($rateKey,10),429,'Report limit reached.');
        abort_if(array_diff(array_keys($r->all()),
            ['message_id','reason','share_evidence','confirm_evidence_share','evidence_text'])!==[],
            422,'Unexpected report fields.');
        $input=$r->validate([
            'message_id'=>['sometimes','nullable','uuid'],
            'reason'=>['required',Rule::in(['spam','abuse','harassment','impersonation','other'])],
            'share_evidence'=>['sometimes','boolean'],
            'confirm_evidence_share'=>['required_if:share_evidence,true','sometimes','boolean'],
            'evidence_text'=>['required_if:share_evidence,true',
                'prohibited_unless:share_evidence,true','nullable','string','max:1500'],
        ]);
        if(($input['share_evidence']??false)===true){
            abort_unless(($input['confirm_evidence_share']??false)===true
                && ($input['message_id']??null)!==null
                && trim((string)($input['evidence_text']??''))!=='',422,
                'Sharing selected report evidence requires explicit confirmation.');
        }
        $thread=$this->conversations->get($conversation,(string)$session->user_id);
        $result=$this->messages->report($thread,
            (string)$session->user_id,$input['message_id']??null,$input['reason'],
            ($input['share_evidence']??false)===true
                ?trim((string)$input['evidence_text']):null);
        RateLimiter::hit($rateKey,60);
        return $this->response($result,201);
    }
}
