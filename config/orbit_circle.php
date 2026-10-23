<?php

return [
    // Production-safe staged rollout. Unapproved flows remain unavailable
    // until product policies, Flutter integration and acceptance are signed off.
    'enabled' => (bool) env('ORBIT_M5_CIRCLES_ENABLED', false),
    'targeted_invites_enabled' => (bool) env('ORBIT_M5_TARGETED_INVITES_ENABLED', false),
    // Zero: invitation TTL not approved, so invitation issuance must stay disabled.
    'targeted_invite_lifetime_hours' => (int) env('ORBIT_M5_TARGETED_INVITE_LIFETIME_HOURS', 168),
    // Codes are single-use, seven-day, and recipient-bound; enabled only with invite rollout.
    'join_codes_enabled' => (bool) env('ORBIT_M5_JOIN_CODES_ENABLED', false),
    'role_changes_enabled' => (bool) env('ORBIT_M5_ROLE_CHANGES_ENABLED', false),
    'archive_enabled' => (bool) env('ORBIT_M5_ARCHIVE_ENABLED', false),
    // Temporary Circles: user-selected expiry, 72h read-only, then cold archive.
    // No automatic purge; scheduling requires explicit cron and QA approval.
    'temporary_circles_enabled' => (bool) env('ORBIT_M5_TEMPORARY_CIRCLES_ENABLED', false),
    // Irreversible retention runs must be separately authorized. No automatic
    // purge scheduling. Both switches default off even if temporary mode is on.
    'retention_policy_approved' => (bool) env('ORBIT_M5_RETENTION_POLICY_APPROVED', false),
    'retention_purge_enabled' => (bool) env('ORBIT_M5_RETENTION_PURGE_ENABLED', false),
    'ownership_transfer_enabled' => (bool) env('ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED', false),
    // Future M6+ protected-content consumer gates. Defaults DENY for every
    // capability until that module's actual read API, consent UI and privacy
    // tests are independently accepted. No Firebase connection is implied.
    'consumer_enabled' => [
        'location'=>false,'presence'=>false,'moments'=>false,
        'check_in'=>false,'plans'=>false,'status'=>false,
        'ping'=>false,'message'=>false,
    ],
    // Location, presence, chat, Moments and SOS are never activated by M5 config.
];
