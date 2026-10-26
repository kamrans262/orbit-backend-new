<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardSummaryController extends Controller
{
    private function viewer(Request $request, DashboardSummaryService $dashboard): string
    {
        $session=$request->attributes->get('orbit_session');
        abort_unless($session,401);
        $dashboard->authorize((string)$session->user_id,(string)$session->device_id);
        return (string)$session->user_id;
    }

    private function reply(array $payload): JsonResponse
    {
        return response()->json(['data'=>$payload])
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('Pragma','no-cache')
            ->header('Vary','Authorization');
    }

    public function summary(Request $request,DashboardSummaryService $dashboard): JsonResponse
    {
        $viewer=$this->viewer($request,$dashboard);
        return $this->reply($dashboard->summary($viewer));
    }

    public function dismiss(Request $request,DashboardSummaryService $dashboard,string $key): JsonResponse
    {
        $viewer=$this->viewer($request,$dashboard);
        return $this->reply($dashboard->dismiss($viewer,$key));
    }

    public function preferences(Request $request,DashboardSummaryService $dashboard): JsonResponse
    {
        $viewer=$this->viewer($request,$dashboard);
        $data=$request->validate([
            'circle_order'=>['required','array','max:20'],
            'circle_order.*'=>['required','uuid','distinct'],
        ]);
        return $this->reply($dashboard->orderCircles($viewer,$data['circle_order']));
    }
}
