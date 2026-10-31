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
use App\Modules\Moments\Http\Controllers\MomentController;
use App\Modules\Moments\Http\Controllers\MomentInteractionController;
use App\Modules\Moments\Http\Controllers\MomentLifecycleController;
use App\Modules\Moments\Http\Controllers\MomentNotificationController;
use App\Modules\Moments\Http\Controllers\MomentPushDeviceController;
use App\Modules\Dashboard\Http\Controllers\DashboardSummaryController;
use App\Modules\Presence\Http\Controllers\PresenceController;
use App\Modules\Presence\Http\Controllers\PresenceGatewayController;
use App\Modules\Location\Http\Controllers\LocationController;
use App\Modules\Location\Http\Controllers\LocationRealtimeController;
use App\Modules\Ping\Http\Controllers\PingController;
use App\Modules\Ping\Http\Controllers\PingCircleConsentController;
use App\Modules\Ping\Http\Middleware\PingOperationalTelemetry;
use App\Modules\CheckIn\Http\Controllers\CheckInController;
use App\Modules\CheckIn\Http\Controllers\CheckInCircleConsentController;
use App\Modules\CheckIn\Http\Middleware\CheckInOperationalTelemetry;
use App\Modules\Messaging\Http\Controllers\MessagingController;
use App\Modules\Messaging\Http\Controllers\MessagingCircleConsentController;
use App\Modules\Messaging\Http\Middleware\MessagingOperationalTelemetry;

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
    // Internal gateway: secret-authenticated, session ticket revalidation per event.
    Route::post('/location/realtime/introspect', [LocationRealtimeController::class,'introspect'])
        ->middleware('throttle:240,1');
    Route::post('/presence/realtime/introspect', [PresenceGatewayController::class,'introspect'])
        ->middleware('throttle:240,1');
    Route::post('/presence/realtime/inbound', [PresenceGatewayController::class,'inbound'])
        ->middleware('throttle:120,1');

    Route::middleware([AuthenticateOrbitToken::class, 'throttle:600,1'])->group(function (): void {
        // Encrypted per-device messaging: all payloads are ciphertext only.
        // No fake realtime, plaintext transport or universal Circle membership grant.
        Route::get('/messaging/capabilities',[MessagingController::class,'capabilities']);
        Route::get('/me/messaging/devices',[MessagingController::class,'myDevices']);
        Route::post('/me/messaging/keys',[MessagingController::class,'registerKeys'])
            ->middleware('throttle:12,1');
        Route::get('/messaging/keys/{recipient}',[MessagingController::class,'discoverKeys'])
            ->whereUuid('recipient')->middleware('throttle:40,1');
        Route::get('/conversations',[MessagingController::class,'listing'])
            ->middleware(MessagingOperationalTelemetry::class);
        Route::post('/conversations/direct',[MessagingController::class,'openDirect'])
            ->middleware(['throttle:20,1',MessagingOperationalTelemetry::class]);
        Route::get('/conversations/{conversation}/envelopes',[MessagingController::class,'envelopes'])
            ->whereUuid('conversation')->middleware(MessagingOperationalTelemetry::class);
        Route::post('/conversations/{conversation}/envelopes',[MessagingController::class,'send'])
            ->whereUuid('conversation')->middleware(['throttle:50,1',MessagingOperationalTelemetry::class]);
        Route::get('/conversations/{conversation}/sent/{clientId}',
            [MessagingController::class,'publishedClientId'])
            ->whereUuid('conversation')->whereUuid('clientId')
            ->middleware('throttle:40,1');
        // Current-epoch per-device Circle sender-key packets. Routing only:
        // trusted devices and consented membership are rechecked on every call.
        Route::get('/conversations/{conversation}/sender-keys',
            [MessagingController::class,'senderKeyInbox'])
            ->whereUuid('conversation')->middleware('throttle:40,1');
        Route::post('/conversations/{conversation}/sender-keys',
            [MessagingController::class,'distributeSenderKey'])
            ->whereUuid('conversation')
            ->middleware(['throttle:20,1',MessagingOperationalTelemetry::class]);
        Route::post('/conversations/{conversation}/typing',[MessagingController::class,'typing'])
            ->whereUuid('conversation')->middleware('throttle:60,1');
        Route::get('/conversations/{conversation}/typing',[MessagingController::class,'currentTyping'])
            ->whereUuid('conversation')->middleware('throttle:60,1');
        Route::post('/conversations/{conversation}/sync',[MessagingController::class,'sync'])
            ->whereUuid('conversation')->middleware('throttle:50,1');
        Route::post('/conversations/{conversation}/envelopes/{envelope}/delivered',
            [MessagingController::class,'delivered'])->whereUuid('conversation')
            ->whereUuid('envelope')->middleware('throttle:90,1');
        Route::post('/conversations/{conversation}/envelopes/{envelope}/read',
            [MessagingController::class,'read'])->whereUuid('conversation')
            ->whereUuid('envelope')->middleware('throttle:90,1');
        Route::post('/conversations/{conversation}/receipts/delivered',
            [MessagingController::class,'deliveredReceipt'])->whereUuid('conversation');
        Route::post('/conversations/{conversation}/receipts/read',
            [MessagingController::class,'readReceipt'])->whereUuid('conversation');
        Route::get('/conversations/{conversation}/messages/{message}/receipts',
            [MessagingController::class,'receipts'])->whereUuid('conversation')->whereUuid('message');
        Route::delete('/conversations/{conversation}/messages/{message}',
            [MessagingController::class,'redact'])->whereUuid('conversation')->whereUuid('message');
        Route::post('/conversations/{conversation}/leave',[MessagingController::class,'leave'])
            ->whereUuid('conversation');
        Route::put('/conversations/{conversation}/preferences',[MessagingController::class,'preferences'])
            ->whereUuid('conversation');
        Route::post('/conversations/{conversation}/reports',[MessagingController::class,'report'])
            ->whereUuid('conversation');
        Route::put('/me/messaging/circles/{circle}/typing',
            [MessagingController::class,'circleTyping'])->whereUuid('circle');
        Route::get('/me/messaging/circles/{circle}/consent',
            [MessagingCircleConsentController::class,'show'])->whereUuid('circle');
        Route::put('/me/messaging/circles/{circle}/consent',
            [MessagingCircleConsentController::class,'update'])->whereUuid('circle');
        Route::get('/circles/{circle}/messages/conversation',
            [MessagingController::class,'circle'])->whereUuid('circle');
        Route::get('/circles/{circle}/messages',[MessagingController::class,'circleEnvelopes'])
            ->whereUuid('circle')->middleware(MessagingOperationalTelemetry::class);
        Route::post('/circles/{circle}/messages',[MessagingController::class,'circleSend'])
            ->whereUuid('circle')->middleware([MessagingOperationalTelemetry::class,'throttle:50,1']);

        // Check-In: private, device-bound publication and authorized reading.
        Route::get('/check-ins/capabilities', [CheckInController::class,'capabilities']);
        Route::get('/check-ins/feed', [CheckInController::class,'feed'])
            ->middleware(CheckInOperationalTelemetry::class);
        Route::get('/check-ins/mine', [CheckInController::class,'mine'])
            ->middleware(CheckInOperationalTelemetry::class);
        Route::post('/check-ins', [CheckInController::class,'publish'])
            ->middleware([CheckInOperationalTelemetry::class,'throttle:30,1']);
        Route::get('/check-ins/{checkin}', [CheckInController::class,'detail'])
            ->whereUuid('checkin')->middleware(CheckInOperationalTelemetry::class);
        Route::put('/check-ins/{checkin}/audience', [CheckInController::class,'audience'])
            ->whereUuid('checkin')->middleware(CheckInOperationalTelemetry::class);
        Route::patch('/check-ins/{checkin}', [CheckInController::class,'expiry'])
            ->whereUuid('checkin')->middleware(CheckInOperationalTelemetry::class);
        Route::delete('/check-ins/{checkin}', [CheckInController::class,'withdraw'])
            ->whereUuid('checkin')->middleware(CheckInOperationalTelemetry::class);
        Route::get('/me/check-ins/circles/{circle}/consent',
            [CheckInCircleConsentController::class,'show'])->whereUuid('circle');
        Route::put('/me/check-ins/circles/{circle}/consent',
            [CheckInCircleConsentController::class,'update'])->whereUuid('circle');

        // Individual Circle Ping consent, never granted by membership alone.
        Route::get('/me/pings/circles/{circle}/consent', [PingCircleConsentController::class, 'show'])
            ->whereUuid('circle');
        Route::put('/me/pings/circles/{circle}/consent', [PingCircleConsentController::class, 'update'])
            ->whereUuid('circle');

        // Ping: durable, trusted-device HTTP delivery. No simulated push or receipts.
        Route::get('/pings/capabilities', [PingController::class, 'capabilities'])->middleware(PingOperationalTelemetry::class);
        Route::get('/pings/inbox', [PingController::class, 'inbox'])->middleware(PingOperationalTelemetry::class);
        Route::get('/pings/outbox', [PingController::class, 'outbox'])->middleware(PingOperationalTelemetry::class);
        Route::post('/pings', [PingController::class, 'send'])->middleware(PingOperationalTelemetry::class);
        Route::get('/pings/{ping}', [PingController::class, 'detail'])->whereUuid('ping')->middleware(PingOperationalTelemetry::class);
        Route::post('/pings/{ping}/delivered', [PingController::class, 'delivered'])->whereUuid('ping')->middleware(PingOperationalTelemetry::class);
        Route::post('/pings/{ping}/seen', [PingController::class, 'seen'])->whereUuid('ping')->middleware(PingOperationalTelemetry::class);
        Route::post('/pings/{ping}/respond', [PingController::class, 'respond'])->whereUuid('ping')->middleware(PingOperationalTelemetry::class);

        // M10: private location sharing; all routes require trusted device tokens.
        // Only a separately verified WSS gateway can activate location invalidations.
        Route::get('/location/capabilities',[LocationController::class,'capabilities'])->middleware('throttle:30,1');
        Route::post('/location/realtime/ticket',[LocationRealtimeController::class,'ticket'])
            ->middleware('throttle:20,1');
        Route::get('/location/map',[LocationController::class,'map'])->middleware('throttle:30,1');
        Route::get('/me/location/shares',[LocationController::class,'shares'])->middleware('throttle:30,1');
        Route::post('/me/location/shares',[LocationController::class,'createShare'])->middleware('throttle:10,1');
        Route::delete('/me/location/shares',[LocationController::class,'stopAll'])->middleware('throttle:15,1');
        Route::post('/me/location/shares/{share}/avatar-grant',[LocationController::class,'attachAvatar'])
            ->whereUuid('share')->middleware('throttle:10,1');
        Route::delete('/me/location/shares/{share}',[LocationController::class,'revokeShare'])
            ->whereUuid('share')->middleware('throttle:20,1');
        Route::post('/me/location/samples',[LocationController::class,'sample'])->middleware('throttle:45,1');

        // M9: trusted-device ephemeral presence. No realtime/polling shortcut.
        Route::post('/presence/heartbeat', [PresenceController::class,'heartbeat'])->middleware('throttle:30,1');
        Route::post('/presence/realtime/ticket', [PresenceGatewayController::class,'ticket'])
            ->middleware('throttle:20,1');
        Route::post('/presence/disconnect', [PresenceController::class,'disconnect'])->middleware('throttle:20,1');
        Route::post('/circles/{circle}/typing', [PresenceController::class,'typing'])
            ->whereUuid('circle')->middleware('throttle:25,1');
        Route::get('/me/presence/settings', [PresenceController::class,'settings'])->middleware('throttle:20,1');
        Route::put('/me/presence/circles/{circle}', [PresenceController::class,'circleSettings'])
            ->whereUuid('circle')->middleware('throttle:12,1');
        Route::get('/circles/{circle}/presence', [PresenceController::class,'circle'])
            ->whereUuid('circle')->middleware('throttle:30,1');

        // M8: private viewer-scoped Home aggregation. Never public/cacheable.
        Route::get('/dashboard/summary', [DashboardSummaryController::class,'summary'])
            ->middleware('throttle:45,1');
        Route::post('/me/dashboard/cards/{key}/dismiss', [DashboardSummaryController::class,'dismiss'])
            ->where('key','[a-z_]{1,64}')->middleware('throttle:20,1');
        Route::put('/me/dashboard/preferences', [DashboardSummaryController::class,'preferences'])
            ->middleware('throttle:15,1');
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

        // M7 private Moments: every route inherits verified Orbit device auth.
        // Fixed paths precede dynamic UUID routes. No public Moment discovery.
        Route::post('/moments', [MomentController::class,'publish'])->middleware('throttle:10,1');
        Route::get('/moments', [MomentController::class,'feed'])->middleware('throttle:45,1');
        Route::get('/moments/rail', [MomentController::class,'rail'])->middleware('throttle:45,1');
        Route::get('/moments/revisions', [MomentController::class,'revisions'])->middleware('throttle:40,1');
        Route::get('/moments/report-evidence-policy', [MomentInteractionController::class,'evidencePolicy'])->middleware('throttle:20,1');
        Route::get('/me/moments/notifications', [MomentNotificationController::class,'index'])->middleware('throttle:20,1');
        Route::get('/me/moments/notifications/muted-circles', [MomentNotificationController::class,'mutedCircles'])->middleware('throttle:20,1');
        Route::put('/me/moments/notifications/mute-circle/{circle}', [MomentNotificationController::class,'muteCircle'])->whereUuid('circle')->middleware('throttle:15,1');
        Route::get('/me/moments/push-bootstrap', [MomentPushDeviceController::class,'bootstrap'])->middleware('throttle:10,1');
        Route::post('/me/moments/push-token', [MomentPushDeviceController::class,'register'])->middleware('throttle:8,1');
        Route::delete('/me/moments/push-token', [MomentPushDeviceController::class,'unregister'])->middleware('throttle:8,1');
        Route::post('/me/moments/notifications/{notice}/read', [MomentNotificationController::class,'markRead'])->whereUuid('notice')->middleware('throttle:30,1');
        Route::get('/circles/{circle}/moments', [MomentController::class,'circleFeed'])->whereUuid('circle')->middleware('throttle:45,1');
        Route::get('/users/{user}/moments', [MomentController::class,'profileFeed'])->whereUuid('user')->middleware('throttle:45,1');
        Route::get('/moments/receipt/{client}', [MomentController::class,'receipt'])
            ->whereUuid('client')->middleware('throttle:20,1');
        Route::get('/moments/{moment}', [MomentController::class,'detail'])->whereUuid('moment');
        Route::get('/moments/{moment}/cache-lease', [MomentController::class,'cacheLease'])->whereUuid('moment')->middleware('throttle:40,1');
        Route::get('/moments/{moment}/media-manifest', [MomentController::class,'manifest'])->whereUuid('moment');
        Route::patch('/moments/{moment}', [MomentController::class,'edit'])->whereUuid('moment')->middleware('throttle:15,1');
        Route::delete('/moments/{moment}', [MomentLifecycleController::class,'delete'])->whereUuid('moment')->middleware('throttle:12,1');
        Route::post('/moments/{moment}/audience', [MomentLifecycleController::class,'audience'])->whereUuid('moment')->middleware('throttle:12,1');
        Route::get('/moments/{moment}/viewers', [MomentInteractionController::class,'viewers'])->whereUuid('moment');
        Route::post('/moments/{moment}/views', [MomentInteractionController::class,'view'])->whereUuid('moment')->middleware('throttle:90,1');
        Route::put('/moments/{moment}/reaction', [MomentInteractionController::class,'react'])->whereUuid('moment')->middleware('throttle:40,1');
        Route::delete('/moments/{moment}/reaction', [MomentInteractionController::class,'unreact'])->whereUuid('moment')->middleware('throttle:40,1');
        Route::get('/moments/{moment}/comments', [MomentInteractionController::class,'comments'])->whereUuid('moment');
        Route::post('/moments/{moment}/comments', [MomentInteractionController::class,'comment'])->whereUuid('moment')->middleware('throttle:30,1');
        Route::delete('/moments/{moment}/comments/{comment}', [MomentInteractionController::class,'deleteComment'])->whereUuid('moment')->whereUuid('comment')->middleware('throttle:20,1');
        Route::post('/moments/{moment}/reports', [MomentInteractionController::class,'report'])->whereUuid('moment')->middleware('throttle:8,1');
        Route::post('/moments/{moment}/comments/{comment}/reports', [MomentInteractionController::class,'report'])
            ->whereUuid('moment')->whereUuid('comment')->middleware('throttle:8,1');
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
