<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;

/** Optional primary Circle. Always resolves against current server membership. */
final class CirclePrimaryService
{
    public function __construct(private readonly CircleAccessPolicy $acl) {}

    public function current(string $viewer): array
    {
        $selected=DB::table('circle_primary_selections')
            ->where('user_id',$viewer)->value('circle_id');
        if ($selected && $this->acl->isMember($selected,$viewer)) {
            return ['circle_id'=>$selected,'fallback'=>false];
        }
        $candidates=DB::table('circle_members as m')
            ->join('circles as c','c.id','=','m.circle_id')
            ->where('m.user_id',$viewer)->whereNull('m.removed_at')
            ->whereNull('c.archived_at')
            ->orderBy('c.created_at')->orderBy('c.id')
            ->pluck('c.id');
        foreach ($candidates as $id) {
            if ($this->acl->isMember($id,$viewer)) {
                return ['circle_id'=>$id,'fallback'=>true];
            }
        }
        return ['circle_id'=>null,'fallback'=>false];
    }

    public function choose(string $viewer, ?string $circleId): array
    {
        return DB::transaction(function () use ($viewer,$circleId): array {
            $person=OrbitUser::query()->whereKey($viewer)->lockForUpdate()->first();
            if (!$person || $person->status !== 'active' || $person->deletion_requested_at) abort(403);
            if ($circleId === null) {
                DB::table('circle_primary_selections')->where('user_id',$viewer)->delete();
                return $this->current($viewer);
            }
            if (!$this->acl->isMember($circleId,$viewer)) abort(404,'Circle unavailable.');
            DB::table('circle_primary_selections')->updateOrInsert(
                ['user_id'=>$viewer],['circle_id'=>$circleId,'updated_at'=>now()]
            );
            return ['circle_id'=>$circleId,'fallback'=>false];
        },3);
    }
}
