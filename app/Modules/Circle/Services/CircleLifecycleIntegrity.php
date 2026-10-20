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
        foreach ($ids as $id) {
            DB::table('circles')->where('id',$id)->increment('revision',1,['updated_at'=>now()]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                'event_type'=>$event,'created_at'=>now(),
            ]);
        }
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
        }
    }
}
