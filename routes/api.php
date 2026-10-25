<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\ConfigBootstrapController;
use App\Modules\Identity\Http\Controllers\IdentityController;
use App\Modules\Profile\Http\Controllers\ProfileSettingsController;
use App\Modules\Identity\Http\Middleware\AuthenticateOrbitToken;
use App\Modules\Social\Http\Controllers\SocialGraphController;
use App\Modules\Social\Http\Controllers\SocialContactController;
use App\Modules\Social\Http\Controllers\SocialInviteController;
use App\Modules\Circle\Http\Controllers\CircleController;
use App\Modules\Circle\Http\Middleware\EnsureCircleM5Enabled;
use App\Modules\Media\Http\Controllers\MediaController;

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
        Route::middleware(EnsureCircleM5Enabled::class)->group(function (): void {
        // M5 private Circles: no public directory, no passive membership consent.
        Route::get('/me/circles/primary', [CircleController::class, 'primary']);
        Route::put('/me/circles/primary', [CircleController::class, 'choosePrimary'])->middleware('throttle:15,1');
        Route::get('/circles/features', [CircleController::class, 'features']);
        Route::post('/circles/realtime/bootstrap', [CircleController::class, 'realtimeBootstrap'])
            ->middleware('throttle:6,1');
        Route::get('/circles', [CircleController::class, 'listing']);
        Route::post('/circles', [CircleController::class, 'create'])->middleware('throttle:10,1');
        Route::get('/circles/archived', [CircleController::class, 'archived']);
        Route::get('/circles/invites/incoming', [CircleController::class, 'incoming']);
        Route::post('/circles/invites/code/preview', [CircleController::class, 'previewCode'])->middleware('throttle:orbit-circle-code-preview');
        Route::post('/circles/invites/code/redeem', [CircleController::class, 'redeemCode'])->middleware('throttle:orbit-circle-code-redeem');
        Route::post('/circles/invites/{invite}/accept', [CircleController::class, 'accept'])->whereUuid('invite')->middleware('throttle:20,1');
        Route::post('/circles/invites/{invite}/decline', [CircleController::class, 'decline'])->whereUuid('invite')->middleware('throttle:20,1');
        Route::get('/circles/{circle}', [CircleController::class, 'detail'])->whereUuid('circle');
        Route::patch('/circles/{circle}', [CircleController::class, 'update'])->whereUuid('circle')->middleware('throttle:20,1');
        Route::get('/circles/{circle}/members', [CircleController::class, 'members'])->whereUuid('circle');
        Route::post('/circles/{circle}/leave', [CircleController::class, 'leave'])->whereUuid('circle')->middleware('throttle:20,1');
        Route::post('/circles/{circle}/ownership-transfer', [CircleController::class, 'transfer'])->whereUuid('circle')->middleware('throttle:5,1');
        Route::post('/circles/{circle}/archive', [CircleController::class, 'archive'])->whereUuid('circle')->middleware('throttle:5,1');
        Route::post('/circles/{circle}/restore', [CircleController::class, 'restore'])->whereUuid('circle')->middleware('throttle:5,1');
        Route::delete('/circles/{circle}/members/{user}', [CircleController::class, 'remove'])->whereUuid('circle')->whereUuid('user')->middleware('throttle:20,1');
        Route::patch('/circles/{circle}/members/{user}', [CircleController::class, 'role'])->whereUuid('circle')->whereUuid('user')->middleware('throttle:20,1');
        Route::get('/circles/{circle}/invites', [CircleController::class, 'outgoing'])->whereUuid('circle');
        Route::post('/circles/{circle}/invites', [CircleController::class, 'invite'])->whereUuid('circle')->middleware('throttle:10,1');
        Route::delete('/circles/{circle}/invites/{invite}', [CircleController::class, 'revoke'])->whereUuid('circle')->whereUuid('invite')->middleware('throttle:20,1');
        Route::get('/circles/{circle}/permissions', [CircleController::class, 'permissions'])->whereUuid('circle');
        Route::patch('/circles/{circle}/permissions', [CircleController::class, 'revokePermission'])->whereUuid('circle')->middleware('throttle:20,1');
        }); // M5 feature gate
        // M6: media is private, authenticated and device-bound. No public asset routes.
        Route::post('/media/recipient-keys', [MediaController::class,'recipientKeys'])->middleware('throttle:30,1');
        Route::post('/media/uploads', [MediaController::class,'initiate'])->middleware('throttle:10,1');
        Route::get('/media/uploads/{upload}', [MediaController::class,'uploadStatus'])->whereUuid('upload');
        Route::post('/media/uploads/{upload}/parts', [MediaController::class,'part'])->whereUuid('upload')->middleware('throttle:120,1');
        Route::put('/media/uploads/{upload}/chunks/{part}', [MediaController::class,'localChunk'])->whereUuid('upload')->whereNumber('part')->middleware('throttle:120,1');
        Route::post('/media/uploads/{upload}/complete', [MediaController::class,'complete'])->whereUuid('upload')->middleware('throttle:10,1');
        Route::delete('/media/uploads/{upload}', [MediaController::class,'abort'])->whereUuid('upload')->middleware('throttle:20,1');
        Route::get('/media/{media}', [MediaController::class,'detail'])->whereUuid('media');
        Route::post('/media/{media}/variants/uploads', [MediaController::class,'initiateVariant'])->whereUuid('media')->middleware('throttle:10,1');
        Route::get('/media/{media}/ciphertext', [MediaController::class,'localCiphertext'])->whereUuid('media')->middleware('throttle:30,1');
        Route::post('/media/{media}/access', [MediaController::class,'access'])->whereUuid('media')->middleware('throttle:30,1');
        Route::get('/media/{media}/key-envelope', [MediaController::class,'keyEnvelope'])->whereUuid('media')->middleware('throttle:30,1');
        Route::post('/media/{media}/grants', [MediaController::class,'grant'])->whereUuid('media')->middleware('throttle:20,1');
        Route::delete('/media/{media}/grants/{grant}', [MediaController::class,'revoke'])->whereUuid('media')->whereUuid('grant')->middleware('throttle:20,1');
        Route::delete('/media/{media}', [MediaController::class,'delete'])->whereUuid('media')->middleware('throttle:10,1');
        // M4 social graph: trusted-device auth, per-IP throttles, per-account quotas,
        // server-side block checks and no-store responses.
        Route::get('/people/search', [SocialGraphController::class, 'search'])->middleware('throttle:30,1');
        Route::get('/me/social/revision', [SocialGraphController::class, 'revision'])->middleware('throttle:30,1');
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
        Route::post('/devices/recovery/request', [IdentityController::class, 'requestDeviceRecovery']);
        Route::post('/devices/recovery/complete', [IdentityController::class, 'completeDeviceRecovery']);
        Route::get('/devices/registration-status', [IdentityController::class, 'deviceRegistrationStatus']);
        Route::patch('/me/devices/{device}', [IdentityController::class, 'renameDevice']);
        Route::post('/me/devices/{device}/approve', [IdentityController::class, 'approveDevice']);
        Route::delete('/me/devices/{device}', [IdentityController::class, 'revokeDevice']);
    });
});
