<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\ConfigBootstrapController;
use App\Modules\Identity\Http\Controllers\IdentityController;
use App\Modules\Profile\Http\Controllers\ProfileSettingsController;
use App\Modules\Identity\Http\Middleware\AuthenticateOrbitToken;
use App\Modules\Social\Http\Controllers\SocialGraphController;
use App\Modules\Social\Http\Controllers\SocialContactController;
use App\Modules\Social\Http\Controllers\SocialInviteController;

Route::prefix('v1')->group(function (): void {
    // M1 mobile configuration: public rollout hints, not an authorization source.
    Route::get('/config/bootstrap', ConfigBootstrapController::class)->middleware('throttle:60,1');

    // Never expose OTP codes or verification-provider credentials to consumers.
    Route::post('/auth/otp/request', [IdentityController::class, 'requestOtp'])->middleware('throttle:20,1');
    Route::post('/auth/password/otp/request', [IdentityController::class, 'requestPasswordOtp'])->middleware('throttle:8,1');
    Route::post('/auth/password/reset/request', [IdentityController::class, 'requestPasswordReset'])->middleware('throttle:8,1');
    Route::post('/auth/password/reset/confirm', [IdentityController::class, 'confirmPasswordReset'])->middleware('throttle:10,1');
    Route::post('/auth/otp/verify', [IdentityController::class, 'verifyOtp'])->middleware('throttle:25,1');
    Route::post('/auth/refresh', [IdentityController::class, 'refresh'])->middleware('throttle:30,1');

    Route::middleware([AuthenticateOrbitToken::class, 'throttle:600,1'])->group(function (): void {
        // M4 social graph: trusted-device auth, per-IP throttles, per-account quotas,
        // server-side block checks and no-store responses.
        Route::get('/people/search', [SocialGraphController::class, 'search'])->middleware('throttle:30,1');
        Route::get('/me/social/discovery', [SocialGraphController::class, 'discovery']);
        Route::patch('/me/social/discovery', [SocialGraphController::class, 'updateDiscovery'])->middleware('throttle:15,1');
        Route::post('/people/contacts/match', [SocialContactController::class, 'match'])->middleware('throttle:4,1');
        Route::get('/friends/invites', [SocialInviteController::class, 'listing']);
        Route::post('/friends/invites', [SocialInviteController::class, 'create'])->middleware('throttle:5,1');
        Route::post('/friends/invites/preview', [SocialInviteController::class, 'preview'])->middleware('throttle:20,1');
        Route::post('/friends/invites/redeem', [SocialInviteController::class, 'redeem'])->middleware('throttle:10,1');
        Route::delete('/friends/invites/{id}', [SocialInviteController::class, 'revoke'])->whereUuid('id')->middleware('throttle:20,1');
        Route::get('/people/{user}/preview', [SocialGraphController::class, 'preview'])->whereUuid('user')->middleware('throttle:60,1');
        Route::get('/friends', [SocialGraphController::class, 'friends']);
        Route::get('/friends/requests', [SocialGraphController::class, 'requests']);
        Route::post('/friends/requests', [SocialGraphController::class, 'sendRequest'])->middleware('throttle:20,1');
        Route::post('/friends/requests/{id}/accept', [SocialGraphController::class, 'accept'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/friends/requests/{id}/decline', [SocialGraphController::class, 'decline'])->whereUuid('id')->middleware('throttle:30,1');
        Route::post('/friends/requests/{id}/cancel', [SocialGraphController::class, 'cancel'])->whereUuid('id')->middleware('throttle:20,1');
        Route::delete('/friends/{user}', [SocialGraphController::class, 'removeFriend'])->whereUuid('user')->middleware('throttle:30,1');
        Route::get('/me/blocks', [SocialGraphController::class, 'blocks']);
        Route::post('/me/blocks', [SocialGraphController::class, 'block'])->middleware('throttle:30,1');
        Route::delete('/me/blocks/{user}', [SocialGraphController::class, 'unblock'])->whereUuid('user')->middleware('throttle:30,1');
        Route::post('/auth/logout', [IdentityController::class, 'logout']);
        Route::post('/auth/logout-all', [IdentityController::class, 'logoutAll']);
        Route::post('/me/deletion-request', [IdentityController::class, 'requestDeletion']);
        Route::get('/me/deletion-status', [IdentityController::class, 'deletionStatus']);
        Route::post('/me/deletion-cancel', [IdentityController::class, 'cancelDeletion'])->middleware('throttle:30,1');
        Route::get('/me', [IdentityController::class, 'me']);
        Route::get('/me/profile', [ProfileSettingsController::class, 'show']);
        Route::patch('/me/profile', [ProfileSettingsController::class, 'update']);
        Route::get('/me/preferences/notifications', [ProfileSettingsController::class, 'notifications']);
        Route::patch('/me/preferences/notifications', [ProfileSettingsController::class, 'updateNotifications']);
        Route::get('/me/preferences/general', [ProfileSettingsController::class, 'general']);
        Route::patch('/me/preferences/general', [ProfileSettingsController::class, 'updateGeneral']);
        Route::get('/me/preferences/privacy', [ProfileSettingsController::class, 'privacy']);
        Route::patch('/me/preferences/privacy', [ProfileSettingsController::class, 'updatePrivacy']);
        Route::patch('/me/profile-basics', [IdentityController::class, 'updateProfileBasics']);
        Route::post('/me/password', [IdentityController::class, 'enrollPassword']);
        Route::post('/me/identities/link', [IdentityController::class, 'linkIdentity']);
        Route::get('/me/devices', [IdentityController::class, 'listDevices']);
        Route::get('/me/sessions', [IdentityController::class, 'listSessions']);
        Route::delete('/me/sessions/{session}', [IdentityController::class, 'revokeSession']);
        Route::post('/devices/register', [IdentityController::class, 'registerDevice']);
        Route::get('/devices/registration-status', [IdentityController::class, 'deviceRegistrationStatus']);
        Route::patch('/me/devices/{device}', [IdentityController::class, 'renameDevice']);
        Route::post('/me/devices/{device}/approve', [IdentityController::class, 'approveDevice']);
        Route::delete('/me/devices/{device}', [IdentityController::class, 'revokeDevice']);
    });
});
