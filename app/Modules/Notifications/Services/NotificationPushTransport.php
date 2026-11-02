<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Moments\Services\MomentFcmTransport;

final class NotificationPushTransport
{
    public function enabled(): bool
    {
        // Independent safety switch; credentials and APNs are prerequisites.
        return config('orbit_notifications.push_enabled') === true
            && app(MomentFcmTransport::class)->enabled();
    }

    public function options(): array
    {
        if(!$this->enabled()) return [];
        $fcm = config('orbit_moments.fcm', []);
        return array_intersect_key($fcm, array_flip([
            'project_id','api_key','sender_id','android_app_id','ios_app_id','ios_bundle_id',
        ]));
    }

    public function send(string $token,string $category): array
    {
        if(!$this->enabled())return ['status'=>'retry','code'=>'provider_off'];
        return app(MomentFcmTransport::class)
            ->sendOperationalResult($token,$category);
    }
}
