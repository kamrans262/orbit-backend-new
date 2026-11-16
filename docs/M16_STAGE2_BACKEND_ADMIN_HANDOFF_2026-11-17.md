# ORBIT / M16 — SOS & Safety Stage 2 Laravel and Admin

**Branch:** `feature/m16-sos-safety-backend-admin-2026-11-17` (from M15 backend feature branch).  
**Stack:** Laravel 13, MySQL/MariaDB, existing Blade Admin layout.  
**Not deployed.** No production migrations, cron changes or Flutter changes performed.

## Implemented engineering contracts

- Authenticated, trusted-device SOS creation, idempotent by `client_id`, server receipt distinct from recipient acknowledgement, revision-checked user cancellation and resolution.
- Separate user-opted-in trusted-contact requests and recipient acceptance. Default Circle responders come from the existing M5 routing policy. Current membership, active status and blocks are rechecked on reads and delivery.
- SOS-specific private HTTPS inbox in `/api/v1/sos/incidents`, **not** assumed to be delivered by M14. Provider jobs are independently tracked as `pending`, `accepted` (provider only), `retry`, `failed` or `suppressed`.
- Reuse of M14 registered device tokens and generic FCM transport with SOS priority and no collapse. FCM sends no identity, coordinates, Circle identity or private SOS content. Still requires actual FCM provider configuration and active trusted device sessions.
- Delayed one-tier trusted-contact escalation (configurable 30–1800 seconds) after Circle responders have not acknowledged; no external emergency-service dispatch.
- Per-incident encrypted fresh location only with explicit initiation consent; scoped read to eligible participants; expires and is erased from incident on owner close. Normal M10 Ghost Mode location APIs remain unchanged.
- Recording consent is recorded, **but no automatic recording, audio capture, audio upload or media transport exists in M16 Stage 2**. This preserves the unresolved PRD consent conflict conservatively.
- Tamper-evident chained audit hashes, with no raw coordinates or message content in events. Retention purge requires separate source-verified policy; do not silently purge emergency evidence.
- Independent Blade **Safety / SOS** navigation: Overview, Incident Directory, Responders, Delivery Operations, Settings and Audit Trail; permission gates `safety.read`, `safety.manage`, `safety.audit`; auditable reason-confirmed changes. No general-purpose Admin precise-location access.

## API contracts

All routes are under the existing M2 trusted-device token middleware:

- `GET /api/v1/sos/capabilities` — real delivery limitations and readiness.
- `GET /api/v1/sos/incidents`, `POST /api/v1/sos/incidents`, `GET /api/v1/sos/incidents/{uuid}`.
- `POST /api/v1/sos/incidents/{uuid}/acknowledge`, `/cancel`, `/resolve`; cancellation and resolution require initiator and `revision`.
- `GET /api/v1/sos/incidents/{uuid}/location` — explicit, time-limited and reauthorized.
- `GET /api/v1/sos/trusted-contacts`, `POST /api/v1/sos/trusted-contacts`, `POST /api/v1/sos/trusted-contacts/{uuid}/decision`, `DELETE /api/v1/sos/trusted-contacts/{uuid}`.
- `POST` creation requires `client_id`, `confirm_sos: true`, `share_location: boolean`. Optional `circle_id`; optional `recording_consent` metadata. `latitude`, `longitude` and `location_captured_at` are required if `share_location` is true; stale samples are rejected.

## Database

Additive migration `2026_11_17_000001_create_orbit_sos_safety.php`: `sos_incidents`, `sos_trusted_contacts`, `sos_recipients`, `sos_delivery_jobs`, `sos_dispatch_attempts`, `sos_controls`, `sos_audit_events`, `sos_admin_audits`. No existing table altered. Retention settings must be explicitly finalized before any permanent purge logic.

## Command and scheduler

`php artisan orbit:sos:dispatch` runs one bounded eligibility, escalation and push attempt pass; Laravel scheduler invokes it every minute with `withoutOverlapping` after release. The FCM transport must be configured separately; if unavailable, the worker reports `push_available=false` and does not pretend that provider delivery occurred.

## Acceptance / release blockers

1. Exact original PRD/TRD requirement IDs and wording were not available in this context; current IDs `M16-SOS-01..12` are provisional only.
2. Original requirements disagree on recording consent vs automatic ten-second capture. No audio pipeline enabled until source decision and Stage 3 integration.
3. External SMS, phone-number contacts, emergency service calling, critical-alert iOS entitlements and the future M22 offline Relay are not implemented. App must not imply they are available.
4. Provider acceptance != on-device delivery, viewing, acknowledgement or emergency response. Device/offline/background tests and monitoring remain Stage 3/4.
5. Confirm escalation ordering/delays, SOS location lifetime and legal retention rules with client source documents before production.
6. Run GitHub CI, migrations and regression tests before release. This branch intentionally does not deploy or migrate production.

## M3 / identity erasure safety integration

The M16 user-FK tables are registered in the existing fail-closed M3 erasure inventory, while `SosErasurePolicy` checks both the initiator and responder relationships before the central `DeleteOrbitUser` cascade. A contact-only user may be deleted through the normal process, but deletion of anyone linked to incident evidence is blocked until an explicit retention and evidence disposal workflow is approved and implemented. Incident records are never silently discarded by user or device deletion. This is intentionally a release policy blocker, not a claim that full erasure/retention compliance has been finalized.
