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
