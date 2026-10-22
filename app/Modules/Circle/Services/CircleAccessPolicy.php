<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

/**
 * A Circle is NOT a universal grant to messaging, presence or location.
 * Called by future feature consumers before reading, writing, subscribing,
 * or exporting Circle-scoped private data. All unconfigured grants fail closed.
 */
final class CircleAccessPolicy
{
    public function __construct(private readonly SocialGraphService $social) {}

    public function isMember(string $circleId, string $userId): bool
    {
        $circle = DB::table('circles')->where('id',$circleId)
            ->whereNull('archived_at')->first(['owner_id','expires_at','archived_at']);
        if (!$circle || app(CircleTemporaryLifecycle::class)->state($circle) === 'archive_due'
            || $this->social->isBlockedEitherWay($userId,$circle->owner_id)) return false;
        if (!OrbitUser::query()->whereKey($userId)->where('status','active')
            ->whereNull('deletion_requested_at')->exists()) return false;
        return DB::table('circle_members')->where('circle_id',$circleId)
            ->where('user_id',$userId)->whereNull('removed_at')->exists();
    }

    public function canAccessProtected(string $circleId, string $viewer,
        string $subject, string $capability): bool
    {
        if ($viewer === $subject || !in_array($capability, [
            'location','presence','moments','check_in','plans','status','ping','message',
        ],true)) return false;
        if (!$this->isMember($circleId,$viewer) || !$this->isMember($circleId,$subject)
            || $this->social->isBlockedEitherWay($viewer,$subject)) return false;
        $restricted = DB::table('circle_members')->where('circle_id',$circleId)
            ->where('user_id',$subject)->value('role');
        if ($restricted === 'restricted') return false;

        // No location, media, status or presence access can be inferred from
        // a Circle role or a saved (possibly stale) permission row. A future
        // scope-specific consent service must explicitly opt in and evaluate
        // Ghost, precision, duration, legal retention and subscription ACL.
        return false;
    }

    public function canSubscribe(string $circleId, string $userId): bool
    {
        // No FCM/WebSocket transport is approved/configured yet.
        return false;
    }
}
