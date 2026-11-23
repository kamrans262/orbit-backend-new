<?php

namespace App\Modules\Activity\Services;

use Illuminate\Support\Facades\DB;

final class ActivityFeedService
{
    public function __construct(private readonly ActivityPolicy $policy) {}

    /** Signed opaque viewer-bound pagination. A raw source ID never grants access. */
    private function cursor(string $viewer, object $row): string
    {
        $json = json_encode(['v' => 1, 'u' => $viewer,
            'at' => (string) $row->occurred_at, 'id' => (string) $row->id,
            'iat' => time()], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'
            .hash_hmac('sha256', $json, (string) config('app.key'));
    }

    private function decode(string $viewer, string $cursor): array
    {
        $parts = explode('.', $cursor);
        abort_unless(count($parts) === 2 && strlen($cursor) <= 500, 422,
            'Invalid Activity cursor.');
        $encoded = strtr($parts[0], '-_', '+/');
        $json = base64_decode($encoded, true);
        abort_unless(is_string($json) && ctype_xdigit($parts[1])
            && strlen($parts[1]) === 64
            && hash_equals(hash_hmac('sha256', $json, (string) config('app.key')),
                $parts[1]), 422, 'Invalid Activity cursor.');
        $payload = json_decode($json, true);
        abort_unless(is_array($payload) && ($payload['v'] ?? null) === 1
            && ($payload['u'] ?? null) === $viewer
            && is_string($payload['at'] ?? null)
            && preg_match('/^[0-9a-f-]{36}$/iD', (string)($payload['id'] ?? ''))
            && is_int($payload['iat'] ?? null)
            && time() - $payload['iat'] <= 86400
            && $payload['iat'] <= time() + 60, 409,
            'Activity cursor expired or belongs to another session.');
        return $payload;
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->id, 'kind' => $row->event_kind,
            'source' => ['type' => $row->source_type, 'id' => $row->source_id],
            'occurred_at' => $row->occurred_at,
            'source_revision' => (int) $row->source_revision,
        ];
    }

    public function page(string $viewer, int $limit, ?string $cursor = null): array
    {
        $this->policy->assertReady();
        $limit = max(1, min((int)config('orbit_activity.feed_page_max', 30), $limit));
        $scan = min((int)config('orbit_activity.candidate_scan_max', 240),
            max($limit * 8, 80));
        $q = DB::table('activity_events')->whereNull('invalidated_at');
        if ($cursor !== null) {
            $after = $this->decode($viewer, $cursor);
            $q->where(fn($q) => $q->where('occurred_at', '<', $after['at'])
                ->orWhere(fn($q) => $q->where('occurred_at', $after['at'])
                    ->where('id', '<', $after['id'])));
        }
        $rows = $q->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($scan)->get();
        $items = []; $last = null;
        foreach ($rows as $row) {
            $last = $row;
            if ($this->policy->allows($row, $viewer)) {
                $items[] = $this->present($row);
                if (count($items) === $limit) break;
            }
        }
        // The cursor can advance across invisible candidates without exposing
        // their identifiers. The next fetch rechecks every source permission.
        return ['items' => $items,
            'next_cursor' => $last !== null && (count($items) === $limit
                || count($rows) === $scan) ? $this->cursor($viewer, $last) : null,
            'sync_mode' => 'authoritative_refresh'];
    }

    public function item(string $viewer, string $id): array
    {
        $this->policy->assertReady();
        $row = DB::table('activity_events')->where('id', $id)->first();
        abort_unless($row && $this->policy->allows($row, $viewer),
            404, 'Activity unavailable.');
        return $this->present($row);
    }

    public function changes(string $viewer): array
    {
        $this->policy->assertReady();
        // Do NOT claim incremental tombstones without hooks for block,
        // deletion, grant revocation, expiry and policy changes. Stage 3
        // consumers must discard local items and fetch an authorized page.
        return ['items' => [], 'invalidated_ids' => [],
            'reset_required' => true, 'sync_mode' => 'authoritative_refresh'];
    }
}
