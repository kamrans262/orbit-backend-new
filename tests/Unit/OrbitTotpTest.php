<?php

namespace Tests\Unit;

use App\Modules\Config\Services\Totp;
use PHPUnit\Framework\TestCase;

final class OrbitTotpTest extends TestCase
{
    public function test_known_rfc_6238_time_step_vector(): void
    {
        // RFC 6238 SHA-1 test secret "12345678901234567890"; 8-digit 94287082, last six 287082.
        $totp = new Totp();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $totp->codeAt($secret, 59));
        $this->assertTrue($totp->verify($secret, '287082', 59));
        $this->assertFalse($totp->verify($secret, '000000', 59));
    }

    public function test_generated_secret_has_expected_strength_and_encoding(): void
    {
        $secret = (new Totp())->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }
}
