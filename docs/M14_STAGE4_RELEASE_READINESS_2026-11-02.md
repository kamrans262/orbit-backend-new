# Orbit M14 — Stage 4 Release Readiness

Date: 2026-11-02.
Backend: feature/m14-notifications-backend-admin-2026-11-02.
Flutter: feature/m14-notifications-flutter-2026-11-02.
Status: source hardening done; production certification and deployment not yet performed.

## Security and release fixes

- Suspended/deleting accounts are no longer eligible for notification projection, reading or push.
- Privacy-filtered inbox pagination resumes after scanned records; Flutter supports continuing from an empty filtered page.
- Push token platform is bound to the registered trusted device's actual platform.
- Backfill preserves the original source-event time, and push only considers recent (30-minute) notices to avoid old-event push storms.
- Focused regression tests added for account suspension, privacy, pagination and event age. Tests added does NOT mean tests passed.
- Both provider push switch and Admin push channel default OFF. Independent legacy M7 Moments delivery is preserved.

## Backend validation and deployment gates

1. Inspect the LIVE Hostinger backend branch, commit SHA and dirty working tree. Build a deployment branch from what is actually running. Do not assume feature/m9-presence-backend-admin is the production head after M13.
2. Take a single initial M14 database backup before its first production migration. No repeat backups needed for each minor change.
3. Merge the M14 backend feature without force-resetting production. Inspect merge conflicts and changed paths. Preserve separate Admin visual redesign work and existing M13 releases.
4. Check PHP dependencies and syntax, Laravel route list, schema migrations pending, production application key stability, configuration flags, and service readiness without printing secrets.
5. Apply only the additive M14 migration after the migration preview and backup; never reset prior M1-M13 data.
6. Confirm six Notifications Admin tabs, role permissions notifications.read and notifications.manage, CSRF, audited configuration updates and privacy-safe aggregate views.
7. Confirm the Hostinger Laravel schedule actually runs: project every five minutes, dispatch every minute, expire hourly. Do not confuse a registered schedule with a configured cron job.
8. Project actual source events with push OFF, verify private inbox, real unread counts, read/read-all, pagination, errors and current grants. Do not seed demo users or notices in production.
9. Never run test suites with production database credentials.

## Flutter validation gates

1. Pull feature/m14-notifications-flutter-2026-11-02 on a clean Mac Flutter repo.
2. Run Flutter package resolution, static analysis, focused notification/profile tests and Android compilation; capture actual output before claiming build success.
3. Test Home bell, unread badge, refreshed Notifications list, read/read-all, pagination, existing seven-setting Profile screen, preference changes, and original onboarding permissions route.
4. Test two real accounts and devices, logout/account switch, blocking, removed Circle membership, expired Moments, unauthorized Check-Ins and stale notifications. No content from FCM can grant access.
5. Verify Android notification permission and real token registration only after backend bootstrap says push is ready. OS notification tray display, background/terminated handling and APNs require product-approved generic alerts, native certification and valid credentials.
6. iOS requires the correct bundle ID, APNs and a physical Apple device. No iOS build result is currently available.

## Current evidence and limitations

- Source changes are committed to isolated M14 backend and Flutter branches.
- Source/import contract checking was performed, but no successful local Laravel suite, flutter analyze, Android/iOS build, real-device receipt or production Hostinger migration is recorded.
- This working environment lacks Flutter/Dart and a local checkout of the private Laravel project. PHP executable alone is insufficient to run project-specific Laravel tests.
- Configuring Firebase, APNs and OS-visible generic alerts remains a separately gated release operation; a generic FCM data-only message is not proof of OS-tray delivery.
- Do not mark M14 production ready until the user runs the next prepared deployment/verification commands and supplies successful outputs.

## Safe execution order for the next turn

Hostinger read-only inspection -> reconcile production branch -> one module-start DB backup -> additive Laravel deploy/migration -> HTTPS inbox and Admin smoke validation with FCM OFF -> Mac Flutter tests/build and Android device run -> provider/APNs rollout only with actual provisioning -> record release evidence and rollback plan.

Stage 4 preparation is complete; Stage 4 production release is still pending verification.

## Final closure changes (2026-11-02)

- Verify all five additive migrations, including `2026_11_02_000005_create_notification_category_preferences.php`.
- M14 owner APIs now return stable machine-readable errors and preserve legacy top-level `message` compatibility.
- Messages and Friends can be disabled separately without changing original M3 seven toggle keys; security notices remain mandatory.
- Flutter adds grouped row semantics and larger text handling; TalkBack/VoiceOver and contrast still require physical QA.
- Requirements now: 70 coded but NOT VERIFIED, 4 partial (019/034/057/072), 11 externally gated, 5 deferred. 122 test cases still NOT RUN.
- Do not deploy or enable Firebase/email without the separately scheduled Hostinger/Mac validation and real credentials.
