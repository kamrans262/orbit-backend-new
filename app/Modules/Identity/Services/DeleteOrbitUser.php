<?php

namespace App\Modules\Identity\Services;

use App\Models\AdminUser;
use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Permanently removes the user graph present in the current M1/M2 schema.
 * All dependent records and the append-only admin audit are committed together.
 * Revisit this service when new user-owned tables or external media are added.
 */
final class DeleteOrbitUser
{
    public function execute(string $userId, AdminUser $actor, string $reason, ?string $ip, ?string $requestId): array
    {
        return DB::transaction(function () use ($userId, $actor, $reason, $ip, $requestId): array {
            $user = OrbitUser::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if (DB::table('circles')->where('owner_id', $userId)->exists()) {
                throw new \DomainException('Circle ownership must be resolved before account removal.');
            }
            $identities = DB::table('user_identities')->where('user_id', $userId)
                ->get(['provider', 'provider_subject']);
            $deviceIds = DB::table('devices')->where('user_id', $userId)->pluck('id')->all();
            $sessionIds = DB::table('orbit_sessions')->where('user_id', $userId)->pluck('id')->all();

            // OTP challenges reference an HMAC of the verified contact rather
            // than the user UUID. Delete only this user's matching challenges.
            $otpSecret = hash('sha256', (string) config('app.key').'|orbit-otp-v1', true);
            $recipientHashes = $identities->map(function ($identity) use ($otpSecret): string {
                $channel = $identity->provider === 'phone' ? 'sms' : 'email';
                return hash_hmac('sha256', $channel.'|'.$identity->provider_subject, $otpSecret);
            })->unique()->values()->all();
            $removedChallenges = $recipientHashes
                ? DB::table('otp_challenges')->whereIn('recipient_hash', $recipientHashes)->delete()
                : 0;

            // These events intentionally have no foreign key, so the user ID
            // and device IDs must both be covered explicitly.
            $removedEvents = DB::table('orbit_identity_events')
                ->where(function ($query) use ($userId, $deviceIds): void {
                    $query->where('user_id', $userId);
                    if ($deviceIds) $query->orWhereIn('device_id', $deviceIds);
                })->delete();

            // Delete from leaf to root, instead of relying on DB cascading
            // alone. The schema's cascades remain a second integrity guarantee.
            $removedHistory = $sessionIds
                ? DB::table('refresh_token_history')->whereIn('session_id', $sessionIds)->delete()
                : 0;
            $removedSessions = DB::table('orbit_sessions')->where('user_id', $userId)->delete();
            $removedDevices = DB::table('devices')->where('user_id', $userId)->delete();
            $removedIdentities = DB::table('user_identities')->where('user_id', $userId)->delete();
            // Revoke surviving counterpart caches before removing graph rows;
            // a deleted account must never leave stale friend permissions.
            $counterparts = array_merge(
                DB::table('friend_requests')->where('sender_id', $userId)->pluck('recipient_id')->all(),
                DB::table('friend_requests')->where('recipient_id', $userId)->pluck('sender_id')->all(),
                DB::table('friendships')->where('user_low_id', $userId)->pluck('user_high_id')->all(),
                DB::table('friendships')->where('user_high_id', $userId)->pluck('user_low_id')->all(),
                DB::table('blocks')->where('blocker_id', $userId)->pluck('blocked_id')->all(),
                DB::table('blocks')->where('blocked_id', $userId)->pluck('blocker_id')->all()
            );
            $counterparts = array_values(array_diff(array_unique($counterparts), [$userId]));
            if ($counterparts) DB::table('social_graph_revisions')
                ->whereIn('user_id', $counterparts)
                ->increment('revision', 1, ['updated_at' => now()]);

            // M4 fanout is explicitly removed before the user row. FK cascades
            // remain defense-in-depth; retain only aggregate counts in audit.
            $socialRequests = DB::table('friend_requests')
                ->where('sender_id', $userId)->orWhere('recipient_id', $userId)->delete();
            $socialFriends = DB::table('friendships')
                ->where('user_low_id', $userId)->orWhere('user_high_id', $userId)->delete();
            $socialBlocks = DB::table('blocks')
                ->where('blocker_id', $userId)->orWhere('blocked_id', $userId)->delete();
            $socialRevisions = DB::table('social_graph_revisions')
                ->where('user_id', $userId)->delete();
            $socialInvites = DB::table('social_friend_invites')
                ->where('creator_id', $userId)->delete();
            $socialDiscovery = DB::table('social_discovery_settings')
                ->where('user_id', $userId)->delete();
            $socialContactQuotas = DB::table('social_contact_lookup_quotas')
                ->where('user_id', $userId)->delete();
            $socialEvents = DB::table('social_graph_outbox')
                ->where('actor_id', $userId)->orWhere('subject_id', $userId)->delete();
            $user->delete();

            // Preserve the immutable audit chain without retaining the deleted
            // user's raw UUID or verified contact information in this new entry.
            $summary = [
                'identities' => $removedIdentities, 'devices' => $removedDevices,
                'sessions' => $removedSessions, 'refresh_history' => $removedHistory,
                'otp_challenges' => $removedChallenges, 'identity_events' => $removedEvents,
                'friend_requests' => $socialRequests, 'friendships' => $socialFriends,
                'blocks' => $socialBlocks, 'social_revisions' => $socialRevisions,
                'social_invites' => $socialInvites,
                'social_discovery' => $socialDiscovery,
                'contact_quotas' => $socialContactQuotas,
                'social_event_outbox' => $socialEvents,
            ];
            $last = DB::table('admin_audit_log')->orderByDesc('created_at')->lockForUpdate()->first();
            $prev = $last?->event_hash;
            $target = 'deleted-user:'.hash_hmac('sha256', $userId, (string) config('app.key'));
            $beforeHash = hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
            $afterHash = hash('sha256', 'orbit-user-graph-deleted');
            $eventHash = hash('sha256', implode('|', [
                $prev ?? '', $actor->id, 'users.delete', $target, $beforeHash, $afterHash, $reason,
            ]));
            DB::table('admin_audit_log')->insert([
                'id' => (string) Str::uuid7(), 'actor_id' => $actor->id,
                'action' => 'users.delete', 'target' => $target,
                'reason' => $reason, 'ip_address' => $ip,
                'request_id' => $requestId ?: (string) Str::uuid7(),
                'before_hash' => $beforeHash, 'after_hash' => $afterHash,
                'previous_hash' => $prev, 'event_hash' => $eventHash,
                'created_at' => now(),
            ]);
            return $summary;
        }, 3);
    }
}
