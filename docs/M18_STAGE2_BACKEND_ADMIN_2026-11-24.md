# ORBIT M18 — Stage 2 Laravel / Blade implementation

**Date:** 24 November 2026
**Branch:** feature/m18-activity-backend-admin-2026-11-24 (based on M17 a6b3cb27)
**Deployment:** NOT performed. No production migrations or backups taken; this is a development branch.

## Implemented on this branch

- Additive schema: activity_events (minimal source pointers), activity_controls, activity_projection_runs and activity_admin_audits.
- Source-specific bounded, idempotent projector for M7 Moments and M12 Check-In only; no cross-module database writes.
- Signed viewer-bound cursor feed and source-specific read-time authorization via existing MomentPolicy and CheckInAccessPolicy.
- Private routes: GET /api/v1/activity/capabilities, /activity/feed, /activity/changes, /activity/items/{activity}.
- Explicit authoritative-refresh synchronization; no misleading incremental invalidation or fake WebSocket claim.
- M18 standalone Blade Admin Overview, Source Controls, Projection Operations and Audit Trail within existing CSS/components.
- Separate activity.read / activity.manage / activity.audit RBAC, confirmation+reason and immutable operational audit insertion.
- Existing Laravel scheduler runs a bounded activity projector every five minutes.
- Isolated MariaDB CI PHP lint, migration rollback/reapply, M18 feature tests and selected M7/M12/M14/M17 regressions.

## Security and scope limits

Source adapters are allowlisted strictly to moment/check_in. No friendships/circle roster, Plans, Ping, messages, SOS, locations, moderation evidence, security or sponsored items are inserted. The M18 event record is not a source authorization. Every page and detail lookup calls the real source policy and account/trusted-device policy; no thumbnail/content/key is copied. New Activity Admin pages expose aggregate counts, source flags, audit reasons and run health, never personal activity contents.

## Deliberately outstanding (do not claim M18 complete)

- Original Orbit master PRD/TRD and internal DOCX are not in accessible handoff; clause-by-clause compliance is UNVERIFIED.
- Robust viewer-scoped incremental tombstones and realtime source invalidations are not implemented. /changes signals reset_required=true so Stage 3 can discard stale pointers and refresh.
- Reconciliation scans bounded candidates but requires performance/coverage certification under volume, including continuous-update storms and deletes.
- No dedicated Flutter Activity route/screens; Stage 3 must use only existing approved Home/Moments widgets and navigation.
- Hostinger cron, MariaDB migration, CI results, Android/iOS behavior, retention/privacy policy and real-device QA are not certified.
- A fresh production backup is needed once, immediately before the first M18 production migration; do not migrate until explicitly authorized.

## Environment instructions (not executed here)

On a clean, isolated development environment:
- composer install
- php artisan migrate
- php artisan test tests/Feature/OrbitM18ActivityTest.php
- php artisan route:list --path=api/v1/activity
- php artisan route:list --path=admin/activity
- php artisan orbit:activity:project

Release gates: fetch and reconcile master docs; confirm schema and scheduled projection; verify all regressions, source suppression/retention and actual ACL revocations; authorize deployment separately. Never run tests against the Hostinger live MariaDB.
