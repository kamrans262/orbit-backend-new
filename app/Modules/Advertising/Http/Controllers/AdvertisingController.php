<?php

namespace App\Modules\Advertising\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Advertising\Services\AdvertisingDelivery;
use App\Modules\Advertising\Services\AdvertisingPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AdvertisingController extends Controller
{
    public function __construct(
        private readonly AdvertisingPolicy $policy,
        private readonly AdvertisingDelivery $delivery
    ) {}

    private function user(Request $r): string
    {
        $session=$r->attributes->get('orbit_session');
        abort_unless($session,401,'Unauthenticated.');
        $user=(string)$session->user_id;
        $this->policy->trusted($user,(string)$session->device_id);
        return $user;
    }

    private function reply(?array $data): JsonResponse
    {
        return response()->json(['data'=>$data])
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')->header('Vary','Authorization');
    }

    public function capabilities(Request $r): JsonResponse
    {
        $this->user($r);
        $slots=[];
        foreach (config('orbit_advertising.slots',[]) as $key)
            $slots[$key]=$this->policy->placement($key)!==null;
        return $this->reply([
            'available'=>$this->policy->available(),
            'placements'=>$slots,
            'measurement_mode'=>'signed_receipt',
            'schema_version'=>1,
        ]);
    }

    public function decide(Request $r): JsonResponse
    {
        $user=$this->user($r);
        $input=$r->validate([
            'placement'=>['required',Rule::in(config('orbit_advertising.slots',[]))],
            'request_id'=>['required','uuid'],
        ]);
        return $this->reply($this->delivery->decision($user,
            $input['placement'],$input['request_id']));
    }

    public function measure(Request $r): JsonResponse
    {
        $user=$this->user($r);
        $input=$r->validate([
            'decision_id'=>['required','uuid'],
            'event_type'=>['required',Rule::in(['impression','click'])],
            'client_event_id'=>['required','uuid'],
            'measurement_token'=>['required','string','max:400'],
            'evidence'=>['sometimes','array:visible_duration_ms'],
            'evidence.visible_duration_ms'=>['nullable','integer','min:0','max:300000'],
        ]);
        return $this->reply($this->delivery->record($user,$input['decision_id'],
            $input['event_type'],$input['client_event_id'],$input['measurement_token'],
            $input['evidence']['visible_duration_ms']??null));
    }

    public function asset(Request $r, string $creative)
    {
        $user=$this->user($r);
        abort_unless($r->hasValidSignature(),403,'Asset signature invalid.');
        $input=$r->validate(['decision'=>['required','uuid']]);
        $record=$this->delivery->authorizeAsset($user,$creative,$input['decision']);
        abort_unless(Storage::disk('local')->exists($record->private_path),
            404,'Asset unavailable.');
        $response=response()->file(Storage::disk('local')->path($record->private_path),[
            'Content-Type'=>$record->mime,
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; sandbox",
            'Referrer-Policy'=>'no-referrer',
        ]);
        // Symfony's BinaryFileResponse sets public by default; override after
        // construction to avoid accidentally caching user-scoped signed images.
        $response->setPrivate();
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
}
