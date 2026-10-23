> **Update — 2026-10-23:** The historical gaps below were recorded before the later M5 Firebase-ready work. Current implementation and professional policy resolutions are tracked in [M5_FIREBASE_READY_DISABLED.md](M5_FIREBASE_READY_DISABLED.md), [M5_AUTHORIZED_POLICY_RESOLUTIONS.md](M5_AUTHORIZED_POLICY_RESOLUTIONS.md) and [M5_CT40_RELEASE_EVIDENCE.md](M5_CT40_RELEASE_EVIDENCE.md). The Firebase server bridge, RTDB rules, Flutter listener contract, scoped session revocation, and 30-day triple-gated automated purge are committed **but all new runtime flags remain OFF**. Neither production activation nor signed-device/realtime CT-01–40 acceptance is claimed. 
>
# Orbit M5 final code-compliance and no-deployment gate — 2026-10-22

Authority: `Orbit_M5_Complete_Requirements_Audit_2026-10-21.md` (134 items, CT-01–CT-40), organized PRD/flows and approved conservative privacy choices. This document records code, not user acceptance.

## Implemented on M5 development branch (production still M4)
- Member-only private Circle CRUD, one Owner, role matrix, friend-only targeted seven-day one-use invites, code/QR acceptance, blocked-user exclusion, and owner/member Admin guards.
- Atomic owner succession, reversible manual archive and archive recovery, safe primary Circle fallback, invitation expiry/revocation, non-sensitive transactional outbox and 120+ M1–M5 database tests.
- Temporary Circles **behind ORBIT_M5_TEMPORARY_CIRCLES_ENABLED=false**: creator/Owner can select future expires_at; on expiry Circle is read-only for 72 hours (privacy revocations and leaving remain permitted); after 72 hours server authorization refuses active access even before scheduler, and hourly idempotent scheduler archives without deleting anything. Owner may restore and restart as permanent. The scheduler requires approved Hostinger cron at coordinated deploy.
- Within 24 hours of expiry, scheduled outbox writes one minimal `circle.temporary_expiring` event and Flutter detail shows a local reminder. **Events are not push notifications until the separate notification transport consumes them.**
- Source-derived offline names-only snapshot TTL is **5 minutes**, account-bound, visibly stale/read-only, not an authorization token. Foreground Circle list/detail rechecks access every ~25 seconds as non-realtime fallback.

## Important outstanding obligations: no false 100% signoff
- **M5-051–064:** The reusable CircleConsentPolicy and CircleAccessPolicy now evaluate role, block status, global Ghost/audience, scoped explicit duration and location precision. All actual protected-content consumer switches remain OFF and no location, Moments, presence, chat or SOS delivery endpoints are enabled by M5. Their real downstream module and user-consent integration is still outstanding. No automatic sharing from Circle membership.
- **M5-076:** The 30-day cold archive is implemented as a review counter plus a **manual, separately authorized purge** requiring BOTH `ORBIT_M5_RETENTION_POLICY_APPROVED=true` and `ORBIT_M5_RETENTION_PURGE_ENABLED=true` and the exact CLI confirmation. A legal hold blocks purge. Only eligible temporary archived Circles are candidates; each operation writes an HMAC-based nonidentifying audit. **No scheduled automatic purge and no live execution.**
- **M5-077 / 113–116:** Event outbox and on-screen expiry reminders are not a configured user notification; Firebase/WS, short-lived channel auth, active subscription revocation, and push delivery remain unimplemented pending infrastructure and security signoff. Polling is not realtime.
- **M5-038, 130:** Circle browser fallback and Apple AASA/Android assetlinks association code plus tests are prepared. Final Android/iOS package/bundle/signing IDs and real-device deep-link + approved visual checks require deployment credentials/device acceptance. See `docs/M5_NATIVE_LINKS_DEVICE_RELEASE_GATE.md`.
- **M5-109, 125, 128–134:** DB query-plan/performance p95 under expected load, actual two-account device tests, Admin browser parity, final 40 manual CT cases and rollback/Hostinger backup/migration pretend remain release blockers.
- **M5-119/120:** Admin's currently approved read-only operations never override privacy. Privileged interventions with reason/audit/retention need a separately approved product policy before enabling.
- Full paid-tier limits are not invented; safety/privacy and core Circles remain free-capable.

## Flags (all OFF until staged acceptance)
```dotenv
ORBIT_M5_CIRCLES_ENABLED=false
ORBIT_M5_TARGETED_INVITES_ENABLED=false
ORBIT_M5_JOIN_CODES_ENABLED=false
ORBIT_M5_ROLE_CHANGES_ENABLED=false
ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED=false
ORBIT_M5_ARCHIVE_ENABLED=false
ORBIT_M5_TEMPORARY_CIRCLES_ENABLED=false
ORBIT_M5_RETENTION_POLICY_APPROVED=false
ORBIT_M5_RETENTION_PURGE_ENABLED=false
ORBIT_M3_ERASURE_EXECUTION_ENABLED=false
```

## Deploy only after final combined completion
Do not merge or deploy automatically. Once accepted: independently back up production DB and source; inspect `php artisan migrate --pretend` and SQL; verify no destructive statements or unrelated migrations; enable only documented flags and explicitly configure an hourly Laravel scheduler in Hostinger; deploy backend/Admin and Flutter together; test final Android/iOS links, real two-account sign-in, privacy revocation and manual CT cases. Never run migrate:fresh or wipe production data.

## Firebase-free revision hook prepared
`OrbitCircleChangeSource` supplies a metadata-only invalidation interface. Live Circle pages validate every event against authenticated backend state; a disabled source and 25-second foreground verification remain the current fallback. Firebase is neither imported nor connected, and subscriber authorization is not falsely claimed. No production data is changed by this implementation.
