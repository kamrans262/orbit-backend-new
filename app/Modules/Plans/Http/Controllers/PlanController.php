<?php

namespace App\Modules\Plans\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Services\PlanPolicy;
use App\Modules\Plans\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans,
        private readonly PlanPolicy $policy) {}

    private function json(array $payload,int $status=200): JsonResponse
    {
        return response()->json($payload,$status)->header('Cache-Control','private, no-store');
    }

    private function planFields(bool $partial=false): array
    {
        $required=$partial?'sometimes':'required';
        return [
            'title'=>[$required,'string','min:1','max:120'],
            'description'=>['sometimes','nullable','string','max:4000'],
            'starts_at'=>[$required,'date'],
            'ends_at'=>['sometimes','nullable','date'],
            'place_label'=>['sometimes','nullable','string','max:160'],
            'place_ref'=>['sometimes','nullable','string','max:255'],
            'place_lat'=>['sometimes','nullable','numeric','between:-90,90','required_with:place_lng'],
            'place_lng'=>['sometimes','nullable','numeric','between:-180,180','required_with:place_lat'],
            'invitee_ids'=>['sometimes','array','max:100'],
            'invitee_ids.*'=>['uuid','distinct'],
            'circle_ids'=>['sometimes','array','max:5'],
            'circle_ids.*'=>['uuid','distinct'],
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $args=$r->validate([
            'tab'=>['sometimes',Rule::in(['upcoming','past'])],
            'limit'=>['sometimes','integer','between:1,30'],
            'cursor'=>['sometimes','string','max:180'],
        ]);
        $result=$this->plans->listing($actor,$args['tab']??'upcoming',
            (int)($args['limit']??20),$args['cursor']??null);
        return $this->json(['data'=>$result['items'],'meta'=>[
            'next_cursor'=>$result['next_cursor'],
        ]]);
    }

    public function store(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $input=$r->validate(array_merge($this->planFields(),[
            'client_id'=>['required','uuid'],
        ]));
        [$plan,$created]=$this->plans->create($actor,$input);
        return $this->json(['data'=>$plan],$created?201:200);
    }

    public function show(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $record=$this->policy->requirePlan($plan,$actor);
        return $this->json(['data'=>$this->plans->present($record,$actor,true)]);
    }

    public function update(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $input=$r->validate(array_merge($this->planFields(true),[
            'revision'=>['required','integer','min:1'],
        ]));
        return $this->json(['data'=>$this->plans->update($plan,$actor,$input)]);
    }

    public function cancel(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $input=$r->validate(['revision'=>['required','integer','min:1']]);
        return $this->json(['data'=>$this->plans->cancel($plan,$actor,(int)$input['revision'])]);
    }

    public function rsvp(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $input=$r->validate(['state'=>['required',Rule::in(['joined','declined'])]]);
        return $this->json(['data'=>$this->plans->respond($plan,$actor,$input['state'])]);
    }

    public function leave(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        return $this->json(['data'=>$this->plans->respond($plan,$actor,'left')]);
    }

    public function participants(Request $r,string $plan): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $record=$this->policy->requirePlan($plan,$actor);
        return $this->json(['data'=>$this->plans->participants($record,$actor)]);
    }

    public function preferences(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        return $this->json(['data'=>$this->plans->preferences($actor)]);
    }

    public function savePreferences(Request $r): JsonResponse
    {
        $actor=$this->policy->actor($r);
        $input=$r->validate([
            'enabled'=>['required','boolean'],
            'offsets_minutes'=>['required','array','max:4'],
            'offsets_minutes.*'=>['required','integer',
                Rule::in(config('orbit_plans.allowed_reminder_offsets',[0,15,60,1440]))],
        ]);
        return $this->json(['data'=>$this->plans->savePreferences(
            $actor,(bool)$input['enabled'],$input['offsets_minutes'])]);
    }
}
