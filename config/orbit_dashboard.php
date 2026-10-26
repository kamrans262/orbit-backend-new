<?php

// M8 Home Dashboard: shared-hosting safe, private, disabled until rollout.
return [
    'enabled' => (bool) env('ORBIT_M8_DASHBOARD_ENABLED', false),
    'circle_limit' => 8,
    'people_limit' => 12,
    'moment_limit' => 3,
    'smart_cards_enabled' => (bool) env('ORBIT_M8_SMART_CARDS_ENABLED', true),
    'dismiss_hours' => 24,
    'max_circle_order' => 20,
    // Plans, presence, SOS, Ping, location and activity have no authorized M8 provider yet.
    'future_capabilities' => [
        'plans' => false,
        'presence' => false,
        'ping' => false,
        'sos' => false,
        'location' => false,
        'activity' => false,
    ],
];
