# Orbit M9 — Stage 1 Presence / Backend & Admin handoff
Date: 2026-10-26. **Architecture only; NO M9 application code, migrations, rollout, or production acceptance.**
Branch: `feature/m9-presence-backend-admin`. Independent from all M8 branches.

## Governing sources
- Orbit Complete Organized Specifications (master PDF): PRD-095 active/recently active and contextual labels (P1), PRD-096 who-can-see-presence control (P0), PRD-072 typing (P0), PRD-105 blocking (P0). TRD: TTL ephemeral, not historical. Backend schema: Redis/Valkey `presence_snapshot`, not durable history.
- Orbit development final document: §§2.6, 6.2–6.8, 7.3, 11.3–11.7, 13.1. Presence per Circle; global Ghost precedence; 90s sliding presence TTL; 5s ephemeral typing; 1 typing event per 3s; coalesce broadcast <=1 event/5s per user per Circle.
- Backend supplemental specification: Redis + WebSocket/Soketi transport, fan-out not HTTP polling.
- October 26 consolidated handoff and user-approved Flutter/Laravel/MySQL/Hostinger environment.

## Security-first architecture
- Authenticate session and active device (M2). Do not accept a client-supplied user ID as authority.
- Track separate short-lived device leases, aggregate valid leases to online/idle/offline; expired leases naturally become offline. No permanent timeline or per-heartbeat audit.
- Derive a privacy-scoped **viewer x subject x Circle** projection. Before returning data or broadcasting, verify live reciprocal block checks, active accepted membership, role, consent, subject state, global/per-Circle Ghost, and temporary share expiry. Friends do not automatically get presence.
- Global Ghost suppresses broadcast for all circles; per-circle sharing may differ simultaneously between circles. Ghost must not disable SOS (M16), messages (M13), or Moments (M7).
- Changing membership, block, Ghost or share policy revokes subscriptions and cached projections immediately; re-check on every read and fanout, not only initial WS subscribe.
- Never disclose other-Circle memberships, coordinates, old location or user typing content. Optional battery/network/activity context is permissioned and never stored as a durable personal timeline.
- Use existing M5 access/consent services and existing Admin RBAC/feature gates, without redundant migrations or user-tracking Admin pages.

## Candidate modules / contracts for Stage 2 (not existing endpoints)
- `app/Modules/Presence/{Http,Services,Policies,Events}`, interfaces for `PresenceLeaseStore`, `PresenceVisibilityPolicy`, `PresenceBroadcaster`, and revoked-projection invalidation.
- `ORBIT_M9_PRESENCE_ENABLED=false` default, kill switch, additive config and migrations for preferences only if M5 storage does not already cover these.
- Authenticated `POST /api/v1/presence` and WS `presence.update`; self-only `GET/PATCH /api/v1/me/presence/preferences`; scoped `GET /api/v1/circles/{id}/presence`.
- WS `presence:{circle_id}` changes/clear, separate 5s transient typing. Namespaced URLs are proposed compatibility adapters, NOT approved exact server routes (the standalone API/Realtime Specification is missing).
- Presence Redis/Valkey sliding 90s, socket binding ~5m, typing TTL 5s. A MySQL/REST-only development adapter is NOT equivalent to the client's realtime requirements.

## Critical external blockers
1. Hostinger shared hosting has no verified Redis + persistent WS gateway; production sender→recipient <250ms p95 / ingest→fanout <200ms cannot be certified without such infrastructure and tests.
2. Standalone API & Realtime appendix referenced in older handoffs is not available.
3. Internal documents disagree on whether global Ghost should present any avatar marker; privacy-first no global presence leak is the safe baseline.
4. 'Recently active' must be short-lived without introducing durable history; display window needs product approval.
5. M8 backend is being developed separately. **Do not edit M8 branches**. Start M9 Stage 2 after M8 backend commits/contract stabilizes, integrate it via reviewed merge later.

## Stage 2 acceptance gates
Focused tests must cover auth spoofing, two devices, 90s lease expiry, block, friend-only denial, per-Circle isolation, both Ghost modes, temporary expiry, logout/deletion, realtime subscription revocation, 3s typing throttle/5s expiry, disable flag, replay/coalescing and rate limits. Do not test with live production DB. Admin only aggregated health metrics.
Stage 1 audit deliverable outside repository: 60-row requirements matrix plus full architecture report in `Orbit_M9_Stage1_Complete_Pack_2026-10-26.zip`. This file is a self-contained engineering handoff, not proof M9 code exists.
