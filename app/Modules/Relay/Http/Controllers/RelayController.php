<?php
namespace App\Modules\Relay\Http\Controllers;

use App\Modules\Relay\Services\RelayPolicy;
use App\Modules\Relay\Services\RelayLeaseService;
use App\Modules\Relay\Services\RelayReconciliationService;
use App\Modules\Relay\Services\RelayMetrics;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

final class RelayController
{
    public function __construct(private readonly RelayPolicy $policy,private readonly MessagingAccessPolicy $access){}
    private function session(Request $r):object {
        $s=$r->attributes->get('orbit_session');
        abort_unless($s,401);
        $this->access->assertSession((string)$s->user_id,(string)$s->device_id);
        return $s;
    }
    private function result(array $data,int $status=200):JsonResponse {
        return response()->json(['data'=>$data],$status)->header('Cache-Control','no-store');
    }
    public function capabilities(Request $r):JsonResponse {
        $this->session($r);
        return $this->result($this->policy->capabilities());
    }
    public function leases(Request $r):JsonResponse {
        $s=$this->session($r);
        if(!$this->policy->capabilities()['enabled'])return $this->result(['code'=>'relay_unavailable'],503);
        abort_if(array_diff(array_keys($r->all()),['scope_ref','content_type','peer_device_ref','device_key_id','protocol_version','client_nonce']),422);
        $input=$r->validate([
            'scope_ref'=>'required|uuid','content_type'=>'required|in:direct_message',
            'peer_device_ref'=>'required|uuid','device_key_id'=>'required|integer|min:1',
            'protocol_version'=>'required|in:orbit-relay-direct-v1',
            'client_nonce'=>'required|string|regex:/^[a-f0-9]{64}$/D',
        ]);
        return $this->result(app(RelayLeaseService::class)->issue($s,$input));
    }
    public function revoke(Request $r,string $lease):JsonResponse {
        $s=$this->session($r);
        abort_unless($this->policy->provisioned(),503);
        $input=$r->validate(['reason'=>'required|in:opt_out,cancel,logout,key_change']);
        DB::transaction(function()use($s,$lease,$input):void {
            $row=DB::table('relay_leases')->where('id',$lease)->where('device_id',$s->device_id)
                ->where('user_id',$s->user_id)->lockForUpdate()->first();
            abort_unless($row,404,'Lease unavailable.');
            if($row->revoked_at===null){
                DB::table('relay_leases')->where('id',$lease)->update(['revoked_at'=>now(),'reason'=>$input['reason']]);
                app(RelayMetrics::class)->record('lease_revoked');
            }
        });
        return $this->result(['state'=>'revoked']);
    }
    public function reconcile(Request $r):JsonResponse {
        $s=$this->session($r);
        $cap=$this->policy->capabilities();
        if(!$cap['enabled'])return $this->result(['code'=>'relay_unavailable'],503);
        abort_if(strlen($r->getContent())>5242880,413,'Relay batch too large.');
        abort_if(array_diff(array_keys($r->all()),['batch_id','items']),422);
        $p=$r->validate([
            'batch_id'=>'required|uuid','items'=>'required|array|list|min:1|max:'.$cap['limits']['batch_limit'],
            'items.*'=>'required|array:envelope_id,source_client_id,lease_id,digest,receipt,source_envelope',
            'items.*.envelope_id'=>'required|uuid|distinct','items.*.source_client_id'=>'required|uuid|distinct',
            'items.*.lease_id'=>'required|uuid','items.*.digest'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'items.*.receipt'=>'sometimes|array:signature_b64,nonce,received_at',
            'items.*.receipt.signature_b64'=>'required_with:items.*.receipt|string|size:88',
            'items.*.receipt.nonce'=>'required_with:items.*.receipt|uuid',
            'items.*.receipt.received_at'=>'required_with:items.*.receipt|integer|min:1|max:4102444800',
            'items.*.source_envelope'=>'required|array:client_id,kind,protocol,group_epoch,envelopes',
            'items.*.source_envelope.client_id'=>'required|uuid','items.*.source_envelope.kind'=>'required|in:text,emoji,sticker,reaction,reply',
            'items.*.source_envelope.protocol'=>'required|in:orbit-m13-olm-megolm-v1',
            'items.*.source_envelope.group_epoch'=>'required|integer|between:1,2147483647',
            'items.*.source_envelope.envelopes'=>'required|array|list|min:1|max:12',
            'items.*.source_envelope.envelopes.*'=>'required|array:device_id,ciphertext_b64,protocol_header_b64',
            'items.*.source_envelope.envelopes.*.device_id'=>'required|uuid',
            'items.*.source_envelope.envelopes.*.ciphertext_b64'=>'required|string|min:24|max:45000',
            'items.*.source_envelope.envelopes.*.protocol_header_b64'=>'required|string|min:16|max:5600',
        ]);
        return $this->result(['results'=>app(RelayReconciliationService::class)->reconcile($s,$p['items'])]);
    }
    public function sync(Request $r):JsonResponse {
        $s=$this->session($r);
        abort_unless($this->policy->provisioned(),503);
        $p=$r->validate(['cursor'=>'sometimes|string|max:2048','limit'=>'sometimes|integer|min:1|max:100']);
        $query=DB::table('relay_reconciliations as r')->where('r.device_id',$s->device_id)
            ->select('r.id','r.envelope_id','r.source_client_id','r.status','r.reason_code','r.updated_at')
            ->selectSub(DB::table('relay_receipts as p')->whereColumn('p.reconciliation_id','r.id')
                ->where('p.kind','received')->selectRaw('count(*)'),'verified_receipts');
        if(isset($p['cursor'])){
            try{$cursor=json_decode(Crypt::decryptString($p['cursor']),true,8,JSON_THROW_ON_ERROR);}
            catch(DecryptException|\JsonException){abort(422,'Invalid cursor.');}
            abort_unless(is_array($cursor) && ($cursor['device']??null)===$s->device_id && ($cursor['user']??null)===$s->user_id
                && is_string($cursor['id']??null) && \Illuminate\Support\Str::isUuid($cursor['id'])
                && is_int($cursor['expires']??null) && $cursor['expires']>=now()->timestamp,422,'Invalid cursor.');
            $query->where('r.id','>',$cursor['id']);
        }
        $limit=(int)($p['limit']??20);
        $items=$query->orderBy('r.id')->limit($limit+1)->get();
        $more=$items->count()>$limit;
        $items=$items->take($limit)->values();
        $next=$more?Crypt::encryptString(json_encode(['device'=>$s->device_id,'user'=>$s->user_id,
            'id'=>$items->last()->id,'expires'=>now()->addMinutes(15)->timestamp],JSON_THROW_ON_ERROR)):null;
        return $this->result(['items'=>$items->map(fn($item)=>[
            'envelope_id'=>$item->envelope_id,'source_client_id'=>$item->source_client_id,'status'=>$item->status,'reason_code'=>$item->reason_code,
            'peer_receipt_verified'=>(int)$item->verified_receipts>0,
            'updated_at'=>$item->updated_at,
        ])->all(),'next_cursor'=>$next,'policy_revision'=>$this->policy->settings()['revision']]);
    }
}
