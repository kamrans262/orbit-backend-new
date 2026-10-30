<?php

return [
    // Orbit testable Circle flows default ON. Laravel permissions, user consent,
    // trusted-device checks and explicit mutations remain authoritative.
    // Unprovisioned Firebase and destructive retention remain separately OFF.
    'enabled' => (bool) env('ORBIT_M5_CIRCLES_ENABLED', true),
    'targeted_invites_enabled' => (bool) env('ORBIT_M5_TARGETED_INVITES_ENABLED', true),
    // Recipient-bound invitations have a bounded 7-day TTL.
    'targeted_invite_lifetime_hours' => (int) env('ORBIT_M5_TARGETED_INVITE_LIFETIME_HOURS', 168),
    // Codes are single-use, seven-day, and recipient-bound; enabled only with invite rollout.
    'join_codes_enabled' => (bool) env('ORBIT_M5_JOIN_CODES_ENABLED', true),
    'role_changes_enabled' => (bool) env('ORBIT_M5_ROLE_CHANGES_ENABLED', true),
    'archive_enabled' => (bool) env('ORBIT_M5_ARCHIVE_ENABLED', true),
    // Temporary Circles: user-selected expiry, 72h read-only, then cold archive.
    // No automatic purge; scheduling requires explicit cron and QA approval.
    'temporary_circles_enabled' => (bool) env('ORBIT_M5_TEMPORARY_CIRCLES_ENABLED', true),
    // Irreversible retention runs must be separately authorized. No automatic
    // purge scheduling. Both switches default off even if temporary mode is on.
    'retention_policy_approved' => (bool) env('ORBIT_M5_RETENTION_POLICY_APPROVED', false),
    'retention_purge_enabled' => (bool) env('ORBIT_M5_RETENTION_PURGE_ENABLED', false),
    'ownership_transfer_enabled' => (bool) env('ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED', true),
    // Only implemented protected-content integrations can be switched ON.
    // An ON gate never replaces per-user location consent and Circle ACL checks.
    // Firebase transport is fully optional. No initialization or remote traffic
    // occurs while this switch is OFF; all consumer sharing remains independent.
    'firebase' => [
        'enabled' => (bool) env('ORBIT_M5_FIREBASE_ENABLED', false),
        'service_account_path' => env('ORBIT_M5_FIREBASE_SERVICE_ACCOUNT_PATH', ''),
        'project_id' => env('ORBIT_M5_FIREBASE_PROJECT_ID', ''),
        'database_url' => env('ORBIT_M5_FIREBASE_DATABASE_URL', ''),
        'api_key' => env('ORBIT_M5_FIREBASE_API_KEY', ''),
        'app_id' => env('ORBIT_M5_FIREBASE_APP_ID', ''),
        'sender_id' => env('ORBIT_M5_FIREBASE_SENDER_ID', ''),
    ],
    // 30-day temporary Circle deletion: triple gated and OFF until an
    // approved disposable-environment verification and production release.
    'retention_autopurge_enabled' => (bool) env('ORBIT_M5_RETENTION_AUTOPURGE_ENABLED', false),
    'consumer_enabled' => [
        'location'=>(bool) env('ORBIT_M5_LOCATION_CONSUMER_ENABLED', true),'presence'=>(bool) env('ORBIT_M5_PRESENCE_CONSUMER_ENABLED', false),'moments'=>false,
        'check_in'=>(bool) env('ORBIT_M5_CHECK_IN_CONSUMER_ENABLED', false),'plans'=>false,'status'=>false,
        'ping'=>(bool) env('ORBIT_M5_PING_CONSUMER_ENABLED', true),'message'=>(bool) env('ORBIT_M5_MESSAGE_CONSUMER_ENABLED',true),
    ],
    // Location, presence, chat, Moments and SOS are never activated by M5 config.
];
