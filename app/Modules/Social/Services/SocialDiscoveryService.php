<?php

namespace App\Modules\Social\Services;

use Illuminate\Support\Facades\DB;

final class SocialDiscoveryService
{
    public function settings(string $userId): array
    {
        $record = DB::table('social_discovery_settings')->where('user_id', $userId)->first();
        return [
            'discoverable' => $record ? (bool) $record->discoverable : true,
            'discoverable_by_name' => $record ? (bool) $record->discoverable_by_name : true,
            'discoverable_by_contacts' => $record ? (bool) $record->discoverable_by_contacts : false,
            'allow_friend_requests' => $record ? (bool) $record->allow_friend_requests : true,
        ];
    }

    public function update(string $userId, array $updates): array
    {
        if ($updates === []) return $this->settings($userId);
        DB::transaction(function () use ($userId, $updates): void {
            DB::table('social_discovery_settings')->insertOrIgnore([
                'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('social_discovery_settings')->where('user_id', $userId)
                ->update([...$updates, 'updated_at' => now()]);
            DB::table('orbit_identity_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'user_id' => $userId, 'device_id' => null,
                'action' => 'social.discovery_updated',
                'reason_code' => 'fields_updated', 'created_at' => now(),
            ]);
        });
        return $this->settings($userId);
    }

    public function canRequest(string $targetId): bool
    {
        $row = DB::table('social_discovery_settings')->where('user_id', $targetId)->first();
        return !$row || (bool) $row->allow_friend_requests;
    }

    public function visibleForSearch(string $viewer, string $subject): bool
    {
        if ($viewer === $subject) return false;
        $settings = $this->settings($subject);
        return $settings['discoverable'];
    }

    public function find(string $viewer, string $query, int $page, SocialGraphService $graph): array
    {
        $page = max(1, min(20, $page));
        $term = trim($query);
        if (mb_strlen($term) < 2) return ['items' => [], 'page' => $page, 'has_more' => false];
        $escaped = addcslashes($term, '%_\\');
        $users = DB::table('orbit_users as u')
            ->leftJoin('social_discovery_settings as ds', 'ds.user_id', '=', 'u.id')
            ->where('u.status', 'active')->whereNull('u.deletion_requested_at')
            ->where('u.id', '!=', $viewer)
            ->where(fn ($q) => $q->whereNull('ds.discoverable')->orWhere('ds.discoverable', true))
            ->where(function ($q) use ($escaped): void {
                $q->where('u.username', 'like', $escaped.'%')
                    ->orWhere(function ($name) use ($escaped): void {
                        $name->where(fn ($s) => $s->whereNull('ds.discoverable_by_name')
                            ->orWhere('ds.discoverable_by_name', true))
                            ->where(fn ($s) => $s->where('u.display_name', 'like', $escaped.'%')
                                ->orWhere('u.display_name', 'like', '% '.$escaped.'%'));
                    });
            })
            ->whereNotExists(function ($q) use ($viewer): void {
                $q->selectRaw('1')->from('blocks as b')
                    ->where(fn ($dir) => $dir
                        ->where('b.blocker_id', $viewer)->whereColumn('b.blocked_id', 'u.id')
                        ->orWhere(fn ($rev) => $rev->where('b.blocked_id', $viewer)
                            ->whereColumn('b.blocker_id', 'u.id')));
            })
            ->orderBy('u.username')
            ->offset(($page - 1) * 10)->limit(11)
            ->get(['u.id','u.display_name','u.username']);
        $hasMore = $users->count() > 10;
        $items = $users->take(10)->map(fn ($u) => [
            'id' => $u->id, 'display_name' => $u->display_name,
            'username' => $u->username,
            'relationship' => $graph->relationship($viewer, $u->id),
        ])->values()->all();
        return ['items' => $items, 'page' => $page, 'has_more' => $hasMore];
    }
}
