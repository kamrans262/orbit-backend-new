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
- M3 erasure now executes an authorized M7 metadata fan-out inside the existing M3 transaction only after physically verified M6 storage purge. Unpurged ciphertext or independently reviewed moderation audit remains a fail-closed legal hold. Full erasure and Hostinger staging still need Stage 4 verification.
- Not all 201 Stage 1 requirements map to Stage 2; Stage 3 owns Flutter UI, playback, caching, native camera editing. Stage 4 owns live acceptance and 74 test-case verification.

## M3 backward-compatible erasure gate
M7 user-linked tables are registered with M3's FK audit. M3 erasure proceeds normally for accounts with NO M7 footprint. Accounts with Moments, reactions, comments, views, recipients, reports, or M7 idempotency records have their private M7 references explicitly erased after verified ciphertext purge. Unpurged media or a previously adjudicated moderation audit blocks account erasure until lawful review, rather than silently orphaning or deleting evidence. M7 lifecycle continues processing existing records even when creation feature flag is disabled. Moderator restriction remains separately disabled with ORBIT_M7_MODERATION_RESTRICT_ENABLED=false until policy approval.

## Enforced M3 privacy ceiling
The user's stored `moment_default_audience` value is authoritative. `Nobody` allows Only Me; `Selected People` allows accepted/selected friends; `My Circle` allows selected Circles only, subject to M5 explicit consent. Publish and subsequent feed/media reads re-check it; the client cannot upgrade privacy by changing a request body. Any missing preference fails closed. Server rejects a recipient with zero valid trusted 32-byte device keys.

## Stage 2 final privacy/integrity hardening
- User-owned Moment records, comments, reactions, viewer receipts, report submissions, recipient rows and request replays are cleaned by `MomentErasureService` from M3's authorized transaction only after physical M6 purge and no outstanding legal review. Active report decisions deliberately block automated evidence destruction.
- Reports use stable per-reporter `Idempotency-Key` (UUID), persisted canonical payload digests and replay-safe receipts; altered payload under the same key is rejected as a conflict. Explicit evidence consent is mandatory before accepting encrypted evidence. Reasons include spam, harassment, threat, scam, impersonation, nudity, violence, safety concern and Other.
- M6/M7 posting flags do not automatically enable Firebase, camera publishing, plaintext content moderation, or client push subscriptions. Source tests are not physical-device acceptance.
- Outstanding cross-milestone items: user/account reporting scope, product sign-off for moderation restriction based on E2EE report evidence, full 74-case staging/device acceptance, and Flutter Stage 3 UI/recipients. These are NOT certified by backend CI alone.
