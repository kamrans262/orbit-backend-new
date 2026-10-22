<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reversible, owner-only Circle archive. No irreversible group deletion.
 * Existing membership stays intact; access is closed while archived.
 */
final class CircleArchiveService
{
    private function actor(string $id, string $owner, bool $archived): object
    {
        $row=DB::table('circles')->where('id',$id)->lockForUpdate()->first();
        if (!$row || $row->owner_id !== $owner || ($row->archived_at !== null) !== $archived) abort(404);
        $valid=OrbitUser::query()->whereKey($owner)->where('status','active')
            ->whereNull('deletion_requested_at')->exists()
            && DB::table('circle_members')->where('circle_id',$id)
                ->where('user_id',$owner)->where('role','owner')
                ->whereNull('removed_at')->exists();
        if (!$valid) abort(404);
        return $row;
    }
    private function changed(string $id, string $action): void
    {
        DB::table('circles')->where('id',$id)->increment('revision',1,['updated_at'=>now()]);
        DB::table('circle_event_outbox')->insert([
            'id'=>(string) Str::uuid7(),'circle_id'=>$id,
            'event_type'=>$action,'created_at'=>now(),
        ]);
    }
    public function archive(string $id, string $owner): array
    {
        abort_unless(config('orbit_circle.archive_enabled') === true,404);
        return DB::transaction(function () use ($id,$owner): array {
            $this->actor($id,$owner,false);
            DB::table('circles')->where('id',$id)->update([
                'archived_at'=>now(),'updated_at'=>now(),
            ]);
            // No stale issued codes or pending approvals survive archive.
            DB::table('circle_invites')->where('circle_id',$id)->where('status','pending')
                ->update(['status'=>'revoked','code_hash'=>null,
                    'pending_pair_key'=>null,'resolved_at'=>now(),'updated_at'=>now()]);
            $this->changed($id,'circle.archived');
            return ['id'=>$id,'archived'=>true];
        },3);
    }
    public function restore(string $id, string $owner): array
    {
        abort_unless(config('orbit_circle.archive_enabled') === true
            || config('orbit_circle.temporary_circles_enabled') === true,404);
        return DB::transaction(function () use ($id,$owner): array {
            $this->actor($id,$owner,true);
            DB::table('circles')->where('id',$id)->update([
                'archived_at'=>null,'expires_at'=>null,'expiry_notified_at'=>null,'updated_at'=>now(),
            ]);
            $this->changed($id,'circle.restored');
            return ['id'=>$id,'archived'=>false,'sharing_enabled'=>false];
        },3);
    }
    public function archived(string $owner, int $page): array
    {
        abort_unless(config('orbit_circle.archive_enabled') === true
            || config('orbit_circle.temporary_circles_enabled') === true,404);
        $rows=DB::table('circles')->where('owner_id',$owner)->whereNotNull('archived_at')
            ->orderByDesc('archived_at')->paginate(20,['id','name','archived_at'],'page',$page);
        return ['items'=>$rows->items(),'page'=>$rows->currentPage(),
            'has_more'=>$rows->hasMorePages()];
    }
}
