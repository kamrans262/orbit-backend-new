<?php
return [
    'enabled'=>(bool)env('ORBIT_RELAY_ENABLED',false),
    'signing_secret'=>env('ORBIT_RELAY_SIGNING_SECRET'),
    'security_reviewed'=>(bool)env('ORBIT_RELAY_SECURITY_REVIEWED',false),
    // Part 1 deliberately has no enabled content adapters.
    // Do not expose signing secrets in public bootstrap or Admin.
];
