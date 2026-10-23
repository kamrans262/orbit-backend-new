<?php

namespace App\Modules\Circle\Services;

use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, Free-tier-safe routing contract for a future SOS consumer.
 * It does NOT send an alert or read/share any location. Only a dedicated SOS
 * action with independently tested user initiation can dispatch.
 */
final class CircleSosRoutingPolicy
{
    public function __construct(
        private readonly CircleAccessPolicy $access,
        private readonly SocialGraphService $social,
    ) {}

    public function defaultResponders(string $circleId, string $senderId): array
    {
        if (!$this->access->isMember($circleId, $senderId)) {
            abort(404, 'Circle unavailable.');
        }
        $members = DB::table('circle_members as m')
            ->join('orbit_users as u', 'u.id', '=', 'm.user_id')
            ->where('m.circle_id', $circleId)
            ->whereNull('m.removed_at')
            ->where('u.status', 'active')->whereNull('u.deletion_requested_at')
            ->where('m.user_id', '!=', $senderId)
            ->orderBy('m.joined_at')->orderBy('m.user_id')
            ->pluck('m.user_id');
        $recipients = [];
        foreach ($members as $personId) {
            if (!$this->social->isBlockedEitherWay($senderId, $personId)
                && $this->access->isMember($circleId, $personId)) {
                $recipients[] = $personId;
            }
        }
        // Ghost hides ordinary presence/location, not a sender-initiated SOS.
        // No custom paid prioritization is used for the default safety path.
        return [
            'mode' => 'simultaneous',
            'recipient_user_ids' => $recipients,
            'paid_entitlement_required' => false,
            'alert_dispatched' => false,
        ];
    }

    public function customResponderOrder(): never
    {
        // Never allow client-provided order to bypass subscription/entitlement.
        throw new \DomainException(
            'Custom SOS order requires a verified server entitlement and downstream SOS service.'
        );
    }
}
