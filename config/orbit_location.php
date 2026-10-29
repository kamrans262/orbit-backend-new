<?php

// M10 foreground REST map and explicit-consent sharing are ON for test builds.
// A server ON flag is not user GPS opt-in. Ghost Mode, trusted device and
// reviewed audience grants remain required. Realtime needs external WSS/Redis.
return [
    'enabled' => (bool) env('ORBIT_M10_LOCATION_ENABLED', true),
    'realtime_enabled' => (bool) env('ORBIT_M10_LOCATION_REALTIME_ENABLED', false),
    // Only an operator-tested gateway may set the independent verified gate.
    'realtime_verified' => (bool) env('ORBIT_M10_REALTIME_VERIFIED',false),
    'gateway_secret' => env('ORBIT_M10_GATEWAY_SECRET',''),
    'realtime_store' => env('ORBIT_M10_REALTIME_STORE','redis'),
    'redis_connection' => env('ORBIT_M10_REDIS_CONNECTION','default'),
    'wss_url' => env('ORBIT_M10_WSS_URL',''),
    'latest_ttl_seconds' => 120,
    'fresh_seconds' => 30,
    'max_accuracy_m' => 500,
    'sample_max_age_seconds' => 90,
    'future_skew_seconds' => 15,
    'max_shares_per_user' => 60,
    // Provisional exit geofence algorithm; off without client/security approval.
    'until_leaving_enabled' => (bool) env('ORBIT_M10_GEOFENCE_ENABLED', false),
    'max_map_candidates' => 200,
    'operational_retention_days' => 7,
    'consent_audit_retention_days' => 30,
    'approximate_grid_degrees' => 0.02, // Conservative ~2 km; requires product sign-off.
];
