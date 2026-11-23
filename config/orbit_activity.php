<?php

return [
    // Private activity pointers only. Source policies always remain authoritative.
    'enabled' => (bool) env('ORBIT_ACTIVITY_ENABLED', true),
    'source_defaults' => [
        'moment' => true,
        'check_in' => true,
    ],
    'feed_page_max' => 30,
    'candidate_scan_max' => 240,
    'project_batch' => 60,
    // Changes endpoint requires an authoritative refresh until safe viewer
    // tombstone deltas are completed and verified against the master spec.
    'sync_mode' => 'authoritative_refresh',
];
