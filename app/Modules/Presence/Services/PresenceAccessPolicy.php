<?php

namespace App\Modules\Presence\Services;

use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Recheck before EVERY scoped read. Membership is not itself consent.
 * Global Ghost overrides a stored per-Circle grant.
 */
final class PresenceAccessPolicy
{
    public function __construct(private readonly CircleAccessPolicy $circles) {}

    public function enabled(): bool
    {
        return config('orbit_presence.enabled') === true
            && config('orbit_circle.enabled') === true
            && config('orbit_circle.consumer_enabled.presence') === true;
    }

    public function userActive(string $user): bool
    {
        return OrbitUser::query()->whereKey($user)->where('status','active')
            ->whereNull('deletion_requested_at')->exists();
    }

    public function member(string $circle, string $user): bool
    {
        return $this->enabled() && $this->userActive($user)
            && $this->circles->isMember($circle,$user);
    }

    public function globalPrivacy(string $subject): array
    {
        $raw = DB::table('orbit_user_preferences')->where('user_id',$subject)->value('privacy');
        $parsed = is_string($raw) ? json_decode($raw,true) : null;
        return [
            'ghost_mode_enabled'=>(is_array($parsed) ? ($parsed['ghost_mode_enabled'] ?? true) : true) !== false,
            'presence_visibility'=>is_array($parsed) ? ($parsed['presence_visibility'] ?? 'Nobody') : 'Nobody',
        ];
    }

    public function canView(string $circle,string $viewer,string $subject): bool
    {
        if (!$this->enabled() || $viewer === $subject || !$this->userActive($viewer)
            || !$this->userActive($subject)) return false;
        $privacy = $this->globalPrivacy($subject);
        if ($privacy['ghost_mode_enabled'] || $privacy['presence_visibility'] !== 'My Circle') return false;
        // Existing M5 check enforces both memberships, block, roles, reviewed
        // consent, subject audience, and any grant expiry.
        return $this->circles->canAccessProtected($circle,$viewer,$subject,'presence');
    }
}
