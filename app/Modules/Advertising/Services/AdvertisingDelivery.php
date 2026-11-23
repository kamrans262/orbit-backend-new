<?php

namespace App\Modules\Advertising\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class AdvertisingDelivery
{
    public function __construct(private readonly AdvertisingPolicy $policy) {}

    private function proof(string $decision, string $digest, string $expires): string
    {
        return hash_hmac('sha256',$decision.'|'.$digest.'|'.$expires,(string)config('app.key'));
    }

    private function token(object $d): string
    {
        $end=(string)$d->expires_at;
        return rtrim(strtr(base64_encode($d->id.'|'.$end),'+/','-_'),'=').'.'
            .$this->proof($d->id,$d->viewer_digest,$end);
    }

    private function verifyToken(object $d, string $token): bool
    {
        // Constant-time compare of scoped proof; malformed tokens never parse.
        return hash_equals($this->token($d),$token);
    }

    private function present(object $d): ?array
    {
        $campaign=DB::table('ad_campaigns')->where('id',$d->campaign_id)->first();
        if (!$campaign || !( $eligible = $this->policy->eligible($campaign))) return null;
        [, $creative]=$eligible;
        if ((int)$d->creative_revision !== (int)$creative->revision
            || now()->gte($d->expires_at) || $d->status !== 'issued') return null;
        $url=URL::temporarySignedRoute('orbit.api.ads.asset',
            now()->addMinutes((int)config('orbit_advertising.asset_minutes',3)),
            ['creative'=>$creative->id,'decision'=>$d->id]);
        return [
            'decision_id'=>$d->id,
            'placement'=>$d->placement_key,
            'creative'=>[
                'title'=>$creative->title,'subtitle'=>$creative->subtitle,
                'image_url'=>$url,'disclosure'=>'Sponsored',
            ],
            'cta'=>['destination_url'=>$creative->cta_url],
            'expires_at'=>$d->expires_at,
            'measurement_token'=>$this->token($d),
        ];
    }

    public function decision(string $viewer, string $placement, string $requestId): ?array
    {
        $this->policy->assertAvailable();
        $slot=$this->policy->placement($placement);
        if (!$slot) return null;
        $digest=$this->policy->digest($viewer);
        return DB::transaction(function () use ($digest,$placement,$slot,$requestId): ?array {
            $previous=DB::table('ad_decisions')->where('viewer_digest',$digest)
                ->where('placement_key',$placement)->where('request_id',$requestId)->first();
            if ($previous) {
                // Never issue a second campaign for a previously committed request.
                return $this->present($previous);
            }
            $candidates=DB::table('ad_campaigns as c')
                ->join('ad_campaign_placements as cp','cp.campaign_id','=','c.id')
                ->where('cp.placement_key',$placement)->where('c.status','active')
                ->where('c.starts_at','<=',now())->where('c.ends_at','>',now())
                ->select('c.*')->orderByDesc('c.priority')->orderBy('c.created_at')
                ->limit(40)->get();
            foreach ($candidates as $campaign) {
                if (!$this->policy->eligible($campaign)) continue;
                $day=now('UTC')->format('Y-m-d');
                DB::table('ad_frequency_windows')->insertOrIgnore([
                    'id'=>(string)Str::uuid7(),
                    'viewer_digest'=>$digest,'campaign_id'=>$campaign->id,
                    'placement_key'=>$placement,'window_start_utc'=>$day,
                    'decisions'=>0,'impressions'=>0,
                ]);
                $window=DB::table('ad_frequency_windows')
                    ->where('viewer_digest',$digest)->where('campaign_id',$campaign->id)
                    ->where('placement_key',$placement)->where('window_start_utc',$day)
                    ->lockForUpdate()->first();
                if (!$window
                    || $window->decisions >= $slot->daily_decision_cap
                    || $window->impressions >= $slot->daily_impression_cap) continue;
                $id=(string)Str::uuid7();
                DB::table('ad_decisions')->insert([
                    'id'=>$id,'campaign_id'=>$campaign->id,'placement_key'=>$placement,
                    'viewer_digest'=>$digest,'request_id'=>$requestId,
                    'creative_revision'=>$campaign->creative_revision,
                    'issued_at'=>now('UTC'),
                    'expires_at'=>now('UTC')->addMinutes((int)config('orbit_advertising.decision_minutes',10)),
                    'status'=>'issued',
                ]);
                DB::table('ad_frequency_windows')->where('id',$window->id)->update([
                    'decisions'=>$window->decisions+1,'last_decision_at'=>now('UTC'),
                ]);
                return $this->present(DB::table('ad_decisions')->where('id',$id)->first());
            }
            return null;
        },3);
    }

    public function record(string $viewer, string $id, string $event, string $eventId,
        string $token, ?int $visibleMs): array
    {
        $this->policy->assertAvailable();
        return DB::transaction(function () use ($viewer,$id,$event,$eventId,$token,$visibleMs): array {
            $d=DB::table('ad_decisions')->where('id',$id)->lockForUpdate()->first();
            abort_unless($d && hash_equals($d->viewer_digest,$this->policy->digest($viewer))
                && $this->verifyToken($d,$token), 404,'Decision unavailable.');
            $campaign=DB::table('ad_campaigns')->where('id',$d->campaign_id)->first();
            abort_unless($campaign && $this->policy->placement($d->placement_key)
                && $this->policy->eligible($campaign)
                && $d->status==='issued' && now()->lt($d->expires_at)
                && (int)$d->creative_revision===(int)$campaign->creative_revision,
                404,'Decision unavailable.');
            $existing=DB::table('ad_measurement_events')
                ->where('decision_id',$id)->where('event_type',$event)->first();
            if ($existing) return ['accepted'=>true,'deduplicated'=>true];
            if ($event==='impression') {
                // A network decision is never automatically counted as a view.
                // The threshold is policy-gated until product approval.
                abort_unless($visibleMs!==null && $visibleMs >= (int)config('orbit_advertising.minimum_visible_ms'),422,
                    'Viewability evidence insufficient.');
            } else {
                abort_unless($d->impression_at!==null,422,'Click requires a verified impression.');
            }
            $slot=$this->policy->placement($d->placement_key);
            $day=\Illuminate\Support\Carbon::parse($d->issued_at)->utc()->toDateString();
            $window=DB::table('ad_frequency_windows')
                ->where('viewer_digest',$d->viewer_digest)
                ->where('campaign_id',$d->campaign_id)
                ->where('placement_key',$d->placement_key)
                ->where('window_start_utc',$day)->lockForUpdate()->first();
            abort_unless($window,409,'Frequency window unavailable.');
            if ($event==='impression') {
                abort_unless($window->impressions<$slot->daily_impression_cap,429,'Impression limit reached.');
            }
            DB::table('ad_measurement_events')->insert([
                'id'=>(string)Str::uuid7(),'decision_id'=>$id,
                'event_type'=>$event,'client_event_id'=>$eventId,
                'accepted_at'=>now('UTC'),'proof_digest'=>hash('sha256',$token),
            ]);
            DB::table('ad_decisions')->where('id',$id)->update([
                $event==='impression'?'impression_at':'click_at'=>now('UTC'),
            ]);
            if ($event==='impression') DB::table('ad_frequency_windows')->where('id',$window->id)
                ->update(['impressions'=>$window->impressions+1]);
            DB::table('ad_daily_aggregates')->insertOrIgnore([
                'campaign_id'=>$d->campaign_id,'placement_key'=>$d->placement_key,
                'day_utc'=>now('UTC')->toDateString(),
                'impressions'=>0,'clicks'=>0,'updated_at'=>now('UTC'),
            ]);
            DB::table('ad_daily_aggregates')->where('campaign_id',$d->campaign_id)
                ->where('placement_key',$d->placement_key)
                ->where('day_utc',now('UTC')->toDateString())
                ->increment($event==='impression'?'impressions':'clicks',1,['updated_at'=>now('UTC')]);
            return ['accepted'=>true,'deduplicated'=>false];
        },3);
    }

    public function authorizeAsset(string $viewer, string $creative, string $decision): object
    {
        $this->policy->assertAvailable();
        $d=DB::table('ad_decisions')->where('id',$decision)->first();
        abort_unless($d && hash_equals($d->viewer_digest,$this->policy->digest($viewer))
            && now()->lt($d->expires_at) && $this->policy->placement($d->placement_key),
            404,'Asset unavailable.');
        $campaign=DB::table('ad_campaigns')->where('id',$d->campaign_id)->first();
        $eligible=$campaign ? $this->policy->eligible($campaign) : null;
        abort_unless($eligible && $eligible[1]->id===$creative
            && (int)$eligible[1]->revision===(int)$d->creative_revision,
            404,'Asset unavailable.');
        return $eligible[1];
    }
}
