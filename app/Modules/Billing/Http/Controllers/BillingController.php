<?php
namespace App\Modules\Billing\Http\Controllers;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Messaging\Services\MessagingAccessPolicy;
use Illuminate\Http\Request;
final class BillingController{
    public function __construct(private readonly BillingService $billing,private readonly MessagingAccessPolicy $access){}
    private function owner(Request $r):string{
        $s=$r->attributes->get('orbit_session');abort_unless($s,401);$this->access->assertSession($s->user_id,$s->device_id);return $s->user_id;
    }
    private function result(array $d){return response()->json(['data'=>$d])->header('Cache-Control','no-store');}
    public function catalog(Request $r){$this->owner($r);$p=$r->validate(['provider'=>'required|in:apple,google']);return $this->result($this->billing->catalog($p['provider']));}
    public function entitlement(Request $r){return $this->result($this->billing->entitlement($this->owner($r)));}
    public function context(Request $r){
        $owner=$this->owner($r);abort_if(strlen($r->getContent())>4096,413);
        abort_if(array_diff(array_keys($r->all()),['provider','product_mapping_id','client_request_id']),422);
        $p=$r->validate(['provider'=>'required|in:apple,google','product_mapping_id'=>'required|uuid','client_request_id'=>'required|uuid']);
        return $this->result($this->billing->context($owner,$p));
    }
    public function verify(Request $r){
        $owner=$this->owner($r);abort_if(strlen($r->getContent())>70000,413);
        abort_if(array_diff(array_keys($r->all()),['provider','mode','context_id','proof','client_request_id']),422);
        $p=$r->validate(['provider'=>'required|in:apple,google','mode'=>'required|in:purchase,restore','context_id'=>'nullable|required_if:mode,purchase|uuid','proof'=>'required|string|max:65536','client_request_id'=>'required|uuid']);
        return $this->result($this->billing->verify($owner,$p));
    }
    public function apple(Request $r){abort_if(strlen($r->getContent())>70000,413);$this->billing->notice('apple',$r->all(),null);return response()->noContent();}
    public function google(Request $r){abort_if(strlen($r->getContent())>70000,413);$this->billing->notice('google',$r->all(),$r->bearerToken());return response()->noContent();}
}
