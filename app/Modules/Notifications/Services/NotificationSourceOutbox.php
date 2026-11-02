<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Durable event marker written INSIDE the caller's existing source transaction.
 * No private message body, location, relationship graph or device token stored.
 * Projection remains a separate bounded worker and may use legacy source
 * scanning for events that predate M14. Idempotency is enforced by the DB key.
 */
final class NotificationSourceOutbox
{
    private const SOURCES = [
        'ping','check_in','message','friend_request','friend_acceptance',
        'circle_invite','circle_acceptance',
    ];

    public function record(string $sourceType, string $sourceId): void
    {
        if (!in_array($sourceType, self::SOURCES, true)
            || !Str::isUuid($sourceId)
            || config('orbit_notifications.enabled') !== true
            || !Schema::hasTable('notification_source_outbox')) return;

        DB::table('notification_source_outbox')->insertOrIgnore([
            'id'=>(string)Str::uuid(),
            'source_type'=>$sourceType,
            'source_id'=>$sourceId,
            'created_at'=>now(),
        ]);
    }
}
