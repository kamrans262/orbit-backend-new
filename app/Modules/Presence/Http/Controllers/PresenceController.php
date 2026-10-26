<?php

namespace App\Modules\Presence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Presence\Services\PresenceService;
use App\Models\OrbitDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PresenceController extends Controller
{
    private function session(Request $r, PresenceService $presence): object
    {
        $session=$r->attributes->get('orbit_session');
        abort_unless($session,401);
        $presence->ensureAvailable((string)$session->user_id);
        // Middleware already checks trusted devices. Recheck revocation.
        abort_unless(OrbitDevice::query()->whereKey($session->device_id)
            ->where('user_id',$session->user_id)->where('trust_state','trusted')
            ->whereNull('revoked_at')->exists(),403);
        return $session;
    }

    private function result(array $data): JsonResponse
    {
        return response()->json(['data'=>$data])
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization');
    }

    public function heartbeat(Request $r, PresenceService $presence): JsonResponse
    {
        $s=$this->session($r,$presence);
        $d=$r->validate(['state'=>['required',Rule::in(['active','idle'])]]);
        return $this->result($presence->heartbeat((string)$s->user_id,(string)$s->device_id,$d['state']));
    }

    public function disconnect(Request $r, PresenceService $presence): JsonResponse
    {
        $s=$this->session($r,$presence);
        return $this->result($presence->disconnect((string)$s->user_id,(string)$s->device_id));
    }

    public function settings(Request $r, PresenceService $presence): JsonResponse
    {
        $s=$this->session($r,$presence);
        return $this->result($presence->preferences((string)$s->user_id));
    }

    public function circleSettings(Request $r, PresenceService $presence,string $circle): JsonResponse
    {
        $s=$this->session($r,$presence);
        $d=$r->validate([
            'enabled'=>['required','boolean'],
            'duration'=>['required_if:enabled,true','nullable',Rule::in(['persistent','until_expiry'])],
            'expires_at'=>['nullable','date'],
        ]);
        return $this->result($presence->setCircleSharing((string)$s->user_id,
            $circle,(bool)$d['enabled'],$d['duration']??null,$d['expires_at']??null));
    }

    /** M13-compatible typing event, gated by both Presence and message consent. */
    public function typing(Request $r,
        \App\Modules\Presence\Services\PresenceTypingService $typing,
        PresenceService $presence,string $circle): JsonResponse
    {
        $session=$this->session($r,$presence);
        $data=$r->validate(['typing'=>['required','boolean']]);
        return $this->result($typing->update((string)$session->user_id,$circle,(bool)$data['typing']));
    }

    public function circle(Request $r, PresenceService $presence,string $circle): JsonResponse
    {
        $s=$this->session($r,$presence);
        return $this->result($presence->circleSnapshot((string)$s->user_id,$circle));
    }
}
