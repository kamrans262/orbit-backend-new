# Orbit Module 14 — Requirements Code Closure

Recorded November 2, 2026.
Backend branch: feature/m14-notifications-backend-admin-2026-11-02.
Flutter branch: feature/m14-notifications-flutter-2026-11-02.
Companion artifact: Orbit_M14_Requirements_Traceability_Code_Closure_2026-11-02.xlsx.

## Honest code traceability posture

Original matrix: 90 requirements, 122 acceptance cases.
As of this code-only audit:
- 69 CODED — NOT VERIFIED: implementation exists but mapped tests and operational evidence have not been run.
- 5 PARTIAL — FOLLOW-UP: M14-009 typed API failure schema; M14-012 scoped category settings; M14-019 silent/quiet OS policy; M14-081 accessibility and dynamic-type QA; M14-085 future-safety visual distinction.
- 11 GATED — EXTERNAL: Firebase/APNs signed app registration, native device and provider verification, real credentials, safe production DB migration/scheduler, privacy regression and deployment sign-off.
- 5 DEFERRED — ROADMAP: SOS escalation, Plans, billing/marketing, dry-run feature and safety policy owned by later modules.
- 0/122 test cases are recorded PASS. All 122 acceptance cases stay NOT RUN.

This is **not** a claim of complete production delivery or acceptance.

## Closed in this branch after Stages 1–4

- Source-transaction outbox markers for Friends, Circle invitations, Pings, Check-Ins and E2EE envelope metadata, idempotently consumed into unified private notifications; existing legacy projection is retained.
- M7 Moments read state synchronized in both directions with M14; dual-inbox migration without deleting legacy records.
- Server-bound context grouping keys, Flutter same-context grouping with a per-notice Orbit bottom sheet; canonical events retained.
- Authorized and owner-scoped Circle/conversation mute GET/PUT; legacy M7 Circle Moment mute compatibility. Conversation and Circle message mutes suppress push without silently deleting authorized private inbox rows.
- FCM sanitized provider outcomes, invalid-token disabling, per-category collapse keys and bounded jittered backoff. Provider remains independently OFF.
- Separate M14 trusted device registrations with encrypted legacy M7 token bridge, deduplication, user opt-out and revoked-session reconciliation; no cross-user tokens.
- Minimal verified security email adapter plus independently gated durable retry worker; no arbitrary Admin bulk email/marketing and OFF by default.
- Bounded retention invalidation, cleanup, failed-job queue diagnostics and reviewed Admin operations.
- Owner-only sharing start/stop security notices and Circle role/permission change notices, all with no GPS, private content or roster data.
- Flutter original Settings/Notifications screens preserved; OS permission status/shortcut via permission_handler 12.x; approved Orbit sheet for grouped notifications.
- Session-safe Flutter listener teardown, no recursive logout HTTP or accidental unregister when Home is disposed.

## Required additive migrations (not applied yet)

1. 2026_11_02_000001_create_orbit_notifications.php
2. 2026_11_02_000002_create_notification_source_outbox.php
3. 2026_11_02_000003_create_notification_security_email_jobs.php
4. 2026_11_02_000004_create_notification_circle_changes.php

Before migrating on Hostinger: inspect live deployed M13 branch and worktree, take ONE initial M14 DB backup, reconcile/merge on clean deployment branch, preview pending migrations, protect existing tables and .env and confirm Laravel scheduler. Never clear M1–M13 data.

## Pending release gates

- No successful PHP/Laravel test run, Flutter analyzer/build, Android/iOS binary, Firebase/APNs actual display, Hostinger cron or production migration is recorded in this conversation.
- Do not imply FCM HTTP accepted means device displayed. Generic data-only messages may not create visible OS notifications without an approved native display policy.
- Firebase, APNs and verified email remain disabled without actual credentials and provider acceptance.
- Real-account block/privacy, Circle revocation, accounts/deletion, read sync, expired notices, paging, OS-denied permissions, token refresh and sign-out need acceptance evidence.
- Existing Admin visual redesign branch is separate and unchanged.
- Run Hostinger/Mac commands only after the user specifically resumes Stage 4 execution.

Next action: code/build/suite/device verification and accurate per-requirement PASS/FAIL evidence, not new demo UI or invented acceptance results.
