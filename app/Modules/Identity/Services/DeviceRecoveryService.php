<?php

namespace App\Modules\Identity\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

final class DeviceRecoveryService
{
    /** Only a recently OTP-authenticated session on a registered pending device. */
    public function eligible(OrbitSession $session): bool
    {
        if ($session->created_at->lt(now()->subMinutes(30)) ||
            $session->revoked_at || $session->expires_at->isPast()) return false;
        $user = OrbitUser::find($session->user_id);
        $device = OrbitDevice::find($session->device_id);
        return $user && $user->status === 'active' && !$user->deletion_requested_at &&
            $device && $device->user_id === $user->id && !$device->revoked_at &&
            $device->trust_state === 'pending' && $device->public_key &&
            OrbitDevice::query()->where('user_id', $user->id)
                ->where('trust_state', 'trusted')->whereNull('revoked_at')->exists();
    }

    /** Restricts recovery codes to existing verified identities on THIS account. */
    public function verifiedRecipient(string $userId, string $input, OtpService $otp): ?array
    {
        $channel = str_contains($input, '@') ? 'email' : 'sms';
        $value = $otp->normalized($channel, $input);
        if (!$value) return null;
        $found = UserIdentity::query()
            ->where('user_id', $userId)
            ->where('provider', $channel === 'sms' ? 'phone' : 'email')
            ->where('provider_subject', $value)
            ->whereNotNull('verified_at')->exists();
        return $found ? ['channel' => $channel, 'value' => $value] : null;
    }

    public function event(string $userId, string $deviceId, string $name): void
    {
        app(TokenService::class)->event($userId, $deviceId, $name, 'password_and_otp_required');
    }

    /** One transaction: consume purpose-bound OTP, revoke others, trust current. */
    public function complete(
        OrbitSession $session, array $data, OtpService $otp, OrbitPasswordHasher $passwords,
    ): string {
        $key = 'orbit:device-recovery:complete:'.hash_hmac('sha256',
            $session->user_id, (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, 8)) return 'rate_limited';
        RateLimiter::hit($key, 900);

        $result = DB::transaction(function () use ($session, $data, $otp, $passwords): string {
            $user = OrbitUser::query()->whereKey($session->user_id)->lockForUpdate()->first();
            // The eligibility check must be repeated under the account lock.
            if (!$user || !$this->eligible($session)) return 'ineligible';
            $recipient = $this->verifiedRecipient($user->id, $data['recipient'], $otp);
            if (!$recipient || !$user->password_hash ||
                !$passwords->check($data['password'], $user->password_hash)) return 'invalid';
            if (!$otp->consumeDeviceRecoveryCode($recipient['channel'],
                $recipient['value'], $data['code'])) return 'invalid';

            OrbitSession::query()->where('user_id', $user->id)
                ->where('id', '!=', $session->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            OrbitDevice::query()->where('user_id', $user->id)
                ->where('id', '!=', $session->device_id)->whereNull('revoked_at')
                ->update(['trust_state' => 'revoked', 'revoked_at' => now()]);
            OrbitDevice::query()->whereKey($session->device_id)
                ->update(['trust_state' => 'trusted', 'approved_at' => now()]);
            $this->event($user->id, $session->device_id, 'device.recovery_completed');
            return 'recovered';
        });

        if ($result !== 'recovered') return $result;
        RateLimiter::clear($key);
        // Best-effort out-of-band security warning. Never allow mail errors
        // to roll back an already committed recovery or log its credentials.
        $emails = UserIdentity::query()->where('user_id', $session->user_id)
            ->where('provider', 'email')->whereNotNull('verified_at')
            ->pluck('email_norm')->filter()->unique();
        foreach ($emails as $email) {
            try {
                Mail::raw('Orbit device access was recovered. Previous devices have been signed out. '
                    .'If this was not you, secure your account and contact support.',
                    fn ($message) => $message->to($email)->subject('Orbit device access recovered'));
            } catch (\Throwable) {
                // Recovery status and event are persisted independently.
            }
        }
        return 'recovered';
    }
}
