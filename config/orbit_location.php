<?php

// M10 stays OFF until reviewed mobile permissions, privacy tests and infrastructure approval.
// These settings are not a live transport health declaration.
return [
    'enabled' => (bool) env('ORBIT_M10_LOCATION_ENABLED', false),
    'realtime_enabled' => (bool) env('ORBIT_M10_LOCATION_REALTIME_ENABLED', false),
    'realtime_verified' => false, // Stage 2 provides NO GPS websocket broadcaster.
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
