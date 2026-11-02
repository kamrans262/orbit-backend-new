<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if (app()->environment() !== 'testing' || $db !== 'orbit_ci'
    || getenv('CI') !== 'true') {
    fwrite(STDERR, "STOP: M14 disposable schema assertion refused.\n");
    exit(2);
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['installed', 'rolledback'], true)) {
    fwrite(STDERR, "Usage: php scripts/m14_assert_migration_schema.php installed|rolledback\n");
    exit(2);
}
$legacy = [
    'orbit_users','orbit_sessions','circles','moment_notification_inbox',
    'moment_push_devices','messaging_messages','checkins','pings',
];
$m14 = [
    'notification_events','notification_inbox','notification_device_tokens',
    'notification_jobs','notification_attempts','notification_controls',
    'notification_templates','notification_context_mutes',
    'notification_admin_audits','notification_source_outbox',
    'notification_security_email_jobs','notification_circle_changes',
    'notification_category_preferences',
];
foreach ($legacy as $table) {
    if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
        fwrite(STDERR, "Missing untouched M1-M13 baseline table: $table\n");
        exit(1);
    }
}
foreach ($m14 as $table) {
    $present = \Illuminate\Support\Facades\Schema::hasTable($table);
    if ($present !== ($mode === 'installed')) {
        fwrite(STDERR, "Unexpected M14 schema in $mode state: $table\n");
        exit(1);
    }
}
echo "M14 schema verified ($mode), M1-M13 tables preserved.\n";
