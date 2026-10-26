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
