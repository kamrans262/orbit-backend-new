<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Verifies QR throttles isolate trusted users even on the same Wi-Fi IP. */
final class OrbitM5QrRateLimitTest extends TestCase
{
    private function keys(string $limiter, string $user, string $ip): array
    {
        $callback = RateLimiter::limiter($limiter);
        $this->assertNotNull($callback);
        $request = Request::create('/api/v1/circles/invites/code/preview', 'POST', [], [], [],
            ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('orbit_session', (object) ['user_id' => $user]);
        $limits = $callback($request);
        $this->assertCount(2, $limits);
        return $limits;
    }

    public function test_preview_limit_is_per_account_with_shared_ip_abuse_cap(): void
    {
        [$a, $aIp] = $this->keys('orbit-circle-code-preview', 'user-a', '203.0.113.7');
        [$b, $bIp] = $this->keys('orbit-circle-code-preview', 'user-b', '203.0.113.7');
        $this->assertSame(8, $a->maxAttempts);
        $this->assertSame(60, $aIp->maxAttempts);
        $this->assertNotSame($a->key, $b->key);
        $this->assertSame($aIp->key, $bIp->key);
        $this->assertNotSame($a->key, $aIp->key);
    }

    public function test_redeem_limit_is_per_account_and_stays_stricter(): void
    {
        [$a, $ipA] = $this->keys('orbit-circle-code-redeem', 'user-a', '203.0.113.7');
        [$b, $ipB] = $this->keys('orbit-circle-code-redeem', 'user-b', '203.0.113.7');
        $this->assertSame(6, $a->maxAttempts);
        $this->assertSame(40, $ipA->maxAttempts);
        $this->assertNotSame($a->key, $b->key);
        $this->assertSame($ipA->key, $ipB->key);
    }

    public function test_preview_and_redeem_namespaces_are_separate(): void
    {
        [$preview, $previewIp] = $this->keys('orbit-circle-code-preview', 'user-a', '203.0.113.7');
        [$redeem, $redeemIp] = $this->keys('orbit-circle-code-redeem', 'user-a', '203.0.113.7');
        $this->assertNotSame($preview->key, $redeem->key);
        $this->assertNotSame($previewIp->key, $redeemIp->key);
    }
}
