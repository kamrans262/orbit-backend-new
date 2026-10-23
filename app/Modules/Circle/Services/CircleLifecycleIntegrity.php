<?php

namespace App\Modules\Circle\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invalidate remaining membership snapshots when a user is removed.
 * Called inside the identity deletion transaction; it never transfers ownership
 * or changes Circle sharing consent.
 */
final class CircleLifecycleIntegrity
{
    public function relationshipChanged(string $a, string $b, string $event): void
    {
        // If both accounts share a Circle, clients must not continue using
        // pre-block, pre-unfriend or pre-unblock membership snapshots.
        $ids=DB::table('circle_members as one')
            ->join('circle_members as two','one.circle_id','=','two.circle_id')
            ->where('one.user_id',$a)->whereNull('one.removed_at')
            ->where('two.user_id',$b)->whereNull('two.removed_at')
            ->pluck('one.circle_id')->unique()->all();
        if (in_array($event,['circle.relationship_blocked','circle.relationship_unfriended'],true)) {
            $pair=DB::table('circle_invites')->where('status','pending')
                ->where(function ($q) use ($a,$b): void {
                    $q->where(fn ($x) => $x->where('inviter_id',$a)->where('invitee_id',$b))
                      ->orWhere(fn ($x) => $x->where('inviter_id',$b)->where('invitee_id',$a));
                });
            $pendingCircles=(clone $pair)->pluck('circle_id')->all();
            $pair->update(['status'=>'revoked','pending_pair_key'=>null,'code_hash'=>null,
                'resolved_at'=>now(),'updated_at'=>now()]);
            $ids=array_values(array_unique(array_merge($ids,$pendingCircles)));
        }
        foreach ($ids as $id) {
            DB::table('circles')->where('id',$id)->increment('revision',1,['updated_at'=>now()]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                'event_type'=>$event,'created_at'=>now(),
            ]);
            app(CircleFirebaseBridge::class)->afterCommit($id);
        }
    }

    /**
     * Session/device revocation, logout-all, recovery and password reset must
     * update Firebase's per-session ACL, even if Circle membership is unchanged.
     * Gated OFF before the M5 rollout; never queries missing M5 tables on M4.
     */
    public function identityAccessChanged(string $userId): void
    {
        if (config('orbit_circle.enabled') !== true) return;
        DB::transaction(function () use ($userId): void {
            $ids = DB::table('circle_members')
                ->where('user_id', $userId)->whereNull('removed_at')
                ->pluck('circle_id')->unique()->all();
            foreach ($ids as $id) {
                DB::table('circles')->where('id', $id)
                    ->increment('revision', 1, ['updated_at' => now()]);
                DB::table('circle_event_outbox')->insert([
                    'id' => (string) Str::uuid7(), 'circle_id' => $id,
                    'event_type' => 'circle.session_access_changed',
                    'created_at' => now(),
                ]);
                app(CircleFirebaseBridge::class)->afterCommit($id);
            }
        }, 3);
    }

    public function beforeUserRemoval(string $userId): void
    {
        // A Circle owner requires separately authorized succession first.
        if (DB::table('circles')->where('owner_id',$userId)->exists()) {
            throw new \DomainException('Circle ownership must be resolved before account removal.');
        }
        $ids = DB::table('circle_members')
            ->where('user_id',$userId)->whereNull('removed_at')
            ->pluck('circle_id')->unique()->all();
        foreach ($ids as $id) {
            DB::table('circles')->where('id',$id)->increment('revision',1,['updated_at'=>now()]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                'event_type'=>'circle.member_account_removed','created_at'=>now(),
            ]);
            app(CircleFirebaseBridge::class)->afterCommit($id);
        }
    }
}
