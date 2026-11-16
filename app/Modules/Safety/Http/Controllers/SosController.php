<?php

namespace App\Modules\Safety\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Safety\Services\SosPolicy;
use App\Modules\Safety\Services\SosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SosController extends Controller
{
    public function __construct(private readonly SosPolicy $policy,
        private readonly SosService $sos) {}

    private function reply(array $payload,int $status=200): JsonResponse
    {
        return response()->json($payload,$status)->header('Cache-Control','private, no-store');
    }

    public function capabilities(Request $r): JsonResponse
    {
        $this->policy->actor($r);
        return $this->reply(['data'=>[
            'sos_enabled'=>true,'free_for_all_accounts'=>true,
            'server_intake_available'=>true,
            'in_app_responder_polling_available'=>true,
            'push_provider_ready'=>app(\App\Modules\Notifications\Services\NotificationPushTransport::class)->enabled(),
            'recording_backend_available'=>false,
            'external_emergency_services_connected'=>false,
            'requires_network_for_remote_alerts'=>true,
            'offline_relay_available'=>false,
        ]]);
    }

    public function index(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate(['limit'=>['sometimes','integer','between:1,40']]);
        return $this->reply(['data'=>$this->sos->listing($actor,(int)($data['limit']??20))]);
    }

    public function store(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate([
            'client_id'=>['required','uuid'],
            'confirm_sos'=>['required','accepted'],
            'circle_id'=>['sometimes','nullable','uuid'],
            'share_location'=>['required','boolean'],
            'latitude'=>['sometimes','numeric','between:-90,90'],
            'longitude'=>['sometimes','numeric','between:-180,180'],
            'location_captured_at'=>['sometimes','date'],
            'recording_consent'=>['sometimes','boolean'],
        ]);
        [$incident,$created]=$this->sos->create($actor,$data);
        return $this->reply(['data'=>$incident],$created?201:200);
    }

    public function show(Request $r,string $incident): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $row=$this->policy->requireIncident($incident,$actor);
        return $this->reply(['data'=>$this->sos->present($row,$actor)]);
    }

    public function location(Request $r,string $incident): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $row=$this->policy->requireIncident($incident,$actor);
        return $this->reply(['data'=>$this->sos->location($row,$actor)]);
    }

    public function acknowledge(Request $r,string $incident): JsonResponse
    {
        $actor=$this->policy->actor($r);
        return $this->reply(['data'=>$this->sos->acknowledge($incident,$actor)]);
    }

    public function cancel(Request $r,string $incident): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate(['revision'=>['required','integer','min:1']]);
        return $this->reply(['data'=>$this->sos->close($incident,$actor,'cancelled',(int)$data['revision'])]);
    }

    public function resolve(Request $r,string $incident): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate(['revision'=>['required','integer','min:1']]);
        return $this->reply(['data'=>$this->sos->close($incident,$actor,'resolved',(int)$data['revision'])]);
    }

    public function contacts(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        return $this->reply(['data'=>$this->sos->contacts($actor)]);
    }

    public function requestContact(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate(['recipient_user_id'=>['required','uuid']]);
        return $this->reply(['data'=>$this->sos->requestContact($actor,$data['recipient_user_id'])]);
    }

    public function decideContact(Request $r,string $contact): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $data=$r->validate(['state'=>['required',Rule::in(['accepted','declined'])]]);
        return $this->reply(['data'=>$this->sos->decideContact($contact,$actor,$data['state'])]);
    }

    public function removeContact(Request $r,string $contact): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $this->sos->removeContact($contact,$actor);
        return $this->reply(['data'=>['removed'=>true]]);
    }
}
