<?php

namespace App\Modules\Advertising\Services;

use App\Modules\CheckIn\Services\CheckInAccessPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AdvertisingPolicy
{
    public function __construct(private readonly CheckInAccessPolicy $sessions) {}

    public function installed(): bool
    {
        return Schema::hasTable('ad_sponsors')
            && Schema::hasTable('ad_placements')
            && Schema::hasTable('ad_creatives')
            && Schema::hasTable('ad_decisions')
            && Schema::hasTable('ad_measurement_events');
    }

    public function trusted(string $user, string $device): void
    {
        $this->sessions->assertSession($user, $device);
    }

    public function policyApproved(): bool
    {
        return config('orbit_advertising.retention_approved') === true
            && config('orbit_advertising.privacy_approved') === true
            && config('orbit_advertising.age_protection_approved') === true
            && config('orbit_advertising.viewability_approved') === true
            && is_int(config('orbit_advertising.minimum_visible_ms'))
            && config('orbit_advertising.minimum_visible_ms') > 0;
    }

    public function available(): bool
    {
        return $this->installed() && config('orbit_advertising.enabled') === true
            && $this->policyApproved();
    }

    public function assertAvailable(): void
    {
        abort_unless($this->available(), 503, 'Advertisements unavailable.');
    }

    public function placement(string $key): ?object
    {
        if (!$this->available() || !in_array($key, config('orbit_advertising.slots', []), true)) {
            return null;
        }
        $slot=DB::table('ad_placements')->where('key',$key)->first();
        if (!$slot || !$slot->enabled || $slot->daily_decision_cap === null
            || $slot->daily_impression_cap === null
            || (int)$slot->daily_decision_cap < 1 || (int)$slot->daily_impression_cap < 1) {
            return null;
        }
        return $slot;
    }

    public function digest(string $user): string
    {
        // No raw person identifier stored in advertising records.
        return hash_hmac('sha256', $user, (string) config('app.key'));
    }

    public function destinationIsSafe(string $url, string $domain): bool
    {
        $parts=parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme']??'')) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])) return false;
        $host=strtolower(rtrim((string)$parts['host'],'.'));
        return $host === strtolower(rtrim($domain,'.'))
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && $host !== 'localhost'
            && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D', $host) === 1;
    }

    public function eligible(object $campaign): ?array
    {
        if (!in_array($campaign->status,['active','scheduled'],true)
            || now()->lt($campaign->starts_at) || now()->gte($campaign->ends_at)) return null;
        $sponsor=DB::table('ad_sponsors')->where('id',$campaign->sponsor_id)->first();
        $creative=DB::table('ad_creatives')->where('id',$campaign->creative_id)->first();
        if (!$sponsor || !$creative
            || $sponsor->status !== 'verified' || $sponsor->verification_status !== 'verified'
            || $sponsor->archived_at !== null
            || $creative->status !== 'approved'
            || $creative->sponsor_id !== $sponsor->id
            || (int)$creative->revision !== (int)$campaign->creative_revision
            || !$this->destinationIsSafe($creative->cta_url,$sponsor->approved_domain)) return null;
        return [$sponsor, $creative];
    }
}
