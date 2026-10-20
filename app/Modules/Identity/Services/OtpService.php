<?php

namespace App\Modules\Identity\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitUser;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

final class OtpService
{
    private function secret(): string
    {
        $key = (string) config('app.key');
        return hash('sha256', $key.'|orbit-otp-v1', true);
    }
    public function normalized(string $channel, string $recipient): ?string
    {
        $recipient = trim($recipient);
        if ($channel === 'email') {
            $email = strtolower($recipient);
            return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }
        if ($channel === 'sms' && preg_match('/^\+[1-9]\d{7,14}$/D', $recipient)) return $recipient;
        return null;
    }
    private function digest(string $channel, string $recipient): string
    {
        return hash_hmac('sha256', $channel.'|'.$recipient, $this->secret());
    }
    public function request(string $channel, string $recipient, string $ip, string $purpose = 'signup'): string
    {
        $normalized = $this->normalized($channel, $recipient);
        if (!$normalized) return 'invalid';
        $digest = $this->digest($channel, $normalized);
        $byRecipient = 'orbit:otp:rec:'.$digest;
        $byIp = 'orbit:otp:ip:'.hash_hmac('sha256', $ip, $this->secret());
        if (RateLimiter::tooManyAttempts($byRecipient, (int) config('orbit_identity.otp_request_per_recipient', 3))
            || RateLimiter::tooManyAttempts($byIp, (int) config('orbit_identity.otp_request_per_ip_hour', 10))) {
            return 'rate_limited';
        }
        // With Twilio Verify, Twilio generates the SMS code; we never mint a competing local code.
        $useTwilioVerify = $channel === 'sms' && app(TwilioVerifyGateway::class)->enabled();
        $code = $useTwilioVerify ? null : (string) random_int(100000, 999999);
        $id = (string) Str::uuid7();
        // Invalidate older unconsumed codes for the same recipient.
        DB::table('otp_challenges')->where('recipient_hash', $digest)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        DB::table('otp_challenges')->insert([
            'id' => $id, 'channel' => $channel, 'recipient_hash' => $digest,
            'purpose' => $purpose,
            // The local challenge is a single-use lock even when verification happens at Twilio.
            'code_hash' => hash_hmac('sha256', $id.'|'.($code ?? bin2hex(random_bytes(24))), $this->secret()),
            'expires_at' => now()->addMinutes((int) config('orbit_identity.otp_minutes', 5)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            if ($channel === 'email') {
                Mail::raw('Your Orbit verification code is '.$code.'. It expires in 5 minutes. Never share this code.', function ($message) use ($normalized): void {
                    $message->to($normalized)->subject('Your Orbit verification code');
                });
            } elseif ($useTwilioVerify) {
                if (!app(TwilioVerifyGateway::class)->start($normalized)) {
                    throw new RuntimeException('Twilio Verify is unavailable.');
                }
            } else {
                $settings = config('orbit_identity.sms');
                if (!$settings['sid'] || !$settings['token'] || !$settings['from']) {
                    throw new RuntimeException('SMS gateway is not configured.');
                }
                Http::timeout(12)->asForm()->withBasicAuth($settings['sid'], $settings['token'])
                    ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($settings['sid']).'/Messages.json', [
                        'To' => $normalized, 'From' => $settings['from'],
                        'Body' => 'Orbit verification code: '.$code.' (expires in 5 minutes). Do not share.',
                    ])->throw();
            }
        } catch (\Throwable) {
            DB::table('otp_challenges')->where('id', $id)->delete();
            // Never reveal code or provider credentials in API errors/logs.
            return 'delivery_unavailable';
        }
        RateLimiter::hit($byRecipient, 600);
        RateLimiter::hit($byIp, 3600);
        return 'sent';
    }
    /** Link a new contact identifier only after a fresh OTP and trusted session. */
    public function linkIdentity(string $channel, string $recipient, string $code, OrbitUser $user, TokenService $tokens): string
    {
        $normalized = $this->normalized($channel, $recipient);
        if (!$normalized || !preg_match('/^\\d{6}$/D', $code)) return 'invalid';
        $digest = $this->digest($channel, $normalized);
        $key = 'orbit:otp:link:'.$digest;
        if (RateLimiter::tooManyAttempts($key, 5)) return 'rate_limited';
        RateLimiter::hit($key, 600);
        return DB::transaction(function () use ($channel, $normalized, $code, $digest, $key, $user, $tokens): string {
            $provider = $channel === 'sms' ? 'phone' : 'email';
            $already = UserIdentity::query()->where('provider', $provider)
                ->where('provider_subject', $normalized)->lockForUpdate()->first();
            if ($already) return $already->user_id === $user->id ? 'already_linked' : 'unavailable';

            $challenge = DB::table('otp_challenges')
                ->where('recipient_hash', $digest)->where('channel', $channel)
                ->where('purpose', 'link')
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();
            if (!$challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) return 'invalid';
            DB::table('otp_challenges')->where('id', $challenge->id)->increment('attempts');
            $approved = ($channel === 'sms' && app(TwilioVerifyGateway::class)->enabled())
                ? app(TwilioVerifyGateway::class)->check($normalized, $code)
                : hash_equals($challenge->code_hash, hash_hmac('sha256', $challenge->id.'|'.$code, $this->secret()));
            if (!$approved) return 'invalid';
            DB::table('otp_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
            UserIdentity::create([
                'user_id' => $user->id, 'provider' => $provider,
                'provider_subject' => $normalized,
                'email_norm' => $provider === 'email' ? $normalized : null,
                'phone_e164' => $provider === 'phone' ? $normalized : null,
                'verified_at' => now(),
            ]);
            $tokens->event($user->id, null, 'identity.linked', $provider);
            RateLimiter::clear($key);
            return 'linked';
        });
    }

    /**
     * OTP-only password recovery. Never creates accounts or mobile sessions;
     * the caller must authorize its change with this exact challenge purpose.
     */
    public function consumeRecoveryCode(string $channel, string $recipient, string $code): bool
    {
        return $this->consumePurposeCode($channel, $recipient, $code, 'reset');
    }

    /** A separate one-time challenge that cannot be exchanged for password reset or login. */
    public function consumeDeviceRecoveryCode(string $channel, string $recipient, string $code): bool
    {
        return $this->consumePurposeCode($channel, $recipient, $code, 'device_recovery');
    }

    private function consumePurposeCode(string $channel, string $recipient, string $code, string $purpose): bool
    {
        $normalized = $this->normalized($channel, $recipient);
        if (!$normalized || !preg_match('/^\\d{6}$/D', $code)) return false;
        $digest = $this->digest($channel, $normalized);
        $key = 'orbit:otp:recovery:'.$purpose.':'.$digest;
        if (RateLimiter::tooManyAttempts($key, 5)) return false;
        RateLimiter::hit($key, 600);
        return DB::transaction(function () use ($channel, $digest, $code, $key, $normalized, $purpose): bool {
            $challenge = DB::table('otp_challenges')->where('recipient_hash', $digest)
                ->where('channel', $channel)->where('purpose', $purpose)
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();
            if (!$challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) return false;
            DB::table('otp_challenges')->where('id', $challenge->id)->increment('attempts');
            $approved = ($channel === 'sms' && app(TwilioVerifyGateway::class)->enabled())
                ? app(TwilioVerifyGateway::class)->check($normalized, $code)
                : hash_equals($challenge->code_hash, hash_hmac('sha256', $challenge->id.'|'.$code, $this->secret()));
            if (!$approved) return false;
            DB::table('otp_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
            RateLimiter::clear($key);
            return true;
        });
    }

    public function verify(string $channel, string $recipient, string $code, string $devicePublicId, string $platform, string $deviceName, TokenService $tokens, ?string $ip, string $purpose = 'signup'): ?array
    {
        $normalized = $this->normalized($channel, $recipient);
        if (!$normalized || !preg_match('/^\d{6}$/D', $code)) return null;
        $digest = $this->digest($channel, $normalized);
        $key = 'orbit:otp:verify:'.$digest;
        if (RateLimiter::tooManyAttempts($key, (int) config('orbit_identity.otp_verify_per_recipient', 5))) return null;
        RateLimiter::hit($key, 600);
        return DB::transaction(function () use ($channel, $normalized, $code, $digest, $devicePublicId, $platform, $deviceName, $tokens, $ip, $key, $purpose): ?array {
            $challenge = DB::table('otp_challenges')->where('recipient_hash', $digest)->where('channel', $channel)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')->orderByDesc('created_at')->lockForUpdate()->first();
            if (!$challenge || now()->greaterThan($challenge->expires_at) || $challenge->attempts >= 5) return null;
            DB::table('otp_challenges')->where('id', $challenge->id)->increment('attempts');
            // SMS codes issued by Twilio Verify are checked with that service, never against
            // a locally generated code. A consumed local challenge blocks token replay.
            $approved = ($channel === 'sms' && app(TwilioVerifyGateway::class)->enabled())
                ? app(TwilioVerifyGateway::class)->check($normalized, $code)
                : hash_equals($challenge->code_hash, hash_hmac('sha256', $challenge->id.'|'.$code, $this->secret()));
            if (!$approved) return null;
            DB::table('otp_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);
            $provider = $channel === 'sms' ? 'phone' : 'email';
            $identity = UserIdentity::query()->where('provider', $provider)->where('provider_subject', $normalized)->lockForUpdate()->first();
            if ($identity) {
                // A verified signup code proves contact ownership but may
                // never authenticate or overwrite an existing Orbit account.
                if ($purpose === 'signup') return ['account_exists' => true];
                $user = OrbitUser::find($identity->user_id);
                if (!$user || $user->status !== 'active' || $purpose !== 'login'
                    || !$user->password_hash) return null;
            } else {
                // A login code never creates a new account.
                if ($purpose !== 'signup') return null;
                $user = OrbitUser::create(['status' => 'active']);
                UserIdentity::create([
                    'user_id' => $user->id, 'provider' => $provider, 'provider_subject' => $normalized,
                    'email_norm' => $provider === 'email' ? $normalized : null,
                    'phone_e164' => $provider === 'phone' ? $normalized : null,
                    'verified_at' => now(),
                ]);
            }
            $hasTrusted = OrbitDevice::query()->where('user_id', $user->id)->where('trust_state', 'trusted')
                ->whereNull('revoked_at')->exists();
            $device = OrbitDevice::query()->where('user_id', $user->id)->where('device_public_id', $devicePublicId)->first();
            if ($device && $device->revoked_at) return null;
            if (!$device) {
                $device = OrbitDevice::create([
                    'user_id' => $user->id, 'device_public_id' => $devicePublicId,
                    'platform' => $platform, 'name' => $deviceName, 'trust_state' => $hasTrusted ? 'pending' : 'trusted',
                    'approved_at' => $hasTrusted ? null : now(),
                ]);
            }
            $device->update(['last_seen_at' => now()]);
            $result = $tokens->issue($user, $device, $ip);
            $tokens->event($user->id, $device->id, 'auth.otp_verified', $channel);
            RateLimiter::clear($key);
            return $result + ['device_requires_registration' => !$device->public_key,
                'device_requires_approval' => $device->trust_state === 'pending'];
        });
    }
}
