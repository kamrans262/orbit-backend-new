<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Modules\Media\Services\MediaObjectStore::class,
            function () {
                return config('orbit_media.driver') === 'hostinger_private'
                    ? new \App\Modules\Media\Services\HostingerPrivateMediaStore()
                    : new \App\Modules\Media\Services\MediaObjectStore();
            });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
