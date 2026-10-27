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
            'supported_durations'=>['one_hour','until_tonight','manual','always',
                ...(config('orbit_location.until_leaving_enabled')===true ? ['until_leaving'] : [])],
            'until_leaving_available'=>config('orbit_location.until_leaving_enabled')===true,
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
            'exit_radius_m'=>$r->duration==='until_leaving' ? (int)$r->geo_radius_m : null,
            'exit_anchor_ready'=>$r->duration==='until_leaving' && $r->geo_anchor_encrypted!==null,
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
        if ($duration==='until_leaving') {
            abort_unless(config('orbit_location.until_leaving_enabled')===true,422,
                'Departure sharing is awaiting policy approval.');
            abort_unless(isset($data['exit_radius_m']) && (int)$data['exit_radius_m']>=150
                && (int)$data['exit_radius_m']<=1000,422,'Exit radius required.');
        }
        abort_if($duration==='always' && $type!=='person',422,
            'Always sharing requires an explicitly selected trusted friend.');
        $expiry=match($duration) {
            'one_hour'=>now()->addHour(),
            'until_tonight'=>now($data['timezone'])->endOfDay()->utc(),
            'manual','always','until_leaving'=>null,
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
                    'status'=>'revoked','revoked_at'=>now(),'geo_anchor_encrypted'=>null,
                    'revision'=>DB::raw('revision + 1'),
                    'updated_at'=>now(),
                ]);
            }
            $id=(string)Str::uuid7();
            DB::table('location_shares')->insert([
                'id'=>$id,'owner_id'=>$owner,'audience_type'=>$type,'audience_id'=>$target,
                'precision'=>$data['precision'],'duration'=>$duration,'status'=>'active',
                'starts_at'=>now(),'expires_at'=>$expiry,'revoked_at'=>null,
                'geo_anchor_encrypted'=>null,
                'geo_radius_m'=>$duration==='until_leaving' ? (int)$data['exit_radius_m'] : null,
                'geo_exit_streak'=>0,
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
                    'status'=>'revoked','revoked_at'=>now(),'geo_anchor_encrypted'=>null,
                'geo_exit_streak'=>0,'revision'=>DB::raw('revision + 1'),
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

    /** Haversine separation in metres, used only for encrypted departure anchors. */
    private function separation(float $latA,float $lonA,float $latB,float $lonB): float
    {
        $deltaLat=deg2rad($latB-$latA);
        $deltaLon=deg2rad($lonB-$lonA);
        $h=sin($deltaLat/2)**2 + cos(deg2rad($latA))*cos(deg2rad($latB))
            * sin($deltaLon/2)**2;
        return 6371000.0 * 2 * atan2(sqrt(max(0,$h)),sqrt(max(0,1-$h)));
    }

    /**
     * First accepted OS sample anchors the approved exit radius. Require two
     * consecutive reliable observations outside radius + accuracy hysteresis.
     * Triggered stops erase the encrypted anchor immediately.
     */
    private function evaluateExits(string $owner,array $point): bool
    {
        if (config('orbit_location.until_leaving_enabled')!==true) return false;
        $shares=DB::table('location_shares')->where('owner_id',$owner)
            ->where('status','active')->where('duration','until_leaving')
            ->whereNull('revoked_at')->lockForUpdate()->get();
        $stopped=false;
        foreach ($shares as $share) {
            if ($share->geo_anchor_encrypted===null) {
                DB::table('location_shares')->where('id',$share->id)->update([
                    'geo_anchor_encrypted'=>Crypt::encryptString(json_encode([
                        'lat'=>$point['latitude'],'lon'=>$point['longitude'],
                    ],JSON_THROW_ON_ERROR)), 'geo_exit_streak'=>0,'updated_at'=>now(),
                ]);
                continue;
            }
            try {
                $anchor=json_decode(Crypt::decryptString($share->geo_anchor_encrypted),
                    true,512,JSON_THROW_ON_ERROR);
                if (!is_numeric($anchor['lat']??null) || !is_numeric($anchor['lon']??null)) {
                    continue; // Never revoke from malformed/unverified anchor.
                }
            } catch (\Throwable) {
                continue;
            }
            $radius=(int)$share->geo_radius_m;
            if ($radius<150 || $radius>1000) continue;
            $accurate=$point['accuracy_m']<=min(100.0,$radius/2);
            $outside=$this->separation((float)$anchor['lat'],(float)$anchor['lon'],
                    $point['latitude'],$point['longitude'])
                > $radius + max(50.0,$point['accuracy_m']);
            $streak=$accurate && $outside ? ((int)$share->geo_exit_streak+1) : 0;
            if ($streak<2) {
                DB::table('location_shares')->where('id',$share->id)->update([
                    'geo_exit_streak'=>$streak,'updated_at'=>now(),
                ]);
                continue;
            }
            DB::table('location_shares')->where('id',$share->id)->update([
                'status'=>'revoked','revoked_at'=>now(),'geo_anchor_encrypted'=>null,
                'geo_exit_streak'=>0,'revision'=>DB::raw('revision + 1'),'updated_at'=>now(),
            ]);
            if ($share->audience_type==='circle') {
                $this->setCirclePermission($owner,$share->audience_id,false);
            }
            $this->audit($owner,$share->audience_type,$share->precision,'revoked');
            $stopped=true;
        }
        return $stopped;
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
            $stoppedOnExit=$this->evaluateExits($owner,$point);
            if (!$this->policy->canUpload($owner)) {
                DB::table('location_latest')->where('owner_id',$owner)->delete();
                return ['accepted'=>false,'sharing_stopped'=>$stoppedOnExit,
                    'realtime_available'=>false];
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
    public function map(string $viewer,?string $circleId=null,?string $viewerDevice=null): array
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
            // Names come from the actual owner profile, never a demo user.
            // A private avatar UUID is returned only if THIS trusted viewer
            // device has an active original M6 envelope and the current
            // MediaPolicy independently permits decryption. No URLs or key
            // material are placed in map JSON.
            $subject=DB::table('orbit_users')->where('id',$id)->first();
            if ($subject) {
                $point['display_name']=(string)$subject->display_name;
                if ($viewerDevice && config('orbit_media.enabled') === true
                    && \Illuminate\Support\Facades\Schema::hasColumn('orbit_users','avatar_media_id')
                    && $subject->avatar_media_id !== null) {
                    $image=DB::table('media_objects')->where('id',$subject->avatar_media_id)
                        ->where('owner_user_id',$id)->where('purpose','avatar')->first();
                    if ($image && app(\App\Modules\Media\Services\MediaPolicy::class)
                        ->canRead($image,$viewer)
                        && DB::table('media_device_key_envelopes as e')
                            ->join('media_audience_grants as g','g.id','=','e.grant_id')
                            ->where('e.media_id',$image->id)
                            ->where('e.recipient_device_id',$viewerDevice)
                            ->where('e.variant','original')
                            ->whereNull('e.revoked_at')
                            ->whereNull('g.revoked_at')
                            ->where('g.recipient_user_id',$viewer)
                            ->where(fn($q)=>$q->whereNull('g.expires_at')
                                ->orWhere('g.expires_at','>',now()))
                            ->exists()) {
                        $point['avatar_media_id']=(string)$image->id;
                    }
                }
            }
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
                'status'=>'expired','geo_anchor_encrypted'=>null,
                'revision'=>DB::raw('revision + 1'),'updated_at'=>now(),
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
