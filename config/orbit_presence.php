<?php

// M9 Presence remains OFF until policy, realtime gateway, Flutter and QA approval.
return [
    'enabled' => (bool) env('ORBIT_M9_PRESENCE_ENABLED', false),
    // Database cache provides atomic per-user locks on shared PHP hosting.
    // Explicit legacy Redis deployments remain supported until M25 cutover.
    'store' => env('ORBIT_M9_PRESENCE_STORE', 'orbit_ephemeral'),
    'lease_seconds' => 90,
    'max_devices' => 12,
    'max_circle_members' => 50,
    'typing_ttl_seconds' => 5,
    'typing_throttle_seconds' => 3,
    'broadcast_throttle_seconds' => 5,
    'realtime_enabled' => (bool) env('ORBIT_M9_REALTIME_ENABLED', false),
    'redis_connection' => env('ORBIT_M9_REDIS_CONNECTION','default'),
    // Secure gateway-only introspection; never place this value in Flutter.
    'gateway_secret' => env('ORBIT_M9_GATEWAY_SECRET',''),
];
