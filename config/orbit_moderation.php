<?php

return [
    // Intake and owned receipts stay available regardless of sanction rollout.
    'intake_enabled' => (bool) env('ORBIT_MODERATION_INTAKE_ENABLED', true),
    'source_sync_batch' => max(5,min(100,(int)env('ORBIT_MODERATION_SYNC_BATCH',40))),
    'account_report_limit_per_hour' => 20,
    // Cross-module sanctions and evidence access remain independently gated.
    'general_evidence_enabled' => false,
];
