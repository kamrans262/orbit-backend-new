<?php

namespace App\Modules\CheckIn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CheckIn\Services\CheckInAccessPolicy;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleConsentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class CheckInCircleConsentController extends Controller
{
    public function __construct(
        private readonly CheckInAccessPolicy $devices,
        private readonly CircleAccessPolicy $circles,
        private readonly CircleConsentPolicy $consent
    ) {}

    private function owner(Request $r,string $circle): string
    {
        $session = $r->attributes->get('orbit_session');
        abort_unless($session,401,'Unauthenticated.');
        $user = (string) $session->user_id;
        $this->devices->assertSession($user,(string)$session->device_id);
        abort_unless($this->circles->isMember($circle,$user),404,'Circle unavailable.');
        $key='checkin:consent:'.hash('sha256',$user.'|'.$circle);
        abort_if(RateLimiter::tooManyAttempts($key,30),429,'Too many permission requests.');
        RateLimiter::hit($key,60);
        return $user;
    }

    private function result(string $user,string $circle): JsonResponse
    {
        $row=DB::table('circle_permissions')->where('circle_id',$circle)
            ->where('scope_key','user:'.$user)->where('subject_user_id',$user)
            ->where('permission_key','check_in')->first();
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)->value('privacy');
        $privacy=$raw ? json_decode((string)$raw,true) : null;
        $active=$row && $this->consent->consentCurrentlyValid($row,'check_in')
            && is_array($privacy) && ($privacy['activity_visibility']??'Nobody')==='My Circle'
            && config('orbit_check_in.enabled')===true
            && config('orbit_circle.consumer_enabled.check_in')===true;
        return response()->json(['data'=>[
            'circle_id'=>$circle,'enabled'=>(bool)$active,
            'service_available'=>config('orbit_check_in.enabled')===true
                && config('orbit_circle.consumer_enabled.check_in')===true,
        ]])->header('Cache-Control','private, no-store');
    }

    public function show(Request $r,string $circle): JsonResponse
    {
        return $this->result($this->owner($r,$circle),$circle);
    }

    public function update(Request $r,string $circle): JsonResponse
    {
        $user=$this->owner($r,$circle);
        $input=$r->validate(['enabled'=>['required','boolean'],
            'confirm'=>['required_if:enabled,true','boolean']]);
        if (!$input['enabled']) {
            $this->consent->revoke($circle,$user,'check_in');
            return $this->result($user,$circle);
        }
        abort_unless(($input['confirm']??false)===true,422,'Explicit consent required.');
        abort_unless(config('orbit_check_in.enabled')===true
            && config('orbit_circle.consumer_enabled.check_in')===true,
            503,'Circle Check-In is not enabled.');
        $raw=DB::table('orbit_user_preferences')->where('user_id',$user)->value('privacy');
        $privacy=$raw?json_decode((string)$raw,true):null;
        abort_unless(is_array($privacy) && ($privacy['activity_visibility']??'Nobody')==='My Circle',
            422,'Allow Circle activity in Privacy Settings first.');
        DB::table('circle_permissions')->updateOrInsert([
            'circle_id'=>$circle,'scope_key'=>'user:'.$user,'permission_key'=>'check_in',
        ],[
            'subject_user_id'=>$user,'enabled'=>true,
            'value_json'=>json_encode(['reviewed'=>true,'audience'=>'circle',
                'duration'=>'persistent']),
            'expires_at'=>null,'updated_at'=>now(),
        ]);
        return $this->result($user,$circle);
    }
}
