<?php

namespace App\Modules\Ping\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PingService
{
    public const PRESETS = ['hello', 'where', 'on-my-way', 'right-there', 'love', 'call'];

    public function __construct(private readonly PingAccessPolicy $policy) {}

    public function assertInstalled(): void
    {
        abort_unless(Schema::hasTable('pings') && Schema::hasTable('ping_receipts')
            && Schema::hasTable('ping_responses'), 503, 'Ping database setup required.');
    }

    public function assertSendingEnabled(): void
    {
        $this->assertInstalled();
        abort_unless(config('orbit_ping.enabled') === true, 503, 'Ping temporarily unavailable.');
    }

    public function send(string $sender, array $input): array
    {
        $this->assertSendingEnabled();
        $recipient = $input['recipient_id'];
        $context = $input['context']['type'];
        $circle = $context === 'circle' ? ($input['context']['id'] ?? null) : null;
        $type = $input['type'];
        $custom = $type === 'custom' ? trim($input['custom_text'] ?? '') : null;
        $client = $input['client_id'];
        $payload = hash('sha256', json_encode([$recipient, $context, $circle, $type, $custom], JSON_UNESCAPED_UNICODE));

        // Resolve an already accepted request before charging any rate budget.
        $existing = DB::table('pings')->where('sender_id', $sender)->where('client_id', $client)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->payload_hash, $payload), 409, 'Client request ID conflict.');
            $this->policy->assertPair($existing);
            return [$this->present($existing, $sender), false];
        }
        abort_unless($this->policy->allows($sender, $recipient, $context, $circle),
            404, 'Recipient unavailable.');

        $senderKey = 'ping:s:'.hash('sha256', $sender);
        $pairKey = 'ping:p:'.hash('sha256', $sender.'|'.$recipient);
        abort_if(RateLimiter::tooManyAttempts($senderKey, max(1, (int) config('orbit_ping.sender_per_minute', 12)))
            || RateLimiter::tooManyAttempts($pairKey, max(1, (int) config('orbit_ping.pair_per_hour', 6))),
            429, 'Too many Pings. Please try again later.');

        $created = DB::transaction(function () use ($sender, $recipient, $client, $type, $custom, $context, $circle, $payload): bool {
            // The unique sender/client index is authoritative across simultaneous retries.
            return DB::table('pings')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'sender_id' => $sender,
                'recipient_id' => $recipient, 'client_id' => $client,
                'payload_hash' => $payload, 'type' => $type, 'custom_text' => $custom,
                'context_type' => $context, 'context_id' => $circle,
                'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
        });

        $row = DB::table('pings')->where('sender_id', $sender)->where('client_id', $client)->first();
        abort_unless($row && hash_equals($row->payload_hash, $payload), 409, 'Client request ID conflict.');
        $this->policy->assertPair($row);
        if ($created) {
            RateLimiter::hit($senderKey, 60);
            RateLimiter::hit($pairKey, 3600);
        }
        return [$this->present($row, $sender), $created];
    }

    public function find(string $pingId, string $user): object
    {
        $this->assertInstalled();
        $row = DB::table('pings')->where('id', $pingId)
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user)->orWhere('recipient_id', $user);
            })->first();
        abort_unless($row, 404, 'Ping unavailable.');
        $this->policy->assertPair($row);
        return $row;
    }

    public function page(string $user, string $direction, int $limit): array
    {
        $this->assertInstalled();
        $key = $direction === 'inbox' ? 'recipient_id' : 'sender_id';
        $page = DB::table('pings')->where($key, $user)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(min(max($limit, 1), (int) config('orbit_ping.page_size_max', 30)));
        $items = [];
        foreach ($page->items() as $row) {
            if ($this->policy->allows($row->sender_id, $row->recipient_id,
                $row->context_type, $row->context_id)) {
                $items[] = $this->present($row, $user);
            }
        }
        return ['items' => $items, 'next_cursor' => $page->nextCursor()?->encode()];
    }

    public function receipt(string $pingId, string $recipient, string $device, string $kind): array
    {
        $this->assertInstalled();
        $this->find($pingId, $recipient);
        return DB::transaction(function () use ($pingId, $recipient, $device, $kind): array {
            $row = DB::table('pings')->where('id', $pingId)->lockForUpdate()->first();
            abort_unless($row && $row->recipient_id === $recipient, 404, 'Ping unavailable.');
            $this->policy->assertPair($row);
            DB::table('ping_receipts')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'ping_id' => $pingId,
                'device_id' => $device, 'kind' => $kind, 'created_at' => now(),
            ]);
            $changes = [];
            if ($row->delivered_at === null) $changes['delivered_at'] = now();
            if ($kind === 'seen' && $row->seen_at === null) $changes['seen_at'] = now();
            if ($changes) {
                $changes['updated_at'] = now();
                DB::table('pings')->where('id', $pingId)->update($changes);
            }
            return $this->present(DB::table('pings')->where('id', $pingId)->first(), $recipient);
        });
    }

    public function respond(string $pingId, string $recipient, string $clientReply): array
    {
        $this->assertSendingEnabled();
        $this->find($pingId, $recipient);
        return DB::transaction(function () use ($pingId, $recipient, $clientReply): array {
            $row = DB::table('pings')->where('id', $pingId)->lockForUpdate()->first();
            abort_unless($row && $row->recipient_id === $recipient, 404, 'Ping unavailable.');
            $this->policy->assertPair($row);
            $existing = DB::table('ping_responses')->where('ping_id', $pingId)->first();
            if ($existing) {
                abort_unless($existing->responder_id === $recipient
                    && $existing->client_reply_id === $clientReply, 409, 'Ping already answered.');
                return $this->replyRepresentation($existing);
            }
            // A client reply ID is bound to precisely one Ping.
            abort_if(DB::table('ping_responses')->where('responder_id', $recipient)
                ->where('client_reply_id', $clientReply)->exists(), 409, 'Reply request ID conflict.');
            $reply = [
                'id' => (string) Str::uuid(), 'ping_id' => $pingId,
                'responder_id' => $recipient, 'client_reply_id' => $clientReply,
                'type' => 'hey', 'created_at' => now(),
            ];
            DB::table('ping_responses')->insert($reply);
            DB::table('pings')->where('id', $pingId)->update([
                'responded_at' => now(), 'updated_at' => now(),
            ]);
            return $this->replyRepresentation((object) $reply);
        });
    }

    private function replyRepresentation(object $reply): array
    {
        return [
            'id' => (string) $reply->id, 'ping_id' => (string) $reply->ping_id,
            'type' => 'hey', 'created_at' => Carbon::parse($reply->created_at)->toIso8601String(),
        ];
    }

    public function present(object $row, string $viewer): array
    {
        $recipient = $viewer === $row->recipient_id;
        $canSeeSeen = $recipient || $this->policy->readReceiptsAllowed($row->recipient_id);
        $seen = $canSeeSeen && $row->seen_at !== null;
        return [
            'id' => (string) $row->id,
            'client_id' => (string) $row->client_id,
            'sender_id' => (string) $row->sender_id,
            'recipient_id' => (string) $row->recipient_id,
            'type' => (string) $row->type,
            'custom_text' => $row->custom_text,
            'context' => ['type' => $row->context_type, 'id' => $row->context_id],
            'status' => $seen ? 'seen' : ($row->delivered_at ? 'delivered' : 'accepted'),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'delivered_at' => $row->delivered_at ? Carbon::parse($row->delivered_at)->toIso8601String() : null,
            'seen_at' => $seen ? Carbon::parse($row->seen_at)->toIso8601String() : null,
            'responded_at' => $row->responded_at ? Carbon::parse($row->responded_at)->toIso8601String() : null,
        ];
    }
}
