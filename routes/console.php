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
