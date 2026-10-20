<?php

return [
    // Production-safe staged rollout. Unapproved flows remain unavailable
    // until product policies, Flutter integration and acceptance are signed off.
    'enabled' => (bool) env('ORBIT_M5_CIRCLES_ENABLED', false),
    'targeted_invites_enabled' => (bool) env('ORBIT_M5_TARGETED_INVITES_ENABLED', false),
    // Zero: invitation TTL not approved, so invitation issuance must stay disabled.
    'targeted_invite_lifetime_hours' => (int) env('ORBIT_M5_TARGETED_INVITE_LIFETIME_HOURS', 0),
    'role_changes_enabled' => (bool) env('ORBIT_M5_ROLE_CHANGES_ENABLED', false),
    'ownership_transfer_enabled' => (bool) env('ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED', false),
    // Location, presence, chat, Moments and SOS are never activated by M5 config.
];
