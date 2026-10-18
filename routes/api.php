<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\ConfigBootstrapController;

// Public client hints are never used for server authorization, billing or safety gates.
Route::prefix('v1')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/config/bootstrap', ConfigBootstrapController::class);
});
