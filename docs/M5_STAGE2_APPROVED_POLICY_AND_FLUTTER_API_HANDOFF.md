# Orbit M5 Stage 2 — Backend and Admin Contract
Status: GitHub feature branch, CI verified; production NOT deployed. Work based on deployed M4 release cbe8347.
Do not enable M5 flags until Flutter Stage 3, approved UI review, and final M5 acceptance are completed.

## Approved conservative policies
1. Circle identity and membership are private, with server-enforced owner/member scope and blocked-user filtering.
2. Four roles: Owner (all Circle management); Admin (send/revoke own invitations, inspect invitation states); Member (read permitted Circle metadata/roster, leave); Restricted (read permitted metadata/roster, leave). Owner only may rename, remove members, change roles, archive/restore, or transfer ownership.
3. Invitation created only for a known, active, unblocked Friend of the inviter; no person auto-joins merely because of friendship. Invitation is recipient-bound, expires after exactly seven days, and can be accepted/declined. When join codes are enabled, a random 40-character code is returned once to the inviter, never stored plaintext or included in listing. POST code preview is authenticated and recipient-bound; POST code redeem requires confirmation JOIN. Accepting by invite ID is an alternative explicit consent action. Codes and invitations are invalidated on acceptance, decline, revocation, expiry, archive, inviter removal/demotion, and relationship block/unfriend.
4. Ownership succession: existing active unblocked Member or Admin only; requires exact TRANSFER confirmation, atomic lock, sole-owner invariant. No automatic ownership deletion.
5. Archive: exact ARCHIVE confirmation, only Owner, revokes pending invitations, retains group records; access becomes unavailable while archived. Only Owner with RESTORE may reactivate. There is NO irreversible Circle deletion and NO unapproved data retention deadline.
6. Primary Circle: user can choose a current authorized Circle or clear selection. When that selection is archived/blocked/left, GET resolves a safe authorized fallback. Other users cannot choose a Circle on someone else's behalf.
7. Location, messaging, chat, Moments, presence, Ping, SOS and background realtime subscriptions are NOT enabled by Circle membership. The CircleAccessPolicy must be consulted by future feature consumers; it returns deny for all protected delivery until the appropriate opt-in, audience, precision, expiry, and transport are implemented and accepted. Owners cannot turn on somebody else's sharing. No fake real-time behavior.
8. Admin: authorized MFA session with circles.read only; privacy-protecting aggregate counts and exact UUID technical metadata. No arbitrary membership overrides, roster export, location, message, emergency intervention, or private-content viewing.
9. Quota/monetization remains unassigned; don't invent paid caps or Stripe dependencies.

## Rollout flags — defaults remain OFF
- ORBIT_M5_CIRCLES_ENABLED=false
- ORBIT_M5_TARGETED_INVITES_ENABLED=false
- ORBIT_M5_JOIN_CODES_ENABLED=false
- ORBIT_M5_TARGETED_INVITE_LIFETIME_HOURS=168 (exact seven days)
- ORBIT_M5_ROLE_CHANGES_ENABLED=false
- ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED=false
- ORBIT_M5_ARCHIVE_ENABLED=false
- ORBIT_M3_ERASURE_EXECUTION_ENABLED=false

## REST contract, all under /api/v1 and authenticated with trusted device
Responses: {'data': ...}; Cache-Control: private,no-store. Standard Laravel validation/errors.

| Method | Route | Purpose |
|---|---|---|
| GET | /circles | Paginated private active memberships, visible member_count, role |
| POST | /circles | Create, body name/icon_key/color_value; optional Idempotency-Key |
| GET | /circles/{circle} | Private detail, role, per-role capabilities, sharing_enabled=false |
| PATCH | /circles/{circle} | Owner edits name/icon/color |
| GET | /circles/{circle}/members | Block-filtered private roster with pagination |
| POST | /circles/{circle}/leave | Non-owner exits, revokes issued pending invites |
| DELETE | /circles/{circle}/members/{user} | Owner removes non-owner |
| PATCH | /circles/{circle}/members/{user} | Owner sets admin/member/restricted (role flag) |
| POST | /circles/{circle}/ownership-transfer | Owner transfers to current eligible member; confirmation=TRANSFER (flag) |
| GET | /circles/{circle}/invites | Owner/Admin scoped invite status and safe recipient profile |
| POST | /circles/{circle}/invites | Owner/Admin invites Friend, body invitee_id; returns code once when enabled |
| DELETE | /circles/{circle}/invites/{invite} | Owner or issuing Admin revokes |
| GET | /circles/invites/incoming | Recipient's pending, unexpired, visible invitations |
| POST | /circles/invites/{invite}/accept | Recipient explicitly accepts and joins |
| POST | /circles/invites/{invite}/decline | Recipient explicitly declines |
| POST | /circles/invites/code/preview | Recipient-bound secret code preview (40 chars, string) |
| POST | /circles/invites/code/redeem | Recipient-bound code plus confirmation=JOIN |
| GET | /circles/archived | Owner's archived Circle list (archive flag) |
| POST | /circles/{circle}/archive | Owner, confirmation=ARCHIVE |
| POST | /circles/{circle}/restore | Owner, confirmation=RESTORE |
| GET | /me/circles/primary | Primary Circle selection or safe fallback |
| PUT | /me/circles/primary | Choose a Circle or clear with circle_id=null |
| GET | /circles/{circle}/permissions | Explicitly no active downstream sharing grant |
| PATCH | /circles/{circle}/permissions | Existing user can revoke own permission only, enabled=false |
| GET | /admin/circles | MFA, circles.read and M5 enabled; operational stats only |

## Admin design verification
- Existing Orbit layout, sidebar, CSS tokens, breadcrumb, stat cards, search/action fields reused.
- Only read-only user authorized counts; no private roster, secret token, Circle contents, precise location, or message text.
- Runtime browser/visual comparison to approved Admin Figma reference still requires Stage 4 device/browser testing; static source review and automated controller/RBAC tests are not screenshot approval.

## Quality gates
- Full Laravel CI with ephemeral MariaDB runs the existing M1–M4 suite plus M5 integration tests.
- Stage 3 must replace Flutter demo Circles with real repository and APIs, map states exactly, review invitation code/QR/role UI, preserve approved styling and never enable forbidden sharing.
- No Hostinger change now. Final coordinated release after M5 Flutter integration, visual review, backup, read-only migration preview, and device acceptance. Never run migrate:fresh/reset/rollback against production.
