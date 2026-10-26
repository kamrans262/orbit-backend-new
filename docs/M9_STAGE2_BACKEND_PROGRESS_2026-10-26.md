# Orbit M9 — Stage 2 Backend and Admin VERIFICATION (2026-10-26)

Status: Laravel backend implementation committed; focused automated tests PASS. Production WebSocket gateway and external infrastructure NOT verified.

**CI:** https://github.com/kamrans262/orbit-backend-new/actions/runs/40350861897 — successful: 17 tests, 109 assertions, M9 PHP lint passed.
**Tested commit:** f066ccbb833f6695932f061f1e08e09c845fe967
**Branch:** feature/m9-presence-backend-admin. M8 baseline f5b86f9 was preserved on both its existing release and feature branches and incorporated into M9; no M8/release write.

Implemented: OFF-by-default M9/M5 consumer gates; Redis 90s multi-device presence leases; authenticated heartbeat/disconnect; read-scoped M5 consent/block checks and M3 Ghost; current self settings; Circle-scoped presence read; per-Circle consent with expiry; no durable activity history; per-viewer Redis fanout with 5s coalescing; 5s typing with 3s rate limit and separate message gate; 60s gateway tickets with per-event session/device/privacy introspection; gateway-only socket event inbound; privacy-change, Circle-lifecycle and session-revocation invalidation; RBAC-gated Admin status page and matching Orbit sidebar.

**External release gates:** Managed isolated Redis and always-running TLS WebSocket gateway are not verified on Hostinger shared hosting. Flutter M9 subscription UI is Stage 3, physical-device latency and security tests are Stage 4. The standalone client API/Realtime appendix, global Ghost marker policy and exact bounded Recently Active display rule remain unresolved. Do not enable live M9 rollout based only on this CI pass.

**Flutter handoff:** Authenticated POST /api/v1/presence/heartbeat, POST /presence/disconnect, GET /me/presence/settings, PUT /me/presence/circles/{circle}, GET /circles/{circle}/presence, POST /circles/{circle}/typing and POST /presence/realtime/ticket. Realtime introspect and inbound endpoints are gateway-only and require a secret never shipped in Flutter. Cache-control is private/no-store. Do not render expired presence as online.

**No production migration/deployment/feature enablement occurred.**


---

# Orbit M9 — Stage 2 backend implementation progress
Date: 2026-10-26
Branch: feature/m9-presence-backend-admin (no changes to M8 branches or Hostinger).

## Source verification
M8 backend Dashboard module, routes, preferences migration, config, tests and Stage 2 handoff are present on backend release/m5-backend-admin-gated-2026-10-21 at f5b86f95. That branch is an M8-content consolidation baseline even though its name is historical. Presence branch incorporated that baseline with merge commit 10b9a30b.

## Added
- Explicit M9 gate and independently OFF-by-default M5 presence consumer gate.
- Redis-target bounded ephemeral per-device lease store; 90-second TTL; no durable event log; multi-device union; redis exception fail closed. In-memory cache allowed only under Laravel testing environment.
- Permissioned circle-scoped projection using existing M5 role/membership/block, M3 ghost/visibility and reviewed M5 duration consent; no stale online dots.
- Authenticated trusted-device heartbeat and disconnect endpoints; self-only Circle presence consent controls, current self settings and Circle read snapshot with no-store headers.
- Admin MFA+config.manage-only static rollout/health status; never user presence identifiers or whereabouts.

## API (relative to /api/v1)
POST /presence/heartbeat {state:"active"|"idle"}
POST /presence/disconnect
GET /me/presence/settings
PUT /me/presence/circles/{circle} {enabled, duration:"persistent"|"until_expiry", expires_at:optional}
GET /circles/{circle}/presence

## Not yet complete / tests mandatory
- Full independent PHP/Laravel automated tests and native/integration tests not run against checkout.
- No Redis provisioning or running Socket/Soketi transport on Hostinger; realtime subscription revocation, delivery, typing WS fan-out and actual p95 latency acceptance are NOT implemented/certified.
- M9 feature flags remain OFF by default and must not be enabled on Hostinger prematurely.
- M8 people summary continues presence=null and capability false until a reviewed M8+M9 integration during Stage 3/4.
- Global Ghost vs badge, recently active bounded display policy and API appendix remain open.

## QA in disposable DB checkout
php -l app/Modules/Presence/Services/PresenceLeaseStore.php
php -l app/Modules/Presence/Services/PresenceAccessPolicy.php
php -l app/Modules/Presence/Services/PresenceService.php
php -l app/Modules/Presence/Http/Controllers/PresenceController.php
php -l app/Modules/Presence/Http/Controllers/AdminPresenceController.php
php artisan test --filter=OrbitM9PresenceTest
php artisan test --filter=OrbitM8DashboardTest
Use isolated staging Redis with secure TLS/auth for actual lease and websocket tests.

## Parallel M8 release movement (verified after M9 Stage 2 CI)
The independently managed backend release branch advanced from f5b86f9 to b16ea90 while M9 was in progress. Those five later M8 commits include an M8 validation workflow, Moments controller change, Stage 4 evidence/release gate and updated M8 test. They were NOT automatically merged into M9; the M9 branch still contains the earlier verified M8 Stage 2 baseline. Resolve any overlap, especially OrbitM8DashboardTest.php, in the later coordinated consolidation. M9 did not write to the M8 or release branches.
