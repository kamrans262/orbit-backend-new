<?php

return [
    // Explicit release gate. Never treat migration or UI presence as activation.
    'enabled' => (bool) env('ORBIT_CHECK_IN_ENABLED', false),
    'custom_max_characters' => 160,
    'sender_per_minute' => (int) env('ORBIT_CHECK_IN_SENDER_PER_MINUTE', 10),
    'max_audience_scopes' => 25,
    'max_snapshot_recipients' => 1000,
    // Technical safety bound, not a user-facing default or approved product TTL.
    'max_expiry_days' => (int) env('ORBIT_CHECK_IN_MAX_EXPIRY_DAYS', 30),
    'page_size_max' => 30,
    'location_reference_enabled' => false,
    'push_enabled' => false,
    'realtime_enabled' => false,
];
