<?php
return [
    'enabled' => (bool) env('ORBIT_M7_MOMENTS_ENABLED', false),
    'rail_hours' => 24,
    'view_days' => 7,
    'purge_days' => 30,
    'max_recipients' => 100,
    'max_caption_cipher_bytes' => 8192,
    'max_comment_cipher_bytes' => 8192,
    'max_feed_page' => 30,
    'moderation_restrict_enabled' => (bool) env('ORBIT_M7_MODERATION_RESTRICT_ENABLED', false),
    // Disabled until the client's Firebase project, service-account file,
    // native app configuration and explicit push opt-in are provided.
    'fcm' => [
        'enabled' => (bool) env('ORBIT_M7_FCM_ENABLED', false),
        'project_id' => env('ORBIT_M7_FCM_PROJECT_ID', ''),
        'service_account_path' => env('ORBIT_M7_FCM_SERVICE_ACCOUNT_PATH', ''),
    ],
];
