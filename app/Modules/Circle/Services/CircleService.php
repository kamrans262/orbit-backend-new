<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitUser;
use App\Modules\Social\Services\SocialGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-authoritative private Circle membership. No paid-tier limits, public
 * discovery, automatic sharing, location, SOS or realtime delivery is implied.
 * Ambiguous ownership transfer, dissolve and join-code policies are disabled.
 */
final class CircleService
{
    public function __construct(private readonly SocialGraphService $social) {}

    private function active(string $userId): bool
    {
        return OrbitUser::query()->whereKey($userId)->where('status','active')
            ->whereNull('deletion_requested_at')->exists();
    }

    private function circle(string $id, string $user, bool $owner = false, bool $lock = false): object
    {
        $query = DB::table('circles')->where('id',$id)->whereNull('archived_at');
        if ($lock) $query->lockForUpdate();
        $circle = $query->first();
        if (!$circle || !$this->active($user)
            || $this->social->isBlockedEitherWay($user,$circle->owner_id)) abort(404,'Circle unavailable.');
        if ($owner && $circle->owner_id !== $user) abort(404,'Circle unavailable.');
        $member = DB::table('circle_members')->where('circle_id',$id)
            ->where('user_id',$user)->whereNull('removed_at')->first();
        if (!$member || ($owner && $member->role !== 'owner')) abort(404,'Circle unavailable.');
        return $circle;
    }

    private function change(string $id, string $event): void
    {
        DB::table('circles')->where('id',$id)->increment('revision',1,['updated_at'=>now()]);
        DB::table('circle_event_outbox')->insert([
            'id'=>(string) Str::uuid7(),'circle_id'=>$id,
            'event_type'=>$event,'created_at'=>now(),
        ]);
    }

    private function format(object $row, string $viewer): array
    {
        return [
            'id'=>$row->id,'name'=>$row->name,'icon_key'=>$row->icon_key,
            'color_value'=>$row->color_value,'owner_id'=>$row->owner_id,
            'revision'=>(int) $row->revision,
            'member_count'=>$this->visibleMemberCount($row->id,$viewer),
        ];
    }

    private function visibleMemberCount(string $circleId, string $viewer): int
    {
        return (int) DB::table('circle_members as m')
            ->join('orbit_users as u','u.id','=','m.user_id')
            ->where('m.circle_id',$circleId)->whereNull('m.removed_at')
            ->where('u.status','active')->whereNull('u.deletion_requested_at')
            ->whereNotExists(function ($q) use ($viewer): void {
                $q->selectRaw('1')->from('blocks as b')->where(function ($b) use ($viewer): void {
                    $b->where(fn ($x) => $x->where('b.blocker_id',$viewer)
                        ->whereColumn('b.blocked_id','m.user_id'))
                      ->orWhere(fn ($x) => $x->where('b.blocked_id',$viewer)
                        ->whereColumn('b.blocker_id','m.user_id'));
                });
            })->count();
    }

    public function listing(string $user, int $page): array
    {
        $items = DB::table('circles as c')
            ->join('circle_members as cm', fn ($j) => $j->on('cm.circle_id','=','c.id')->whereNull('cm.removed_at'))
            ->where('cm.user_id',$user)->whereNull('c.archived_at')
            ->orderByDesc('c.created_at')->orderByDesc('c.id')
            ->select('c.*','cm.role')->paginate(20,['*'],'page',$page);
        $result = [];
        foreach ($items->items() as $circle) {
            if (!$this->active($circle->owner_id)
                || $this->social->isBlockedEitherWay($user,$circle->owner_id)) continue;
            $result[] = [...$this->format($circle,$user),'role'=>$circle->role];
        }
        return ['items'=>$result,'page'=>$items->currentPage(),'has_more'=>$items->hasMorePages()];
    }

    public function create(string $user, array $data, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($user,$data,$idempotencyKey): array {
            $person = OrbitUser::query()->whereKey($user)->lockForUpdate()->first();
            if (!$person || $person->status !== 'active' || $person->deletion_requested_at) abort(403);
            $idempotencyHash = $idempotencyKey === null ? null :
                hash_hmac('sha256', $user.'|'.$idempotencyKey, (string) config('app.key'));
            $payloadHash = hash('sha256', json_encode([
                'name'=>trim($data['name']),
                'icon_key'=>$data['icon_key'] ?? null,
                'color_value'=>$data['color_value'] ?? null,
            ], JSON_THROW_ON_ERROR));
            if ($idempotencyHash) {
                $existing = DB::table('circles')->where('creation_key_hash',$idempotencyHash)->first();
                if ($existing) {
                    if ($existing->creation_payload_hash !== $payloadHash) {
                        abort(409,'Idempotency key already used for a different Circle.');
                    }
                    return $this->detail($existing->id,$user);
                }
            }
            $id = (string) Str::uuid7();
            $now = now();
            DB::table('circles')->insert([
                'id'=>$id,'owner_id'=>$user,'name'=>trim($data['name']),
                'icon_key'=>$data['icon_key'] ?? null,
                'color_value'=>$data['color_value'] ?? null,
                'revision'=>1,'creation_key_hash'=>$idempotencyHash,
                'creation_payload_hash'=>$idempotencyHash ? $payloadHash : null,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            DB::table('circle_members')->insert([
                'circle_id'=>$id,'user_id'=>$user,'role'=>'owner','joined_at'=>$now,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string) Str::uuid7(),'circle_id'=>$id,
                'event_type'=>'circle.created','created_at'=>$now,
            ]);
            return $this->detail($id,$user);
        }, 3);
    }

    public function detail(string $id, string $user): array
    {
        $circle = $this->circle($id,$user);
        $role = DB::table('circle_members')->where('circle_id',$id)
            ->where('user_id',$user)->value('role');
        return [...$this->format($circle,$user),'role'=>$role,
            'sharing_enabled'=>false]; // Never assert consent through membership alone.
    }

    public function rename(string $id, string $user, array $data): array
    {
        return DB::transaction(function () use ($id,$user,$data): array {
            $this->circle($id,$user,true,true);
            DB::table('circles')->where('id',$id)->update([
                ...$data,'updated_at'=>now(),
            ]);
            $this->change($id,'circle.updated');
            return $this->detail($id,$user);
        }, 3);
    }

    public function members(string $id, string $user, int $page): array
    {
        $this->circle($id,$user);
        $pageData = DB::table('circle_members as m')
            ->join('orbit_users as u','u.id','=','m.user_id')
            ->where('m.circle_id',$id)->whereNull('m.removed_at')
            ->where('u.status','active')->whereNull('u.deletion_requested_at')
            ->orderBy('m.joined_at')->orderBy('m.user_id')
            ->select('u.id','u.display_name','u.username','m.role')
            ->paginate(20,['*'],'page',$page);
        $rows = [];
        foreach ($pageData->items() as $member) {
            if ($member->id !== $user && $this->social->isBlockedEitherWay($user,$member->id)) continue;
            $rows[] = ['id'=>$member->id,'display_name'=>$member->display_name,
                'username'=>$member->username,'role'=>$member->role];
        }
        return ['items'=>$rows,'page'=>$pageData->currentPage(),
            'has_more'=>$pageData->hasMorePages()];
    }

    /** Targeted invitation only; no reusable join links until token policy approval. */
    public function invite(string $id, string $user, string $invitee): array
    {
        if (config('orbit_circle.targeted_invites_enabled') !== true) abort(404,'Invitation unavailable.');
        $ttl = (int) config('orbit_circle.targeted_invite_lifetime_hours',0);
        if ($ttl < 1 || $ttl > 720) abort(404,'Invitation policy unavailable.');
        return DB::transaction(function () use ($id,$user,$invitee,$ttl): array {
            $this->circle($id,$user,true,true);
            if ($user === $invitee || !$this->active($invitee)
                || $this->social->isBlockedEitherWay($user,$invitee)) abort(404,'Person unavailable.');
            // No automatic admission from friendship; friendship only permits inviting.
            if (!$this->social->isFriend($user,$invitee)) abort(404,'Person unavailable.');
            if (DB::table('circle_members')->where('circle_id',$id)
                ->where('user_id',$invitee)->whereNull('removed_at')->exists()) abort(409,'Already a member.');
            if (DB::table('circle_invites')->where('pending_pair_key',$id.':'.$invitee)->exists()) {
                abort(409,'Invitation already pending.');
            }
            $inviteId = (string) Str::uuid7();
            DB::table('circle_invites')->insert([
                'id'=>$inviteId,'circle_id'=>$id,'inviter_id'=>$user,
                'invitee_id'=>$invitee,'status'=>'pending',
                'pending_pair_key'=>$id.':'.$invitee,
                'expires_at'=>now()->addHours($ttl),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->change($id,'circle.invited');
            return ['id'=>$inviteId,'status'=>'pending'];
        }, 3);
    }

    public function invites(string $id, string $user, int $page): array
    {
        if (config('orbit_circle.targeted_invites_enabled') !== true) abort(404,'Invitation unavailable.');
        $this->circle($id,$user,true);
        $items = DB::table('circle_invites')->where('circle_id',$id)
            ->orderByDesc('created_at')->paginate(20,['id','invitee_id','status','created_at','resolved_at'],'page',$page);
        $safe = [];
        foreach ($items->items() as $invite) {
            if ($this->social->isBlockedEitherWay($user,$invite->invitee_id)) continue;
            $person = OrbitUser::query()->whereKey($invite->invitee_id)
                ->where('status','active')->whereNull('deletion_requested_at')
                ->first(['id','username','display_name']);
            if (!$person) continue;
            $safe[] = [
                'id'=>$invite->id,'status'=>$invite->status,
                'created_at'=>$invite->created_at,'resolved_at'=>$invite->resolved_at,
                'person'=>['id'=>$person->id,'username'=>$person->username,
                    'display_name'=>$person->display_name],
            ];
        }
        return ['items'=>$safe,'page'=>$items->currentPage(),
            'has_more'=>$items->hasMorePages()];
    }

    public function incoming(string $user, int $page): array
    {
        if (config('orbit_circle.targeted_invites_enabled') !== true) return ['items'=>[],'page'=>1,'has_more'=>false];
        $items = DB::table('circle_invites')->where('invitee_id',$user)->where('status','pending')
            ->where('expires_at','>',now())
            ->orderByDesc('created_at')->paginate(20,['*'],'page',$page);
        $result = [];
        foreach ($items->items() as $invite) {
            $circle = DB::table('circles')->where('id',$invite->circle_id)->whereNull('archived_at')->first();
            if (!$circle || !$this->active($circle->owner_id)
                || $this->social->isBlockedEitherWay($user,$circle->owner_id)
                || $this->social->isBlockedEitherWay($user,$invite->inviter_id)) continue;
            $result[] = ['id'=>$invite->id,'circle_id'=>$circle->id,
                'circle_name'=>$circle->name,'status'=>'pending'];
        }
        return ['items'=>$result,'page'=>$items->currentPage(),'has_more'=>$items->hasMorePages()];
    }

    public function answer(string $id, string $user, bool $accept): array
    {
        if (config('orbit_circle.targeted_invites_enabled') !== true) abort(404,'Invitation unavailable.');
        return DB::transaction(function () use ($id,$user,$accept): array {
            // Lock circle before invite, consistent with invitation sender.
            $hint = DB::table('circle_invites')->where('id',$id)->where('invitee_id',$user)->first();
            if (!$hint) abort(404,'Invitation unavailable.');
            $circle = DB::table('circles')->where('id',$hint->circle_id)
                ->whereNull('archived_at')->lockForUpdate()->first();
            if (!$circle) abort(404,'Invitation unavailable.');
            $invite = DB::table('circle_invites')->where('id',$id)
                ->where('invitee_id',$user)->lockForUpdate()->first();
            if (!$invite || $invite->status !== 'pending') abort(409,'Invitation already resolved.');
            if (!$invite->expires_at || now()->gte($invite->expires_at)) abort(409,'Invitation expired.');
            if (!$this->active($user) || !$this->active($circle->owner_id)
                || $this->social->isBlockedEitherWay($user,$circle->owner_id)
                || $this->social->isBlockedEitherWay($user,$invite->inviter_id)) abort(404,'Invitation unavailable.');
            if ($accept && !$this->social->isFriend($invite->inviter_id,$user)) {
                abort(404,'Invitation unavailable.');
            }
            if ($accept) {
                $old = DB::table('circle_members')->where('circle_id',$circle->id)
                    ->where('user_id',$user)->lockForUpdate()->first();
                if ($old && $old->removed_at === null) abort(409,'Already a member.');
                if ($old) {
                    DB::table('circle_members')->where('circle_id',$circle->id)->where('user_id',$user)
                        ->update(['role'=>'member','joined_at'=>now(),'removed_at'=>null,'updated_at'=>now()]);
                } else {
                    DB::table('circle_members')->insert([
                        'circle_id'=>$circle->id,'user_id'=>$user,'role'=>'member',
                        'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            DB::table('circle_invites')->where('id',$id)->update([
                'status'=>$accept?'accepted':'declined','pending_pair_key'=>null,
                'resolved_at'=>now(),'updated_at'=>now(),
            ]);
            $this->change($circle->id,$accept?'circle.joined':'circle.invite_declined');
            return ['status'=>$accept?'accepted':'declined','circle_id'=>$circle->id];
        }, 3);
    }

    public function revoke(string $id, string $user, string $inviteId): void
    {
        if (config('orbit_circle.targeted_invites_enabled') !== true) abort(404,'Invitation unavailable.');
        DB::transaction(function () use ($id,$user,$inviteId): void {
            $this->circle($id,$user,true,true);
            $row = DB::table('circle_invites')->where('id',$inviteId)
                ->where('circle_id',$id)->lockForUpdate()->first();
            if (!$row || $row->status !== 'pending') abort(404,'Invitation unavailable.');
            DB::table('circle_invites')->where('id',$inviteId)->update([
                'status'=>'revoked','pending_pair_key'=>null,'resolved_at'=>now(),'updated_at'=>now(),
            ]);
            $this->change($id,'circle.invite_revoked');
        }, 3);
    }

    public function leave(string $id, string $user): void
    {
        DB::transaction(function () use ($id,$user): void {
            $this->circle($id,$user,false,true);
            $circle = DB::table('circles')->where('id',$id)->first();
            if ($circle->owner_id === $user) abort(409,'Owner transfer policy required before leaving.');
            DB::table('circle_members')->where('circle_id',$id)->where('user_id',$user)
                ->whereNull('removed_at')->update(['removed_at'=>now(),'updated_at'=>now()]);
            $this->change($id,'circle.left');
        }, 3);
    }

    public function remove(string $id, string $user, string $target): void
    {
        DB::transaction(function () use ($id,$user,$target): void {
            $circle = $this->circle($id,$user,true,true);
            if ($circle->owner_id === $target) abort(409,'Cannot remove owner.');
            $count = DB::table('circle_members')->where('circle_id',$id)
                ->where('user_id',$target)->whereNull('removed_at')
                ->update(['removed_at'=>now(),'updated_at'=>now()]);
            if (!$count) abort(404,'Member unavailable.');
            $this->change($id,'circle.member_removed');
        }, 3);
    }

    /** Role escalation other than owner-managed member restrictions needs policy approval. */
    public function changeRole(string $id, string $user, string $target, string $role): void
    {
        if (config('orbit_circle.role_changes_enabled') !== true) abort(404,'Role changes unavailable.');
        DB::transaction(function () use ($id,$user,$target,$role): void {
            $circle = $this->circle($id,$user,true,true);
            if ($circle->owner_id === $target) abort(409,'Owner role cannot be edited.');
            $count = DB::table('circle_members')->where('circle_id',$id)
                ->where('user_id',$target)->whereNull('removed_at')
                ->update(['role'=>$role,'updated_at'=>now()]);
            if (!$count) abort(404,'Member unavailable.');
            $this->change($id,'circle.role_changed');
        }, 3);
    }

    public function permissions(string $id, string $user): array
    {
        $this->circle($id,$user);
        // Persisted grants are not activated without feature-specific consent and consumers.
        $saved = DB::table('circle_permissions')->where('circle_id',$id)
            ->where('scope_key','user:'.$user)->get(['permission_key','enabled','expires_at']);
        return ['sharing_active'=>false,'grants_enabled'=>false,
            'saved'=>$saved->map(fn ($r) => ['permission_key'=>$r->permission_key,
                'enabled'=>false,'expires_at'=>$r->expires_at])->all()];
    }

    public function disablePermission(string $id, string $user, string $key): array
    {
        return DB::transaction(function () use ($id,$user,$key): array {
            $this->circle($id,$user,false,true);
            DB::table('circle_permissions')->updateOrInsert([
                'circle_id'=>$id,'scope_key'=>'user:'.$user,'permission_key'=>$key,
            ],['subject_user_id'=>$user,'enabled'=>false,'expires_at'=>null,'updated_at'=>now()]);
            $this->change($id,'circle.permission_revoked');
            return ['permission_key'=>$key,'enabled'=>false];
        }, 3);
    }
}
