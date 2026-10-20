> **Update — approved conservative M5 Stage 2 implementation:** This document contains an earlier gap snapshot. The seven-day recipient-bound invite code, explicit consent, four-role capabilities, owner succession, reversible archive/restore, and safe primary Circle selection are now implemented behind default-off feature switches. For the current API contract and remaining Stage 3/4 boundaries, use [M5_STAGE2_APPROVED_POLICY_AND_FLUTTER_API_HANDOFF.md](M5_STAGE2_APPROVED_POLICY_AND_FLUTTER_API_HANDOFF.md). No Hostinger or Flutter deployment has occurred.\n\n# Orbit M5 Stage 2 — Backend & Admin gap audit (before Flutter / deployment)

**Source documents:** `Orbit_Complete_Organized_Specifications.pdf`, `Orbit development final document (1).docx`, `Orbit_Complete backend intro in monetization.docx`, and `Orbit_M5_Complete_Requirements_Audit_2026-10-21` (134 requirements / D01–D28).

**Baseline:** Deployed M4 backend `cbe8347`. This is a GitHub-only M5 feature branch; no production Hostinger migration or Flutter release has been performed.

## Verified backend and Admin code
| Requirement IDs | Stage 2 result | Evidence / boundary |
|---|---|---|
| 001–006, 012–013, 091–092, 097–099 | Implemented behind release gate | Circle/member schema, trusted-device scope, atomic owner enrollment, FK+index |
| 010, 018–021, 040, 048–050, 102, 104, 108 | Implemented behind release gate | Circle CRUD, owner mutation authority, roster IDOR checks, visible member counts and pagination |
| 014, 030, 032, 034–036 | Partly implemented; approval required | Targeted friends-only invitations (separate feature switch), status lifecycle, explicit acceptance, configurable TTL (zero by default) |
| 022, 095 | Owner transfer implementation, OFF by default | Atomic existing-member promotion and original-owner demotion, explicit confirmation required, separate flag; no implicit ownership changes |
| 045–047 | Partial, policy-gated | Recorded Owner/Admin/Member/Restricted roles and owner-only changes. Detailed Admin and Restricted action policy is NOT yet approved |
| 051–064, 066–070 | Foundations only | Per-Circle permission storage includes source-required value_json, but grants stay OFF; read/subscribe policy explicitly denies sensitive data |
| 096, 100–101 | Partial with safeguards | Transactions; canonical membership; owner-account deletion fails closed; member deletion bumps Circle revision; minimal metadata outbox; advanced concurrency/load tests pending |
| 105–107 | Partial | Create idempotency key, duplicate member/invite uniqueness, server commit-before-response, standard 403/404/409/422/429; full invite retry contract to be approved |
| 117–118, 121 | Implemented with visual review pending | MFA Admin guard and circles.read; read-only aggregate statistics / UUID lookup using existing Blade layout; no private roster in Admin |
| 119–120, 122–123 | Deferred by product scope | Admin enforcement/retention/monetization interventions, higher limits and entitlements not approved, so no dangerous controls activated |

## Explicit backend/UI items NOT certified complete
- **M5-016, 027–029, 033, 037–039, 094, 103:** Circle join-by-code/link issuance, code preview/redeem, recipient and replay policy, iOS/Android host association. This needs a source-authorized code admission policy and TTL. Current code supports *targeted invite records*, not public or reusable join codes.
- **M5-023–025, 042, 073–077, 095:** Dissolution/archiving/restore, primary-circle fallback, temporary expiry/retention, and recovery need approved high-impact lifecycle rules. The gated ownership transfer is additive but not final product signoff.
- **M5-031, 041, 043–047, 051–064:** Full role matrix and enabling shared Moments/location/presence/SOS/Pings/Chat require client policy, feature consumer authorization and realtime transport. Owner-only mutations are an interim restrictive subset.
- **M5-071, 105, 109:** Exact per-account/device spam limits, production idempotency for all mutations, and measured p95 performance remain to be verified.
- **M5-110–116:** Native Flutter encrypted offline Circle cache and FCM/WebSocket revocation are not Stage 2 implementations. Server access remains authoritative and safe by default.
- **Admin D20 and visual QA:** No live-browser screenshot comparison of the Circle page with approved Admin reference. The original full dashboard reference includes later modules (SOS, Moments, Chat, Billing, health). Their data must not be fabricated or labeled M5 completed.
- **M5-080–090 and M5-008–009,015,017:** Live Flutter Circles/onboarding/navigation are Stage 3, not yet done; existing app still contains demonstration Circle screens.

## Controlled feature flags (all OFF by default)
```dotenv
ORBIT_M5_CIRCLES_ENABLED=false
ORBIT_M5_TARGETED_INVITES_ENABLED=false
ORBIT_M5_TARGETED_INVITE_LIFETIME_HOURS=0
ORBIT_M5_ROLE_CHANGES_ENABLED=false
ORBIT_M5_OWNERSHIP_TRANSFER_ENABLED=false
ORBIT_M3_ERASURE_EXECUTION_ENABLED=false
```
Do not enable these in production until product decisions, Flutter integration, native tests, and final acceptance.

## Decisions needed before final Stage 2 sign-off
1. D01 – Consent and location/presence defaults (recommended: explicit opt-in, default OFF).
2. D02 – Basic Circle count/member limits and Orbit+ numeric caps (recommended: core Free unblocked; confirm numeric values).
3. D05/D10/D12 – Who may use a Circle join code, whether owner approval is required, and exact TTL/use/revoke/replay rules.
4. D07/D08/D25 – Owner/Admin/Member/Restricted action matrix and exact ownership succession.
5. D09/D11/D15/D24 – Temporary/dissolved Circle retention, primary Circle and deletion succession.
6. D20 – Admin scope: read-only support metrics vs audited privileged interventions.
7. D03/D04 – Firebase/WS and downstream feature ownership; server authorization remains deny-by-default until wired.

## No-deployment agreement and next stage
- Stage 2 feature branch remains isolated; **do not deploy Hostinger or Flutter now**.
- Run the entire GitHub MariaDB suite on final commit, review admin role-gates and requirements traceability.
- Only after required policies are approved and backend/Admin code gaps closed should Stage 3 connect approved Flutter UI to the real APIs.
- Deploy the fully verified M5 backend, Admin and Flutter together at the end with backups, migration preview, and explicit user approval.
