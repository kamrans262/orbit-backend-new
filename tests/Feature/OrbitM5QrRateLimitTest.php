<?php

namespace Tests\Feature;

use App\Models\OrbitUser;
use App\Models\OrbitDevice;
use App\Modules\Identity\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Verifies QR throttles isolate trusted users even on the same Wi-Fi IP. */
final class OrbitM5QrRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function trustedToken(): string
    {
        $user = OrbitUser::create([
            'display_name' => 'QR Test',
            'username' => 'qr_'.Str::lower(Str::random(12)),
            'status' => 'active',
        ]);
        $device = OrbitDevice::create([
            'user_id' => $user->id,
            'device_public_id' => (string) Str::uuid(),
            'name' => 'QR Test Device',
            'platform' => 'android',
            'public_key' => base64_encode(random_bytes(32)),
            'trust_state' => 'trusted',
            'attestation_state' => 'verified',
            'approved_at' => now(),
        ]);
        return app(TokenService::class)->issue($user, $device, null)['access_token'];
    }

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

    public function test_second_trusted_account_on_same_ip_is_not_locked_out(): void
    {
        config()->set('orbit_circle.enabled', true);
        config()->set('orbit_circle.targeted_invites_enabled', true);
        config()->set('orbit_circle.join_codes_enabled', true);
        $tokenA = $this->trustedToken();
        $tokenB = $this->trustedToken();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.209']);
        $route = '/api/v1/circles/invites/code/preview';
        $invalidCode = str_repeat('Z', 40);

        // Even unsuccessful previews count toward the brute-force limit.
        for ($i = 0; $i < 8; $i++) {
            $this->withToken($tokenA)->postJson($route, ['code' => $invalidCode])
                ->assertNotFound();
        }
        $this->withToken($tokenA)->postJson($route, ['code' => $invalidCode])
            ->assertStatus(429);

        // A second trusted account on this very same IP keeps its own quota.
        $this->withToken($tokenB)->postJson($route, ['code' => $invalidCode])
            ->assertNotFound();
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
