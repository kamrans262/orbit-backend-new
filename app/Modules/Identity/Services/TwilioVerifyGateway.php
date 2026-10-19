<?php

namespace App\Modules\Identity\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TwilioVerifyGateway
{
    public function configured(): bool
    {
        $sms = config('orbit_identity.sms', []);
        return preg_match('/^AC[0-9a-fA-F]{32}$/D', (string) ($sms['sid'] ?? '')) === 1
            && preg_match('/^VA[0-9a-fA-F]{32}$/D', (string) ($sms['verify_service_sid'] ?? '')) === 1
            && (string) ($sms['token'] ?? '') !== '';
    }

    public function enabled(): bool
    {
        return (string) config('orbit_identity.sms.verify_service_sid', '') !== '';
    }

    private function endpoint(string $resource): string
    {
        $sid = config('orbit_identity.sms.verify_service_sid');
        return 'https://verify.twilio.com/v2/Services/'.$sid.'/'.$resource;
    }

    public function start(string $recipient): bool
    {
        if (!$this->configured()) return false;
        try {
            $response = Http::timeout(12)->asForm()
                ->withBasicAuth(config('orbit_identity.sms.sid'), config('orbit_identity.sms.token'))
                ->post($this->endpoint('Verifications'), ['To' => $recipient, 'Channel' => 'sms']);
            return $response->successful() && $response->json('status') === 'pending';
        } catch (\Throwable) {
            return false;
        }
    }

    public function check(string $recipient, string $code): bool
    {
        if (!$this->configured()) return false;
        try {
            $response = Http::timeout(12)->asForm()
                ->withBasicAuth(config('orbit_identity.sms.sid'), config('orbit_identity.sms.token'))
                ->post($this->endpoint('VerificationCheck'), ['To' => $recipient, 'Code' => $code]);
            return $response->successful() && $response->json('status') === 'approved';
        } catch (\Throwable) {
            return false;
        }
    }
}
