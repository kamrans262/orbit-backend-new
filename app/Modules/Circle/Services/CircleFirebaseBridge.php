<?php

namespace App\Modules\Circle\Services;

use App\Models\OrbitSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Disabled-by-default Firebase Realtime Database invalidation bridge.
 *
 * Firebase contains ONLY Circle revisions and live-session ACL entries.
 * No private content, Circle name, roster, recipient code or location is
 * ever replicated. Actual content authorization stays in Laravel.
 *
 * Uses RS256 service-account assertions; credentials must live outside
 * public_html and outside the repository. This bridge is not an FCM sender.
 */
final class CircleFirebaseBridge
{
    public function enabled(): bool
    {
        return config('orbit_circle.enabled') === true
            && config('orbit_circle.firebase.enabled') === true;
    }

    private function credentials(): array
    {
        $path = (string) config('orbit_circle.firebase.service_account_path');
        if ($path === '' || !is_file($path) || !is_readable($path)
            || filesize($path) > 65536) {
            throw new RuntimeException('M5 Firebase service-account file unavailable.');
        }
        $real = realpath($path);
        $webRoot = realpath(public_path());
        if ($real === false || ($webRoot !== false &&
            ($real === $webRoot ||
             str_starts_with($real, $webRoot.DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('Firebase credentials cannot reside in web root.');
        }
        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data) || ($data['type'] ?? '') !== 'service_account'
            || !filter_var($data['client_email'] ?? null, FILTER_VALIDATE_EMAIL)
            || !is_string($data['private_key'] ?? null)
            || !str_contains($data['private_key'], 'BEGIN PRIVATE KEY')
            || ($data['project_id'] ?? null) !== config('orbit_circle.firebase.project_id')) {
            throw new RuntimeException('M5 Firebase service-account identity mismatch.');
        }
        return $data;
    }

    private function databaseUrl(): string
    {
        $value = rtrim((string) config('orbit_circle.firebase.database_url'), '/');
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https'
            || !preg_match('/^[a-z0-9-]+(?:-default-rtdb)?(?:\.[a-z0-9-]+)?\.(?:firebaseio\.com|firebasedatabase\.app)$/D', $host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ($parts['path'] ?? '') !== '' || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('M5 Firebase database URL must be the canonical HTTPS origin.');
        }
        return $value;
    }

    /** Firebase custom-token session identity; no scope is granted by the JWT. */
    private function signedJwt(array $claims, array $creds): string
    {
        $encode = static fn(array $data): string => rtrim(strtr(base64_encode(
            json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $input = $header.'.'.$payload;
        if (!openssl_sign($input, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('M5 Firebase token signing failed.');
        }
        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    public function bootstrap(OrbitSession $session): array
    {
        abort_unless($this->enabled(), 404);
        $creds = $this->credentials();
        $url = $this->databaseUrl();
        $client = [
            'api_key' => (string) config('orbit_circle.firebase.api_key'),
            'project_id' => (string) config('orbit_circle.firebase.project_id'),
            'app_id' => (string) config('orbit_circle.firebase.app_id'),
            'sender_id' => (string) config('orbit_circle.firebase.sender_id'),
            'database_url' => $url,
        ];
        foreach ($client as $field => $value) {
            if ($value === '') throw new RuntimeException('M5 Firebase client configuration incomplete: '.$field);
        }
        if ($session->revoked_at !== null || $session->expires_at->isPast()) abort(401);
        $now = time();
        $token = $this->signedJwt([
            'iss' => $creds['client_email'], 'sub' => $creds['client_email'],
            'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
            'iat' => $now, 'exp' => $now + 900,
            'uid' => $session->id,
            'claims' => ['orbit_m5' => true, 'orbit_device' => $session->device_id,
                'orbit_session' => $session->id],
        ], $creds);
        return ['enabled' => true, 'custom_token' => $token, 'firebase' => $client,
            'uid' => $session->id, 'expires_in' => 900];
    }

    /** OAuth2 service-account access token; never returned to a mobile client. */
    private function accessToken(array $creds): string
    {
        $now = time();
        $assertion = $this->signedJwt([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now, 'exp' => $now + 3000,
        ], $creds);
        $reply = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        if (!$reply->successful() || !is_string($reply->json('access_token'))
            || $reply->json('access_token') === '') {
            throw new RuntimeException('M5 Firebase OAuth credentials were rejected.');
        }
        return $reply->json('access_token');
    }

    /**
     * Rebuild server-approved session ACL from SQL at the instant of publish.
     * Multi-location PATCH updates ACL + non-sensitive revision atomically.
     */
    public function syncCircle(string $circleId): void
    {
        if (!$this->enabled()) return;
        $circle = DB::table('circles')->where('id', $circleId)->first();
        $acl = [];
        if ($circle !== null && app(CircleTemporaryLifecycle::class)->state($circle) !== 'archive_due'
            && $circle->archived_at === null) {
            $sessions = DB::table('orbit_sessions as s')
                ->join('devices as d', 'd.id', '=', 's.device_id')
                ->join('orbit_users as u', 'u.id', '=', 's.user_id')
                ->join('circle_members as m', 'm.user_id', '=', 's.user_id')
                ->where('m.circle_id', $circleId)->whereNull('m.removed_at')
                ->whereNull('s.revoked_at')->where('s.expires_at', '>', now())
                ->whereNull('d.revoked_at')->where('d.trust_state', 'trusted')
                ->whereNotNull('d.public_key')
                ->where('u.status', 'active')->whereNull('u.deletion_requested_at')
                ->get(['s.id as sid', 's.user_id as uid',
                    's.device_id as did']);
            $policy = app(CircleAccessPolicy::class);
            foreach ($sessions as $session) {
                if ($policy->isMember($circleId, $session->uid)) {
                    $acl[$session->sid] = $session->did;
                }
            }
        }
        $creds = $this->credentials();
        $url = $this->databaseUrl();
        $token = $this->accessToken($creds);
        $response = Http::withToken($token)->acceptJson()->timeout(8)
            ->patch($url.'/orbit_m5.json', [
                'acl/'.$circleId => $acl ?: null,
                'revisions/'.$circleId => $circle !== null
                    ? (int) $circle->revision : null,
            ]);
        if (!$response->successful()) {
            throw new RuntimeException('M5 Firebase invalidation publication failed.');
        }
    }

    /**
     * Inline best-effort invalidation after a successful MariaDB commit.
     * Circle mutations NEVER roll back or show false failure if Firebase is
     * unavailable. The durable outbox independently retries publication.
     */
    public function afterCommit(string $circleId): void
    {
        if (!$this->enabled()) return;
        DB::afterCommit(function () use ($circleId): void {
            try {
                $this->syncCircle($circleId);
            } catch (\Throwable $error) {
                // No sensitive tokens or private Circle details in logs.
                \Illuminate\Support\Facades\Log::warning(
                    'M5 realtime publish deferred to retry worker.'
                );
            }
        });
    }

    /**
     * An outbox retry does not grant access. Failure preserves the event for a
     * later bounded retry. Never log OAuth tokens or credential JSON.
     */
    public function publishPending(int $limit = 20): int
    {
        if (!$this->enabled()) return 0;
        $ids = DB::table('circle_event_outbox')->whereNull('processed_at')
            ->orderBy('created_at')->limit(min(max($limit, 1), 100))
            ->pluck('id');
        $done = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$done): void {
                $event = DB::table('circle_event_outbox')->where('id', $id)
                    ->lockForUpdate()->first();
                if (!$event || $event->processed_at !== null) return;
                $this->syncCircle($event->circle_id);
                DB::table('circle_event_outbox')->where('id', $id)
                    ->update(['processed_at' => now()]);
                $done++;
            }, 2);
        }
        return $done;
    }
}
