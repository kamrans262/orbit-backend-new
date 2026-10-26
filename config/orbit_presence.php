<?php

// M9 Presence remains OFF until policy, realtime gateway, Flutter and QA approval.
return [
    'enabled' => (bool) env('ORBIT_M9_PRESENCE_ENABLED', false),
    // Redis is the only supported production store. 'array' is for isolated tests.
    'store' => env('ORBIT_M9_PRESENCE_STORE', 'redis'),
    'lease_seconds' => 90,
    'max_devices' => 12,
    'max_circle_members' => 50,
    'typing_ttl_seconds' => 5,
    'typing_throttle_seconds' => 3,
    'realtime_enabled' => (bool) env('ORBIT_M9_REALTIME_ENABLED', false),
    'redis_connection' => env('ORBIT_M9_REDIS_CONNECTION','default'),
];
