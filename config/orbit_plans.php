<?php

return [
    'enabled' => (bool) env('ORBIT_PLANS_ENABLED', true),
    'max_invitees' => 100,
    'max_circles' => 5,
    'page_limit' => 30,
    'allowed_reminder_offsets' => [0, 15, 60, 1440],
    'default_reminder_offsets' => [15, 60],
    'worker_batch' => 40,
    // Push transport belongs exclusively to M14 and is never enabled by Plans.
];
