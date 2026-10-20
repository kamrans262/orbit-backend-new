# Orbit M4 backend and Admin implementation — acceptance/release gate

Status: **DRAFT, NOT DEPLOYED**. Base M3 backend commit: `663a2d1`.
Original audit: `Orbit_M4_Deep_Requirements_Audit_2026-10-20.md`, including 103 trace rows, 35 proposed acceptance tests and 18 product/policy decisions.

## Implemented in this backend branch

- Additive MariaDB social tables: `friend_requests`, `friendships`, `blocks`, `social_graph_revisions`. Canonical friendship PK, unique nullable active pair request key, directional blocks, indexes and FKs.
- Trusted-device authenticated REST flows: exact username discovery, minimal profile preview, paginated friends/request lists, requests, recipient-only accept/decline, unfriend, list/block/unblock.
- Transactional state transitions, deterministic ordered user locks, neutral denied results and bounded requests. No automatic Circle membership, location consent or relationship restoration after unblock.
- Block checks take precedence in social requests and minimal previews; `SocialGraphService::isBlockedEitherWay` and monotonic relation revisions are reusable by future domains.
- `SocialRelationPolicy::canInteract` now explicitly consumes M3 privacy settings and denies all cross-person capabilities by default; Friends consent works only for supported capabilities. Ghost Mode and a block always override location eligibility. `My Circle` / `Selected People` remain denied until approved downstream permission consumers exist.
- Laravel Blade Admin: Accounts -> Friends & Blocking, permission `social.read`, exact UUID filtered metadata and social action events. Read-only; no impersonation, contact disclosure, PII or unexplained operator mutations. Super Admin wildcard inherits visibility.
- Extend `DeleteOrbitUser` and opt-in guarded erasure cleanup/receipt scope to include social tables. Erasure execution remains OFF by default.
- Isolated test coverage for trusted devices, privacy, duplicate/cross requests, accept/decline/unfriend, blocks, suspension/deletion, Admin RBAC, deletion fanout and throttling quota.

## Non-negotiable limitations and unresolved audit decisions

1. **Missing API & Realtime Specification**: proposed REST paths remain provisional until client review. No websocket event names, websocket transport or live subscription invalidation is claimed; revision hooks exist only.
2. **Contacts and friend invite links**: optional contact permission/import and signed friend invite links remain pending D05/D11; no contact book is accessed or stored by this branch.
3. **Discovery** is **exact username only**; no global directory, phone/email search or publicly browseable Circles. Product to approve precise visibility rules (D01/D05/D18).
4. **Block policy is conservative**: block cancels both pending requests and deletes existing friendship, unblock does **not** restore friendship. Product must explicitly approve this D03/D04 safety policy before live rollout. Shared Circle effects remain M5.
5. **Outbound limits** are provisional config defaults (new accounts: 10/day; other accounts: 20/day; 20 pending per actor). Per-IP middleware caps are in routes; obtain product approval for numeric limits, cooldown and cancel rules (D06/D07).
6. **Offline friend snapshots, queued mutations, Flutter integration, contacts onboarding, UI person detail** are not developed here and remain M4 mobile tasks. No demo data should be shown as real accounts.
7. **Full block propagation into future Location, Presence, Moments, Ping, Messaging, Plans, realtime and caches** is not testable until those consumers exist. Every future consumer must consult pairwise block policy at read, write, broadcast, share, cache and subscription boundaries.
8. SOS trusted responders and report/moderation remain separate modules; never disable SOS due to a social block without approved safety policy.
9. Final retention policy, backups, and external content owners are NOT included in erasure. `ORBIT_M3_ERASURE_EXECUTION_ENABLED=false` must remain off until signed approval.

## Safe validation and deployment

CI should run Laravel tests with ephemeral MariaDB. Never run `php artisan test`, `migrate:fresh`, or user cleanup tests on Hostinger.

Before deploying, check local M3 acceptance, review every migration's `--pretend` SQL and independent backups, and resolve D03, D05/D11, D07 and missing API spec. Deployment requires explicit approval. Do not deploy this branch merely because draft CI passes. M3 remains live and untouched.

## Draft APIs (relative to /api/v1)

`GET /people/search?username=...`, `GET /people/{uuid}/preview`,
`GET /friends`, `GET /friends/requests?direction=incoming|outgoing`,
`POST /friends/requests`, `POST /friends/requests/{uuid}/accept`,
`POST /friends/requests/{uuid}/decline`, `DELETE /friends/{user_uuid}`,
`GET /me/blocks`, `POST /me/blocks`, `DELETE /me/blocks/{user_uuid}`.

Rate and replay authorization are server-controlled; no client-supplied relationship state can override a block.
