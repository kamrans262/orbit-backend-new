<?php
namespace App\Modules\Relay\Http\Controllers;

use App\Modules\Relay\Services\RelayPolicy;
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
        $this->session($r);
        // Deliberately deny issuance until the source adapter and signer
        // contract are complete; never issue unusable/offline authority.
        return $this->result(['code'=>'relay_unavailable'],503);
    }
    public function revoke(Request $r,string $lease):JsonResponse {
        $s=$this->session($r);
        abort_unless($this->policy->provisioned(),503);
        $input=$r->validate(['reason'=>'required|in:opt_out,cancel,logout,key_change']);
        DB::transaction(function()use($s,$lease,$input):void {
            $row=DB::table('relay_leases')->where('id',$lease)->where('device_id',$s->device_id)
                ->where('user_id',$s->user_id)->lockForUpdate()->first();
            abort_unless($row,404,'Lease unavailable.');
            if($row->revoked_at===null) DB::table('relay_leases')->where('id',$lease)->update([
                'revoked_at'=>now(),'reason'=>$input['reason'],
            ]);
        });
        return $this->result(['state'=>'revoked']);
    }
    public function reconcile(Request $r):JsonResponse {
        $this->session($r);
        // Never turn an unvalidated receipt into an accepted source event.
        return $this->result(['code'=>'relay_unavailable'],503);
    }
    public function sync(Request $r):JsonResponse {
        $s=$this->session($r);
        abort_unless($this->policy->provisioned(),503);
        $p=$r->validate(['cursor'=>'sometimes|string|max:2048','limit'=>'sometimes|integer|min:1|max:100']);
        $query=DB::table('relay_reconciliations')->where('device_id',$s->device_id);
        if(isset($p['cursor'])){
            try{$cursor=json_decode(Crypt::decryptString($p['cursor']),true,8,JSON_THROW_ON_ERROR);}
            catch(DecryptException|\JsonException){abort(422,'Invalid cursor.');}
            abort_unless(is_array($cursor) && ($cursor['device']??null)===$s->device_id && ($cursor['user']??null)===$s->user_id
                && is_string($cursor['id']??null) && \Illuminate\Support\Str::isUuid($cursor['id'])
                && is_int($cursor['expires']??null) && $cursor['expires']>=now()->timestamp,422,'Invalid cursor.');
            $query->where('id','>',$cursor['id']);
        }
        $limit=(int)($p['limit']??20);
        $items=$query->orderBy('id')->limit($limit+1)->get(['id','envelope_id','status','reason_code','updated_at']);
        $more=$items->count()>$limit;
        $items=$items->take($limit)->values();
        $next=$more?Crypt::encryptString(json_encode(['device'=>$s->device_id,'user'=>$s->user_id,
            'id'=>$items->last()->id,'expires'=>now()->addMinutes(15)->timestamp],JSON_THROW_ON_ERROR)):null;
        return $this->result(['items'=>$items->map(fn($item)=>[
            'envelope_id'=>$item->envelope_id,'status'=>$item->status,'reason_code'=>$item->reason_code,
            'updated_at'=>$item->updated_at,
        ])->all(),'next_cursor'=>$next,'policy_revision'=>$this->policy->settings()['revision']]);
    }
}
