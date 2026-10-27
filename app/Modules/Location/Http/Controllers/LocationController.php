<?php

namespace App\Modules\Location\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OrbitDevice;
use App\Modules\Location\Services\LocationService;
use App\Modules\Location\Services\LocationRealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocationController extends Controller
{
    private function who(Request $r): array
    {
        $s=$r->attributes->get('orbit_session');
        abort_unless($s && $s->revoked_at===null && $s->expires_at>now(),401);
        abort_unless(OrbitDevice::query()->whereKey($s->device_id)
            ->where('user_id',$s->user_id)->where('trust_state','trusted')
            ->whereNull('revoked_at')->whereNotNull('public_key')->exists(),
            403,'Trusted device required.');
        return [(string)$s->user_id,(string)$s->device_id];
    }

    private function result(array $value,int $status=200): JsonResponse
    {
        return response()->json(['data'=>$value],$status)
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization')
            ->header('X-Content-Type-Options','nosniff');
    }

    public function capabilities(Request $r,LocationService $location): JsonResponse
    {
        [$user]=$this->who($r);
        return $this->result($location->capabilities($user));
    }

    public function shares(Request $r,LocationService $location): JsonResponse
    {
        [$user]=$this->who($r);
        return $this->result(['shares'=>$location->shares($user)]);
    }

    public function createShare(Request $r,LocationService $location,LocationRealtimePublisher $realtime): JsonResponse
    {
        [$user]=$this->who($r);
        $d=$r->validate([
            'audience_type'=>['required',Rule::in(['person','circle'])],
            'audience_id'=>['required','uuid'],
            'precision'=>['required',Rule::in(['approximate','precise'])],
            'duration'=>['required',Rule::in(['one_hour','until_tonight','manual','always','until_leaving'])],
            'timezone'=>['required_if:duration,until_tonight','nullable','timezone'],
            'exit_radius_m'=>['required_if:duration,until_leaving','nullable','integer','between:150,1000'],
            'reviewed'=>['required','accepted'],
        ]);
        $value=$location->create($user,$d);
        $realtime->invalidate($user);
        return $this->result($value,201);
    }

    /** Link separately reviewed M6 avatar consent to an existing person share. */
    public function attachAvatar(Request $r,
        \App\Modules\Location\Services\LocationAvatarGrantService $avatars,
        string $share): JsonResponse
    {
        [$owner]=$this->who($r);
        $d=$r->validate(['media_id'=>['required','uuid'],
            'grant_id'=>['required','uuid']]);
        return $this->result($avatars->attach($owner,$share,
            $d['media_id'],$d['grant_id']));
    }

    public function revokeShare(Request $r,LocationService $location,LocationRealtimePublisher $realtime,string $share): JsonResponse
    {
        [$user]=$this->who($r);
        $value=$location->revoke($user,$share);
        $realtime->invalidate($user);
        return $this->result($value);
    }

    public function stopAll(Request $r,LocationService $location,LocationRealtimePublisher $realtime): JsonResponse
    {
        [$user]=$this->who($r);
        $value=$location->revoke($user);
        $realtime->invalidate($user);
        return $this->result($value);
    }

    public function sample(Request $r,LocationService $location,LocationRealtimePublisher $realtime): JsonResponse
    {
        [$user,$device]=$this->who($r);
        $d=$r->validate([
            'latitude'=>['required','numeric','between:-90,90'],
            'longitude'=>['required','numeric','between:-180,180'],
            'accuracy_m'=>['required','numeric','between:0,10000'],
            'observed_at'=>['required','date'],
            'heading'=>['sometimes','nullable','numeric','between:0,360'],
            'speed_mps'=>['sometimes','nullable','numeric','between:0,100'],
        ]);
        $value=$location->ingest($user,$device,$d);
        $realtime->invalidate($user);
        return $this->result($value,202);
    }

    public function map(Request $r,LocationService $location): JsonResponse
    {
        [$user,$device]=$this->who($r);
        $d=$r->validate(['circle_id'=>['sometimes','nullable','uuid']]);
        return $this->result($location->map($user,$d['circle_id']??null,$device));
    }
}
