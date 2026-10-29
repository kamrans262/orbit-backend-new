<?php

namespace App\Modules\Ping\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Modules\Circle\Services\CircleAccessPolicy;
use App\Modules\Social\Services\SocialRelationPolicy;

final class PingAccessPolicy
{
    public function __construct(
        private readonly SocialRelationPolicy $social,
        private readonly CircleAccessPolicy $circles
    ) {}

    public function assertSession(string $userId, string $deviceId): void
    {
        abort_unless(OrbitUser::query()->whereKey($userId)->where('status', 'active')
            ->whereNull('deletion_requested_at')->exists(), 403, 'Account unavailable.');
        abort_unless(OrbitDevice::query()->whereKey($deviceId)->where('user_id', $userId)
            ->where('trust_state', 'trusted')->whereNotNull('public_key')
            ->whereNull('revoked_at')->exists(), 403, 'Trusted device required.');
    }

    public function allows(string $sender, string $recipient, string $context, ?string $circle): bool
    {
        if ($sender === $recipient) return false;
        if ($context === 'friend') {
            return $circle === null && $this->social->canInteract($sender, $recipient, 'ping');
        }
        if ($context === 'circle') {
            return $circle !== null
                && $this->circles->canAccessProtected($circle, $sender, $recipient, 'ping');
        }
        return false;
    }

    public function assertPair(object $row): void
    {
        // An old invitation, a previous friendship or an old receipt must never
        // survive the currently valid recipient privacy decision.
        abort_unless($this->allows((string) $row->sender_id, (string) $row->recipient_id,
            (string) $row->context_type, $row->context_id), 404, 'Ping unavailable.');
    }

    public function readReceiptsAllowed(string $recipient): bool
    {
        $raw = \Illuminate\Support\Facades\DB::table('orbit_user_preferences')
            ->where('user_id', $recipient)->value('privacy');
        $privacy = $raw ? json_decode((string) $raw, true) : null;
        return is_array($privacy) && ($privacy['read_receipts_enabled'] ?? false) === true;
    }
}
