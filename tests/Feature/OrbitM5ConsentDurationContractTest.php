<?php

namespace Tests\Feature;

use App\Modules\Circle\Services\CircleConsentPolicy;
use Tests\TestCase;

/**
 * Future protected consumers must fail closed even when a stored consent
 * record is edited outside the public API. These tests do not enable
 * location, messages, presence, or a realtime delivery transport.
 */
final class OrbitM5ConsentDurationContractTest extends TestCase
{
    private function grant(string $duration, mixed $expiry, array $changes = []): object
    {
        $settings = array_merge([
            'reviewed' => true,
            'audience' => 'circle',
            'duration' => $duration,
            'precision' => 'approximate',
        ], $changes);

        return (object) [
            'enabled' => true,
            'value_json' => json_encode($settings, JSON_THROW_ON_ERROR),
            'expires_at' => $expiry,
        ];
    }

    public function test_permanent_consent_needs_explicit_persistent_choice(): void
    {
        $policy = app(CircleConsentPolicy::class);
        $this->assertTrue($policy->consentCurrentlyValid(
            $this->grant('persistent', null), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('until_expiry', null), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('persistent', null, ['reviewed' => false]), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('persistent', null, ['audience' => 'public']), 'location'));
    }

    public function test_time_limited_consent_requires_matching_duration_and_future_expiry(): void
    {
        $policy = app(CircleConsentPolicy::class);
        $future = now()->addHour()->toDateTimeString();

        $this->assertTrue($policy->consentCurrentlyValid(
            $this->grant('until_expiry', $future), 'location'));
        // A timestamp by itself is never affirmative timed consent.
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('persistent', $future), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('unknown', $future), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('until_expiry', now()->subSecond()), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('until_expiry', 'not-a-date'), 'location'));
    }

    public function test_hidden_precision_and_unapproved_capabilities_always_deny(): void
    {
        $policy = app(CircleConsentPolicy::class);
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('persistent', null, ['precision' => 'hidden']), 'location'));
        $this->assertFalse($policy->consentCurrentlyValid(
            $this->grant('persistent', null), 'unsupported'));
        $this->assertTrue($policy->consentCurrentlyValid(
            $this->grant('persistent', null), 'moments'));
        // This pure result is NOT a live grant. CircleAccessPolicy and the
        // downstream service gate independently deny unimplemented consumers.
        $this->assertFalse(config('orbit_circle.consumer_enabled.moments', false));
    }
}
