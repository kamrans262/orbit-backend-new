<?php

return [
    // Off by default. Neither a cron entry nor an admin page can cause erasure
    // until the operator approves the precise scope and enabled deployment.
    'execution_enabled' => (bool) env('ORBIT_M3_ERASURE_EXECUTION_ENABLED', false),
    'scope' => 'identity_profile_m3',
    // Future media, circles, content, messaging, payments and legal hold
    // owners MUST integrate before upgrading this scope to whole-account.
];
