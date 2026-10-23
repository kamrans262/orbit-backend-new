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
