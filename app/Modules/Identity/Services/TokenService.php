<?php

namespace App\Modules\Identity\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use App\Models\OrbitUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TokenService
{
    private function key(): string
    {
        $configured = (string) config('app.key');
        $bytes = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : $configured;
        if (!is_string($bytes) || strlen($bytes) < 32) throw new RuntimeException('APP_KEY must be at least 256 bits.');
        return hash_hkdf('sha256', $bytes, 32, 'orbit-access-v1');
    }
    private function encoded(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
    public function refreshHash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->key());
    }
    private function jwt(OrbitSession $session): string
    {
        $now = time();
        $header = $this->encoded(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->encoded(json_encode([
            'iss' => rtrim((string) config('app.url'), '/'),
            'aud' => 'orbit-mobile', 'sub' => $session->user_id, 'sid' => $session->id,
            'did' => $session->device_id, 'iat' => $now, 'nbf' => $now,
            'exp' => $now + (int) config('orbit_identity.access_minutes', 15) * 60,
            'jti' => (string) Str::uuid7(),
        ], JSON_THROW_ON_ERROR));
        $data = $header.'.'.$payload;
        return $data.'.'.$this->encoded(hash_hmac('sha256', $data, $this->key(), true));
    }
    public function authenticate(string $jwt): ?OrbitSession
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 3500) return null;
        [$h,$p,$s] = $parts;
        if (!hash_equals($this->encoded(hash_hmac('sha256', $h.'.'.$p, $this->key(), true)), $s)) return null;
        $raw = base64_decode(strtr($p, '-_', '+/'), true);
        try {
            $claims = json_decode($raw ?: '', true, 32, JSON_THROW_ON_ERROR);
            $header = json_decode(base64_decode(strtr($h, '-_', '+/'), true) ?: '', true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { return null; }
        $now = time();
        if (($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT'
            || ($claims['aud'] ?? '') !== 'orbit-mobile'
            || ($claims['iss'] ?? '') !== rtrim((string) config('app.url'), '/')
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['nbf'] ?? null) || $claims['nbf'] > $now + 10
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 10
            || !is_string($claims['sid'] ?? null) || !Str::isUuid($claims['sid'])
            || !is_string($claims['sub'] ?? null) || !Str::isUuid($claims['sub'])
            || !is_string($claims['did'] ?? null) || !Str::isUuid($claims['did'])) return null;
        $session = OrbitSession::query()->find($claims['sid']);
        if (!$session || $session->user_id !== $claims['sub'] || $session->device_id !== $claims['did']
            || $session->revoked_at || $session->expires_at->isPast()) return null;
        $user = OrbitUser::find($session->user_id);
        $device = OrbitDevice::find($session->device_id);
        if (!$user || $user->status !== 'active' || !$device || $device->revoked_at) return null;
        return $session;
    }
    public function issue(OrbitUser $user, OrbitDevice $device, ?string $ip): array
    {
        $refresh = $this->encoded(random_bytes(48));
        $session = OrbitSession::create([
            'user_id' => $user->id, 'device_id' => $device->id,
            'token_family_id' => (string) Str::uuid7(),
            'refresh_token_hash' => $this->refreshHash($refresh),
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, $this->key()) : null,
            'expires_at' => now()->addDays((int) config('orbit_identity.refresh_days', 60)),
        ]);
        return $this->response($session, $refresh);
    }
    public function rotate(string $refreshToken): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{64}$/D', $refreshToken)) return null;
        return DB::transaction(function () use ($refreshToken): ?array {
            $hash = $this->refreshHash($refreshToken);
            // A reused rotated token revokes the entire token family.
            $used = DB::table('refresh_token_history')->where('token_hash', $hash)->first();
            if ($used) {
                $old = OrbitSession::query()->whereKey($used->session_id)->lockForUpdate()->first();
                if ($old) {
                    OrbitSession::query()->where('token_family_id', $old->token_family_id)->update(['revoked_at' => now()]);
                    $this->event($old->user_id, $old->device_id, 'session.refresh_replay', 'token_reuse');
                }
                return null;
            }
            $session = OrbitSession::query()->where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if (!$session || $session->revoked_at || $session->expires_at->isPast()) return null;
            $user = OrbitUser::find($session->user_id);
            $device = OrbitDevice::find($session->device_id);
            if (!$user || $user->status !== 'active' || !$device || $device->revoked_at) return null;
            DB::table('refresh_token_history')->insert(['token_hash' => $hash, 'session_id' => $session->id, 'used_at' => now()]);
            $next = $this->encoded(random_bytes(48));
            $session->update(['refresh_token_hash' => $this->refreshHash($next), 'rotated_at' => now(),
                'last_used_at' => now()]);
            return $this->response($session->fresh(), $next);
        });
    }
    public function revokeDevice(OrbitDevice $device, string $reason = 'user_revoke'): void
    {
        DB::transaction(function () use ($device, $reason): void {
            OrbitDevice::query()->whereKey($device->id)->update(['revoked_at' => now(), 'trust_state' => 'revoked']);
            OrbitSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->event($device->user_id, $device->id, 'device.revoked', $reason);
        });
    }
    public function event(?string $uid, ?string $did, string $action, ?string $reason = null): void
    {
        DB::table('orbit_identity_events')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $uid,
            'device_id' => $did, 'action' => $action, 'reason_code' => $reason, 'created_at' => now(),
        ]);
        if ($uid !== null && in_array($action, [
            'auth.logout', 'auth.logout_all', 'session.revoked',
            'session.refresh_replay', 'device.revoked',
            'auth.password_reset', 'account.deletion_requested',
            'device.recovery_completed',
        ], true)) {
            app(\App\Modules\Circle\Services\CircleLifecycleIntegrity::class)
                ->identityAccessChanged($uid);
        }
    }
    private function response(OrbitSession $session, string $refresh): array
    {
        return ['token_type' => 'Bearer', 'access_token' => $this->jwt($session),
            'expires_in' => (int) config('orbit_identity.access_minutes', 15) * 60,
            'refresh_token' => $refresh, 'refresh_expires_at' => $session->expires_at->toIso8601String(),
            'user' => ['id' => $session->user_id], 'device' => ['id' => $session->device_id]];
    }
}
