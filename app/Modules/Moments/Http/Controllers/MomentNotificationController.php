<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Moments\Services\MomentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MomentNotificationController extends Controller
{
    private function actor(Request $r):string
    {
        abort_unless(config('orbit_moments.enabled')===true
            && config('orbit_media.enabled')===true,404);
        $session=$r->attributes->get('orbit_session');
        abort_unless($session,401);
        return (string)$session->user_id;
    }

    private function optedIn(string $user):bool
    {
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)
            ->value('notifications');
        $prefs=is_string($raw)?json_decode($raw,true):null;
        return is_array($prefs)
            && ($prefs['moments_notifications_enabled']??false)===true;
    }

    public function index(Request $r,MomentPolicy $policy):JsonResponse
    {
        $viewer=$this->actor($r);
        if(!$this->optedIn($viewer)){
            return response()->json(['data'=>['items'=>[]]])
                ->header('Cache-Control','private, no-store');
        }
        $rows=DB::table('moment_notification_inbox')
            ->where('recipient_user_id',$viewer)->orderByDesc('created_at')
            ->limit(60)->get();
        $out=[];
        foreach($rows as $row){
            $moment=DB::table('moments')->where('id',$row->moment_id)->first();
            if(!$moment || $policy->context($moment,$viewer,$row->context_id)
                !==$row->context_id)continue;
            $out[]=['id'=>$row->id,'moment_id'=>$row->moment_id,
                'context_id'=>$row->context_id,'created_at'=>$row->created_at,
                'read'=>$row->read_at!==null];
            if(count($out)>=20)break;
        }
        return response()->json(['data'=>['items'=>$out]])
            ->header('Cache-Control','private, no-store');
    }

    public function markRead(Request $r,string $notice,MomentPolicy $policy):\Illuminate\Http\Response
    {
        $viewer=$this->actor($r);
        abort_unless($this->optedIn($viewer),404);
        $row=DB::table('moment_notification_inbox')->where('id',$notice)
            ->where('recipient_user_id',$viewer)->first();
        abort_unless($row,404);
        $moment=DB::table('moments')->where('id',$row->moment_id)->first();
        abort_unless($moment && $policy->context($moment,$viewer,
            $row->context_id)===$row->context_id,404);
        DB::table('moment_notification_inbox')->where('id',$notice)
            ->where('recipient_user_id',$viewer)->update(['read_at'=>now()]);
        return response()->noContent();
    }
}
