<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Moderation\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ModerationController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    private function reply(array $data,int $code=200):JsonResponse
    {
        return response()->json(['data'=>$data],$code)
            ->header('Cache-Control','private, no-store');
    }

    public function capabilities(Request $r):JsonResponse
    {
        $this->moderation->actor($r);
        return $this->reply([
            'report_intake_available'=>config('orbit_moderation.intake_enabled')===true,
            'report_receipts_available'=>true,'appeals_available'=>true,
            'automated_content_access'=>false,'emergency_dispatch'=>false,
        ]);
    }

    public function submit(Request $r):JsonResponse
    {
        $actor=$this->moderation->actor($r);
        abort_unless(array_diff(array_keys($r->all()),[
            'client_id','target_kind','target_id','reason','additional_details',
        ])===[],422,'Unexpected report fields.');
        $data=$r->validate([
            'client_id'=>['required','uuid'],
            'target_kind'=>['required',Rule::in(['account'])],
            'target_id'=>['required','uuid'],
            'reason'=>['required',Rule::in([
                'inappropriate_content','harassment','spam','other',
            ])],
            'additional_details'=>['sometimes','nullable','string','max:1500'],
        ]);
        [$receipt,$created]=$this->moderation->submit($actor,$data);
        return $this->reply($receipt,$created?201:200);
    }

    public function reports(Request $r):JsonResponse
    {
        return $this->reply($this->moderation->ownedReports(
            $this->moderation->actor($r)));
    }

    public function report(Request $r,string $report):JsonResponse
    {
        return $this->reply($this->moderation->ownedReport(
            $this->moderation->actor($r),$report));
    }

    public function actions(Request $r):JsonResponse
    {
        return $this->reply($this->moderation->myActions(
            $this->moderation->actor($r)));
    }

    public function appeal(Request $r,string $action):JsonResponse
    {
        $actor=$this->moderation->actor($r);
        abort_unless(array_diff(array_keys($r->all()),[
            'client_id','statement',
        ])===[],422,'Unexpected appeal fields.');
        $data=$r->validate([
            'client_id'=>['required','uuid'],
            'statement'=>['sometimes','nullable','string','max:1500'],
        ]);
        [$receipt,$created]=$this->moderation->appeal($actor,$action,$data);
        return $this->reply($receipt,$created?201:200);
    }

    public function appeals(Request $r):JsonResponse
    {
        return $this->reply($this->moderation->myAppeals(
            $this->moderation->actor($r)));
    }
}
