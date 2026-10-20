<?php

namespace App\Modules\Social\Services;

use App\Models\OrbitUser;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Server-authoritative, identity-scoped friendship and blocking service.
 * Blocks outrank all friendship states and invalidate both parties' revisions.
 * Later modules MUST call isBlockedEitherWay() before returning protected data.
 */
final class SocialGraphService
{
    public function canonical(string $a, string $b): array
    {
        return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
    }

    public function isBlockedEitherWay(string $a, string $b): bool
    {
        return DB::table('blocks')->where(function ($q) use ($a, $b): void {
            $q->where(fn ($x) => $x->where('blocker_id', $a)->where('blocked_id', $b))
              ->orWhere(fn ($x) => $x->where('blocker_id', $b)->where('blocked_id', $a));
        })->exists();
    }

    public function isFriend(string $a, string $b): bool
    {
        if ($a === $b || $this->isBlockedEitherWay($a, $b)) return false;
        [$low, $high] = $this->canonical($a, $b);
        return DB::table('friendships')->where('user_low_id', $low)
            ->where('user_high_id', $high)->exists();
    }

    public function relationship(string $viewer, string $subject): string
    {
        if ($viewer === $subject) return 'self';
        if ($this->isBlockedEitherWay($viewer, $subject)) return 'unavailable';
        if ($this->isFriend($viewer, $subject)) return 'friend';
        [$low, $high] = $this->canonical($viewer, $subject);
        $pending = DB::table('friend_requests')->where('active_pair_key', $low.':'.$high)->first();
        if (!$pending) return 'none';
        return $pending->sender_id === $viewer ? 'outgoing_pending' : 'incoming_pending';
    }

    public function assertVisible(string $viewer, string $subject): OrbitUser
    {
        $user = OrbitUser::query()->whereKey($subject)->where('status', 'active')
            ->whereNull('deletion_requested_at')->first();
        if (!$user || $viewer === $subject || $this->isBlockedEitherWay($viewer, $subject)) {
            // A neutral 404 does not reveal whether a target exists or has blocked the viewer.
            abort(404, 'Person unavailable.');
        }
        return $user;
    }

    private function lockPeople(string $a, string $b): void
    {
        $rows = OrbitUser::query()->whereIn('id', [$a, $b])
            ->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== 2 || $rows->contains(fn ($u) =>
            $u->status !== 'active' || $u->deletion_requested_at !== null)) {
            abort(404, 'Person unavailable.');
        }
    }

    private function revision(string $a, string $b, ?string $device, string $event): void
    {
        foreach (array_unique([$a, $b]) as $id) {
            DB::table('social_graph_revisions')->insertOrIgnore([
                'user_id' => $id, 'revision' => 0, 'updated_at' => now(),
            ]);
            DB::table('social_graph_revisions')->where('user_id', $id)
                ->increment('revision', 1, ['updated_at' => now()]);
        }
        app(TokenService::class)->event($a, $device, $event, 'social_graph');
    }

    public function currentRevision(string $user): int
    {
        return (int) (DB::table('social_graph_revisions')->where('user_id', $user)
            ->value('revision') ?? 0);
    }

    public function request(string $sender, string $recipient, ?string $device): array
    {
        if ($sender === $recipient) abort(422, 'Cannot send a request to yourself.');
        return DB::transaction(function () use ($sender, $recipient, $device): array {
            $this->lockPeople($sender, $recipient);
            if ($this->isBlockedEitherWay($sender, $recipient)) abort(404, 'Person unavailable.');
            if ($this->isFriend($sender, $recipient)) abort(409, 'Already friends.');
            [$low, $high] = $this->canonical($sender, $recipient);
            if (DB::table('friend_requests')->where('active_pair_key', $low.':'.$high)->exists()) {
                abort(409, 'A request is already pending for this relationship.');
            }
            $accountAge = OrbitUser::query()->findOrFail($sender)->created_at;
            $dailyLimit = $accountAge->gt(now()->subDay())
                ? (int) config('orbit_social.new_account_daily_limit', 10)
                : (int) config('orbit_social.daily_outbound_limit', 20);
            if (DB::table('friend_requests')->where('sender_id', $sender)
                ->where('created_at', '>=', now()->subDay())->count() >= $dailyLimit) {
                abort(429, 'Daily request limit reached. Please try later.');
            }
            if (DB::table('friend_requests')->where('sender_id', $sender)
                ->where('status', 'pending')->count() >= (int) config('orbit_social.pending_outbound_limit', 20)) {
                abort(429, 'Too many pending friend requests.');
            }
            $id = (string) Str::uuid7();
            DB::table('friend_requests')->insert([
                'id' => $id, 'sender_id' => $sender, 'recipient_id' => $recipient,
                'status' => 'pending', 'active_pair_key' => $low.':'.$high,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->revision($sender, $recipient, $device, 'social.request_sent');
            return ['id' => $id, 'status' => 'pending'];
        }, 3);
    }

    public function respond(string $actor, string $requestId, bool $accept, ?string $device): array
    {
        $initial = DB::table('friend_requests')->where('id', $requestId)->first();
        if (!$initial || $initial->recipient_id !== $actor) abort(404, 'Request unavailable.');
        return DB::transaction(function () use ($actor, $requestId, $accept, $device, $initial): array {
            $this->lockPeople($initial->sender_id, $initial->recipient_id);
            $row = DB::table('friend_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (!$row || $row->recipient_id !== $actor) abort(404, 'Request unavailable.');
            if ($row->status !== 'pending') abort(409, 'Request is no longer pending.');
            if ($this->isBlockedEitherWay($row->sender_id, $row->recipient_id)) {
                abort(404, 'Request unavailable.');
            }
            $status = $accept ? 'accepted' : 'declined';
            DB::table('friend_requests')->where('id', $row->id)->update([
                'status' => $status, 'responded_at' => now(),
                'active_pair_key' => null, 'updated_at' => now(),
            ]);
            if ($accept) {
                [$low, $high] = $this->canonical($row->sender_id, $row->recipient_id);
                DB::table('friendships')->insertOrIgnore([
                    'user_low_id' => $low, 'user_high_id' => $high, 'created_at' => now(),
                ]);
            }
            $this->revision($row->sender_id, $row->recipient_id, $device,
                $accept ? 'social.request_accepted' : 'social.request_declined');
            return ['id' => $row->id, 'status' => $status];
        }, 3);
    }

    public function unfriend(string $actor, string $other, ?string $device): void
    {
        if ($actor === $other) abort(422, 'Invalid person.');
        DB::transaction(function () use ($actor, $other, $device): void {
            $this->lockPeople($actor, $other);
            [$low, $high] = $this->canonical($actor, $other);
            $deleted = DB::table('friendships')->where('user_low_id', $low)
                ->where('user_high_id', $high)->delete();
            if ($deleted) $this->revision($actor, $other, $device, 'social.unfriended');
        }, 3);
    }

    public function block(string $actor, string $other, ?string $device, ?string $reason): void
    {
        if ($actor === $other) abort(422, 'Cannot block yourself.');
        DB::transaction(function () use ($actor, $other, $device, $reason): void {
            $this->lockPeople($actor, $other);
            $inserted = DB::table('blocks')->insertOrIgnore([
                'blocker_id' => $actor, 'blocked_id' => $other,
                'reason_code' => $reason, 'created_at' => now(),
            ]);
            if (!$inserted) return;
            [$low, $high] = $this->canonical($actor, $other);
            // Fail closed: no automatic re-friending when a user unblocks later.
            DB::table('friendships')->where('user_low_id', $low)
                ->where('user_high_id', $high)->delete();
            DB::table('friend_requests')->where('active_pair_key', $low.':'.$high)
                ->update(['status' => 'cancelled', 'active_pair_key' => null,
                    'responded_at' => now(), 'updated_at' => now()]);
            $this->revision($actor, $other, $device, 'social.blocked');
        }, 3);
    }

    public function unblock(string $actor, string $other, ?string $device): void
    {
        DB::transaction(function () use ($actor, $other, $device): void {
            $this->lockPeople($actor, $other);
            $deleted = DB::table('blocks')->where('blocker_id', $actor)
                ->where('blocked_id', $other)->delete();
            if ($deleted) $this->revision($actor, $other, $device, 'social.unblocked');
        }, 3);
    }
}
