<?php
// M25 is independently disabled until provider, security and device acceptance.
return [
    'enabled'=>(bool)env('ORBIT_M25_REALTIME_ENABLED',false),
    'verified'=>(bool)env('ORBIT_M25_REALTIME_VERIFIED',false),
    'project_id'=>env('ORBIT_M25_FIREBASE_PROJECT_ID',''),
    'database_url'=>env('ORBIT_M25_FIREBASE_DATABASE_URL',''),
    'service_account_path'=>env('ORBIT_M25_FIREBASE_SERVICE_ACCOUNT_PATH',''),
    'api_key'=>env('ORBIT_M25_FIREBASE_API_KEY',''),
    'sender_id'=>env('ORBIT_M25_FIREBASE_SENDER_ID',''),
    'android_app_id'=>env('ORBIT_M25_FIREBASE_ANDROID_APP_ID',''),
    'ios_app_id'=>env('ORBIT_M25_FIREBASE_IOS_APP_ID',''),
    'ios_bundle_id'=>env('ORBIT_M25_FIREBASE_IOS_BUNDLE_ID',''),
    'lease_seconds'=>60,
    'worker_batch'=>20,
    'inline_batch'=>2,
    'modules'=>[
        'presence'=>(bool)env('ORBIT_M25_PRESENCE_ENABLED',false),
        'location'=>(bool)env('ORBIT_M25_LOCATION_ENABLED',false),
        'circles'=>(bool)env('ORBIT_M25_CIRCLES_ENABLED',false),
    ],
];
