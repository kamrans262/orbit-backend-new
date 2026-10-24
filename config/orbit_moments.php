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
];
