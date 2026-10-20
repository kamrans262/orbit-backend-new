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
