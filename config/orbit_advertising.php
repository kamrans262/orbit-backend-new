<?php

// M20 is a stand-alone, first-party sponsorship service. ALL delivery gates
// default OFF until source documents, placement policy and privacy approval.
return [
    'enabled' => (bool) env('ORBIT_M20_ADVERTISING_ENABLED', false),
    'slots' => ['home.promo', 'moments.promo'],
    'asset_max_bytes' => 4 * 1024 * 1024,
    'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'max_width' => 2400,
    'max_height' => 2400,
    'min_width' => 300,
    'min_height' => 100,
    'decision_minutes' => 10,
    'asset_minutes' => 3,
    'max_cap_per_day' => 20, // Validation ceiling, NOT an activated policy.
    'retention_approved' => (bool) env('ORBIT_M20_RETENTION_APPROVED', false),
    'age_protection_approved' => (bool) env('ORBIT_M20_AGE_POLICY_APPROVED', false),
    'viewability_approved' => (bool) env('ORBIT_M20_VIEWABILITY_APPROVED', false),
    'privacy_approved' => (bool) env('ORBIT_M20_PRIVACY_POLICY_APPROVED', false),
];
