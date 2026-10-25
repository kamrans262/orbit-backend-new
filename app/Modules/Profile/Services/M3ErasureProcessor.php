<?php

namespace App\Modules\Profile\Services;

use App\Models\AdminUser;
use App\Models\OrbitUser;
use App\Modules\Identity\Services\DeleteOrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use DomainException;

/**
 * Retention-aware, manually authorized erasure of the CURRENT M1-M3 database
 * graph only. This is NOT a full future-product / backup erasure guarantee.
 * Future user-owned modules must supply erasure contracts before promotion.
 */
final class M3ErasureProcessor
{
    private const KNOWN_USER_FK_TABLES = [
        'user_identities', 'devices', 'orbit_sessions', 'orbit_user_preferences',
        'orbit_account_deletion_requests', 'orbit_data_retention_holds',
        'friend_requests', 'friendships', 'blocks', 'social_graph_revisions',
        'social_discovery_settings', 'social_friend_invites', 'social_contact_lookup_quotas',
        'social_graph_outbox',
        'circles', 'circle_members', 'circle_invites', 'circle_permissions',
        'circle_primary_selections',
        'media_objects','media_upload_sessions','media_audience_grants','media_references',
        'moments','moment_recipients','moment_reactions','moment_comments',
        'moment_views','moment_reports','moment_idempotency',
        'moment_people_tags',
        'moment_push_devices','moment_notification_inbox',
        'moment_notification_muted_circles',
    ];

    public function preview(string $requestId): array
    {
        $row = DB::table('orbit_account_deletion_requests')->where('request_id', $requestId)->first();
        if (!$row) {
            $receipt = DB::table('orbit_erasure_receipts')->where('request_id', $requestId)->first();
            if ($receipt) return ['status' => 'completed', 'scope' => $receipt->scope];
            throw new DomainException('Deletion request not found.');
        }
        $holds = DB::table('orbit_data_retention_holds')->where('user_id', $row->user_id)
            ->whereNull('released_at')->count();
        return [
            'status' => $row->status, 'request_id' => $row->request_id,
            'eligible' => $row->status === 'pending'
                && now()->gte($row->eligible_for_erasure_at),
            'active_holds' => $holds,
            'scope' => config('orbit_erasure.scope'),
            'execution_enabled' => config('orbit_erasure.execution_enabled') === true,
            'unregistered_tables' => $this->unregisteredUserTables(),
        ];
    }

    /** @return list<string> */
    private function unregisteredUserTables(): array
    {
        $actual = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('REFERENCED_TABLE_SCHEMA = DATABASE()')
            ->where('REFERENCED_TABLE_NAME', 'orbit_users')
            ->whereNotNull('REFERENCED_COLUMN_NAME')
            ->distinct()->pluck('TABLE_NAME')->all();

        return array_values(array_diff($actual, self::KNOWN_USER_FK_TABLES));
    }

    public function execute(string $requestId, AdminUser $operator, string $ticket): array
    {
        if (config('orbit_erasure.execution_enabled') !== true) {
            throw new DomainException('Erasure disabled. Review current product dependencies and enable explicitly.');
        }
        if (!$operator->canPerform('users.delete') ||
            !$operator->canPerform('deletions.process')) {
            throw new DomainException('Operator lacks the required independent deletion permissions.');
        }
        if (!Str::isUuid($requestId) ||
            !preg_match('/^[A-Za-z0-9._-]{10,64}$/D', $ticket)) {
            throw new DomainException('A valid request ID and non-sensitive ticket reference are required.');
        }

        return DB::transaction(function () use ($requestId, $operator, $ticket): array {
            $initial = DB::table('orbit_account_deletion_requests')
                ->where('request_id', $requestId)->first();
            if (!$initial) {
                $receipt = DB::table('orbit_erasure_receipts')
                    ->where('request_id', $requestId)->first();
                if ($receipt) return ['status' => 'completed', 'scope' => $receipt->scope];
                throw new DomainException('Deletion request not found.');
            }
            // Match request/cancellation lock ordering (user, then request).
            // Otherwise concurrent cancellation could deadlock or erase a
            // request already cancelled on another connection.
            $user = OrbitUser::query()->whereKey($initial->user_id)
                ->lockForUpdate()->firstOrFail();
            $record = DB::table('orbit_account_deletion_requests')
                ->where('request_id', $requestId)->where('user_id', $user->id)
                ->lockForUpdate()->first();
            if (!$record) throw new DomainException('Deletion request was changed concurrently.');
            if ($record->status !== 'pending' ||
                now()->lt($record->eligible_for_erasure_at)) {
                throw new DomainException('Deletion request is cancelled or still within the grace period.');
            }
            if (DB::table('orbit_data_retention_holds')->where('user_id', $record->user_id)
                ->whereNull('released_at')->exists()) {
                throw new DomainException('Active retention hold blocks erasure.');
            }
            if ($this->unregisteredUserTables() !== []) {
                throw new DomainException('Unregistered user-owned tables block erasure; register their cleanup.');
            }
            if (!$user->deletion_requested_at) {
                throw new DomainException('Account has no active deletion request.');
            }

            // M6 encrypted objects must first be physically deleted by the
            // separately idempotent storage worker. Never orphan ciphertext or
            // cascade-delete someone else's media during account erasure.
            if (\Illuminate\Support\Facades\Schema::hasTable('media_objects')) {
                $owned=DB::table('media_objects')->where('owner_user_id',$user->id);
                if ((clone $owned)->where('state','!=','deleted')->exists()
                    || (clone $owned)->whereNotNull('legal_hold_until')
                        ->where('legal_hold_until','>',now())->exists()) {
                    throw new DomainException('M6 media requires verified ciphertext purge before account erasure.');
                }
                // Every owned blob is confirmed deleted. Remove M7 references,
                // comments, receipts and reports while the account still exists.
                $m7Summary=app(\App\Modules\Moments\Services\MomentErasureService::class)
                    ->eraseUser($user->id);
                // M6 metadata may be removed only after M7's FK references are gone.
                $owned->delete();
            }

            if (!isset($m7Summary)) {
                $m7Summary=app(\App\Modules\Moments\Services\MomentErasureService::class)
                    ->eraseUser($user->id);
            }

            // Released holds can be removed, since they are user-linked and
            // must not prevent a genuine request from reaching the FK delete.
            DB::table('orbit_data_retention_holds')
                ->where('user_id', $user->id)->whereNotNull('released_at')->delete();

            $userReferenceHash = hash_hmac('sha256', $user->id, (string) config('app.key'));
            $summary = app(DeleteOrbitUser::class)->execute(
                $user->id, $operator, 'M3 identity/profile erasure ticket '.$ticket,
                null, $requestId,
            );

            if(array_sum($m7Summary)>0) $summary['moments_m7']=$m7Summary;

            // The request record is removed by FK cascade. Receipt contains
            // a one-way hashed reference, scope and counts, never raw user PII.
            DB::table('orbit_erasure_receipts')->insert([
                'request_id' => $requestId,
                'user_reference_hash' => $userReferenceHash,
                'scope' => 'identity_profile_social_m4',
                'removed_counts' => json_encode($summary, JSON_THROW_ON_ERROR),
                'operator_id' => $operator->id,
                'completed_at' => now(),
            ]);
            return ['status' => 'completed', 'scope' => 'identity_profile_social_m4'];
        }, 3);
    }
}
