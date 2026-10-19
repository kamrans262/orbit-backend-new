<?php

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\Hash;

/**
 * Orbit consumer passwords use Argon2id, independent of Laravel Admin.
 * Existing bcrypt hashes stay valid and are upgraded on password sign-in.
 */
final class OrbitPasswordHasher
{
    private const ARGON_OPTIONS = ['memory' => 65536, 'time' => 3, 'threads' => 1];

    public function make(string $plain): string
    {
        return Hash::driver('argon2id')->make($plain, self::ARGON_OPTIONS);
    }

    public function check(string $plain, string $stored): bool
    {
        return match (password_get_info($stored)['algoName'] ?? 'unknown') {
            'bcrypt' => Hash::driver('bcrypt')->check($plain, $stored),
            'argon2id' => Hash::driver('argon2id')->check($plain, $stored),
            default => false,
        };
    }

    public function needsRehash(string $stored): bool
    {
        $algo = password_get_info($stored)['algoName'] ?? 'unknown';
        if ($algo !== 'argon2id') return $algo === 'bcrypt';

        return Hash::driver('argon2id')->needsRehash($stored, self::ARGON_OPTIONS);
    }
}
