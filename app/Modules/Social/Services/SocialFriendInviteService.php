<?php

namespace App\Modules\Social\Services;

use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SocialFriendInviteService
{
    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key').'|orbit-friend-invite-v1');
    }

    private function invitedBy(string $token): object
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{43}$/D', $token)) abort(404, 'Invite unavailable.');
        $invite = DB::table('social_friend_invites')
            ->where('token_hash', $this->hash($token))
            ->whereNull('revoked_at')->whereNull('redeemed_at')
            ->where('expires_at', '>', now())->first();
        if (!$invite) abort(404, 'Invite unavailable.');
        return $invite;
    }

    public function create(string $creator): array
    {
        return DB::transaction(function () use ($creator): array {
            $user = OrbitUser::query()->whereKey($creator)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active' || $user->deletion_requested_at
                || !app(SocialDiscoveryService::class)->canRequest($creator)) {
                abort(403, 'Invitations are disabled.');
            }
            $active = DB::table('social_friend_invites')->where('creator_id', $creator)
                ->whereNull('revoked_at')->whereNull('redeemed_at')
                ->where('expires_at', '>', now())->count();
            if ($active >= (int) config('orbit_social.active_friend_invite_limit', 5)) abort(429, 'Maximum active invitation links reached.');
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $id = (string) Str::uuid7();
            $expires = now()->addDays((int) config('orbit_social.friend_invite_lifetime_days', 7));
            DB::table('social_friend_invites')->insert([
                'id' => $id, 'creator_id' => $creator,
                'token_hash' => $this->hash($token), 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => $id, 'invite_url' => url('/invite/friend/'.$token),
                'invite_code' => $token, 'expires_at' => $expires->toIso8601String()];
        }, 3);
    }

    public function preview(string $recipient, string $token, SocialGraphService $graph): array
    {
        $invite = $this->invitedBy($token);
        $user = $graph->assertVisible($recipient, $invite->creator_id);
        if (!app(SocialDiscoveryService::class)->canRequest($user->id)) abort(404, 'Invite unavailable.');
        return ['display_name' => $user->display_name, 'username' => $user->username,
            'relationship' => $graph->relationship($recipient, $user->id),
            'expires_at' => $invite->expires_at];
    }

    public function redeem(string $recipient, string $token, ?string $device,
        SocialGraphService $graph): array
    {
        return DB::transaction(function () use ($recipient, $token, $device, $graph): array {
            $invite = $this->invitedBy($token);
            // Serialize one-time redemptions. Failure to create a request rolls back consumption.
            $locked = DB::table('social_friend_invites')->where('id', $invite->id)
                ->lockForUpdate()->first();
            if (!$locked || $locked->revoked_at || $locked->redeemed_at
                || now()->gte($locked->expires_at)) abort(404, 'Invite unavailable.');
            $graph->assertVisible($recipient, $locked->creator_id);
            if (!app(SocialDiscoveryService::class)->canRequest($locked->creator_id)) {
                abort(404, 'Invite unavailable.');
            }
            $result = $graph->request($recipient, $locked->creator_id, $device);
            DB::table('social_friend_invites')->where('id', $locked->id)->update([
                'redeemed_by' => $recipient, 'redeemed_at' => now(), 'updated_at' => now(),
            ]);
            return $result;
        }, 3);
    }

    public function revoke(string $creator, string $inviteId): void
    {
        $changed = DB::table('social_friend_invites')->where('id', $inviteId)
            ->where('creator_id', $creator)->whereNull('revoked_at')
            ->whereNull('redeemed_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        if (!$changed) abort(404, 'Invite unavailable.');
    }

    public function listing(string $creator): array
    {
        return DB::table('social_friend_invites')->where('creator_id', $creator)
            ->orderByDesc('created_at')->limit(30)
            ->get(['id','expires_at','revoked_at','redeemed_at','created_at'])
            ->map(fn ($i) => (array) $i)->all();
    }
}
