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
