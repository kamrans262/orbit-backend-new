<?php
return [
    'enabled'=>(bool)env('ORBIT_RELAY_ENABLED',false),
    'signing_secret'=>env('ORBIT_RELAY_SIGNING_SECRET'),
    'security_reviewed'=>(bool)env('ORBIT_RELAY_SECURITY_REVIEWED',false),
    'peer_transport_reviewed'=>(bool)env('ORBIT_RELAY_PEER_TRANSPORT_REVIEWED',false),
    'metadata_retention_days'=>7,
    'aggregate_retention_days'=>30,
];
