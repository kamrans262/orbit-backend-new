<?php

return [
    // Provisional anti-abuse limits pending product signoff (M4 audit D07).
    // Conservative enough for new-user onboarding; all operations still require a trusted session.
    'daily_outbound_limit' => (int) env('ORBIT_SOCIAL_DAILY_OUTBOUND_LIMIT', 20),
    'new_account_daily_limit' => (int) env('ORBIT_SOCIAL_NEW_ACCOUNT_DAILY_LIMIT', 10),
    'pending_outbound_limit' => (int) env('ORBIT_SOCIAL_PENDING_OUTBOUND_LIMIT', 20),
    'search_min_chars' => 3,
    'search_limit' => 10,
    // Only exact, user-entered usernames; no contact book, full-directory or phone/email enumeration.
    'search_exact_username_only' => true,
];
