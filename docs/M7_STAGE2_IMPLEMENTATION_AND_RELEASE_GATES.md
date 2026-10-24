# M7 Stage 2 — Backend and Orbit Admin Implementation
Branch: `feature/m7-moments-backend-admin` based on verified M6 backend `4d41425fa9a6d4cb8628be8d048d6a333fe11dda`.
Only Laravel 13/MariaDB/Hostinger-private media and EXISTING Orbit Blade CSS/layout are used. No new colors, typography, popup system, widgets or admin theme. M7 is disabled by default via `ORBIT_M7_MOMENTS_ENABLED=false`; `ORBIT_MEDIA_ENABLED` also required.

## Implemented areas
- 12 additive M7 logical tables; private ciphertext caption/comment payloads, reports, UUID receipts, audience contexts, viewer traces, event outbox and retention jobs.
- Authenticated and device-bound `/api/v1/moments` publish, feed/rail/revision token, detail, owner edit/delete/audience, media manifest, scope-specific Circle/profile feed, views, reactions, comments, reports.
- 4 audiences: Only Me, accepted friends, selected friends and selected Circles (live Circle consent). No public discovery or popularity totals. Recipient device M6 original+derivative key envelope coverage required at publish.
- Publication lifetime 24h rail / 7d view / 30d purge, using server publication time; M6 media read service honors active published Moment TTL. Hourly `php artisan orbit:moments:process` queues encrypted physical deletion to existing M6 worker; no background WebSocket/FCM claims.
- Existing Orbit Admin Blade `/admin/moments` metrics, limited exact-ID technical lookup, pending report queue, permission-gated audited moderation; cannot decrypt content. All existing navigation class names reused.
- New feature tests in `OrbitM7MomentsTest`; existing M2–M6 test suite still required.

## Security/release constraints
- Do NOT enable Stage 2 on production before Stage 3 Flutter client supplies real E2EE recipient key distribution, comment context encryption, camera UI, secure viewer, and evidence of accepted product/privacy decisions.
- No R2/VPS required. Hostinger staging must validate private chunk upload/download, storage permissions, cron, quota, 30d retention, and backpressure.
- Administrators never receive a key. Report submissions can include only voluntarily submitted encrypted evidence, never an automatic screenshot/upload.
- M3 erasure remains fail-closed on new user-owned Moment records and must be explicitly certified for cross-module personal data erasure in the final gate.
- Not all 201 Stage 1 requirements map to Stage 2; Stage 3 owns Flutter UI, playback, caching, native camera editing. Stage 4 owns live acceptance and 74 test-case verification.
