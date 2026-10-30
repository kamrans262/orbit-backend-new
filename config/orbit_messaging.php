<?php
return [
    // HTTPS ciphertext backend is enabled once migrated. Client E2EE must
    // separately pass review before the app represents secure chat as ready.
    'enabled'=>(bool) env('ORBIT_MESSAGING_ENABLED',true),
    'https_enabled'=>true,
    'e2ee_client_verified'=>false,
    'realtime_enabled'=>false, // Hostinger Redis/WSS not provisioned
    'push_enabled'=>false, // Firebase credentials not provisioned
    'max_envelope_bytes'=>32768,
    'max_header_bytes'=>4096,
    'max_message_devices'=>128,
    'max_direct_devices'=>12,
    'max_one_time_prekeys'=>100,
    'page_max'=>100,
    'sync_max'=>200,
    'undelivered_retention_days'=>30,
    'per_minute_send'=>45,
];
