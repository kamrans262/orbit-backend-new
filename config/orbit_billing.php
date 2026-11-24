<?php
return [
    // Production must remain off until independently reviewed, commercially
    // approved and explicitly authorized. This implementation is sandbox-only.
    'sandbox_enabled'=>(bool)env('ORBIT_BILLING_SANDBOX_ENABLED',false),
    'environment'=>'sandbox',
    'binding_key'=>env('ORBIT_BILLING_BINDING_KEY',''),
    'apple'=>[
        'bundle'=>env('ORBIT_BILLING_APPLE_BUNDLE',''),
        'issuer'=>env('ORBIT_BILLING_APPLE_ISSUER',''),
        'key_id'=>env('ORBIT_BILLING_APPLE_KEY_ID',''),
        'private_key_path'=>env('ORBIT_BILLING_APPLE_KEY_PATH',''),
        'root_ca_path'=>env('ORBIT_BILLING_APPLE_ROOT_CA_PATH',''),
    ],
    'google'=>[
        'package'=>env('ORBIT_BILLING_GOOGLE_PACKAGE',''),
        'service_account_path'=>env('ORBIT_BILLING_GOOGLE_SERVICE_ACCOUNT_PATH',''),
        'push_email'=>env('ORBIT_BILLING_GOOGLE_PUSH_EMAIL',''),
        'push_audience'=>env('ORBIT_BILLING_GOOGLE_PUSH_AUDIENCE',''),
        'push_subscription'=>env('ORBIT_BILLING_GOOGLE_PUSH_SUBSCRIPTION',''),
    ],
];
