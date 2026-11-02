<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orbit:circles:expire', function () {
    $count = app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class)->archiveDue();
    $this->info('Expired Circle archives: '.$count);
})->purpose('Archive temporary Circles after 72 hours');

Schedule::command('orbit:circles:expire')->hourly();

Artisan::command('orbit:circles:expiry-notices', function () {
    $count=app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class)->queueExpiryNotices();
    $this->info('Temporary Circle notices queued: '.$count);
})->purpose('Queue minimal Circle expiring soon events');

Schedule::command('orbit:circles:expiry-notices')->hourly();

Artisan::command('orbit:circles:retention-review {--purge} {--confirmation=}', function () {
    $lifecycle=app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class);
    $count=$lifecycle->eligibleForRetentionReviewCount();
    $this->info('Temporary Circles eligible for 30-day retention review: '.$count);
    if(!$this->option('purge')) return;
    $purged=$lifecycle->purgeReviewed(25,(string)$this->option('confirmation'));
    $this->info('Audited, authorized temporary Circle purges: '.$purged);
})->purpose('Preview Circle retention eligibility; explicit audited purge is separately gated');

/**
 * Configured transport only; zero network activity while Firebase is OFF.
 * Replaying outbox data is idempotent and never sends Circle private content.
 */
Artisan::command('orbit:circles:firebase-publish', function () {
    $count = app(\App\Modules\Circle\Services\CircleFirebaseBridge::class)
        ->publishPending(20);
    $this->info('Circle revision events published: '.$count);
})->purpose('Publish scoped revision invalidations to approved Firebase RTDB');

Schedule::command('orbit:circles:firebase-publish')->everyMinute()->withoutOverlapping();

/** Disabled until 30-day retention policy and controlled release are approved. */
Artisan::command('orbit:circles:retention-purge-due', function () {
    $count = app(\App\Modules\Circle\Services\CircleTemporaryLifecycle::class)
        ->purgeDue(25);
    $this->info('Eligible, legally unheld temporary archives purged: '.$count);
})->purpose('Triple-gated daily audited 30-day temporary Circle retention');

Schedule::command('orbit:circles:retention-purge-due')->dailyAt('03:15')
    ->withoutOverlapping();


/**
 * M6 private media worker. Runs locally without Firebase.
 * Retention purges remove ciphertext objects only after verified policy deadlines.
 */
Artisan::command('orbit:media:process', function () {
    $result = app(\App\Modules\Media\Services\MediaLifecycleWorker::class)->run(15);
    $this->info('M6 media lifecycle: '.json_encode($result));
})->purpose('Verify ciphertext uploads, expire sessions and safely purge deleted/expired media');

Schedule::command('orbit:media:process')->everyFiveMinutes()->withoutOverlapping();

/** M7: local-only expiry + retention staging; no Firebase or push delivery. */
Artisan::command('orbit:moments:process', function () {
    $result=app(\App\Modules\Moments\Services\MomentLifecycleWorker::class)->run(30);
    $this->info('M7 Moment lifecycle: '.json_encode($result));
})->purpose('Expire private Moment views and queue legally unheld encrypted retention purges');
Schedule::command('orbit:moments:process')->hourly()->withoutOverlapping();


/**
 * M7 in-app HTTPS notification projection. Never transmits a push or marks a
 * mobile device delivered. Scope is reevaluated when its owner next polls.
 */
Artisan::command('orbit:moments:notifications', function () {
    $result=app(\App\Modules\Moments\Services\MomentNotificationOutboxWorker::class)->run(30);
    $this->info('M7 private notification inbox: '.json_encode($result));
})->purpose('Project opted-in, authorized Moment events into private HTTPS notices');
Schedule::command('orbit:moments:notifications')->everyFiveMinutes()->withoutOverlapping();

/** Prepared FCM sender; OFF by default. No Firebase call before client setup. */
Artisan::command('orbit:moments:push-send', function () {
    $stats=app(\App\Modules\Moments\Services\MomentPushDeliveryWorker::class)->run(30);
    $this->info('M7 FCM dispatch: '.json_encode($stats));
})->purpose('Deliver generic opted-in Moment notices only when FCM is configured');
Schedule::command('orbit:moments:push-send')->everyFiveMinutes()->withoutOverlapping();

/** M13 encrypted envelope retention: ciphertext only; no plaintext or keys. */
Artisan::command('orbit:messaging:prune', function () {
    if(!app(\App\Modules\Messaging\Services\MessagingConversationService::class)
        ->ready()){
        $this->info('Encrypted messaging retention: schema not provisioned.');
        return;
    }
    $count=0;
    for($batch=0;$batch<20;$batch++){
        $n=app(\App\Modules\Messaging\Services\MessagingEnvelopeService::class)->prune();
        $count+=$n;
        if($n<500)break;
    }
    $this->info('Expired ciphertext envelopes purged: '.$count);
    $groupCount=0;
    for($batch=0;$batch<20;$batch++){
        $n=app(\App\Modules\Messaging\Services\MessagingSenderKeyService::class)->prune();
        $groupCount+=$n;
        if($n<500)break;
    }
    $this->info('Expired opaque sender-key distribution packets purged: '.$groupCount);
})->purpose('Purge expired encrypted messaging envelopes within retention bounds');
Schedule::command('orbit:messaging:prune')->dailyAt('03:35')->withoutOverlapping();


// M14 private inbox projection and MariaDB-backed push delivery. Provider
// transport remains inactive until release gate + real FCM/APNs credentials.
Artisan::command('orbit:notifications:project', function () {
    $result=app(\App\Modules\Notifications\Services\NotificationProjector::class)->run(75);
    $this->info('Private notification projection: '.json_encode($result));
})->purpose('Project permitted existing module events into the private notification inbox');
Schedule::command('orbit:notifications:project')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('orbit:notifications:dispatch', function () {
    $result=app(\App\Modules\Notifications\Services\NotificationDeliveryWorker::class)->run(40);
    $this->info('Notification push delivery: '.json_encode($result));
})->purpose('Send generic notification invalidations using approved device-scoped FCM');
Schedule::command('orbit:notifications:dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('orbit:notifications:expire', function () {
    $service=app(\App\Modules\Notifications\Services\NotificationService::class);
    $invalidated=$service->invalidateExpired(500);
    $purged=$service->purgeInvalidated(500);
    $this->info('Notification expiry: '.json_encode([
        'invalidated'=>$invalidated,'purged'=>$purged,
    ]));
})->purpose('Hide notices beyond approved retention without purging M7 legacy source');
Schedule::command('orbit:notifications:expire')->hourly()->withoutOverlapping();

// Revoke encrypted push registrations when trust, session, account status or
// global push preference changes. Runs with Firebase disabled as well.
Artisan::command('orbit:notifications:devices-reconcile',function () {
    $result=app(\App\Modules\Notifications\Services\NotificationDeviceBridge::class)
        ->reconcile(100);
    $this->info('Push device registration reconciliation: '.json_encode($result));
})->purpose('Disable stale or untrusted M14 push registrations');
Schedule::command('orbit:notifications:devices-reconcile')
    ->everyFiveMinutes()->withoutOverlapping();

// Account-security email is OFF by default and never a social/marketing route.
Artisan::command('orbit:notifications:security-mail',function () {
    $stats=app(\App\Modules\Notifications\Services\NotificationSecurityEmailDeliveryWorker::class)
        ->run(30);
    $this->info('Verified security email: '.json_encode($stats));
})->purpose('Deliver approved generic account-security notices by verified mailer');
Schedule::command('orbit:notifications:security-mail')
    ->everyMinute()->withoutOverlapping();
