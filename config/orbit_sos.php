<?php

return [
    // User-initiated SOS is free and does not depend on subscriptions.
    'enabled' => (bool) env('ORBIT_SOS_ENABLED', true),
    'location_ttl_minutes' => max(5, min(60, (int) env('ORBIT_SOS_LOCATION_TTL_MINUTES', 30))),
    'max_location_age_seconds' => 120,
    'max_history' => 40,
    // Operational tuning is persisted in sos_controls. Core intake cannot be disabled from Admin.
    'default_escalation_seconds' => 120,
    'max_push_attempts' => 6,
];
