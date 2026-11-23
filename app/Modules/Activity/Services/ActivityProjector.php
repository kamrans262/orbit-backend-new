<?php

namespace App\Modules\Activity\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivityProjector
{
    public function __construct(private readonly ActivityPolicy $policy) {}

    /**
     * Bounded idempotent source-pointer indexing. Never projects content,
     * audiences, message metadata, coordinates, reports or SOS.
     */
    public function run(int $batch = 60): array
    {
        $stats = ['moments_added' => 0, 'check_ins_added' => 0, 'invalidated' => 0];
        if (!$this->policy->installed() || config('orbit_activity.enabled') !== true) {
            return $stats;
        }
        $batch = max(1, min(100, $batch));
        $sources = [
            'moment' => ['table' => 'moments', 'actor' => 'creator_id',
                'occurred' => 'published_at', 'revision' => 'audience_revision',
                'kind' => 'moment.published', 'count' => 'moments_added'],
            'check_in' => ['table' => 'checkins', 'actor' => 'creator_id',
                'occurred' => 'created_at', 'revision' => 'audience_revision',
                'kind' => 'check_in.published', 'count' => 'check_ins_added'],
        ];

        foreach ($sources as $type => $spec) {
            if (!$this->policy->sourceEnabled($type)) continue;
            $q = DB::table($spec['table'].' as s')
                ->whereNull('s.deleted_at')
                ->whereNotExists(fn($q) => $q->selectRaw('1')
                    ->from('activity_events as e')
                    ->where('e.source_type', $type)
                    ->whereColumn('e.source_id', 's.id'));
            if ($type === 'moment') {
                $q->where('s.state', 'published')
                    ->where('s.view_expires_at', '>', now());
            } else {
                $q->where(fn($q) => $q->whereNull('s.expires_at')
                    ->orWhere('s.expires_at', '>', now()));
            }
            $rows = $q->orderBy('s.'.$spec['occurred'])
                ->limit($batch)->get(['s.id','s.'.$spec['actor'],
                    's.'.$spec['revision'],'s.'.$spec['occurred']]);
            foreach ($rows as $row) {
                $stats[$spec['count']] += DB::table('activity_events')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'source_type' => $type, 'source_id' => $row->id,
                    'actor_user_id' => $row->{$spec['actor']},
                    'event_kind' => $spec['kind'],
                    'source_revision' => $row->{$spec['revision']},
                    'occurred_at' => $row->{$spec['occurred']},
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        // Reconcile only a bounded older slice per run. ACL revocation is
        // always enforced during reads even before this catches up.
        $candidates = DB::table('activity_events')
            ->whereNull('invalidated_at')->orderBy('updated_at')->limit($batch)->get();
        foreach ($candidates as $row) {
            $q = DB::table($row->source_type === 'moment' ? 'moments' : 'checkins')
                ->where('id', $row->source_id)->whereNull('deleted_at');
            if ($row->source_type === 'moment') {
                $q->where('state', 'published')->where('view_expires_at', '>', now());
            } else {
                $q->where(fn($q) => $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()));
            }
            if (!$q->exists()) {
                $stats['invalidated'] += DB::table('activity_events')
                    ->where('id', $row->id)->whereNull('invalidated_at')
                    ->update(['invalidated_at' => now(), 'updated_at' => now()]);
            } else {
                // Rotate verified rows so later rows cannot starve behind the
                // same first batch during repeated scheduled reconciliations.
                DB::table('activity_events')->where('id', $row->id)
                    ->update(['updated_at' => now()]);
            }
        }
        DB::table('activity_projection_runs')->insert([
            'id' => (string) Str::uuid7(),
            ...$stats, 'status' => 'completed', 'created_at' => now(),
        ]);
        return $stats;
    }
}
