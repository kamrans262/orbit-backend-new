<?php
namespace App\Modules\Moments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class MomentPushDeviceController extends Controller
{
    private function device(Request $request):array
    {
        abort_unless(config('orbit_moments.enabled')===true,404);
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        $user=(string)$session->user_id;$device=(string)$session->device_id;
        $trusted=DB::table('devices')->where('id',$device)
            ->where('user_id',$user)->where('trust_state','trusted')
            ->whereNotNull('approved_at')->whereNull('revoked_at')->exists();
        abort_unless($trusted,403,'Trusted device required.');
        return [$user,$device];
    }
    private function optedIn(string $user):bool
    {
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)
            ->value('notifications');
        $pref=is_string($raw)?json_decode($raw,true):null;
        return is_array($pref)
            && ($pref['push_notifications_enabled']??false)===true
            && ($pref['moments_notifications_enabled']??false)===true;
    }

    public function bootstrap(Request $request):JsonResponse
    {
        [$user]=$this->device($request);
        $fcm=config('orbit_moments.fcm',[]);
        $ready=$this->optedIn($user)
            && app(\App\Modules\Moments\Services\MomentFcmTransport::class)->enabled();
        return response()->json(['data'=>$ready?[
            'enabled'=>true,
            'options'=>[
                'project_id'=>$fcm['project_id'],
                'api_key'=>$fcm['api_key'],
                'sender_id'=>$fcm['sender_id'],
                'android_app_id'=>$fcm['android_app_id'],
                'ios_app_id'=>$fcm['ios_app_id'],
                'ios_bundle_id'=>$fcm['ios_bundle_id'],
            ],
        ]:['enabled'=>false]])->header('Cache-Control','private, no-store');
    }

    public function register(Request $request):JsonResponse
    {
        [$user,$device]=$this->device($request);
        // Never collect a push token until Firebase exists and the user
        // explicitly enabled BOTH platform push and Moment notifications.
        abort_unless(app(\App\Modules\Moments\Services\MomentFcmTransport::class)->enabled(),404);
        abort_unless($this->optedIn($user),403,'Push consent required.');
        $d=$request->validate([
            'token'=>['required','string','min:20','max:4096',
                'regex:/^[A-Za-z0-9_:~.\\-]+$/D'],
            'platform'=>['required','in:android,ios'],
        ]);
        $hash=hash('sha256',$d['token']);
        DB::transaction(function()use($user,$device,$d,$hash):void{
            abort_if(DB::table('moment_push_devices')
                ->where('token_digest',$hash)->where('device_id','!=',$device)
                ->exists(),409,'Push token already registered.');
            DB::table('moment_push_devices')->updateOrInsert(
                ['device_id'=>$device],
                ['user_id'=>$user,'token_ciphertext'=>Crypt::encryptString($d['token']),
                 'token_digest'=>$hash,'platform'=>$d['platform'],
                 'disabled_at'=>null,'created_at'=>now(),'updated_at'=>now()]);
        });
        // Never echo an FCM token in logs or response.
        return response()->json(['data'=>['registered'=>true]])
            ->header('Cache-Control','private, no-store');
    }

    public function unregister(Request $request):\Illuminate\Http\Response
    {
        [$user,$device]=$this->device($request);
        DB::table('moment_push_devices')->where('user_id',$user)
            ->where('device_id',$device)->delete();
        return response()->noContent();
    }
}
