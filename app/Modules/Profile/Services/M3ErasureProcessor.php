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
        // M15 has verified cascade FKs from private Plans, invitees,
        // reminders and notice events. Other modules remain separately
        // gated until their own erasure contracts are approved.
        'plans','plan_invitees','plan_reminder_preferences',
        'plan_reminder_jobs','plan_change_events',
        // M16 user FKs are registered, but incident-bearing accounts are
        // independently blocked by SosErasurePolicy until retention is reviewed.
        'sos_trusted_contacts','sos_incidents','sos_recipients',
        // M19 is sandbox-only; verified billing erasure is wired into DeleteOrbitUser.
        'billing_purchase_contexts','billing_subscriptions','billing_events','billing_entitlements',
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
            'unregistered_tables' => $this->unregisteredUserTables((string)$row->user_id),
        ];
    }

    /**
     * Reviewed schema registration is NOT approval to erase newer data.
     * A clean account may use the existing M3 scope even on a newer schema.
     * Any actual dependency outside that scope remains a hard block.
     * Unknown FK tables/columns block globally, even when empty.
     */
    private const EMPTY_SCOPE_ONLY = [
        'dashboard_preferences'=>['user_id'],
        'dashboard_card_dismissals'=>['user_id'],
        'location_shares'=>['owner_id'],
        'location_latest'=>['owner_id'],
        'location_consent_audits'=>['owner_id'],
        'pings'=>['sender_id','recipient_id'],
        'ping_responses'=>['responder_id'],
        'checkins'=>['creator_id'],
        'checkin_recipients'=>['recipient_user_id'],
        'messaging_device_keys'=>['user_id'],
        'messaging_one_time_prekeys'=>['claimed_by'],
        'messaging_members'=>['user_id'],
        'messaging_messages'=>['sender_id'],
        'messaging_reports'=>['reporter_id','subject_user_id'],
        'messaging_sender_key_batches'=>['sender_id'],
        'messaging_send_restrictions'=>['user_id'],
        'messaging_send_restriction_audits'=>['user_id'],
        'notification_inbox'=>['recipient_user_id'],
        'notification_device_tokens'=>['user_id'],
        'notification_context_mutes'=>['user_id'],
        'notification_circle_changes'=>['recipient_user_id'],
        'notification_category_preferences'=>['user_id'],
        'moderation_reports'=>['reporter_id','subject_user_id'],
        'moderation_cases'=>['subject_user_id'],
        'moderation_actions'=>['subject_user_id'],
        'moderation_appeals'=>['appellant_id'],
        'activity_events'=>['actor_user_id'],
        'relay_leases'=>['user_id'],
    ];

    /** @return list<string> */
    private function unregisteredUserTables(string $user): array
    {
        $actual=DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE()')
            ->where('REFERENCED_TABLE_NAME','orbit_users')->whereNotNull('REFERENCED_COLUMN_NAME')
            ->get(['TABLE_NAME','COLUMN_NAME','REFERENCED_COLUMN_NAME']);
        $blocked=[];
        foreach($actual as $fk){
            $table=$fk->TABLE_NAME;$column=$fk->COLUMN_NAME;
            if(in_array($table,self::KNOWN_USER_FK_TABLES,true))continue;
            if(!in_array($column,self::EMPTY_SCOPE_ONLY[$table]??[],true)||$fk->REFERENCED_COLUMN_NAME!=='id'){
                $blocked[]=$table;continue;
            }
            // Locking read (not a stale repeatable-read snapshot). The existing
            // user lock and FK integrity prevent a new direct child racing erasure.
            if(DB::table($table)->where($column,$user)->lockForUpdate()->first([$column]))$blocked[]=$table;
        }

        // Device-only descendants would otherwise disappear by cascade without
        // an approved later-module retention contract.
        $devices=fn()=>DB::table('devices')->where('user_id',$user)->select('id');
        foreach([
            'ping_receipts'=>['device_id'],
            'messaging_one_time_prekeys'=>['device_id'],
            'messaging_envelopes'=>['recipient_device_id'],
            'messaging_redactions'=>['recipient_device_id'],
            'messaging_sender_key_envelopes'=>['recipient_device_id'],
            'notification_jobs'=>['device_id'],
            'relay_leases'=>['device_id','peer_device_id'],
            'relay_reconciliations'=>['device_id'],
            'relay_receipts'=>['recipient_device_id'],
        ] as $table=>$columns){
            if(!\Illuminate\Support\Facades\Schema::hasTable($table))continue;
            foreach($columns as $column)
                if(DB::table($table)->whereIn($column,$devices())->lockForUpdate()->first([$column]))$blocked[]=$table;
        }

        // Non-FK pointers into the existing approved graph. Do not delete
        // another person's notice, evidence or projected record as a shortcut.
        if(\Illuminate\Support\Facades\Schema::hasTable('checkin_audience')&&
            DB::table('checkin_audience')->where('audience_type','selected_friend')->where('audience_id',$user)->lockForUpdate()->first())
            $blocked[]='checkin_audience';
        $sources=[
            'moments'=>DB::table('moments')->where('creator_id',$user)->select('id'),
            'moment_notification_inbox'=>DB::table('moment_notification_inbox')->where('recipient_user_id',$user)->select('id'),
            'friend_requests'=>DB::table('friend_requests')->where('sender_id',$user)->orWhere('recipient_id',$user)->select('id'),
            'circle_invites'=>DB::table('circle_invites')->where('inviter_id',$user)->orWhere('invitee_id',$user)->select('id'),
            'orbit_identity_events'=>DB::table('orbit_identity_events')->where('user_id',$user)->orWhereIn('device_id',$devices())->select('id'),
            'plans'=>DB::table('plans')->where('organizer_id',$user)->select('id'),
            'moment_reports'=>DB::table('moment_reports')->where('reporter_id',$user)->orWhereIn('moment_id',DB::table('moments')->where('creator_id',$user)->select('id'))->select('id'),
            'plan_change_events'=>DB::table('plan_change_events')->where('recipient_user_id',$user)->orWhereIn('plan_id',DB::table('plans')->where('organizer_id',$user)->select('id'))->select('id'),
        ];
        foreach(['notification_events'=>['source_id','target_id'],'notification_source_outbox'=>['source_id'],
            'notification_inbox'=>['target_id'],'activity_events'=>['source_id'],
            'moderation_cases'=>['source_report_id'],'moderation_case_sources'=>['source_report_id']] as $table=>$columns){
            if(!\Illuminate\Support\Facades\Schema::hasTable($table))continue;
            foreach($columns as $column){
                if(DB::table($table)->where($column,$user)->lockForUpdate()->first([$column]))$blocked[]=$table;
                foreach($sources as $source=>$query){
                    if(!\Illuminate\Support\Facades\Schema::hasTable($source))continue;
                    if(DB::table($table)->whereIn($column,clone $query)->lockForUpdate()->first([$column]))$blocked[]=$table;
                }
            }
        }
        return array_values(array_unique($blocked));
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
            if ($this->unregisteredUserTables((string)$user->id) !== []) {
                throw new DomainException('Unregistered user-owned tables block erasure; register their cleanup.');
            }
            if (app(\App\Modules\Realtime\Services\RealtimeAuthCleanup::class)->pendingForUser((string)$user->id)) {
                throw new DomainException('M25 provider metadata cleanup requires verification before erasure.');
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
