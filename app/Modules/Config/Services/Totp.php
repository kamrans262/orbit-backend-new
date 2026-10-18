<?php

namespace App\Modules\Config\Services;

use InvalidArgumentException;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $chunk) {
            $secret .= self::ALPHABET[bindec($chunk)];
        }
        return $secret;
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        $secret = strtoupper(preg_replace('/\s+/', '', $secret));
        if ($secret === '' || preg_match('/[^A-Z2-7]/', $secret)) {
            throw new InvalidArgumentException('Invalid TOTP secret');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $chunk) {
            $decoded .= chr(bindec($chunk));
        }
        $counter = intdiv($timestamp, 30);
        $message = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $message, $decoded, true);
        $offset = ord(substr($hash, -1)) & 0xf;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (!preg_match('/^\d{6}$/D', $code)) return false;
        $now = $timestamp ?? time();
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals($this->codeAt($secret, $now + $i * 30), $code)) return true;
        }
        return false;
    }
}
