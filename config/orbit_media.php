<?php
return [
    'enabled' => (bool) env('ORBIT_MEDIA_ENABLED', false),
    // Fail closed: no automatic downgrade to publicly served Laravel storage.
    'bucket' => env('ORBIT_MEDIA_BUCKET'),
    'region' => env('ORBIT_MEDIA_REGION', 'auto'),
    'endpoint' => env('ORBIT_MEDIA_ENDPOINT'),
    'access_key' => env('ORBIT_MEDIA_ACCESS_KEY'),
    'secret_key' => env('ORBIT_MEDIA_SECRET_KEY'),
    'path_prefix' => 'orbit-private/v1',
    'url_ttl_seconds' => 60,
    'upload_url_ttl_seconds' => 600,
    'upload_session_hours' => 2,
    'part_size_bytes' => 8388608,
    'limits' => ['image'=>26214400,'video'=>262144000,'audio'=>20971520],
    'max_video_duration_ms'=>60000,
    'moments_rail_hours'=>24,
    'moments_view_days'=>7,
    'moments_purge_days'=>30,
    'sos_retention_days'=>90,
];