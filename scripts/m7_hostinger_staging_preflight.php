<?php
/**
 * Read-only-first M7 Hostinger staging preflight. Never run on production.
 * Usage: M7_STAGING_PREFLIGHT=APPROVED php scripts/m7_hostinger_staging_preflight.php
 */
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fail=static function(string $message):never {
    fwrite(STDERR,"BLOCKED: {$message}\n");
    exit(2);
};
if(getenv('M7_STAGING_PREFLIGHT')!=='APPROVED'){
    $fail('Explicit M7 staging preflight acknowledgment is missing.');
}
if(!app()->environment('staging')){
    $fail('This command may only run where APP_ENV=staging.');
}
if((bool)config('app.debug')===true){
    $fail('Disable APP_DEBUG for staging acceptance.');
}
if(config('orbit_media.driver')!=='hostinger_private'){
    $fail('This Hostinger-only acceptance requires the private media adapter.');
}
if(config('orbit_media.enabled')!==true){
    $fail('Private M6 media is disabled on this staging instance.');
}
if(config('orbit_moments.enabled')!==true){
    $fail('M7 is disabled on staging; no acceptance can be recorded.');
}
$private=storage_path('app/private');
$normalized=str_replace('\\','/',$private);
if(str_contains($normalized,'/public_html/') ||
   str_contains($normalized,'/public/')){
    $fail('Private encrypted storage is located in a web-served directory.');
}
if(!is_dir($private) || !is_writable($private)){
    $fail('Encrypted private media directory does not exist or is not writable.');
}
$public=realpath(base_path('public'));
$privateReal=realpath($private);
if(!$privateReal || ($public &&
    str_starts_with($privateReal.'/',rtrim($public,'/').'/'))){
    $fail('Encrypted storage resolves beneath Laravel public.');
}
$required=['moments','moment_media','moment_audience',
    'moment_recipients','moment_comments','moment_reactions',
    'moment_views','moment_reports','moment_lifecycle_jobs','media_objects',
    'media_device_key_envelopes','media_delete_jobs'];
foreach($required as $table){
    if(!\Illuminate\Support\Facades\Schema::hasTable($table)){
        $fail("Migration incomplete: {$table} missing.");
    }
}
try{
    \Illuminate\Support\Facades\DB::select('SELECT 1 AS healthy');
}catch(\Throwable){
    $fail('MariaDB connection check failed.');
}
// Confined temporary filesystem probe: no existing encrypted object touched.
$probe=$private.'/.m7-staging-'.bin2hex(random_bytes(8));
try{
    if(file_put_contents($probe,'orbit-staging-probe',LOCK_EX)===false){
        $fail('Private media directory is not writable.');
    }
    if(file_get_contents($probe)!=='orbit-staging-probe'){
        $fail('Private storage readback failed.');
    }
} finally {
    if(is_file($probe))@unlink($probe);
}
echo "M7 HOSTINGER STAGING PREFLIGHT PASSED\n";
echo "- APP_ENV=staging and APP_DEBUG=false\n";
echo "- private Hostinger storage outside web-served directories\n";
echo "- M6 + M7 staging flags enabled\n";
echo "- required Laravel migrations and MariaDB connection available\n";
echo "- private filesystem probe read/write/unlink succeeded\n";
echo "NOT VERIFIED BY THIS SCRIPT: 60s Android/iOS encoding, HTTP authentication, physical data purge, cron invocation, and 74 user acceptance cases.\n";
