# Orbit M9 — Standalone WebSocket Gateway

This folder contains a standalone Node 22+ TLS WebSocket and Redis Pub/Sub gateway. The process requires a managed always-on Node/container host, **not ordinary Hostinger shared hosting**. Production deployment is NOT complete.

## Transport contract
- server.mjs: TLS WSS route /m9/presence; phone authenticates via a 60-second opaque ticket returned by authenticated Laravel REST.
- Gateway validates the ticket with Laravel /api/v1/presence/realtime/introspect, obtains the server-chosen opaque Redis viewer channel, and re-authorizes the session and permission against Laravel **for every delivered event**. No user chooses a Redis channel.
- Subscriptions use authenticated per-viewer channels and forward only current, explicitly authorized Circle events. Stale, malformed, or expired statuses are never delivered.
- Gateway validates the active session every 10 seconds; Flutter reconnects using a newly issued ticket before its expiry. No persistent online/offline history.
- Socket outbound frames: {type:"auth",ticket}, then {type:"authenticated"} on authorization. Incoming protected frames contain {event:{type,circle_id,...}}.
- Phone-to-Laravel gateway frames: {type:"presence.update",state:"active"} or {type:"typing.update",circle_id:"UUID",typing:true}, only for an approved session. Laravel determines actor from session ticket.
- Gateway only accepts HTTPS Laravel origin, encrypted rediss:// Redis, mounted TLS cert/key, and a separate server-only gateway secret. Never include gateway secret in Flutter or public repositories.

## Provisioning sequence (on approved managed host)
1. Arrange isolated TLS Redis/Valkey and an always-running Node 22+ container host. Hostinger shared hosting currently has no verified support.
2. Securely provision the same ORBIT_M9_GATEWAY_SECRET (at least 32 random characters) to the Laravel backend and gateway. Never commit real values or expose them to phones.
3. Configure ORBIT_BACKEND_API_ORIGIN=https://approved-api.example/, ORBIT_GATEWAY_REDIS_URL=rediss://..., ORBIT_GATEWAY_TLS_CERT_PATH, ORBIT_GATEWAY_TLS_KEY_PATH, and hostname, bind, port and connection limits. See .env.example as documentation, not credentials.
4. Deploy the Dockerfile with non-root node, TLS certificates mounted read-only, health check at /healthz, network restrictions and private logs. Set up the approved DNS and TLS WSS ingress.
5. Run staged multi-device, Ghost Mode, block, consent expiry, Circle removal and reconnect tests. Validate per-event permission rechecks under current Redis and WebSocket transport, p95 delivery, battery use and rollback.
6. Only after staging acceptance, enable backend M9, M5 Presence consumer and gateway realtime switches. For approved Flutter build define ORBIT_M9_PRESENCE_ENABLED=true and ORBIT_M9_WSS_URL=wss://approved-host:8443/m9/presence.

## Focused developer commands (gateway folder)
- npm install --omit=dev --ignore-scripts --no-audit --no-fund
- npm start
- npm test
- node --check server.mjs

These validate source only until a real Redis/WSS service is provisioned. Do not represent Redis Pub/Sub code or green CI as live WebSocket availability. Realtime gateway remains disabled in Hostinger until approved.

## M8 boundary
M8 Home server API retains presence=null. M9 Flutter Home projects only authorized, current per-Circle status; no cross-Circle or global-friend online inference. The M9 branches remain isolated from the consolidated branches until acceptance.
