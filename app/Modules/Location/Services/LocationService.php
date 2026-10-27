<?php

namespace App\Modules\Location\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M10 private, explicit location sharing. Durable GPS history is intentionally absent.
 * MySQL latest is encrypted and short-lived; no realtime broadcaster is deployed.
 */
final class LocationService
{
    public function __construct(private readonly LocationPolicy $policy) {}

    private function ready(string $user): void
    {
        $this->policy->ensureActive($user);
    }

    public function capabilities(string $user): array
    {
        $this->ready($user);
        return [
            'can_view_without_device_location'=>true,
            'sharing_active_by_default'=>false,
            'global'=>$this->policy->privacy($user),
            'supported_audiences'=>['person','circle'],
            'supported_precisions'=>['precise','approximate'],
            'supported_durations'=>['one_hour','until_tonight','manual','always'],
            'until_leaving_available'=>false, // Geofence radius/hysteresis needs product approval.
            'realtime_available'=>false, // No provisioned GPS broadcaster.
            'latest_ttl_seconds'=>(int)config('orbit_location.latest_ttl_seconds',120),
        ];
    }

    public function shares(string $owner): array
    {
        $this->ready($owner);
        return DB::table('location_shares')->where('owner_id',$owner)
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($r)=>$this->publicShare($r))->all();
    }

    private function publicShare(object $r): array
    {
        return [
            'id'=>$r->id,'audience_type'=>$r->audience_type,'audience_id'=>$r->audience_id,
            'precision'=>$r->precision,'duration'=>$r->duration,
            'status'=>$this->policy->active($r) ? 'active' : ($r->status==='active' ? 'expired' : $r->status),
            'starts_at'=>$r->starts_at,'expires_at'=>$r->expires_at,'revoked_at'=>$r->revoked_at,
            'revision'=>(int)$r->revision,
        ];
    }

    /** A share is never created until caller explicitly reviews and accepts it. */
    public function create(string $owner,array $data): array
    {
        $this->ready($owner);
        $type=$data['audience_type'];
        $target=$data['audience_id'];
        abort_unless($this->policy->mayCreate($owner,$type,$target),403,'Location sharing not allowed.');
        $duration=$data['duration'];
        abort_if($duration==='always' && $type!=='person',422,
            'Always sharing requires an explicitly selected trusted friend.');
        $expiry=match($duration) {
            'one_hour'=>now()->addHour(),
            'until_tonight'=>now($data['timezone'])->endOfDay()->utc(),
            'manual','always'=>null,
            default=>abort(422,'Unsupported duration; exit geofence is not yet approved.'),
        };
        abort_if($expiry!==null && (!$expiry->isFuture()
            || $expiry->greaterThan(now()->addDays(30))),422,'Invalid expiration.');
        return DB::transaction(function () use ($owner,$data,$type,$target,$duration,$expiry): array {
            // Serializes simultaneous creates for the same owner across trusted devices.
            DB::table('orbit_users')->where('id',$owner)->lockForUpdate()->first();
            $active=DB::table('location_shares')->where('owner_id',$owner)->where('status','active')->get();
            $matches=$active->filter(fn ($r)=>$r->audience_type===$type && $r->audience_id===$target);
            $newTarget=$matches->isEmpty();
            if ($newTarget && $active->count()>=(int)config('orbit_location.max_shares_per_user',60)) {
                abort(422,'Too many location shares.');
            }
            foreach ($matches as $previous) {
                DB::table('location_shares')->where('id',$previous->id)->update([
                    'status'=>'revoked','revoked_at'=>now(),'revision'=>DB::raw('revision + 1'),
                    'updated_at'=>now(),
                ]);
            }
            $id=(string)Str::uuid7();
            DB::table('location_shares')->insert([
                'id'=>$id,'owner_id'=>$owner,'audience_type'=>$type,'audience_id'=>$target,
                'precision'=>$data['precision'],'duration'=>$duration,'status'=>'active',
                'starts_at'=>now(),'expires_at'=>$expiry,'revoked_at'=>null,
                'revision'=>1,'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($type==='circle') $this->setCirclePermission($owner,$target,true,$data['precision'],$expiry);
            $this->audit($owner,$type,$data['precision'],'activated');
            return $this->publicShare(DB::table('location_shares')->where('id',$id)->first());
        },3);
    }

    /**
     * Mirror only explicitly confirmed circle shares into M5's existing guarded
     * per-user Circle grant. This does NOT update global M3 location preferences.
     */
    private function setCirclePermission(string $owner,string $circle,bool $enabled,
        string $precision='hidden',?Carbon $expires=null): void
    {
        $settings=$enabled ? [
            'reviewed'=>true,'audience'=>'circle',
            'duration'=>$expires===null ? 'persistent' : 'until_expiry',
            'precision'=>$precision,
        ] : null;
        DB::table('circle_permissions')->updateOrInsert([
            'circle_id'=>$circle,'scope_key'=>'user:'.$owner,'permission_key'=>'location',
        ],[
            'subject_user_id'=>$owner,'enabled'=>$enabled,
            'expires_at'=>$enabled ? $expires : null,
            'value_json'=>$settings ? json_encode($settings,JSON_THROW_ON_ERROR) : null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('circles')->where('id',$circle)->increment('revision',1,['updated_at'=>now()]);
        DB::table('circle_event_outbox')->insert([
            'id'=>(string)Str::uuid7(),'circle_id'=>$circle,
            'event_type'=>'circle.location_policy_changed','created_at'=>now(),
        ]);
    }

    private function audit(string $owner,string $type,string $precision,string $action): void
    {
        DB::table('location_consent_audits')->insert([
            'id'=>(string)Str::uuid7(),'owner_id'=>$owner,'audience_type'=>$type,
            'precision'=>$precision,'action'=>$action,'occurred_at'=>now(),
        ]);
    }

    public function revoke(string $owner,?string $shareId=null): array
    {
        $this->ready($owner);
        return DB::transaction(function () use ($owner,$shareId): array {
            DB::table('orbit_users')->where('id',$owner)->lockForUpdate()->first();
            $query=DB::table('location_shares')->where('owner_id',$owner)->where('status','active');
            if ($shareId!==null) {
                abort_unless(DB::table('location_shares')->where('id',$shareId)->where('owner_id',$owner)->exists(),
                    404,'Location share unavailable.');
                $query->where('id',$shareId);
            }
            $rows=$query->get();
            foreach ($rows as $row) {
                DB::table('location_shares')->where('id',$row->id)->update([
                    'status'=>'revoked','revoked_at'=>now(),'revision'=>DB::raw('revision + 1'),
                    'updated_at'=>now(),
                ]);
                if ($row->audience_type==='circle') {
                    $stillActive=DB::table('location_shares')->where('owner_id',$owner)
                        ->where('audience_type','circle')->where('audience_id',$row->audience_id)
                        ->where('status','active')->whereNull('revoked_at')
                        ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))
                        ->exists();
                    if (!$stillActive) $this->setCirclePermission($owner,$row->audience_id,false);
                }
                $this->audit($owner,$row->audience_type,$row->precision,'revoked');
            }
            if (!$this->policy->canUpload($owner)) {
                DB::table('location_latest')->where('owner_id',$owner)->delete();
            }
            return ['revoked'=>count($rows),'sharing_active'=>$this->policy->canUpload($owner)];
        },3);
    }

    /** Idempotent, strictly increasing observation time prevents replay resurrecting GPS. */
    public function ingest(string $owner,string $device,array $data): array
    {
        $this->ready($owner);
        abort_unless($this->policy->canUpload($owner),403,'No active location sharing consent.');
        $seen=Carbon::parse($data['observed_at'])->utc();
        $now=now()->utc();
        abort_unless($seen->gte($now->copy()->subSeconds((int) config('orbit_location.sample_max_age_seconds',90)))
            && $seen->lte($now->copy()->addSeconds((int)config('orbit_location.future_skew_seconds',15))),
            422,'Invalid sample timestamp.');
        abort_if($data['accuracy_m']>(float)config('orbit_location.max_accuracy_m',500),422,
            'Unreliable location accuracy.');
        $point=[
            'latitude'=>(float)$data['latitude'],
            'longitude'=>(float)$data['longitude'],
            'accuracy_m'=>(float)$data['accuracy_m'],
        ];
        if (isset($data['heading'])) $point['heading']=(float)$data['heading'];
        if (isset($data['speed_mps'])) $point['speed_mps']=(float)$data['speed_mps'];
        return DB::transaction(function () use ($owner,$device,$seen,$now,$point): array {
            DB::table('orbit_users')->where('id',$owner)->lockForUpdate()->first();
            abort_unless($this->policy->canUpload($owner),403,'Location consent revoked.');
            $previous=DB::table('location_latest')->where('owner_id',$owner)->first();
            if ($previous && Carbon::parse($previous->observed_at)->gte($seen)) {
                abort(409,'Location sample is not newer.');
            }
            $expires=$now->copy()->addSeconds((int)config('orbit_location.latest_ttl_seconds',120));
            DB::table('location_latest')->updateOrInsert(['owner_id'=>$owner],[
                'device_id'=>$device,
                'encrypted_payload'=>Crypt::encryptString(json_encode($point,JSON_THROW_ON_ERROR)),
                'observed_at'=>$seen,'received_at'=>$now,
                'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now,
            ]);
            return ['accepted'=>true,'observed_at'=>$seen->toIso8601String(),
                'expires_at'=>$expires->toIso8601String(),'realtime_available'=>false];
        },3);
    }

    private function coarse(float $coordinate): float
    {
        $grid=(float) config('orbit_location.approximate_grid_degrees',0.02);
        $grid=max(0.01,$grid); // Never accidentally publish unrounded precise positions.
        return round((floor($coordinate / $grid)+0.5)*$grid,5);
    }

    /** Viewer-scoped bounded projection. Never return owner history or false LIVE labels. */
    public function map(string $viewer,?string $circleId=null): array
    {
        $this->ready($viewer);
        if ($circleId!==null) {
            abort_unless(app(\App\Modules\Circle\Services\CircleAccessPolicy::class)
                ->isMember($circleId,$viewer),404,'Circle unavailable.');
        }
        $q=DB::table('location_shares as s')->where('s.status','active')
            ->where('s.owner_id','!=',$viewer)
            ->where(fn ($w)=>$w->whereNull('s.expires_at')->orWhere('s.expires_at','>',now()));
        if ($circleId!==null) {
            $q->where('s.audience_type','circle')->where('s.audience_id',$circleId);
        } else {
            $q->where(function($w) use($viewer): void {
                $w->where(fn($p)=>$p->where('s.audience_type','person')
                    ->where('s.audience_id',$viewer))
                  ->orWhere(fn($c)=>$c->where('s.audience_type','circle')
                    ->whereIn('s.audience_id',DB::table('circle_members')
                        ->where('user_id',$viewer)->whereNull('removed_at')->select('circle_id')));
            });
        }
        $max=(int)config('orbit_location.max_map_candidates',200);
        $candidates=$q->orderBy('s.owner_id')->limit($max+1)->select('s.*')->get();
        abort_if($candidates->count()>$max,422,'Map scope is too large.');
        $pins=[];
        foreach ($candidates as $share) {
            if (!$this->policy->visibleTo($share,$viewer)) continue;
            $id=(string)$share->owner_id;
            $latest=DB::table('location_latest as l')
                ->join('devices as d','d.id','=','l.device_id')
                ->where('l.owner_id',$id)
                ->where('d.user_id',$id)->where('d.trust_state','trusted')->whereNull('d.revoked_at')
                ->where('l.expires_at','>',now())
                ->whereExists(fn($sessions)=>$sessions->selectRaw('1')->from('orbit_sessions as os')
                    ->whereColumn('os.device_id','l.device_id')->where('os.user_id',$id)
                    ->whereNull('os.revoked_at')->where('os.expires_at','>',now()))
                ->first(['l.encrypted_payload','l.observed_at','l.received_at']);
            if (!$latest) continue;
            try {
                $position=json_decode(Crypt::decryptString($latest->encrypted_payload),true,512,JSON_THROW_ON_ERROR);
                if (!is_array($position) || !is_numeric($position['latitude']??null)
                    || !is_numeric($position['longitude']??null)) continue;
            } catch (\Throwable) {
                continue; // Do not leak ciphertext or malformed values to recipient.
            }
            $age=max(0,now()->diffInSeconds(Carbon::parse($latest->observed_at),false)*-1);
            if ($age>(int)config('orbit_location.latest_ttl_seconds',120)) continue;
            $precision=$this->policy->effectivePrecision($share);
            $lat=(float)$position['latitude']; $lng=(float)$position['longitude'];
            if ($precision==='approximate') {
                $lat=$this->coarse($lat); $lng=$this->coarse($lng);
            }
            // If multiple shares permit visibility, emit exactly one best
            // authorized projection, never duplicate people or raw metadata.
            $point=[
                'user_id'=>$id,'latitude'=>$lat,'longitude'=>$lng,
                'precision'=>$precision,
                'observed_at'=>Carbon::parse($latest->observed_at)->toIso8601String(),
                'received_at'=>Carbon::parse($latest->received_at)->toIso8601String(),
                'age_seconds'=>(int)$age,
                'state'=>$age <= (int)config('orbit_location.fresh_seconds',30) ? 'updated' : 'paused',
            ];
            if ($precision==='precise') $point['accuracy_m']=(float)($position['accuracy_m']??0);
            if (!isset($pins[$id]) || ($pins[$id]['precision']==='approximate' && $precision==='precise')) {
                $pins[$id]=$point;
            }
        }
        return [
            'circle_id'=>$circleId,'markers'=>array_values($pins),
            'realtime_available'=>false,'live_claimed'=>false,
            'server_time'=>now()->toIso8601String(),
        ];
    }

    /** Optional separately scheduled maintenance command; no production cron set here. */
    public function prune(): array
    {
        $latest=DB::table('location_latest')->where('expires_at','<=',now())->delete();
        $old=DB::table('location_shares')->where('status','active')
            ->whereNotNull('expires_at')->where('expires_at','<=',now())->get();
        foreach ($old as $share) {
            DB::table('location_shares')->where('id',$share->id)->where('status','active')->update([
                'status'=>'expired','revision'=>DB::raw('revision + 1'),'updated_at'=>now(),
            ]);
            if ($share->audience_type==='circle') {
                $this->setCirclePermission($share->owner_id,$share->audience_id,false);
            }
            $this->audit($share->owner_id,$share->audience_type,$share->precision,'expired');
        }
        // Purge non-active records; no historical GPS trail is retained.
        $shares=DB::table('location_shares')->whereIn('status',['expired','revoked'])
            ->where('updated_at','<',now()->subDays((int)config('orbit_location.operational_retention_days',7)))
            ->delete();
        $audit=DB::table('location_consent_audits')
            ->where('occurred_at','<',now()->subDays((int)config('orbit_location.consent_audit_retention_days',30)))
            ->delete();
        return ['latest_deleted'=>$latest,'shares_expired'=>$old->count(),
            'shares_pruned'=>$shares,'audits_pruned'=>$audit];
    }
}
