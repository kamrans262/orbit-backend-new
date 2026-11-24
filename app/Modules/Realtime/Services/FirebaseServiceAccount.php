<?php
namespace App\Modules\Realtime\Services;

use RuntimeException;

/** Shared service-account JWT signer, extracted unchanged from M5. */
final class FirebaseServiceAccount
{
    public static function signedJwt(array $claims, array $creds): string
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

}
