<?php

namespace App\Modules\Activity\Services;

use App\Modules\CheckIn\Services\CheckInAccessPolicy;
use App\Modules\Moments\Services\MomentPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActivityPolicy
{
    public function __construct(
        private readonly CheckInAccessPolicy $checkIns,
        private readonly MomentPolicy $moments
    ) {}

    public function installed(): bool
    {
        return Schema::hasTable('activity_events')
            && Schema::hasTable('activity_controls')
            && Schema::hasTable('activity_projection_runs');
    }

    public function assertReady(): void
    {
        abort_unless($this->installed(), 503, 'Activity migration required.');
        abort_unless(config('orbit_activity.enabled') === true, 503, 'Activity unavailable.');
    }

    public function assertTrusted(string $user, string $device): void
    {
        $this->checkIns->assertSession($user, $device);
    }

    public function sourceEnabled(string $kind): bool
    {
        if (!in_array($kind, ['moment', 'check_in'], true)
            || $this->installed() === false
            || config('orbit_activity.enabled') !== true) return false;
        // A disabled source module may still contain historical rows, but
        // Activity must not expose pointers while its own API is disabled.
        if ($kind === 'check_in' && config('orbit_check_in.enabled') !== true) return false;
        if ($kind === 'moment' && (config('orbit_moments.enabled') !== true
            || config('orbit_media.enabled') !== true)) return false;
        return DB::table('activity_controls')
            ->where('key', 'source.'.$kind)->where('enabled', true)->exists();
    }

    public function allows(object $event, string $viewer): bool
    {
        if ($event->invalidated_at !== null
            || !$this->sourceEnabled($event->source_type)) return false;
        // Account privacy/deletion and source-specific grants are checked
        // each time. A projected source reference is NEVER an access grant.
        $creator = DB::table('orbit_users')->where('id', $event->actor_user_id)
            ->where('status', 'active')->whereNull('deletion_requested_at')->exists();
        if (!$creator) return false;

        if ($event->source_type === 'moment') {
            $moment = DB::table('moments')->where('id', $event->source_id)->first();
            return $moment !== null
                && $moment->creator_id === $event->actor_user_id
                && $this->moments->context($moment, $viewer) !== null;
        }
        if ($event->source_type === 'check_in') {
            $checkIn = DB::table('checkins')->where('id', $event->source_id)->first();
            return $checkIn !== null
                && $checkIn->creator_id === $event->actor_user_id
                && $this->checkIns->canRead($checkIn, $viewer);
        }
        return false;
    }
}
