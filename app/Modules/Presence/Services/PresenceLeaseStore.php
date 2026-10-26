<?php

namespace App\Modules\Presence\Services;

use App\Models\OrbitDevice;
use App\Models\OrbitSession;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Ephemeral 90-second per-device leases. No persistent last-seen history.
 * Redis cache locks preserve a user's concurrent multi-device heartbeats.
 */
final class PresenceLeaseStore
{
    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        $name = (string) config('orbit_presence.store', 'redis');
        if ($name !== 'redis' && !app()->environment('testing')) {
            throw new ServiceUnavailableHttpException(null, 'Presence transport unavailable.');
        }
        try {
            return Cache::store($name);
        } catch (\Throwable) {
            throw new ServiceUnavailableHttpException(null, 'Presence transport unavailable.');
        }
    }

    private function key(string $user): string
    {
        return 'orbit:m9:presence:'.hash_hmac('sha256', $user, (string) config('app.key'));
    }

    private function guarded(callable $call): mixed
    {
        try {
            return $call();
        } catch (ServiceUnavailableHttpException $e) {
            throw $e;
        } catch (\Throwable) {
            // No silent MySQL/polling substitute for a failed Redis backend.
            throw new ServiceUnavailableHttpException(null, 'Presence transport unavailable.');
        }
    }

    public function touch(string $user, string $device, string $state): array
    {
        return $this->guarded(function () use ($user, $device, $state): array {
            $cache = $this->store();
            $key = $this->key($user);
            $ttl = (int) config('orbit_presence.lease_seconds', 90);
            $now = now()->timestamp;
            return $cache->lock($key.':lock', 4)->block(2, function () use ($cache,$key,$device,$state,$now,$ttl): array {
                $leases = $cache->get($key, []);
                if (!is_array($leases)) $leases = [];
                $leases = array_filter($leases, static fn ($lease): bool =>
                    is_array($lease) && ($lease['until'] ?? 0) > $now);
                $leases[$device] = ['state'=>$state,'at'=>$now,'until'=>$now+$ttl];
                // Bound stale index growth without making long-term activity history.
                if (count($leases) > (int) config('orbit_presence.max_devices',12)) {
                    uasort($leases, static fn ($a,$b): int => $b['at'] <=> $a['at']);
                    $leases = array_slice($leases, 0, (int)config('orbit_presence.max_devices',12), true);
                }
                $cache->put($key, $leases, $ttl);
                return ['state'=>$state,'expires_at'=>now()->addSeconds($ttl)->toIso8601String()];
            });
        });
    }

    public function clear(string $user, string $device): void
    {
        $this->guarded(function () use ($user, $device): void {
            $cache = $this->store(); $key = $this->key($user);
            $cache->lock($key.':lock',4)->block(2,function () use ($cache,$key,$device): void {
                $leases = $cache->get($key, []);
                if (!is_array($leases)) return;
                unset($leases[$device]);
                if ($leases) $cache->put($key,$leases,(int) config('orbit_presence.lease_seconds',90));
                else $cache->forget($key);
            });
        });
    }

    public function snapshot(string $user): ?array
    {
        return $this->guarded(function () use ($user): ?array {
            $now = now()->timestamp;
            $leases = $this->store()->get($this->key($user), []);
            if (!is_array($leases) || $leases === []) return null;
            $deviceIds = array_keys(array_filter($leases, static fn ($lease): bool =>
                is_array($lease) && ($lease['until'] ?? 0) > $now));
            if (!$deviceIds) return null;
            // Revoked, untrusted, deleted and cross-user devices never make someone online.
            $trusted = OrbitDevice::query()->whereIn('id',$deviceIds)->where('user_id',$user)
                ->where('trust_state','trusted')->whereNull('revoked_at')->pluck('id')->all();
            // A trusted device alone is insufficient after logout, global
            // session revocation or refresh-token replay: require live auth.
            $sessions = OrbitSession::query()->where('user_id',$user)
                ->whereIn('device_id',$trusted)->whereNull('revoked_at')
                ->where('expires_at','>',now())->distinct()->pluck('device_id')->all();
            $valid = array_intersect_key($leases,array_flip($sessions));
            if (!$valid) return null;
            $active = collect($valid)->contains(static fn ($lease): bool => $lease['state']==='active');
            $latest = max(array_column($valid,'at'));
            $expiry = max(array_column($valid,'until'));
            return [
                'state'=>$active ? 'active_now' : 'idle',
                'observed_at'=>\Illuminate\Support\Carbon::createFromTimestamp($latest,'UTC')->toIso8601String(),
                'expires_at'=>\Illuminate\Support\Carbon::createFromTimestamp($expiry,'UTC')->toIso8601String(),
            ];
        });
    }
}
