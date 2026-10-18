<?php

return [
    'access_minutes' => 15,
    'refresh_days' => 60,
    'otp_minutes' => 5,
    'otp_request_per_recipient' => 3,
    'otp_request_per_ip_hour' => 10,
    'otp_verify_per_recipient' => 5,
    'sms' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
        'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
    ],
];
