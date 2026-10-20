<?php

namespace App\Modules\Social\Services;

use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;

/**
 * Single server-side decision point for upcoming social consumers.
 * Fail closed on missing consent, missing Circle membership evaluators,
 * inactive/deleting accounts, or a block in EITHER direction.
 *
 * This service does not grant SOS access or perform delivery; SOS has a
 * separate safety policy pending approval.
 */
final class SocialRelationPolicy
{
    public function __construct(private readonly SocialGraphService $graph) {}

    public function canInteract(string $viewer, string $subject, string $capability): bool
    {
        if ($viewer === $subject) return false;
        $active = OrbitUser::query()->whereIn('id', [$viewer, $subject])
            ->where('status', 'active')->whereNull('deletion_requested_at')->count();
        if ($active !== 2 || $this->graph->isBlockedEitherWay($viewer, $subject)) return false;

        // Exact-username lookup and minimal public previews are handled in
        // SocialGraphController; do not treat preview visibility as permission
        // to see activity, presence, location, messages, or private media.
        $row = DB::table('orbit_user_preferences')->where('user_id', $subject)->value('privacy');
        $privacy = $row ? json_decode((string) $row, true) : null;
        $privacy = is_array($privacy) ? $privacy : [];

        if ($capability === 'location') {
            return ($privacy['ghost_mode_enabled'] ?? true) === false
                && ($privacy['location_sharing_enabled'] ?? false) === true
                && ($privacy['location_visibility'] ?? 'Nobody') === 'Friends'
                && $this->graph->isFriend($viewer, $subject);
        }

        $field = match ($capability) {
            'presence' => 'presence_visibility',
            'activity' => 'activity_visibility',
            'ping' => 'ping_eligibility',
            'message' => 'message_eligibility',
            'moment' => 'moment_default_audience',
            default => null,
        };
        if ($field === null) return false;
        $audience = $privacy[$field] ?? 'Nobody';

        // "My Circle" and "Selected People" are deliberately unsupported
        // until M5 membership and per-person consent records exist.
        return $audience === 'Friends'
            && $this->graph->isFriend($viewer, $subject);
    }
}
