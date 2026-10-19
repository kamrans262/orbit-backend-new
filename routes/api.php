<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\ConfigBootstrapController;
use App\Modules\Identity\Http\Controllers\IdentityController;
use App\Modules\Identity\Http\Middleware\AuthenticateOrbitToken;

Route::prefix('v1')->group(function (): void {
    // M1 mobile configuration: public rollout hints, not an authorization source.
    Route::get('/config/bootstrap', ConfigBootstrapController::class)->middleware('throttle:60,1');

    // Never expose OTP codes or verification-provider credentials to consumers.
    Route::post('/auth/otp/request', [IdentityController::class, 'requestOtp'])->middleware('throttle:20,1');
    Route::post('/auth/otp/verify', [IdentityController::class, 'verifyOtp'])->middleware('throttle:25,1');
    Route::post('/auth/refresh', [IdentityController::class, 'refresh'])->middleware('throttle:30,1');

    Route::middleware([AuthenticateOrbitToken::class, 'throttle:600,1'])->group(function (): void {
        Route::post('/auth/logout', [IdentityController::class, 'logout']);
        Route::post('/auth/logout-all', [IdentityController::class, 'logoutAll']);
        Route::post('/me/deletion-request', [IdentityController::class, 'requestDeletion']);
        Route::get('/me', [IdentityController::class, 'me']);
        Route::patch('/me/profile-basics', [IdentityController::class, 'updateProfileBasics']);
        Route::get('/me/devices', [IdentityController::class, 'listDevices']);
        Route::get('/me/sessions', [IdentityController::class, 'listSessions']);
        Route::delete('/me/sessions/{session}', [IdentityController::class, 'revokeSession']);
        Route::post('/devices/register', [IdentityController::class, 'registerDevice']);
        Route::patch('/me/devices/{device}', [IdentityController::class, 'renameDevice']);
        Route::post('/me/devices/{device}/approve', [IdentityController::class, 'approveDevice']);
        Route::delete('/me/devices/{device}', [IdentityController::class, 'revokeDevice']);
    });
});
