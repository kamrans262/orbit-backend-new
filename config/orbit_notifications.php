<?php

return [
    // In-app notifications use HTTPS and the local database, not Firebase.
    'enabled' => (bool) env('ORBIT_NOTIFICATIONS_ENABLED', true),
    'push_enabled' => (bool) env('ORBIT_NOTIFICATIONS_PUSH_ENABLED', false),
    'max_page' => 30,
    'project_batch' => 75,
    'dispatch_batch' => 40,
    'max_attempts' => 8,
    'worker_lease_seconds' => 120,
    // Never awaken old backfilled activity as a new OS push.
    'push_max_age_minutes' => 30,
    // Seven-day compatibility with existing Moment inbox. Product-wide
    // retention remains configurable and requires approval before release.
    'retention_days' => max(1, min(30, (int) env('ORBIT_NOTIFICATIONS_RETENTION_DAYS', 7))),
    'categories' => ['moments', 'pings', 'check_ins', 'messages', 'friends', 'circles', 'security'],
    // SOS critical escalation and marketing broadcast belong to later modules.
];
