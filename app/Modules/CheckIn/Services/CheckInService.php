<?php

namespace App\Modules\CheckIn\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CheckInService
{
    public const PRESETS = ['home','work','driving','gym','travel','coffee','eating','chill','sleep'];

    public function __construct(private readonly CheckInAccessPolicy $policy) {}

    public function installed(): bool
    {
        return Schema::hasTable('checkins') && Schema::hasTable('checkin_audience')
            && Schema::hasTable('checkin_recipients');
    }

    public function assertReady(): void
    {
        abort_unless($this->installed(), 503, 'Check-In database setup required.');
        abort_unless(config('orbit_check_in.enabled') === true, 503, 'Check-In service is not enabled.');
    }

    public function normalizeAudience(array $input): array
    {
        abort_if(!$input || count($input) > (int) config('orbit_check_in.max_audience_scopes', 25),
            422, 'Choose a valid Check-In audience.');
        $unique = [];
        foreach ($input as $scope) {
            $type = (string) ($scope['type'] ?? '');
            $id = $scope['id'] ?? null;
            abort_unless(in_array($type, ['only_me','friends','selected_friend','circle'], true)
                && in_array($type, ['selected_friend','circle'], true) === ($id !== null)
                && ($id === null || (is_string($id) && Str::isUuid($id))),
                422, 'Invalid Check-In audience.');
            $key = $type.':'.($id ?? '');
            $unique[$key] = ['type' => $type, 'id' => $id];
        }
        abort_if(isset($unique['only_me:']) && count($unique) > 1, 422,
            'Only Me cannot be combined with another audience.');
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    private function assertScope(string $creator, array $scopes): void
    {
        foreach ($scopes as $scope) {
            abort_unless($this->policy->scopeAvailable($creator, $scope), 422,
                'This audience is unavailable under current privacy settings.');
        }
    }

    private function candidates(string $creator, array $scope): array
    {
        if ($scope['type'] === 'selected_friend') return [$scope['id']];
        if ($scope['type'] === 'friends') {
            return DB::table('friendships')->where('user_low_id', $creator)
                ->pluck('user_high_id')->merge(
                    DB::table('friendships')->where('user_high_id', $creator)
                        ->pluck('user_low_id')
                )->all();
        }
        if ($scope['type'] === 'circle') {
            return DB::table('circle_members')->where('circle_id', $scope['id'])
                ->whereNull('removed_at')->where('user_id', '!=', $creator)
                ->pluck('user_id')->all();
        }
        return [];
    }

    private function snapshot(string $creator, array $scopes): array
    {
        $ids = [];
        foreach ($scopes as $scope) {
            foreach ($this->candidates($creator, $scope) as $candidate) {
                if ($candidate === $creator) continue;
                $ids[(string) $candidate] = true;
                abort_if(count($ids) > (int) config('orbit_check_in.max_snapshot_recipients', 1000),
                    422, 'This Check-In audience is too large.');
            }
        }
        $allowed = [];
        foreach (array_keys($ids) as $candidate) {
            foreach ($scopes as $scope) {
                if ($this->policy->allows($candidate, $creator, $scope)) {
                    $allowed[] = $candidate;
                    break;
                }
            }
        }
        return $allowed;
    }

    private function writeAudience(string $checkin, int $revision, array $scopes, array $recipients): void
    {
        foreach ($scopes as $scope) {
            DB::table('checkin_audience')->insert([
                'checkin_id' => $checkin, 'audience_revision' => $revision,
                'audience_type' => $scope['type'], 'audience_id' => $scope['id'] ?? '',
            ]);
        }
        foreach ($recipients as $recipient) {
            DB::table('checkin_recipients')->insert([
                'checkin_id' => $checkin, 'recipient_user_id' => $recipient,
                'audience_revision' => $revision, 'created_at' => now(),
            ]);
        }
    }

    private function assertExpiry(?string $expiry): ?string
    {
        if ($expiry === null) return null; // PRD specifies optional expiry, no invented default.
        $date = Carbon::parse($expiry);
        abort_unless($date->isFuture() && $date->lte(now()->addDays(
            max(1, (int) config('orbit_check_in.max_expiry_days', 30)))), 422, 'Invalid expiry.');
        return $date->utc()->toDateTimeString();
    }

    public function create(string $creator, array $input): array
    {
        $this->assertReady();
        $scopes = $this->normalizeAudience($input['audience']);
        $type = $input['type'];
        $text = $type === 'custom' ? trim($input['text'] ?? '') : null;
        abort_if($type === 'custom' && $text === '', 422, 'Custom Check-In cannot be blank.');
        // Location is not a bypass into M10: reject all references until verified
        // by M10 and bound to the chosen audience's live precision consent.
        abort_if(isset($input['place_ref']) && $input['place_ref'] !== null,
            422, 'Location attachment is not available.');
        $expiry = $this->assertExpiry($input['expires_at'] ?? null);
        $fingerprint = hash('sha256', json_encode([$type,$text,$scopes,$expiry,null], JSON_UNESCAPED_UNICODE));
        $existing = DB::table('checkins')->where('creator_id', $creator)
            ->where('client_id', $input['client_id'])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->payload_hash, $fingerprint), 409,
                'Client request ID conflict.');
            return [$this->present($existing, $creator), false];
        }
        $this->assertScope($creator, $scopes);
        $limitKey = 'checkin:send:'.hash('sha256', $creator);
        abort_if(RateLimiter::tooManyAttempts($limitKey,
            max(1, (int) config('orbit_check_in.sender_per_minute', 10))),
            429, 'Too many Check-Ins. Please try again.');
        $accepted = DB::transaction(function () use ($creator, $input, $type, $text, $scopes, $expiry, $fingerprint): bool {
            $existing = DB::table('checkins')->where('creator_id', $creator)
                ->where('client_id', $input['client_id'])->first();
            if ($existing) return false;
            $this->assertScope($creator, $scopes);
            $recipients = $this->snapshot($creator, $scopes);
            $id = (string) Str::uuid();
            $created = DB::table('checkins')->insertOrIgnore([
                'id' => $id, 'creator_id' => $creator, 'client_id' => $input['client_id'],
                'payload_hash' => $fingerprint, 'type' => $type, 'text' => $text,
                'place_ref' => null, 'audience_revision' => 1,
                'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
            if ($created) $this->writeAudience($id, 1, $scopes, $recipients);
            return $created;
        });
        $row = DB::table('checkins')->where('creator_id', $creator)
            ->where('client_id', $input['client_id'])->first();
        abort_unless($row && hash_equals($row->payload_hash, $fingerprint), 409,
            'Client request ID conflict.');
        if ($accepted) RateLimiter::hit($limitKey, 60);
        return [$this->present($row, $creator), $accepted];
    }

    public function find(string $id, string $viewer): object
    {
        $this->assertReady();
        $row = DB::table('checkins')->where('id', $id)->first();
        abort_unless($row && $this->policy->canRead($row, $viewer),
            404, 'Check-In unavailable.');
        return $row;
    }

    private function ownerRecord(string $id, string $owner, bool $lock = false): object
    {
        $query = DB::table('checkins')->where('id', $id)->where('creator_id', $owner);
        $row = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($row, 404, 'Check-In unavailable.');
        return $row;
    }

    public function page(string $viewer, string $kind, int $limit): array
    {
        $this->assertReady();
        $query = $kind === 'mine' ? DB::table('checkins')->where('creator_id', $viewer)
            : DB::table('checkins as c')->join('checkin_recipients as r',
                'r.checkin_id', '=', 'c.id')
                ->where('r.recipient_user_id', $viewer)->whereNull('r.revoked_at')
                ->whereColumn('r.audience_revision', 'c.audience_revision')
                ->whereNull('c.deleted_at')->where(fn ($q) =>
                    $q->whereNull('c.expires_at')->orWhere('c.expires_at', '>', now()))
                ->select('c.*');
        $prefix = $kind === 'mine' ? '' : 'c.';
        $records = $query->orderByDesc($prefix.'created_at')->orderByDesc($prefix.'id')
            ->cursorPaginate(min(max(1,$limit), (int) config('orbit_check_in.page_size_max', 30)));
        $items = [];
        foreach ($records->items() as $row) {
            if ($kind === 'mine' || $this->policy->canRead($row, $viewer)) {
                $items[] = $this->present($row, $viewer);
            }
        }
        return ['items' => $items, 'next_cursor' => $records->nextCursor()?->encode()];
    }

    public function changeAudience(string $id, string $owner, array $input): array
    {
        $this->assertReady();
        $scopes = $this->normalizeAudience($input['audience']);
        return DB::transaction(function () use ($id, $owner, $input, $scopes): array {
            $row = $this->ownerRecord($id, $owner, true);
            abort_if($row->deleted_at !== null ||
                ($row->expires_at !== null && Carbon::parse($row->expires_at)->lte(now())),
                409, 'This Check-In can no longer be changed.');
            abort_unless((int) $row->audience_revision === (int) $input['expected_revision'],
                409, 'Check-In audience changed. Refresh first.');
            $this->assertScope($owner, $scopes);
            $recipients = $this->snapshot($owner, $scopes);
            $revision = (int) $row->audience_revision + 1;
            DB::table('checkin_recipients')->where('checkin_id', $id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->writeAudience($id, $revision, $scopes, $recipients);
            DB::table('checkins')->where('id', $id)->update([
                'audience_revision' => $revision, 'updated_at' => now(),
            ]);
            return $this->present($this->ownerRecord($id, $owner), $owner);
        });
    }

    public function changeExpiry(string $id, string $owner, ?string $expiry): array
    {
        $this->assertReady();
        $date = $this->assertExpiry($expiry);
        return DB::transaction(function () use ($id, $owner, $date): array {
            $row = $this->ownerRecord($id, $owner, true);
            abort_if($row->deleted_at !== null, 409, 'Check-In withdrawn.');
            abort_if($row->expires_at !== null && Carbon::parse($row->expires_at)->lte(now()),
                409, 'Expired Check-In cannot be reopened.');
            DB::table('checkins')->where('id', $id)->update(['expires_at' => $date,
                'updated_at' => now()]);
            return $this->present($this->ownerRecord($id, $owner), $owner);
        });
    }

    public function withdraw(string $id, string $owner): void
    {
        $this->assertReady();
        DB::transaction(function () use ($id, $owner): void {
            $row = $this->ownerRecord($id, $owner, true);
            if ($row->deleted_at !== null) return;
            DB::table('checkins')->where('id', $id)->update([
                'deleted_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('checkin_recipients')->where('checkin_id', $id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });
    }

    public function present(object $row, string $viewer): array
    {
        $owner = $viewer === $row->creator_id;
        $expired = $row->expires_at !== null && Carbon::parse($row->expires_at)->lte(now());
        $record = [
            'id' => (string) $row->id,
            'creator' => [
                'id' => (string) $row->creator_id,
                'name' => (string) (DB::table('orbit_users')->where('id',$row->creator_id)
                    ->value('display_name') ?? 'Orbit member'),
            ],
            'type' => (string) $row->type,
            'text' => $row->text,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'expires_at' => $row->expires_at ? Carbon::parse($row->expires_at)->toIso8601String() : null,
            'audience_revision' => (int) $row->audience_revision,
            'status' => $row->deleted_at !== null ? 'withdrawn' : ($expired ? 'expired' : 'accepted'),
            'place_ref' => null,
        ];
        if ($owner) {
            $record['client_id'] = (string) $row->client_id;
            $record['audience'] = DB::table('checkin_audience')
                ->where('checkin_id', $row->id)->where('audience_revision', $row->audience_revision)
                ->get()->map(fn ($s) => ['type' => $s->audience_type,
                    'id' => $s->audience_id === '' ? null : $s->audience_id])->all();
        }
        return $record;
    }
}
