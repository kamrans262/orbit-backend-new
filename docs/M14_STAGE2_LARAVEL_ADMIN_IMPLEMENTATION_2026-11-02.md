# Orbit Module 14 — Stage 2 Laravel + Admin implementation

**Date:** 2026-11-02  
**Branch:** feature/m14-notifications-backend-admin-2026-11-02  
**Base:** feature/m9-presence-backend-admin at 4d4b1c67  
**Scope:** additive schema, unified HTTPS notification inbox, privacy policy, source projections, protected FCM transport, database delivery jobs, Admin operations. Stage 3 Flutter unchanged. No Hostinger deployment performed.

## Database and API
- Additive migration: 2026_11_02_000001_create_orbit_notifications.php. Tables: notification_events, notification_inbox, notification_device_tokens, notification_jobs, notification_attempts, notification_controls, notification_templates, notification_context_mutes, notification_admin_audits.
- GET /api/v1/me/notifications, GET /api/v1/me/notifications/unread, POST /api/v1/me/notifications/{notice}/read, POST /api/v1/me/notifications/read-all.
- GET /api/v1/me/notifications/push-bootstrap, POST /api/v1/me/notifications/push-devices, DELETE /api/v1/me/notifications/push-devices/current.
- Existing M3 seven-key GET/PATCH preferences are unchanged.
- Existing M7 Moments notification and push-token APIs are unchanged. The new shared worker skips Moment FCM sends to prevent double notification during cutover.
- Source projection: existing M7 private Moment notices, M4 pending/accepted friend requests, M5 Circle invitations/acceptances, M11 Pings, M12 Check-Ins, M13 encrypted message envelope metadata, and selected M2 identity security events. No private contents, coordinates or sender names are copied into the notification schema.
- M2 selected device/session events use user-scoped, generic metadata-only security notices. M4/M5 notifications use event-specific live access checks for friend requests/acceptances and Circle invitations/acceptances. Unsupported security event kinds remain fail-closed; no simulated notices are created.

## Admin: standalone sidebar
Notifications is its own RBAC-protected group, not inside Accounts or Configurations. Six pages:
1. Overview: aggregate counts, category totals, worker/channel readiness, bounded maintenance.
2. Delivery Queue: filters, attempt history, manual failed-job retry.
3. Push Devices: limited device references and token hash prefix, audited disable.
4. Templates: edit only generic safe title/body, enable/pause, audited.
5. Settings & Controls: category/channel enable/pause, audited. Admin cannot bypass FCM readiness.
6. Audit Trail: who changed what and why; no user private notification content.

Permissions: notifications.read (all read-only pages) and notifications.manage (all writes); users with existing Super Admin * automatically inherit these, while custom roles need explicit assignment. CSRF/throttle/confirm/reason on management forms.

## Provider release gate
- In-app HTTPS is functional independently of Redis, WebSockets and Firebase.
- Shared FCM needs **both** ORBIT_NOTIFICATIONS_PUSH_ENABLED=true and valid existing M7 FCM readiness, plus an administrator enabling channel.push, explicit user push preference and a current trusted device session.
- Server only sends the generic notification_available data event. No user IDs, message content, Circle identities, GPS or key material in provider payloads.
- FCM acceptance is not proof of device display, open or read.
- Real Firebase project registration, service-account file outside public_html and Apple APNs provisioning are external dependencies. Never commit secrets.
- M7 Moments push is deliberately separate until Stage 3 client compatibility and migration are verified.

## Queue/cron and deployment
- Artisan: orbit:notifications:project, orbit:notifications:dispatch, orbit:notifications:expire. Laravel schedule every five minutes, every minute and hourly respectively; Hostinger cron invocation must be verified in Stage 4.
- Preview the additive migration and verify backups before initial M14 production migration; do not rerun the user's module-start backup for each minor additive change. Do not modify existing M13 tables or clear production queues.
- Stage 3 must connect the already-approved Flutter Notification Settings and Notifications inbox, including notification_available listener, cold-start/deep-link routing and badge/unread. Do not redesign screens.
- Stage 4: execute source 90-item requirements / 122 planned acceptance checks, Android/iOS device QA, real token expiry/rotation, permissions, consent revocation, queues, native push and Firebase/APNs as provisioned.

## Not claimed
- No claim of 100% M14 readiness: no production migration, Flutter M14 integration, live provider/OS tests, all requirements acceptance, or real device notification display.
- User-reported M13 Hostinger deployment is not automatically M13 E2EE native certification.
- Future SOS escalation, marketing broadcasts, plans/reminders and billing are outside M14.
- Original separate Admin visual redesign branch remains untouched; M14 functionality is implemented in the isolated M14 branch and should be merged into approved Admin changes only after review.
