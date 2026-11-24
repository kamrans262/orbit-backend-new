<?php
namespace App\Modules\Identity\Http\Controllers;
use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Modules\Config\Services\OperationalAudit;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AdminAccountAccessController{
    public function revoke(Request $r,OrbitUser $user,TokenService $tokens,OperationalAudit $audit){
        $d=$r->validate(['scope'=>'required|in:device,sessions','device_id'=>'nullable|required_if:scope,device|uuid',
            'reason'=>'required|string|min:10|max:500','confirm'=>'accepted']);
        abort_if($d['scope']==='sessions'&&!empty($d['device_id']),422);
        $actor=$r->user('admin');
        abort_unless($actor?->canPerform('users.read')&&$actor->canPerform('users.manage'),403);
        DB::transaction(function()use($d,$user,$actor,$tokens,$audit){
            $target=OrbitUser::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if($d['scope']==='device'){
                $device=OrbitDevice::where('user_id',$target->id)->whereKey($d['device_id'])->lockForUpdate()->firstOrFail();
                if($device->revoked_at)return;
                $tokens->revokeDevice($device,'admin_security_revoke');
                $audit->record($actor,'users.device.revoke','device:'.$device->id,$d['reason'],['revoked'=>false],['revoked'=>true]);
            }else{
                $count=OrbitSession::where('user_id',$target->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
                if(!$count)return;
                $tokens->event($target->id,null,'session.revoked','admin_security_revoke');
                $audit->record($actor,'users.sessions.revoke','user:'.$target->id,$d['reason'],['active'=>$count],['revoked'=>$count]);
            }
        },3);
        return redirect()->route('orbit.admin.users.show',$user)->with('status','Account access revocation recorded.');
    }
}
