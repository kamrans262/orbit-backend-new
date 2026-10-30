<?php

namespace App\Modules\CheckIn\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Circle\Services\CircleConsentPolicy;
use App\Modules\Social\Services\SocialRelationPolicy;
use Illuminate\Support\Facades\DB;

final class CheckInAccessPolicy
{
    public function __construct(
        private readonly SocialRelationPolicy $social,
        private readonly CircleAccessPolicy $circles
    ) {}

    public function assertSession(string $user, string $device): void
    {
        abort_unless(OrbitUser::query()->whereKey($user)->where('status', 'active')
            ->whereNull('deletion_requested_at')->exists(), 403, 'Account unavailable.');
        abort_unless(OrbitDevice::query()->whereKey($device)->where('user_id', $user)
            ->where('trust_state', 'trusted')->whereNotNull('public_key')
            ->whereNull('revoked_at')->exists(), 403, 'Trusted device required.');
    }

    public function privacy(string $subject): array
    {
        $raw = DB::table('orbit_user_preferences')->where('user_id', $subject)->value('privacy');
        $value = $raw ? json_decode((string) $raw, true) : null;
        return is_array($value) ? $value : [];
    }

    public function scopeAvailable(string $creator, array $scope): bool
    {
        $type = $scope['type'];
        $id = $scope['id'] ?? null;
        if ($type === 'only_me') return $id === null;
        if ($type === 'friends') {
            return $id === null && ($this->privacy($creator)['activity_visibility'] ?? 'Nobody') === 'Friends';
        }
        if ($type === 'selected_friend') {
            return is_string($id) && $this->social->canInteract($id, $creator, 'activity');
        }
        if ($type === 'circle') {
            return is_string($id) && config('orbit_circle.consumer_enabled.check_in') === true
                && ($this->privacy($creator)['activity_visibility'] ?? 'Nobody') === 'My Circle'
                && $this->circles->isMember($id, $creator)
                && (($grant = DB::table('circle_permissions')->where('circle_id', $id)
                    ->where('scope_key', 'user:'.$creator)->where('subject_user_id', $creator)
                    ->where('permission_key','check_in')->first()) !== null)
                && app(CircleConsentPolicy::class)->consentCurrentlyValid($grant,'check_in');
        }
        return false;
    }

    public function allows(string $viewer, string $creator, array $scope): bool
    {
        if ($viewer === $creator) return true;
        $type = $scope['type'];
        $id = $scope['id'] ?? null;
        if ($type === 'friends' || ($type === 'selected_friend' && $id === $viewer)) {
            return $this->social->canInteract($viewer, $creator, 'activity');
        }
        if ($type === 'circle' && is_string($id)) {
            return $this->circles->canAccessProtected($id, $viewer, $creator, 'check_in');
        }
        return false;
    }

    public function canRead(object $checkin, string $viewer): bool
    {
        if ($checkin->deleted_at !== null ||
            ($checkin->expires_at !== null && \Illuminate\Support\Carbon::parse($checkin->expires_at)->lte(now()))) {
            return false;
        }
        if ($viewer === $checkin->creator_id) return true;
        $grant = DB::table('checkin_recipients')->where('checkin_id', $checkin->id)
            ->where('recipient_user_id', $viewer)
            ->where('audience_revision', $checkin->audience_revision)
            ->whereNull('revoked_at')->exists();
        if (!$grant) return false;
        $scopes = DB::table('checkin_audience')->where('checkin_id', $checkin->id)
            ->where('audience_revision', $checkin->audience_revision)->get();
        foreach ($scopes as $scope) {
            if ($this->allows($viewer, (string) $checkin->creator_id, [
                'type' => (string) $scope->audience_type,
                'id' => $scope->audience_id === '' ? null : (string) $scope->audience_id,
            ])) return true;
        }
        return false;
    }
}
