> **Update — approved conservative M5 Stage 2 implementation:** This document contains an earlier gap snapshot. The seven-day recipient-bound invite code, explicit consent, four-role capabilities, owner succession, reversible archive/restore, and safe primary Circle selection are now implemented behind default-off feature switches. For the current API contract and remaining Stage 3/4 boundaries, use [M5_STAGE2_APPROVED_POLICY_AND_FLUTTER_API_HANDOFF.md](M5_STAGE2_APPROVED_POLICY_AND_FLUTTER_API_HANDOFF.md). No Hostinger or Flutter deployment has occurred.

# Orbit M5 — Circles Backend & Admin rollout gate

Base: deployed M4 backend `cbe8347` (Laravel 13 / MariaDB / Blade Admin).
M5 feature branch: `feature/m5-circles-backend-admin`.

## Delivered Stage 2 core
- Additive indexed tables: circles, circle_members, circle_invites, circle_permissions, circle_event_outbox.
- Trusted-device authenticated endpoints under /api/v1/circles, with IDOR-denying Circle membership, owner-only modifications, validation, throttling and bounded list APIs.
- One original owner per Circle. No automatic invitation acceptance. No friendship-to-membership conversion.
- Optional Idempotency-Key (8–128 URL-safe chars) prevents duplicate Circle creation on retries when reused for identical request data.
- Targeted invitations only to trusted friends with explicit recipient accept/decline and owner revoke. No reusable join code or live link association.
- Roles: owner, admin, member, restricted are recorded. Role changes are owner-controlled and separately flagged OFF by default. No ownership transfer or dissolution until policy approval.
- CircleAccessPolicy denies ALL downstream protected sharing/subscription by default; Circle membership is not consent to disclose location, chat, media, presence, SOS, status.
- Account ownership deletion fails closed to prevent losing Circle members' trust group. M3 erasure FK guardian includes Circle-owned tables. M3 erasure flag remains OFF.
- Admin Accounts > Circle Management is read-only, with aggregate counts and technical exact-UUID lookup only, gated by circles.read and the global rollout flag.
- MariaDB CI runs M1–M5 tests together. No Flutter changes in Stage 2.

## Safe configuration
All flags default to false unless explicitly enabled in the Laravel environment and config cache refreshed.

- `ORBIT_M5_CIRCLES_ENABLED=false` — root gate for M5 API and Admin UI.
- `ORBIT_M5_TARGETED_INVITES_ENABLED=false` — invitation capability is disputed in source D05/D10/D12.
- `ORBIT_M5_ROLE_CHANGES_ENABLED=false` — role authority disputed in D07.
- `ORBIT_M3_ERASURE_EXECUTION_ENABLED=false` — remains OFF.

Do not enable first three in live production before the product policy and Flutter app are approved; passing CI is not device acceptance.

## Held for Product approval or external integration
- D01 default location/consent (safe current behavior: never grant).
- D02 quota/premium limits (no arbitrary limits imposed).
- D03 websocket/FCM delivery (not present).
- D05/D10/D12 invitation eligibility, expiry, reusable code and admit behavior (only targeted invites implemented behind gate).
- D06 block consequences inside pre-existing mutual Circle (ACL denies while blocked; full automatic revocation needs product approval).
- D07/D08 owner/admin/restricted action matrix, owner leave, transfer, dissolution.
- D09 temporary Circle expiry/retention and D24 owner account-erasure succession.
- D20 full Admin intervention scope (read-only until explicitly approved).
- M5 Flutter UI and two-account device acceptance are Stage 3/Stage 4, not delivered here.

## Endpoint summary (all authenticated and hidden behind ORBIT_M5_CIRCLES_ENABLED)
```
GET     /api/v1/circles
POST    /api/v1/circles
GET     /api/v1/circles/{circle}
PATCH   /api/v1/circles/{circle}
GET     /api/v1/circles/{circle}/members
POST    /api/v1/circles/{circle}/leave
DELETE  /api/v1/circles/{circle}/members/{user}
PATCH   /api/v1/circles/{circle}/members/{user}
GET     /api/v1/circles/{circle}/permissions
PATCH   /api/v1/circles/{circle}/permissions  # REVOKE ONLY, enabled=false
GET     /api/v1/circles/invites/incoming
GET     /api/v1/circles/{circle}/invites
POST    /api/v1/circles/{circle}/invites
DELETE  /api/v1/circles/{circle}/invites/{invite}
POST    /api/v1/circles/invites/{invite}/accept
POST    /api/v1/circles/invites/{invite}/decline
GET     /admin/circles   # MFA + circles.read + feature enabled
```

## Hostinger operational release
Production deploy is a separate action from GitHub edits. Read CI status on the final commit, back up existing Hostinger database and files, inspect git diff, `php artisan migrate --pretend --force`, and verify M3/M4 behavior with real accounts. Do not run `migrate:rollback` as a first-line mitigation on production. Use a disposable MariaDB clone for down-migration tests. Preserve the original `.env` and never change `APP_KEY`.

When deploying the code with default-off M5 flags, the existing Orbit mobile app and M4 APIs remain unaffected. The new Admin Circles page stays hidden until M5 is approved and enabled.
