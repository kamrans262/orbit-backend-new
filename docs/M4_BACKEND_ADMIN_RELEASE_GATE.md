# Orbit M4 — Friends & Social Graph backend and Blade Admin handoff

**Status:** Implemented in isolated GitHub draft PR #7, **NOT deployed to Hostinger**.
**Base:** feature/m3-completion-erasure at 663a2d1.
**Reviewed against:** Orbit M4 deep audit (103 checks / 35 acceptance scenarios). Existing client PRD/TRD/workflow/schema/security and user direction are authoritative. The separately referenced original API & Realtime specification was not provided; the endpoints below are a documented Laravel 13 API contract, not an assertion that missing source was reviewed.

## Implemented (backend + Admin)

1. **MariaDB schema:** friend_requests, friendships (canonical pair), blocks (directional), social_graph_revisions, social_discovery_settings, social_friend_invites, social_contact_lookup_quotas, social_graph_outbox. Additive migrations, UUID FKs, secondary indexes and pairwise unique request index.
2. **Search by username or name:** `GET /api/v1/people/search?q=...` (2+ chars) with a 10-item paginated, bounded response, limited to active searchable accounts. Username/name prefix and name-word search; private bio/contacts/Circles/GPS/last-seen never returned. Users can opt out completely or only from name-based search. Legacy `?username=` supported.
3. **Discovery controls:** authenticated `GET/PATCH /api/v1/me/social/discovery` supports `discoverable` (default true), `discoverable_by_name` (true), `discoverable_by_contacts` (false) and `allow_friend_requests` (true). Changes increment social revision and create safe outbox metadata.
4. **Contact matching:** `POST /api/v1/people/contacts/match` accepts up to 25 email/E.164 values per request with explicit `consent_confirmed`. Only other users who deliberately opted in are returned, and only minimal preview. No bulk address-book storage. Per-account daily 500 values, per-IP rate-limited; daily counters contain no contact identifiers. App must provide OS permission and explanatory UI.
5. **Requests/friendships:** recipient-only accept/decline, sender-only cancel, remove/unfriend, bidirectional membership queries, unique active pair even for crossing requests, transactional deterministic locks and revision bumps. Decline triggers 24-hour same-sender cooldown. Request quota: new users 10/day, established users 20/day, maximum 20 pending outbound.
6. **Short-lived invite links:** POST to create, GET own link metadata, POST preview/redeem, DELETE to revoke. Seven-day randomly generated one-time 256-bit tokens stored only as HMACs; max five active per creator. Browser landing `/invite/friend/{code}` is private, no-index/no-referrer and instructs sign-in before using the code. Native iOS/Android link handling remains a Flutter integration task.
7. **Blocks:** idempotent directional block/unblock, neutral 404 for blocked/noneligible targets; block removes friendship and cancels pending requests, unblock does not restore them. This is the chosen conservative professional safety policy. No membership or GPS is silently granted by friendship.
8. **Cross-module authorization:** `SocialRelationPolicy` rejects blocked, suspended and pending-deletion pairs and requires explicit M3 privacy choices before granting location/presence/Ping/message/Moment eligibility. Unknown capabilities and not-yet-implemented Circle/Selected-People grants deny by default. Consumers must enforce the policy at read, write, share and subscription boundaries.
9. **Revision and transactional outbox:** relationship/privacy/discovery updates create monotonic relationship revision and minimal outbox event records. No contact data, raw messages, invite token or private content is logged. A realtime dispatcher is **not yet connected**, so instant delivery and websocket revocation cannot be claimed.
10. **Blade Admin:** Accounts -> Friends & Blocking via permission `social.read`, counters for requests, friendships, blocks, active invites and contact-opt-ins, exact-UUID account metadata and paginated social event trail. Existing Orbit admin layout/sidebar style preserved. Read-only operational oversight; no private relation counterpart lists or block overrides. M17 owns full moderation and appeals.
11. **Account lifecycle:** user deletion explicitly deletes social request/friend/block, discovery/contact quota/invite/outbox records and invalidates surviving counterparts' revision; schema guardian recognizes every new user FK. Opt-in erasure remains OFF. No claim of external cloud media, backups or future-module erasure.

## API endpoints (all require trusted Orbit bearer session except browser invite landing)

```text
GET    /api/v1/people/search?q=<name-or-username>&page=1
GET    /api/v1/people/{user_uuid}/preview
POST   /api/v1/people/contacts/match
GET    /api/v1/me/social/discovery
PATCH  /api/v1/me/social/discovery
GET    /api/v1/friends
GET    /api/v1/friends/requests?direction=incoming|outgoing
POST   /api/v1/friends/requests
POST   /api/v1/friends/requests/{request_uuid}/accept
POST   /api/v1/friends/requests/{request_uuid}/decline
POST   /api/v1/friends/requests/{request_uuid}/cancel
DELETE /api/v1/friends/{user_uuid}
GET    /api/v1/friends/invites
POST   /api/v1/friends/invites
POST   /api/v1/friends/invites/preview
POST   /api/v1/friends/invites/redeem
DELETE /api/v1/friends/invites/{invite_uuid}
GET    /api/v1/me/blocks
POST   /api/v1/me/blocks
DELETE /api/v1/me/blocks/{user_uuid}
GET    /admin/social-graph [MFA web auth + social.read]
GET    /invite/friend/{token} [browser-only informational fallback]
```

## Professional defaults where client documents do not define numbers

- Name/username search: 2+ characters, 10 results/page, 20 pages maximum, 60 account queries/minute and additional IP limits.
- First-day account: 10 outbound requests/day; mature account: 20/day; 20 pending at once.
- Decline cooldown: 24h for the same sender; outgoing cancellation allowed.
- Contact matching: no raw input storage, explicitly consented request, target opt-in off by default, max 25 contacts/call and 500/day; user-level and IP throttles.
- Invites: 7-day expiry, one redemption, 5 active links per user. Tokens never written in plaintext to database.
- Blocking permanently removes friendship and pending requests, unblock requires new request.
- Search opt-out available, no globally searchable email/phone or Circle directory, no exposing private bio/location/status.

## Validation, release boundaries and dependencies

- PHP/Laravel automated tests must pass CI on ephemeral MariaDB (including M1-M3 regressions, M4 permission/abuse/erasure tests). CI is not proof of device acceptance.
- **Not included:** Flutter wiring, OS contact permissions, native app links, Firebase/WS push delivery, offline friend cache and mutation queue, M5 Circle membership, M17 reporting/moderation, user-location/media/chat/plan backend modules. Their block and privacy consumers remain future integration acceptance gates.
- **Do not deploy this draft automatically.** M3 Flutter tests still need user acceptance; Hostinger has no staging DB. Before eventual deployment verify backups, `git status`, code/branch SHA, read-only migrations and `php artisan migrate --pretend --force`. Obtain user approval before applying production migrations.
- Keep `ORBIT_M3_ERASURE_EXECUTION_ENABLED=false`; no direct/test erasure on production.
