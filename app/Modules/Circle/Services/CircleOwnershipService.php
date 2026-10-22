<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactionally hands ownership to a current eligible member.
 * Opt-in feature: no routing is exposed until the exact succession policy
 * and UI confirmation are approved.
 */
final class CircleOwnershipService
{
    public function __construct(private readonly SocialGraphService $social) {}

    public function transfer(string $circleId, string $actor, string $recipient): array
    {
        abort_unless(config('orbit_circle.ownership_transfer_enabled') === true,404);
        return DB::transaction(function () use ($circleId,$actor,$recipient): array {
            $circle = DB::table('circles')->where('id',$circleId)
                ->whereNull('archived_at')->lockForUpdate()->first();
            if (!$circle || $circle->owner_id !== $actor || $actor === $recipient) abort(404);
            app(CircleTemporaryLifecycle::class)->assertWritable($circle);
            $owners = DB::table('circle_members')->where('circle_id',$circleId)
                ->where('role','owner')->whereNull('removed_at')->get();
            if ($owners->count() !== 1 || $owners[0]->user_id !== $actor) abort(409);
            $target = DB::table('circle_members')->where('circle_id',$circleId)
                ->where('user_id',$recipient)->whereNull('removed_at')->lockForUpdate()->first();
            if (!$target || !in_array($target->role,['admin','member'],true)
                || $this->social->isBlockedEitherWay($actor,$recipient)) abort(404);
            $eligible = OrbitUser::query()->whereIn('id',[$actor,$recipient])
                ->where('status','active')->whereNull('deletion_requested_at')->count();
            if ($eligible !== 2) abort(404);
            DB::table('circle_members')->where('circle_id',$circleId)
                ->where('user_id',$actor)->update(['role'=>'admin','updated_at'=>now()]);
            DB::table('circle_members')->where('circle_id',$circleId)
                ->where('user_id',$recipient)->update(['role'=>'owner','updated_at'=>now()]);
            DB::table('circles')->where('id',$circleId)->update([
                'owner_id'=>$recipient,'updated_at'=>now(),
            ]);
            DB::table('circles')->where('id',$circleId)
                ->increment('revision',1,['updated_at'=>now()]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string) Str::uuid7(),'circle_id'=>$circleId,
                'event_type'=>'circle.ownership_transferred','created_at'=>now(),
            ]);
            return ['circle_id'=>$circleId,'owner_id'=>$recipient];
        },3);
    }
}
