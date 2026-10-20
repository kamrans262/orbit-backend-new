<?php

return [
    // Safe defaults used where the client's separate API/Realtime spec is absent.
    // These are server-controlled limits, not entitlement gates.
    'daily_outbound_limit' => (int) env('ORBIT_SOCIAL_DAILY_OUTBOUND_LIMIT', 20),
    'new_account_daily_limit' => (int) env('ORBIT_SOCIAL_NEW_ACCOUNT_DAILY_LIMIT', 10),
    'pending_outbound_limit' => (int) env('ORBIT_SOCIAL_PENDING_OUTBOUND_LIMIT', 20),
    'decline_cooldown_hours' => (int) env('ORBIT_SOCIAL_DECLINE_COOLDOWN_HOURS', 24),
    'search_min_chars' => 2,
    'search_page_size' => 10,
    'search_page_max' => 20,
    'search_per_account_per_minute' => 60,
    'contact_batch_limit' => 25,
    'contact_daily_lookup_limit' => 500,
    'friend_invite_lifetime_days' => 7,
    'active_friend_invite_limit' => 5,
    // Must be configured from actual Apple Team/Bundle IDs and Android signing SHA256.
    'ios_app_id' => env('ORBIT_IOS_APP_ID', ''),
    'android_package' => env('ORBIT_ANDROID_PACKAGE', ''),
    'android_sha256' => env('ORBIT_ANDROID_SHA256', ''),
];
