# Orbit M5 — professional product/security decisions (user-authorized implementation)

Authority: original October 21 M5 audit (134 requirements; decisions D01–D28), PRD and existing Laravel 13 / MariaDB / Flutter releases. The owner explicitly authorized conservative professional decisions without further clarification. This establishes **implementation choices**, not permission to activate Firebase, delete production records, or skip real-device acceptance.

| Decision | Adopted implementation policy |
|---|---|
| D01 | Explicit member opt-in only; no default precise/persistent location grant |
| D02 | Basic private Circles and safety are Free; no invented quota or premium numeric limits |
| D03 | Prepared Firebase RTDB authenticated revision bridge, OFF until actual client project and end-to-end revocation tests |
| D04 | M5 owns Circle authorization contracts; location, Moments, message, presence and SOS content delivery remains owned by downstream services, all initially deny |
| D05 | Invitations only to confirmed, unblocked friends; trusted recipient must explicitly accept |
| D06 | Mutual block immediately denies Circle membership data and protected access; do not silently delete membership history |
| D07 | Owner/admin/member/restricted matrix as coded; only Owner performs destructive or privileged membership changes; restricted role cannot publish |
| D08 | Original Owner cannot leave without transfer; tested atomic succession is gated |
| D09 | Temporary Circle expires to 72h read-only then cold archive; legal-hold-aware 30-day retention, no live irreversible execution by default |
| D10 | Seven-day, recipient-bound, single-use invite code; hashed, nonreplayable, revocable |
| D11 | Server-side per-user primary Circle selection and safe fallback |
| D12 | Explicit recipient Join on valid code/invite; do not require an additional implicit owner approval that can leak identity |
| D13 | MariaDB-compatible unique Circle membership pair and safe rejoin/soft removal semantics |
| D14 | Deny precedence: user status/block/lifecycle > membership/role > global Ghost/audience > reviewed Circle consent > downstream feature gate |
| D15 | Owner archive/restore instead of unbounded irreversible dissolve; retention only for temporary archives |
| D16 | Retain the existing approved Flutter Circle components; avoid recreating Figma without source |
| D17 | Onboarding must offer Create, Join and Skip; Skip never blocks Home |
| D18 | No invented member avatars, unread counters, map positions or online presence |
| D19 | Existing Hostinger HTTPS invitation host until a coordinated, approved production domain change |
| D20 | Admin Circle Management read-only, aggregate + exact UUID with RBAC/MFA; no unauthorized roster or private-data actions |
| D21 | Flutter + Laravel13/MariaDB/Blade is authoritative implemented tech stack, regardless of older generic architecture references |
| D22 | Offline names-only account-bound encrypted snapshot TTL five minutes, stale/read-only |
| D23 | Core SOS recipients are simultaneous and Free; global Ghost cannot suppress intentional SOS; Plus custom routing off until verified entitlement |
| D24 | Owner account erasure fails closed until authorized transfer; never orphan/delete other users' Circle |
| D25 | Restricted Circle role read-only metadata access; zero protected consumer access or writes |
| D26 | 80-character name, 40-character safe icon_key, optional six-digit hex color |
| D27 | Existing Orbit /api/v1 authenticated Laravel contract, not an unimplemented generic service endpoint |
| D28 | No fabricated server persistence for local reorder/favorites; defer until actual user-visible persistence contract exists |

## Firebase and realtime

Laravel is the sole authority for protected content. Firebase contains only Circle revisions and an account-session ACL. Android/iOS Firebase SDK code is present, but has an explicit **compile-time** OFF switch; the backend has a separate runtime OFF switch. Session, device, block, membership, lifecycle, archive, consent and logout updates generate outbox invalidations; best-effort after-commit fanout plus durable retry is implemented. Live real-device Firebase authentication and subscriber disconnection must still be proven after the client supplies its actual project. Polling is never described as instant.

## Retention and legal hold

A temporary Circle is **active -> 72-hour read-only -> cold archived**. Thirty full days after archive it becomes eligible for reviewed deletion. Legal hold vetoes deletion even when eligible. Automatic daily deletion is implemented but requires all three independent flags (policy approved, manual purge enabled, automatic purge enabled), plus global M5/temporary Circle rollout. All default OFF. Permanent Circles are never eligible. The deletion operation writes a nonidentifying HMAC audit in the same transaction. A product-policy choice is not permission to retroactively run the job against live data.

## Hard release exclusions

Do not interpret prepared transport or consent policy as provisioned real downstream services. Do not activate location/Moments/presence/message/SOS delivery. Do not replace client signing IDs, skip two-account real-device acceptance, forge CT passes, or deploy/migrate to Hostinger without verified backup and non-destructive migration planning. Current draft PRs remain unmerged.
