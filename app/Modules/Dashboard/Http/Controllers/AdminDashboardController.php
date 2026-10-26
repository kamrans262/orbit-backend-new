<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class AdminDashboardController extends Controller
{
    /** Feature health only; never expose viewer data, media, keys or location. */
    public function index(): Response
    {
        abort_unless(config('orbit_dashboard.enabled') === true, 404);
        return response()->view('orbit-admin.home-dashboard', [
            'limits'=>[
                'Circles'=>config('orbit_dashboard.circle_limit'),
                'People'=>config('orbit_dashboard.people_limit'),
                'Moments'=>config('orbit_dashboard.moment_limit'),
            ],
            'sections'=>[
                'Circle summaries'=>config('orbit_circle.enabled') === true,
                'Moment references'=>config('orbit_moments.enabled')===true
                    && config('orbit_media.enabled')===true,
                'Smart cards'=>config('orbit_dashboard.smart_cards_enabled')===true,
                'Plans / Presence / Ping / SOS'=>false,
            ],
        ])->header('Cache-Control','private, no-store');
    }
}
