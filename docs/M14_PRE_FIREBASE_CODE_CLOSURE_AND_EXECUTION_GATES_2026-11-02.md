# Orbit M14 — Firebase-independent code closure and next release gates

Recorded: 2026-11-02.
Backend branch: feature/m14-notifications-backend-admin-2026-11-02.
Flutter branch: feature/m14-notifications-flutter-2026-11-02.

## Evidence-based status

The source-level implementation for four previously partial areas is now prepared:
- **M14-019 quiet/silent:** explicit in-app, generic FCM data-only, OS focus/Do Not Disturb, foreground/background/terminated policy in M14_PUSH_AND_QUIET_DELIVERY_POLICY_2026-11-02.md. No guaranteed OS alert or emergency delivery.
- **M14-034 legacy Moments read bridge:** M7 notice IDs/read states retained; M14 reads synchronize M7; older M7 read timestamps synchronize M14; live audience grants are reevaluated by source policy. Admin Overview now exposes read-state convergence counts (aggregate only); regression assertions check convergence.
- **M14-057 encrypted device-token transition:** M7 token source remains intact, consent/trusted device/session/platform checked; token copy gated until provider readiness. Notifications > Push Devices now has a bounded read-only migration readiness audit with no raw tokens/account IDs. Off-provider tests assert no token mutation or FCM call.
- **M14-072 additive rollback:** Five 2026-11-02 migrations preserve M1–M13 schema. CI-only MariaDB migrate/rollback/reapply test is guarded by CI=true, APP_ENV=testing, DB_DATABASE=orbit_ci and DB_HOST=127.0.0.1; legacy M7/M13 survival is explicitly asserted. Never run it on Hostinger.

**90 requirement baseline:** 74 coded or documented-policy requirements still await acceptance, 11 require providers/device/release validation, 5 are intentionally deferred. **122/122 cases remain NOT RUN** until actual test output is recorded. Do not claim 100% completed/released.

## Guardrails

- Leave ORBIT_NOTIFICATIONS_PUSH_ENABLED=false and ORBIT_M7_FCM_ENABLED=false until real Firebase project, service-account file outside web root, Android/iOS registrations/APNs and hardware acceptance.
- Generic push invalidation is data-only. It does not create a guaranteed OS notification, read receipt or critical SOS alert.
- M14 private HTTPS inbox works without Firebase.
- Do not blindly merge with deployed M13 or the separate Admin visual redesign branch; inspect live Hostinger Git state and dirty worktree.
- One initial M14 database backup before the first migration, not repeated after every follow-up.
- Production migration/cron status unknown; no Hostinger deployment, Flutter build, provider test, Laravel suite or Android/iOS certification was performed in this turn.

## Once the user authorizes commands

1. Read-only Hostinger Git, PHP, migrations, scheduler inventory; preserve M13.
2. Assemble clean reviewed M14 deploy branch. Initial M14 backup. Apply additive migrations 000001–000005; NEVER use migrate:fresh on Hostinger.
3. Check six dedicated Notifications Admin sections, audit trail, new legacy audits, private inbox, original settings, opt-outs, authorization, pagination and real source projections.
4. On disposable CI MariaDB execute scripts/m14_verify_isolated_migration_rollback.sh. Run focused OrbitM14NotificationsTest and record outcomes.
5. On Mac run Flutter package resolution, analyzer, focused notification/settings tests, Android build and device run; fix actual failures.
6. Verify two accounts/devices, M7 read, consent/revocation, mutes, suspended accounts, read-all and offline behavior. Record evidence for all 122 cases.
7. **Separate Firebase phase** only after configuration access: verify FCM HTTP v1, token registration, APNs, foreground/background/terminated taps and reviewed OS-visible display policy; never commit secrets.

No production or provider setting was changed by these source commits.
