<?php
return [
    // HTTPS ciphertext backend is enabled once migrated. Client E2EE must
    // separately pass review before the app represents secure chat as ready.
    'enabled'=>(bool) env('ORBIT_MESSAGING_ENABLED',true),
    'https_enabled'=>true,
    // Safety barrier: only a separately security-reviewed client release may
    // enable encrypted sends. HTTPS mailbox availability is not E2EE proof.
    // Default stays OFF until mobile source review, signed native library
    // and two-device Olm/Megolm rotation tests pass. Never set for debugging.
    'e2ee_client_verified'=>(bool) env(
        'ORBIT_MESSAGING_E2EE_CLIENT_VERIFIED',false),
    // Only the pinned audited vodozemac protocol can be advertised. The
    // verified flag is necessary but not sufficient: the mobile app also
    // has its compile-time source release certification barrier.
    'approved_protocols'=>(bool)env('ORBIT_MESSAGING_E2EE_CLIENT_VERIFIED',false)
        ?['orbit-m13-olm-megolm-v1']:[],
    'realtime_enabled'=>false, // Hostinger Redis/WSS not provisioned
    'push_enabled'=>false, // Firebase credentials not provisioned
    'typing_http_enabled'=>true,
    'typing_cache_store'=>env('ORBIT_MESSAGING_TYPING_STORE',
        env('APP_ENV')==='testing' ? 'array' : 'file'),
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
