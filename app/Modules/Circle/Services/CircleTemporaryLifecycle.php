<?php

namespace App\Modules\Circle\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Non-destructive implementation of the documented M5 temporary Circle phases:
 * active -> 72-hour read-only grace -> cold archive.
 *
 * Cold-archive rows are NEVER purged by this service. 30-day post-archive
 * eligibility is reporting-only until product/legal retention approval.
 * Existing members can always revoke their own permission before archival.
 */
final class CircleTemporaryLifecycle
{
    private const GRACE_HOURS = 72;
    private const COLD_DAYS = 30;

    public function state(object $circle): string
    {
        if ($circle->archived_at !== null) return 'archived';
        if ($circle->expires_at === null) return 'active';
        $expiry = Carbon::parse($circle->expires_at);
        if (now()->lt($expiry)) return 'active';
        return now()->lt($expiry->copy()->addHours(self::GRACE_HOURS))
            ? 'read_only' : 'archive_due';
    }

    public function assertWritable(object $circle): void
    {
        if ($this->state($circle) !== 'active') {
            abort(409, 'Temporary Circle is read-only or archived.');
        }
    }

    /** Scheduler safe: bounded, lock-protected, repeatable and never destructive. */
    public function archiveDue(int $limit = 100): int
    {
        if (config('orbit_circle.temporary_circles_enabled') !== true
            || config('orbit_circle.enabled') !== true) return 0;
        $ids = DB::table('circles')->whereNull('archived_at')
            ->whereNotNull('expires_at')
            ->where('expires_at','<=',now()->subHours(self::GRACE_HOURS))
            ->orderBy('expires_at')->limit(min(max($limit,1),1000))->pluck('id');
        $count=0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id,&$count): void {
                $row=DB::table('circles')->where('id',$id)->lockForUpdate()->first();
                if (!$row || $this->state($row) !== 'archive_due') return;
                DB::table('circles')->where('id',$id)->update([
                    'archived_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('circle_invites')->where('circle_id',$id)
                    ->where('status','pending')->update([
                        'status'=>'revoked','code_hash'=>null,
                        'pending_pair_key'=>null,'resolved_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                DB::table('circles')->where('id',$id)->increment('revision',1);
                DB::table('circle_event_outbox')->insert([
                    'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                    'event_type'=>'circle.temporary_archived','created_at'=>now(),
                ]);
                app(CircleFirebaseBridge::class)->afterCommit($id);
                $count++;
            },3);
        }
        return $count;
    }

    /**
     * Enqueue a privacy-minimal 24h reminder event once per temporary Circle.
     * Delivery is intentionally delegated to an approved notification transport;
     * Circle names, members and coordinates are never added to the outbox.
     */
    public function queueExpiryNotices(int $limit = 100): int
    {
        if (config('orbit_circle.temporary_circles_enabled') !== true
            || config('orbit_circle.enabled') !== true) return 0;
        $ids=DB::table('circles')->whereNull('archived_at')
            ->whereNull('expiry_notified_at')->whereNotNull('expires_at')
            ->where('expires_at','>',now())
            ->where('expires_at','<=',now()->addHours(24))
            ->orderBy('expires_at')->limit(min(max($limit,1),1000))->pluck('id');
        $count=0;
        foreach($ids as $id) {
            DB::transaction(function () use ($id,&$count): void {
                $row=DB::table('circles')->where('id',$id)->lockForUpdate()->first();
                if (!$row || $row->expiry_notified_at !== null ||
                    $this->state($row) !== 'active' ||
                    Carbon::parse($row->expires_at)->gt(now()->addHours(24))) return;
                DB::table('circles')->where('id',$id)
                    ->update(['expiry_notified_at'=>now(),'updated_at'=>now()]);
                DB::table('circle_event_outbox')->insert([
                    'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                    'event_type'=>'circle.temporary_expiring','created_at'=>now(),
                ]);
                $count++;
            },3);
        }
        return $count;
    }

    private function purgeCandidates(): \Illuminate\Database\Query\Builder
    {
        return DB::table('circles')
            ->whereNotNull('expires_at')
            ->whereNotNull('archived_at')
            ->whereNull('retention_hold_at')
            ->where('archived_at','<=',now()->subDays(self::COLD_DAYS));
    }

    /**
     * An explicitly invoked, audited purge. Not scheduled. A legal hold or
     * changed eligibility must reject the operation under the row lock.
     * Only temporary Circles beyond their cold-archive retention are touched.
     * No production execution is performed by normal CI or deployment.
     */
    public function purgeReviewed(int $limit, string $confirmation): int
    {
        if ($confirmation !== 'PURGE_REVIEWED_TEMPORARY_CIRCLES'
            || config('orbit_circle.enabled') !== true
            || config('orbit_circle.temporary_circles_enabled') !== true
            || config('orbit_circle.retention_policy_approved') !== true
            || config('orbit_circle.retention_purge_enabled') !== true) {
            throw new \DomainException('Temporary Circle retention purge is not authorized.');
        }
        $ids=$this->purgeCandidates()->orderBy('archived_at')
            ->limit(min(max($limit,1),100))->pluck('id');
        $purged=0;
        foreach($ids as $id) {
            DB::transaction(function () use ($id,&$purged): void {
                $row=DB::table('circles')->where('id',$id)->lockForUpdate()->first();
                if (!$row || $row->expires_at===null
                    || $row->archived_at===null || $row->retention_hold_at!==null
                    || Carbon::parse($row->archived_at)->gt(now()->subDays(self::COLD_DAYS))) {
                    return;
                }
                $hash=hash_hmac('sha256','circle-retention|'.$row->id,
                    (string)config('app.key'));
                // Write the non-identifying audit in the same transaction as
                // FK-enforced deletion. Duplicate retries become harmless.
                DB::table('circle_retention_audits')->insert([
                    'id'=>(string)Str::uuid7(),
                    'circle_hash'=>$hash,
                    'action'=>'temporary_circle_purged',
                    'executed_at'=>now(),
                ]);
                DB::table('circles')->where('id',$id)->delete();
                $purged++;
            },3);
        }
        return $purged;
    }

    /** Scope only: no cold-archive deletion until separately approved. */
    public function eligibleForRetentionReviewCount(): int
    {
        return $this->purgeCandidates()->count();
    }
}
