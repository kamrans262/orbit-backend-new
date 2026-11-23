<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Config\Http\Controllers\AdminAuthController;
use App\Modules\Config\Http\Controllers\AdminConfigController;
use App\Modules\Identity\Http\Controllers\AdminIdentityController;
use App\Modules\Profile\Http\Controllers\AdminProfileController;
use App\Modules\Social\Http\Controllers\AdminSocialGraphController;
use App\Modules\Circle\Http\Controllers\AdminCircleController;
use App\Modules\Media\Http\Controllers\AdminMediaController;
use App\Modules\Moments\Http\Controllers\AdminMomentController;
use App\Modules\Dashboard\Http\Controllers\AdminDashboardController;
use App\Modules\Presence\Http\Controllers\AdminPresenceController;
use App\Modules\Location\Http\Controllers\AdminLocationController;
use App\Modules\Ping\Http\Controllers\AdminPingController;
use App\Modules\CheckIn\Http\Controllers\AdminCheckInController;
use App\Modules\Messaging\Http\Controllers\AdminMessagingController;
use App\Modules\Notifications\Http\Controllers\AdminNotificationController;
use App\Modules\Plans\Http\Controllers\AdminPlanController;
use App\Modules\Safety\Http\Controllers\AdminSosController;
use App\Modules\Activity\Http\Controllers\AdminActivityController;
use App\Modules\Advertising\Http\Controllers\AdminAdvertisingController;
use App\Modules\Moderation\Http\Controllers\AdminModerationController;
use App\Modules\Config\Http\Middleware\EnsureAdmin;
use App\Modules\Config\Http\Middleware\RequireAdminPermission;

// Every route here inherits Laravel's 'web' stack: secure session, CSRF, cookies.
Route::prefix('admin')->name('orbit.admin.')->group(function (): void {
    // Serve the small admin assets from the existing Laravel app on Hostinger.
    // public_html uses a separate front controller; no duplicated public files or symlinks.
    Route::get('/assets/{file}', function (string $file) {
        abort_unless(in_array($file, ['admin.css', 'admin.js', 'orbit-logo.png'], true), 404);
        return response()->file(public_path('assets/orbit-admin/'.$file), [
            'Content-Type' => match ($file) { 'admin.css' => 'text/css; charset=UTF-8', 'admin.js' => 'application/javascript; charset=UTF-8', default => 'image/png' },
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    })->where('file', 'admin\\.(css|js)|orbit-logo\\.png')->name('asset');
    Route::get('/login', [AdminAuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'password'])->middleware('throttle:8,1')->name('password');
    Route::get('/mfa', [AdminAuthController::class, 'mfaForm'])->name('mfa');
    Route::post('/mfa', [AdminAuthController::class, 'verifyMfa'])->middleware('throttle:8,1')->name('mfa.verify');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::middleware([EnsureAdmin::class])->group(function (): void {
        Route::get('/start', function () {
            $actor = auth()->guard('admin')->user();
            foreach ([
                'config.manage' => 'orbit.admin.dashboard',
                'users.read' => 'orbit.admin.users.index',
                'deletions.read' => 'orbit.admin.deletions.index',
                'settings.audit.read' => 'orbit.admin.settings.activity',
                'social.read' => 'orbit.admin.social.index',
                'circles.read' => 'orbit.admin.circles.index',
                'media.read' => 'orbit.admin.media.index',
                'moments.read' => 'orbit.admin.moments.index',
                'notifications.read' => 'orbit.admin.notifications.overview',
                'plans.read' => 'orbit.admin.plans.overview',
                'safety.read' => 'orbit.admin.safety.overview',
                'activity.read' => 'orbit.admin.activity.overview',
                'advertising.read' => 'orbit.admin.advertising.overview',
                'moderation.read' => 'orbit.admin.moderation.overview',
            ] as $permission => $route) {
                if ($permission === 'circles.read' && config('orbit_circle.enabled') !== true) continue;
                if ($actor?->canPerform($permission)) return redirect()->route($route);
            }
            abort(403);
        })->name('landing');

        // M20: independent sponsored-content operations, same MFA/CSRF/Blade RBAC.
        Route::get('/advertising',[AdminAdvertisingController::class,'overview'])
            ->middleware(RequireAdminPermission::class.':advertising.read')
            ->name('advertising.overview');
        foreach (['sponsors','creatives','campaigns','placements'] as $section) {
            Route::get('/advertising/'.$section,
                [AdminAdvertisingController::class,$section])
                ->middleware(RequireAdminPermission::class.':advertising.read')
                ->name('advertising.'.$section);
        }
        Route::get('/advertising/analytics',[AdminAdvertisingController::class,'analytics'])
            ->middleware(RequireAdminPermission::class.':advertising.analytics')
            ->name('advertising.analytics');
        Route::get('/advertising/audits',[AdminAdvertisingController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':advertising.audit')
            ->name('advertising.audits');
        Route::post('/advertising/sponsors',[AdminAdvertisingController::class,'createSponsor'])
            ->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.sponsors.create');
        Route::post('/advertising/sponsors/{sponsor}/status',[AdminAdvertisingController::class,'sponsorStatus'])
            ->whereUuid('sponsor')->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.sponsors.status');
        Route::post('/advertising/creatives',[AdminAdvertisingController::class,'uploadCreative'])
            ->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.creatives.upload');
        Route::post('/advertising/creatives/{creative}/revise',[AdminAdvertisingController::class,'reviseCreative'])
            ->whereUuid('creative')->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.creatives.revise');
        Route::get('/advertising/creatives/{creative}/preview',[AdminAdvertisingController::class,'previewCreative'])
            ->whereUuid('creative')->middleware(RequireAdminPermission::class.':advertising.review')
            ->name('advertising.creatives.preview');
        Route::post('/advertising/creatives/{creative}/review',[AdminAdvertisingController::class,'reviewCreative'])
            ->whereUuid('creative')->middleware([RequireAdminPermission::class.':advertising.review','throttle:8,1'])
            ->name('advertising.creatives.review');
        Route::post('/advertising/campaigns',[AdminAdvertisingController::class,'createCampaign'])
            ->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.campaigns.create');
        Route::post('/advertising/campaigns/{campaign}/transition',
            [AdminAdvertisingController::class,'campaignTransition'])
            ->whereUuid('campaign')->middleware([RequireAdminPermission::class.':advertising.read','throttle:8,1'])
            ->name('advertising.campaigns.transition');
        Route::post('/advertising/placements/{placement}/control',
            [AdminAdvertisingController::class,'placementControl'])
            ->whereIn('placement',['home.promo','moments.promo'])
            ->middleware([RequireAdminPermission::class.':advertising.manage','throttle:8,1'])
            ->name('advertising.placements.control');

        // M18 first-class Activity operations, under existing MFA/RBAC.
        Route::get('/activity',[AdminActivityController::class,'overview'])
            ->middleware(RequireAdminPermission::class.':activity.read')
            ->name('activity.overview');
        Route::get('/activity/sources',[AdminActivityController::class,'sources'])
            ->middleware(RequireAdminPermission::class.':activity.read')
            ->name('activity.sources');
        Route::get('/activity/operations',[AdminActivityController::class,'operations'])
            ->middleware(RequireAdminPermission::class.':activity.read')
            ->name('activity.operations');
        Route::get('/activity/audits',[AdminActivityController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':activity.audit')
            ->name('activity.audits');
        Route::post('/activity/control',[AdminActivityController::class,'control'])
            ->middleware([RequireAdminPermission::class.':activity.manage','throttle:6,1'])
            ->name('activity.control');
        Route::post('/activity/project',[AdminActivityController::class,'project'])
            ->middleware([RequireAdminPermission::class.':activity.manage','throttle:3,1'])
            ->name('activity.project');

        // M17: first-class, independently permissioned Moderation workspace.
        Route::get('/moderation',[AdminModerationController::class,'overview'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.overview');
        Route::get('/moderation/queue',[AdminModerationController::class,'queue'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.queue');
        Route::get('/moderation/cases',[AdminModerationController::class,'cases'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.cases');
        Route::get('/moderation/cases/{case}',[AdminModerationController::class,'detail'])
            ->whereUuid('case')->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.cases.show');
        Route::post('/moderation/cases/{case}/transition',[AdminModerationController::class,'transition'])
            ->whereUuid('case')->middleware([
                RequireAdminPermission::class.':moderation.triage','throttle:10,1'])
            ->name('moderation.cases.transition');
        Route::post('/moderation/cases/{case}/notes',[AdminModerationController::class,'addNote'])
            ->whereUuid('case')->middleware([
                RequireAdminPermission::class.':moderation.triage','throttle:10,1'])
            ->name('moderation.cases.notes');
        Route::post('/moderation/cases/{case}/enforce',[AdminModerationController::class,'enforce'])
            ->whereUuid('case')->middleware([
                RequireAdminPermission::class.':moderation.decide','throttle:6,1'])
            ->name('moderation.cases.enforce');
        Route::post('/moderation/cases/{case}/evidence',[AdminModerationController::class,'evidence'])
            ->whereUuid('case')->middleware([
                RequireAdminPermission::class.':moderation.evidence','throttle:6,1'])
            ->name('moderation.cases.evidence');
        Route::get('/moderation/actions',[AdminModerationController::class,'actions'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.actions');
        Route::get('/moderation/appeals',[AdminModerationController::class,'appeals'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.appeals');
        Route::post('/moderation/appeals/{appeal}/decision',
            [AdminModerationController::class,'decideAppeal'])
            ->whereUuid('appeal')->middleware([
                RequireAdminPermission::class.':moderation.appeals','throttle:6,1'])
            ->name('moderation.appeals.decision');
        Route::get('/moderation/settings',[AdminModerationController::class,'settings'])
            ->middleware(RequireAdminPermission::class.':moderation.read')
            ->name('moderation.settings');
        Route::post('/moderation/settings',[AdminModerationController::class,'saveControl'])
            ->middleware([RequireAdminPermission::class.':moderation.manage','throttle:5,1'])
            ->name('moderation.settings.save');
        Route::post('/moderation/sync',[AdminModerationController::class,'sync'])
            ->middleware([RequireAdminPermission::class.':moderation.manage','throttle:5,1'])
            ->name('moderation.sync');
        Route::get('/moderation/audits',[AdminModerationController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':moderation.audit')
            ->name('moderation.audits');

        // M14 is a first-class operations area, NOT a submenu under
        // Accounts or Configurations. Admin roles are explicit and audited.
        // M16 is a standalone, independently RBAC-gated Safety workspace.
        Route::get('/safety',[AdminSosController::class,'overview'])
            ->middleware(RequireAdminPermission::class.':safety.read')->name('safety.overview');
        Route::get('/safety/incidents',[AdminSosController::class,'incidents'])
            ->middleware(RequireAdminPermission::class.':safety.read')->name('safety.incidents');
        Route::get('/safety/responders',[AdminSosController::class,'responders'])
            ->middleware(RequireAdminPermission::class.':safety.read')->name('safety.responders');
        Route::get('/safety/delivery',[AdminSosController::class,'delivery'])
            ->middleware(RequireAdminPermission::class.':safety.read')->name('safety.delivery');
        Route::get('/safety/settings',[AdminSosController::class,'settings'])
            ->middleware(RequireAdminPermission::class.':safety.read')->name('safety.settings');
        Route::get('/safety/audits',[AdminSosController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':safety.audit')->name('safety.audits');
        Route::post('/safety/settings',[AdminSosController::class,'saveControl'])
            ->middleware([RequireAdminPermission::class.':safety.manage','throttle:6,1'])
            ->name('safety.settings.save');
        Route::post('/safety/delivery/{job}/retry',[AdminSosController::class,'retry'])
            ->whereUuid('job')
            ->middleware([RequireAdminPermission::class.':safety.manage','throttle:6,1'])
            ->name('safety.retry');
        Route::post('/safety/run',[AdminSosController::class,'run'])
            ->middleware([RequireAdminPermission::class.':safety.manage','throttle:3,1'])
            ->name('safety.run');

        // M15 dedicated Plans workspace.
        Route::get('/plans',[AdminPlanController::class,'index'])
            ->middleware(RequireAdminPermission::class.':plans.read')->name('plans.overview');
        Route::get('/plans/directory',[AdminPlanController::class,'directory'])
            ->middleware(RequireAdminPermission::class.':plans.read')->name('plans.directory');
        Route::get('/plans/attendance',[AdminPlanController::class,'attendance'])
            ->middleware(RequireAdminPermission::class.':plans.read')->name('plans.attendance');
        Route::get('/plans/reminders',[AdminPlanController::class,'reminders'])
            ->middleware(RequireAdminPermission::class.':plans.read')->name('plans.reminders');
        Route::get('/plans/settings',[AdminPlanController::class,'settings'])
            ->middleware(RequireAdminPermission::class.':plans.read')->name('plans.settings');
        Route::get('/plans/audits',[AdminPlanController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':plans.audit')->name('plans.audits');
        Route::post('/plans/settings',[AdminPlanController::class,'saveControl'])
            ->middleware([RequireAdminPermission::class.':plans.manage','throttle:6,1'])->name('plans.settings.save');
        Route::post('/plans/cancel',[AdminPlanController::class,'cancel'])
            ->middleware([RequireAdminPermission::class.':plans.manage','throttle:6,1'])->name('plans.cancel');
        Route::post('/plans/reminders/{job}/retry',[AdminPlanController::class,'retry'])
            ->whereUuid('job')->middleware([RequireAdminPermission::class.':plans.manage','throttle:6,1'])->name('plans.retry');
        Route::post('/plans/process',[AdminPlanController::class,'process'])
            ->middleware([RequireAdminPermission::class.':plans.manage','throttle:3,1'])->name('plans.process');
        Route::get('/notifications',[AdminNotificationController::class,'index'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.overview');
        Route::get('/notifications/jobs',[AdminNotificationController::class,'jobs'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.jobs');
        Route::get('/notifications/devices',[AdminNotificationController::class,'devices'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.devices');
        Route::get('/notifications/templates',[AdminNotificationController::class,'templates'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.templates');
        Route::get('/notifications/settings',[AdminNotificationController::class,'settings'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.settings');
        Route::get('/notifications/audits',[AdminNotificationController::class,'audits'])
            ->middleware(RequireAdminPermission::class.':notifications.read')
            ->name('notifications.audits');
        Route::post('/notifications/settings',[AdminNotificationController::class,'saveControl'])
            ->middleware([RequireAdminPermission::class.':notifications.manage','throttle:10,1'])
            ->name('notifications.settings.save');
        Route::post('/notifications/templates/{key}',[AdminNotificationController::class,'saveTemplate'])
            ->middleware([RequireAdminPermission::class.':notifications.manage','throttle:10,1'])
            ->name('notifications.templates.save');
        Route::post('/notifications/jobs/{job}/retry',[AdminNotificationController::class,'retry'])
            ->whereUuid('job')
            ->middleware([RequireAdminPermission::class.':notifications.manage','throttle:6,1'])
            ->name('notifications.retry');
        Route::post('/notifications/devices/{device}/disable',[AdminNotificationController::class,'disableDevice'])
            ->whereUuid('device')
            ->middleware([RequireAdminPermission::class.':notifications.manage','throttle:6,1'])
            ->name('notifications.devices.disable');
        Route::post('/notifications/maintenance/{operation}',[AdminNotificationController::class,'maintenance'])
            ->whereIn('operation',['project','dispatch','expire'])
            ->middleware([RequireAdminPermission::class.':notifications.manage','throttle:3,1'])
            ->name('notifications.maintenance');

        Route::get('/messaging', [AdminMessagingController::class,'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('messaging.index');
        Route::get('/messaging/settings',[AdminMessagingController::class,'settings'])
            ->middleware(RequireAdminPermission::class.':config.manage')
            ->name('messaging.settings');
        Route::post('/messaging/settings',[AdminMessagingController::class,'saveSettings'])
            ->middleware([RequireAdminPermission::class.':config.manage','throttle:8,1'])
            ->name('messaging.settings.save');
        Route::post('/messaging/maintenance/prune',[AdminMessagingController::class,'prune'])
            ->middleware([RequireAdminPermission::class.':config.manage','throttle:2,1'])
            ->name('messaging.maintenance.prune');
        Route::get('/messaging/restrictions',[AdminMessagingController::class,'restrictions'])
            ->middleware(RequireAdminPermission::class.':messages.moderate')
            ->name('messaging.restrictions');
        Route::post('/messaging/restrictions/{user}',[AdminMessagingController::class,'updateRestriction'])
            ->whereUuid('user')->middleware([RequireAdminPermission::class.':messages.moderate','throttle:10,1'])
            ->name('messaging.restrictions.update');
        Route::get('/messaging/reports',[AdminMessagingController::class,'reports'])
            ->middleware(RequireAdminPermission::class.':messages.moderate')->name('messaging.reports');
        Route::post('/messaging/reports/{report}/evidence',[AdminMessagingController::class,'evidence'])
            ->whereUuid('report')->middleware([RequireAdminPermission::class.':messages.moderate','throttle:10,1'])
            ->name('messaging.reports.evidence');
        Route::get('/messaging/reports/{report}',[AdminMessagingController::class,'report'])
            ->whereUuid('report')->middleware(RequireAdminPermission::class.':messages.moderate')
            ->name('messaging.reports.show');
        Route::post('/messaging/reports/{report}/action',[AdminMessagingController::class,'action'])
            ->whereUuid('report')->middleware([RequireAdminPermission::class.':messages.moderate','throttle:10,1'])
            ->name('messaging.reports.action');
        Route::get('/check-in', [AdminCheckInController::class,'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('check-in.index');
        Route::get('/ping', [AdminPingController::class, 'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('ping.index');
        Route::get('/location', [AdminLocationController::class,'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('location.index');
        Route::get('/presence', [AdminPresenceController::class,'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('presence.index');
        Route::get('/home-dashboard', [AdminDashboardController::class,'index'])
            ->middleware(RequireAdminPermission::class.':config.manage')->name('home-dashboard.index');
        Route::post('/media/jobs/retry', [AdminMediaController::class,'retry'])->middleware([RequireAdminPermission::class.':media.jobs.retry','throttle:4,1'])->name('media.jobs.retry');
        Route::get('/media', [AdminMediaController::class,'index'])
            ->middleware(RequireAdminPermission::class.':media.read')->name('media.index');
        Route::get('/moments', [AdminMomentController::class,'index'])
            ->middleware(RequireAdminPermission::class.':moments.read')->name('moments.index');
        Route::post('/moments/reports/{report}/evidence', [AdminMomentController::class,'evidence'])
            ->whereUuid('report')->middleware([RequireAdminPermission::class.':moments.moderate','throttle:6,1'])
            ->name('moments.reports.evidence');
        Route::post('/moments/reports/{report}/action', [AdminMomentController::class,'action'])
            ->whereUuid('report')->middleware([RequireAdminPermission::class.':moments.moderate','throttle:6,1'])
            ->name('moments.reports.action');
        Route::get('/circles', [AdminCircleController::class, 'index'])
            ->middleware(RequireAdminPermission::class.':circles.read')->name('circles.index');
        Route::get('/social-graph', [AdminSocialGraphController::class, 'index'])
            ->middleware(RequireAdminPermission::class.':social.read')->name('social.index');
        Route::get('/deletion-requests', [AdminProfileController::class, 'deletionRequests'])
            ->middleware(RequireAdminPermission::class.':deletions.read')->name('deletions.index');
        Route::get('/settings-activity', [AdminProfileController::class, 'settingsActivity'])
            ->middleware(RequireAdminPermission::class.':settings.audit.read')->name('settings.activity');
        Route::get('/users', [AdminIdentityController::class, 'index'])
            ->middleware(RequireAdminPermission::class.':users.read')->name('users.index');
        Route::get('/users/{user}', [AdminIdentityController::class, 'show'])
            ->middleware(RequireAdminPermission::class.':users.read')->name('users.show');
        Route::post('/users/{user}/status', [AdminIdentityController::class, 'updateStatus'])
            ->middleware(RequireAdminPermission::class.':users.manage')->name('users.status');
        Route::delete('/users/{user}', [AdminIdentityController::class, 'destroy'])
            ->middleware([RequireAdminPermission::class.':users.delete', 'throttle:6,1'])->name('users.destroy');
    });
    Route::middleware([EnsureAdmin::class, RequireAdminPermission::class])->group(function (): void {
        Route::get('/', [AdminConfigController::class, 'dashboard'])->name('dashboard');
        Route::get('/flags', [AdminConfigController::class, 'flagsIndex'])->name('flags.index');
        Route::get('/config', [AdminConfigController::class, 'configsIndex'])->name('config.index');
        Route::get('/flags/new', [AdminConfigController::class, 'flagForm'])->name('flags.new');
        Route::post('/flags', [AdminConfigController::class, 'storeFlag'])->name('flags.store');
        Route::get('/flags/{flag}/edit', [AdminConfigController::class, 'flagForm'])->name('flags.edit');
        Route::post('/flags/{flag}', [AdminConfigController::class, 'storeFlag'])->name('flags.update');
        Route::get('/flags/{flag}/preview', [AdminConfigController::class, 'preview'])->name('flags.preview');
        Route::get('/config/new', [AdminConfigController::class, 'configForm'])->name('config.new');
        Route::post('/config', [AdminConfigController::class, 'storeConfig'])->name('config.store');
        Route::get('/config/{config}/edit', [AdminConfigController::class, 'configForm'])->name('config.edit');
        Route::post('/config/{config}', [AdminConfigController::class, 'storeConfig'])->name('config.update');
        Route::post('/revisions/{type}/{key}/rollback', [AdminConfigController::class, 'rollback'])->name('rollback');
    });
});
