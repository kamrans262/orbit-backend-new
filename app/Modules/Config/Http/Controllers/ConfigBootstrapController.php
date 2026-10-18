<?php

namespace App\Modules\Config\Http\Controllers;

use App\Modules\Config\Services\ConfigRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ConfigBootstrapController extends Controller
{
    public function __invoke(Request $request, ConfigRegistry $config): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'in:ios,android,web'],
            'app_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'country' => ['nullable', 'regex:/^[A-Z]{2}$/D'],
            'cohort' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'subject' => ['nullable', 'uuid'],
        ]);
        $payload = $config->bootstrap($data);
        return response()->json(['data' => $payload])->header('Cache-Control', 'no-store');
    }
}
