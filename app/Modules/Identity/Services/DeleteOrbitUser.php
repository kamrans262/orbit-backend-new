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
            $user->delete();

            // Preserve the immutable audit chain without retaining the deleted
            // user's raw UUID or verified contact information in this new entry.
            $summary = [
                'identities' => $removedIdentities, 'devices' => $removedDevices,
                'sessions' => $removedSessions, 'refresh_history' => $removedHistory,
                'otp_challenges' => $removedChallenges, 'identity_events' => $removedEvents,
                'friend_requests' => $socialRequests, 'friendships' => $socialFriends,
                'blocks' => $socialBlocks, 'social_revisions' => $socialRevisions,
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
