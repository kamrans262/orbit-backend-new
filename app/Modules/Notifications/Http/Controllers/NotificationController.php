<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Notifications\Services\NotificationPushTransport;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationPolicy $policy,
                                private readonly NotificationService $notifications,
                                private readonly NotificationPushTransport $transport) {}

    private function response(array $data): JsonResponse
    {
        return response()->json(['data'=>$data])->header('Cache-Control','private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        [$user]=$this->policy->actor($request);
        $data=$request->validate([
            'limit'=>['sometimes','integer','between:1,30'],
            'cursor'=>['nullable','string','max:160'],
            'category'=>['nullable','string',Rule::in(config('orbit_notifications.categories',[]))],
        ]);
        return $this->response($this->notifications->page($user,
            (int)($data['limit']??20),$data['cursor']??null,$data['category']??null));
    }

    public function unread(Request $request): JsonResponse
    {
        [$user]=$this->policy->actor($request);
        return $this->response([
            'count'=>$this->notifications->unread($user),
            'updated_at'=>now()->toIso8601String(),
        ]);
    }

    public function read(Request $request,string $notice): \Illuminate\Http\Response
    {
        [$user]=$this->policy->actor($request);
        $this->notifications->markRead($user,$notice);
        return response()->noContent()->header('Cache-Control','private, no-store');
    }

    public function readAll(Request $request): JsonResponse
    {
        [$user]=$this->policy->actor($request);
        $data=$request->validate([
            'category'=>['nullable','string',Rule::in(config('orbit_notifications.categories',[]))],
        ]);
        return $this->response([
            'updated_count'=>$this->notifications->readAll($user,$data['category']??null),
        ]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        [$user]=$this->policy->actor($request);
        $allowed=$this->transport->enabled()
            && $this->policy->enabled('channel.push')
            && $this->policy->preference($user,'push_notifications_enabled');
        return $this->response($allowed?[
            'enabled'=>true,'options'=>$this->transport->options(),
        ]:[
            'enabled'=>false,'reason_code'=>!$this->transport->enabled()
                ?'provider_unavailable':(!$this->policy->enabled('channel.push')
                    ?'channel_paused':'user_opt_out'),
        ]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        [$user,$device]=$this->policy->actor($request);
        abort_unless($this->transport->enabled()
            && $this->policy->enabled('channel.push'),503,'Push provider unavailable.');
        abort_unless($this->policy->preference($user,'push_notifications_enabled'),403,
            'Push consent required.');
        $data=$request->validate([
            'platform'=>['required',Rule::in(['ios','android'])],
            'token'=>['required','string','min:20','max:4096',
                'regex:/^[A-Za-z0-9_:~.\-]+$/D'],
        ]);
        $project=(string)config('orbit_moments.fcm.project_id','');
        abort_unless($project!=='',503,'Firebase project unavailable.');
        $digest=hash('sha256',$data['token']);
        DB::transaction(function()use($user,$device,$data,$project,$digest):void{
            abort_if(DB::table('notification_device_tokens')->where('token_digest',$digest)
                ->where('device_id','!=',$device)->exists(),409,'Push token already registered.');
            if(Schema::hasTable('moment_push_devices')) {
                abort_if(DB::table('moment_push_devices')->where('token_digest',$digest)
                    ->where('device_id','!=',$device)->exists(),409,'Push token registered elsewhere.');
            }
            $existing=DB::table('notification_device_tokens')->where('device_id',$device)->exists();
            $attributes=[
                'user_id'=>$user,'platform'=>$data['platform'],'project_id'=>$project,
                'token_digest'=>$digest,'token_ciphertext'=>Crypt::encryptString($data['token']),
                'disabled_at'=>null,'updated_at'=>now(),
            ];
            if($existing){
                DB::table('notification_device_tokens')->where('device_id',$device)->update($attributes);
            }else{
                DB::table('notification_device_tokens')->insert(
                    array_merge(['device_id'=>$device,'created_at'=>now()],$attributes));
            }
        },3);
        return $this->response(['registered'=>true]);
    }

    public function unregisterDevice(Request $request): \Illuminate\Http\Response
    {
        [$user,$device]=$this->policy->actor($request);
        DB::table('notification_device_tokens')->where('device_id',$device)
            ->where('user_id',$user)->delete();
        return response()->noContent()->header('Cache-Control','private, no-store');
    }
}
