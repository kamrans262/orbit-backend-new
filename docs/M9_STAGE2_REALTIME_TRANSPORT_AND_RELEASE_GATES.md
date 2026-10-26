# M9 Presence — realtime gateway and Hostinger release boundary

Backend implementation in `feature/m9-presence-backend-admin` is **off by default**. `ORBIT_M9_PRESENCE_ENABLED`, `ORBIT_M5_PRESENCE_CONSUMER_ENABLED`, and `ORBIT_M9_REALTIME_ENABLED` are all required for live delivery. `ORBIT_M9_GATEWAY_SECRET` must be independently provisioned (minimum 32 characters); never ship it in Flutter. Redis is required for non-test leases.

## Implemented backend contracts
- `POST /api/v1/presence/heartbeat` and `/presence/disconnect` from a trusted device.
- `GET /api/v1/me/presence/settings`, `PUT /api/v1/me/presence/circles/{circle}`, `GET /api/v1/circles/{circle}/presence`.
- `POST /api/v1/circles/{circle}/typing` (requires separate M13 message-consent gate).
- `POST /api/v1/presence/realtime/ticket` authenticated by a trusted Orbit token; opaque short-lived Redis ticket (60 s).
- `POST /api/v1/presence/realtime/introspect` called by a gateway using header `X-Orbit-Presence-Gateway` and `ticket`; optional `event: {type,circle_id,subject_id}` rechecks per-event visibility.

## Redis -> managed websocket gateway

Laravel publishes on internal channel `orbit:m9:viewer:{HMAC_SHA256(viewer_id,APP_KEY)}` only after M3 global Ghost and M5 Circle membership, block and explicit consent evaluation. Minimal events include `presence.changed`, `presence.clear`, `presence.circle_clear` and `presence.typing`. Channel keys are **internal Redis transport** and must never be exposed directly or used as publicly choosable websocket topics. Timestamp expiration must be enforced by clients and gateway; no permanent last-seen record is stored.

A managed TLS WebSocket gateway is REQUIRED to:
1. Obtain the opaque ticket from the client after bearer authentication.
2. Introspect the ticket to bind the socket to its authenticated user/device/session; never accept client-selected viewers.
3. Introspect **every protected event** before forward. Denied events are dropped and the Circle cache cleared. Do not rely on previous channel authorization after block, Ghost, consent or member removal.
4. Forward `circle_clear` even to a removed Circle member where applicable; it has no subject status or roster. Discard expired event state.
5. Renew/re-authenticate before ticket's 60-second expiry. Drop socket if introspection or Redis connection fails; no REST fallback pretending to be realtime.
6. Use isolated TLS Redis and TLS WebSocket; do not expose Redis to phones. Restrict the gateway secret to infrastructure and never log private status payloads.

## Environment and release gates

The present Hostinger shared-hosting deployment is **not** proven to provide always-on isolated Redis + WebSockets. No socket process, Redis deployment or p95 SLA is certified by this branch. M5 Firebase remains separately disabled unless client supplies credentials and authorizes it.

The real-time infrastructure, Flutter Stage 3 subscription implementation and device end-to-end acceptance must be completed before enabling the switches. Backend architecture/code can be ready without falsely claiming an operational websocket gateway.

Targeted tests: `php artisan test --filter=OrbitM9PresenceTest` and M8 baseline `--filter=OrbitM8DashboardTest`, using disposable CI MariaDB. Do not run database-refresh tests on production.
