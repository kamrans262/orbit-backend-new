<?php

namespace App\Modules\Presence\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Read-scope authorization is shared by future websocket subscriber adapters.
 * No delivery is claimed until a reviewed realtime gateway exists.
 */
final class PresenceService
{
    public function __construct(
        private readonly PresenceAccessPolicy $policy,
        private readonly PresenceLeaseStore $leases,
        private readonly PresenceRealtimePublisher $realtime,
    ) {}

    public function ensureAvailable(string $user): void
    {
        abort_unless($this->policy->enabled(),404,'Presence unavailable.');
        abort_unless($this->policy->userActive($user),403);
    }

    public function heartbeat(string $user,string $device,string $state): array
    {
        $this->ensureAvailable($user);
        $lease=$this->leases->touch($user,$device,$state);
        if ($this->realtime->enabled()) $this->realtime->changed($user);
        return $lease;
    }

    public function disconnect(string $user,string $device): array
    {
        $this->ensureAvailable($user);
        $this->leases->clear($user,$device);
        if ($this->realtime->enabled()) $this->realtime->changed($user);
        return ['disconnected'=>true];
    }

    public function preferences(string $user): array
    {
        $this->ensureAvailable($user);
        $global=$this->policy->globalPrivacy($user);
        $rows=DB::table('circle_permissions')->where('subject_user_id',$user)
            ->where('permission_key','presence')->get(['circle_id','enabled','expires_at','value_json']);
        $items=[];
        foreach ($rows as $row) {
            if (!$this->policy->member($row->circle_id,$user)) continue;
            $value=is_string($row->value_json) ? json_decode($row->value_json,true) : null;
            $items[]=['circle_id'=>$row->circle_id,'requested'=>(bool)$row->enabled,
                'duration'=>is_array($value) ? ($value['duration']??null) : null,
                'expires_at'=>$row->expires_at];
        }
        return ['global'=>$global,'circles'=>$items,'server_ttl_seconds'=>(int)config('orbit_presence.lease_seconds',90)];
    }

    /**
     * Explicit subject opt-in. Requires existing M3 global visibility+Ghost
     * choices separately; a stored requested grant never overrides them.
     */
    public function setCircleSharing(string $user,string $circle,bool $enabled,?string $duration,?string $expiry): array
    {
        $this->ensureAvailable($user);
        abort_unless($this->policy->member($circle,$user),404,'Circle unavailable.');
        $until=null;
        if ($enabled) {
            abort_unless(in_array($duration,['persistent','until_expiry'],true),422,'Duration required.');
            if ($duration==='until_expiry') {
                abort_unless($expiry!==null,422,'Expiry required.');
                $until=Carbon::parse($expiry)->utc();
                abort_unless($until->greaterThan(now()) && $until->lessThanOrEqualTo(now()->addDays(30)),
                    422,'Expiry must be in the next 30 days.');
            } else {
                abort_unless($expiry===null,422,'Permanent sharing cannot have expiry.');
            }
        }
        DB::transaction(function () use ($user,$circle,$enabled,$duration,$until): void {
            DB::table('circle_permissions')->updateOrInsert([
                'circle_id'=>$circle,'scope_key'=>'user:'.$user,'permission_key'=>'presence',
            ],[
                'subject_user_id'=>$user,'enabled'=>$enabled,
                'expires_at'=>$enabled && $until ? $until->toDateTimeString() : null,
                'value_json'=>$enabled ? json_encode([
                    'reviewed'=>true,'audience'=>'circle','duration'=>$duration,
                ],JSON_THROW_ON_ERROR) : null,
                'updated_at'=>now(),'created_at'=>now(),
            ]);
            DB::table('circles')->where('id',$circle)->increment('revision',1,['updated_at'=>now()]);
            DB::table('circle_event_outbox')->insert([
                'id'=>(string)Str::uuid7(),'circle_id'=>$circle,
                'event_type'=>'circle.presence_policy_changed','created_at'=>now(),
            ]);
        },3);
        if ($this->realtime->enabled()) $this->realtime->safelyClearCircle($circle);
        return ['circle_id'=>$circle,'requested'=>$enabled,
            'global_allows_presence'=>$enabled &&
                !$this->policy->globalPrivacy($user)['ghost_mode_enabled'] &&
                $this->policy->globalPrivacy($user)['presence_visibility']==='My Circle',
            'expires_at'=>$until?->toIso8601String()];
    }

    public function circleSnapshot(string $viewer,string $circle): array
    {
        $this->ensureAvailable($viewer);
        abort_unless($this->policy->member($circle,$viewer),404,'Circle unavailable.');
        $members=DB::table('circle_members')->where('circle_id',$circle)
            ->whereNull('removed_at')->limit((int)config('orbit_presence.max_circle_members',50)+1)
            ->pluck('user_id')->all();
        abort_if(count($members)>(int) config('orbit_presence.max_circle_members',50),422,'Circle too large.');
        $visible=[];
        foreach ($members as $subject) {
            if (!$this->policy->canView($circle,$viewer,$subject)) continue;
            $state=$this->leases->snapshot($subject);
            if ($state === null) continue; // Never imply an authorized person's offline history.
            $visible[]=['user_id'=>$subject,...$state];
        }
        return ['circle_id'=>$circle,'members'=>$visible,'ttl_seconds'=>(int)config('orbit_presence.lease_seconds',90),
            'realtime_available'=>false];
    }
}
