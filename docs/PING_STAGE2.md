# Ping — Backend and Admin / Stage 2

**Branch:** feature/m9-presence-backend-admin (existing branch; no new branch).

**Runtime:** Laravel 13, existing Orbit v1 bearer-token authentication and trusted devices. No Firebase, Redis or WebSocket dependency for foreground HTTP Ping.

## Endpoints

- GET \`/api/v1/pings/capabilities\`
- POST \`/api/v1/pings\` — requires \`client_id\` UUID, \`recipient_id\` UUID, \`context: {"type":"friend"}\` or \`{"type":"circle","id":"<circle-uuid>"}\`, and one of \`hello\`, \`where\`, \`on-my-way\`, \`right-there\`, \`love\`, \`call\`, \`custom\`. Custom requires \`custom_text\` of up to 160 characters.
- GET \`/api/v1/pings/inbox\` or \`outbox\` with cursor and max 30 items; GET \`/api/v1/pings/{ping}\`.
- POST \`/api/v1/pings/{ping}/delivered\`, \`/seen\` — recipient device only.
- POST \`/api/v1/pings/{ping}/respond\` — \`client_reply_id\` UUID and \`type:"hey"\`.

All return \`{"data": ...}\`. POST send returns 201 when inserted or 200 for safe idempotent replay, 409 on conflicting ID. Only recipient-confirmed receipts can change Delivered/Seen. When the recipient hides read receipts, sender receives Delivered rather than Seen.

## Security and lifecycle

- Recipient must have globally opted into Friends (friend request) or My Circle (Circle request).
- Circle requests additionally require a current member role and reviewed \`ping\` grant; \`orbit_circle.consumer_enabled.ping\` is independently gated.
- Blocks, account deletion and device revocation deny access; no fallback from Circle to friend or reverse.
- Sender rate: 12/minute and pairwise 6/hour defaults; configure with \`ORBIT_PING_SENDER_PER_MINUTE\`, \`ORBIT_PING_PAIR_PER_HOUR\` or disable with \`ORBIT_PING_ENABLED=false\`.
- No automatic GPS sharing, SOS integration, admin message-body access or unapproved retention purge.
- No background push, fake real-time badge, or guessed delivery state. Offline queue belongs to the Flutter stage.

## Admin

Open \`/admin/ping\` (Configurations → Ping Operations), guarded by \`config.manage\` permission. Only aggregated counters and capability states are displayed. Rejected request totals and device-side offline retry counts are explicitly marked unmeasured, rather than falsely shown as zero.

**Shared panel spacing:** outer panel content now has Orbit-consistent horizontal gutters; direct child paragraphs and notices are padded across the Admin workspace. Location Sharing’s “The figures above…” line is covered without changing its wording.

## Deployment gate (NOT performed)

1. Pull the existing backend branch to the authorized Hostinger deployment path.
2. Run \`php artisan migrate --force\` after a database backup.
3. Run targeted \`php artisan test --filter=OrbitPingTest\`, and verify \`php artisan route:list --path=pings\` on the deployment target.
4. Confirm trusted-device login, a recipient opt-in, confirmed receipt and block/revocation behavior using real accounts.
5. Stage 3 must wire the Flutter UI before declaring end-to-end user availability. Redis/WSS and Firebase are not a prerequisite for foreground HTTP use, but background notification delivery is not available.

**Scope:** Code is committed to GitHub only. Hostinger migration, physical-device testing and production deployment must not be reported as completed without execution evidence.
