# ORBIT — MODULE 17: MODERATION & REPORTING
## Stage 1 — Source-Verified Requirements, Architecture and Handoff | 2026-11-23

**Status:** Stage 1 specified; implementation and deployment NOT started. Original client master PDF (65 pages), internal development DOCX (48 pages), and backend/admin DOCX (9 pages) reviewed. Code baseline `kamrans262/orbit-backend-new` M16 branch `feature/m16-sos-safety-backend-admin-2026-11-17` and `kamrans262/orbit-app-new` M16 branch `feature/m16-sos-safety-flutter-2026-11-23`.

**Inventory:** 92 M17 design controls; 23 proposed/current API entries; 18 proposed/current schema entities; 52 acceptance tests; 18 explicit decisions/gates. No new Flutter styles, colors, popups or Admin design system. No production migration or deployment.

## 1. Original sources and authority

- **PRD:** Orbit_Complete_Organized_Specifications.pdf — PRD pp. 7-12 (PDF pp. 11-12)
- **FLOWS:** Orbit_Complete_Organized_Specifications.pdf — Workflows §4.3, §9 (PDF pp. 30-32)
- **TRD:** Orbit_Complete_Organized_Specifications.pdf — TRD (PDF pp. 19-24)
- **SCHEMA:** Orbit_Complete_Organized_Specifications.pdf — Backend Schema / Safety & Moderation (PDF p. 39)
- **ADMIN:** Orbit_Complete_Organized_Specifications.pdf — Admin & Monetization §§2-4 (PDF pp. 43-44)
- **SEC:** Orbit_Complete_Organized_Specifications.pdf — Security & Infrastructure §§2-5 (PDF pp. 49-51)
- **QA:** Orbit_Complete_Organized_Specifications.pdf — QA & Release §§1-10 (PDF pp. 62-65)
- **DEV:** Orbit development final document (1).docx — §§11.12-11.13, 14.8 (DOCX pp. 35, 41)
- **ADMIN_DEV:** Orbit Complete backend intro in monetization.docx — §§15-16 (DOCX pp. 5-6)

Original product source IDs that govern the module: PRD-004 (free safety), PRD-073 (chat block/report), PRD-083 (Moment reports), PRD-105 (block revokes protected access), PRD-106 (all six report targets), PRD-107 (nine report reasons), PRD-110 (Profile control center), PRD-121 (no ads in sensitive safety), PRD-142 (UI states), PRD-145 (accessibility). **M17-###** IDs below are engineering tracking IDs, not original PRD identifiers.

Approved stack equivalent: Flutter + Laravel 13 + MariaDB/MySQL + existing Blade Admin. Original Go/PostgreSQL/Next.js references do NOT authorize a stack rewrite. Product behavior follows PRD.

## 2. Existing code baseline — reuse, do not duplicate

- **M4 Social Graph / Laravel:** `app/Modules/Social/Services/SocialGraphService.php` — Friends, blocks, pairwise ACL, social revisions. **Reuse:** Reuse existing block policy; never replace.
- **M4 Block API / Laravel:** `routes/api.php -> /me/blocks` — Private block/unblock and revoked access. **Reuse:** Retain current routes; report may follow user action.
- **M7 Moment reports / Laravel:** `moment_reports + MomentInteractionController::report` — Moment/comment/account report, optional consented preview. **Reuse:** Adapt to unified M17 queue; keep M7 policy.
- **M7 audited evidence / Laravel:** `moment_evidence_access_logs + AdminMomentController::evidence` — Purpose/ticket-logged volunteered evidence read. **Reuse:** Case-specific RBAC and evidence rules.
- **M7 enforcement / Laravel:** `AdminMomentController::action` — Moment restrict/dismiss with media ACL revocation. **Reuse:** Reuse; do not bypass consent gates.
- **M13 Messaging reports / Laravel:** `messaging_reports + MessagingController::report` — E2EE reporting with explicit shared text. **Reuse:** Adapt to unified queue; no raw conversation browsing.
- **M13 Messaging moderation / Laravel:** `AdminMessagingController + MessagingSendRestrictionService` — Report review and account message send restrictions. **Reuse:** Reuse; single enforcement action ledger.
- **M2 Identity / Laravel:** `AdminIdentityController::updateStatus` — Account suspend/restore and audit. **Reuse:** Integrate case IDs, approvals and sessions.
- **M16 SOS Safety / Laravel:** `Safety/SosDeliveryWorker, safety Admin` — SOS incident audit and abuse signals. **Reuse:** Signal-only to M17; never hard-block emergency.
- **Report User UI / Flutter:** `features/profile/presentation/screens/report_user_screen.dart` — Approved layout and reason selection. **Reuse:** Extend enum/repository, wire real backend; no new UI.
- **Report User model / Flutter:** `features/profile/domain/models/report_user_models.dart` — Four legacy reasons only. **Reuse:** Map canonical reasons without changing screen styling.
- **Moment report UI / Flutter:** `features/moments/presentation/screens/orbit_live_moment_viewer.dart` — Moment/account/comment reporting and evidence consent. **Reuse:** Keep approved bottom sheet and actions.
- **Blocked Users UI / Flutter:** `features/profile/presentation/screens/orbit_live_blocked_users_screen.dart` — Server-backed blocked users screen. **Reuse:** Do not redesign or fork block action.
- **Shared Orbit components / Flutter:** `core/widgets and core/popups` — Existing header, primary/secondary buttons, sheets and navigation. **Reuse:** No new colors, popups, bottom-sheet systems.
- **Existing Admin layout / Blade:** `resources/views/orbit-admin/layout.blade.php + sidebar-navigation.blade.php` — Professional approved operational UI. **Reuse:** Separate first-class Moderation & Reporting sidebar.

## 3. Canonical architecture

1. **Source intake:** Keep existing M7 Moment/comment/account report API and M13 E2EE conversation/message report API untouched. Add generic account/profile/suspicious reporting with trusted-device auth, target-scoped ACL, explicit `client_id`, reason and optional details/evidence consent. Do not let a fake mobile callback claim success.
2. **Idempotent case ingestor:** Durable outbox resolves `(source_kind, source_id)` to one canonical M17 case and one or more immutable report links. Concurrency/duplicates cannot inflate sanctions or erase reporter receipts. Do not replicate plaintext from source tables.
3. **Case workflow:** Received → triaged → in_review → actioned|dismissed → closed; optional escalation/needs-info with revision. Policy-driven severity and SLA. Threat reports prioritized for *human* review, never auto SOS dispatch.
4. **Evidence:** User-volunteered selected evidence ONLY. M7 JPEG evidence remains conditional on consent and verified feature flags; M13 selected E2EE text only. Encrypted private storage, explicit purpose/assignment, access audit, and no routine chat/location browsing.
5. **Enforcement adapters:** M7 Moment restrict and media grant invalidation, M13 message send restrictions, M2 account suspension/session policy. New action ledger and approvals only orchestrate existing domain policies. M4 user blocks remain independent and authoritative.
6. **Appeals:** Affected user only; revisioned and reasoned decision with independent reviewer when needed; overturn applies compensating state and preserves historical enforcement/audit.
7. **Standalone Blade Admin:** Top-level **Moderation & Reporting** with Overview, Report Queue, Case Review, Enforcement, Appeals, Policies & Settings, Audit Trail; approved Admin UI, permission-driven navigation, separate from Accounts/Messaging/Config and M16 SOS.
8. **Flutter:** Preserve existing `ReportUserScreen`, Moment report sheet, conversation action menus, `BlockUsersScreen`, approved shared components. Extend only real functionality and typography-preserving state; no new design system.

## 4. Proposed database layout

| Table | New/existing | Planned fields | Indexes and privacy |
|---|---|---|---|
| moderation_cases | new | id UUID; source_kind; source_report_id; target_type/id; subject_user_id nullable; reason; severity; status; assignee_id; revision; timestamps | unique(source_kind,source_report_id); severity,status,created_at; target index |
| moderation_report_links | new | id; case_id; source_kind; source_id; reporter_digest; intake_at | unique(source_kind,source_id); case_id |
| moderation_case_events | new | id; case_id; actor_hash; event_type; redacted_data; prev_hash; event_hash; created_at | case_id,created_at; event_hash |
| moderation_evidence_items | new | id; case_id; source_report_ref; consent_type; cipher_ref; digest; expires_at; status | case_id,status; expires_at |
| moderation_evidence_access | new | id; evidence_id; admin_id; purpose_digest; case_revision; audit_digest; read_at | evidence_id,read_at |
| moderation_case_assignments | new | id; case_id; reviewer_id; assigned_by; from/to timestamps | case_id,assigned_at |
| moderation_actions | new | id; case_id; affected_user_id; target_type/id; action; scope; reason; expires_at; status; revision | case_id; affected_user_id,status; expires_at |
| moderation_action_approvals | new | id; action_id; reviewer_id; decision; reason_digest; timestamp | unique(action_id,reviewer_id) |
| moderation_appeals | new | id; action_id; appellant_id; statement_ciphertext; state; revision; submitted_at; resolved_at | action_id,state; appellant_id,created_at |
| moderation_appeal_events | new | id; appeal_id; actor_hash; state; reason_digest; created_at | appeal_id,created_at |
| moderation_controls | new | key; value_json; updated_by; revision; updated_at | primary key(key) |
| moderation_admin_audit | new | id; actor_admin_id; action; case_hash; purpose; prev_hash; event_hash; time | created_at,action |
| moderation_ingest_outbox | new | id; source_kind; source_id; status; attempts; due_at; processed_at | unique(source_kind,source_id); status,due_at |
| moment_reports | existing | M7 records for Moment/comment/account target with optional consented evidence | preserve original unique reporter/request hash |
| messaging_reports | existing | M13 E2EE conversation/message reports; selected evidence text encrypted | preserve original FK/retention behavior |
| blocks | existing | M4 relationship blocks | existing pair policy |
| messaging_send_restrictions | existing | M13 message-specific enforcement | existing user_id and end-time |
| orbit_users | existing | M2/Identity active/suspended | existing status/session semantics |

## 5. Proposed API contracts (Stage 2)

| Method | Path | Actor | Purpose | Security contract | Status |
|---|---|---|---|---|---|
| POST | /api/v1/moderation/reports | Member | Submit account/profile/suspicious report with client_id, target, reason, details, optional evidence consent | reporter, target scope, idempotency, size and rate | M17 new |
| GET | /api/v1/moderation/reports | Member | List only caller-owned report receipts, coarse states | session identity; no target-private data | M17 new |
| GET | /api/v1/moderation/reports/{id} | Member | Coarse receipt/status for own report | reporter only; no internal notes | M17 new |
| POST | /api/v1/conversations/{id}/reports | Member | Continue existing private-message reporting | existing M13 ACL; evidence explicit | M13 reuse |
| POST | /api/v1/moments/{id}/reports | Member | Continue existing Moment reporting | existing M7 audience authorization | M7 reuse |
| POST | /api/v1/moments/{id}/comments/{id}/reports | Member | Continue existing comment reporting | existing M7 comment/context ACL | M7 reuse |
| GET | /api/v1/moderation/actions | Member | Own appealable enforcement notices | affected account only | M17 new |
| POST | /api/v1/moderation/actions/{id}/appeals | Member | Appeal own eligible sanction | action owner, deadline, idempotency | M17 new |
| GET | /api/v1/moderation/appeals | Member | Own appeals with coarse status | affected account only | M17 new |
| GET | /admin/moderation | Moderator | Overview and aggregates | moderation.read + MFA | M17 new |
| GET | /admin/moderation/cases | Moderator | Queue with severity/SLA filters | moderation.read | M17 new |
| GET | /admin/moderation/cases/{id} | Moderator | Scoped case details and timeline | moderation.review | M17 new |
| POST | /admin/moderation/cases/{id}/assign | Moderator | Claim/reassign reviewer with revision | moderation.review + CSRF | M17 new |
| POST | /admin/moderation/cases/{id}/decision | Moderator | Dismiss/escalate/action review | moderation.review, reason, revision, confirm | M17 new |
| POST | /admin/moderation/cases/{id}/evidence | Evidence reviewer | Access volunteered evidence for case purpose | moderation.evidence + reason + audit | M17 new |
| POST | /admin/moderation/enforcements | Enforcement admin | Apply warn/restrict/suspend/restore with policy gates | moderation.enforce + dual approval where required | M17 new |
| GET | /admin/moderation/appeals | Appeal reviewer | Appeal queue and details | moderation.appeals | M17 new |
| POST | /admin/moderation/appeals/{id}/decision | Appeal reviewer | Uphold/overturn with compensating action | moderation.appeals + reason + revision | M17 new |
| GET | /admin/moderation/policy | Policy administrator | Configured reasons/severity/SLA/retention status | moderation.manage | M17 new |
| POST | /admin/moderation/policy | Policy administrator | Schema-validated policy update + audit | moderation.manage + explicit confirm | M17 new |
| GET | /admin/moderation/audit | Auditor | Immutable action/evidence access trail | moderation.audit | M17 new |
| GET | /admin/messaging/reports | Existing M13 | Legacy messaging report queue still accessible | messages.moderate | M13 existing |
| GET | /admin/moments | Existing M7 | Legacy Moment moderation metrics/queue remain | moments.read | M7 existing |

## 6. Role matrix and exclusions

| Role | Read queue | Review | Enforce | Appeals | Audit | Policies | Explicit exclusions |
|---|---|---|---|---|---|---|---|
| Super Admin | yes | yes | yes | yes | yes | yes | With justification and MFA; never routine private browsing |
| Moderator | yes | yes | limited | optional | no | no | Allowed scoped case review; no raw location |
| Senior Moderator | yes | yes | yes | yes | limited | no | Can enforce scoped sanctions with approvals |
| Safety | safe subset | safe subset | limited | no | no | limited | SOS operations remain separately permissioned |
| Support | counts only | no | no | no | no | no | Help articles and account status only |
| Auditor | read only | no | no | no | yes | no | Immutable audits only, no evidence plaintext |
| Finance/Growth | no | no | no | no | no | no | No moderation case browsing by default |
| Developer/Ops | aggregate | no | no | no | aggregate | no | Operational metrics only unless break-glass granted |

## 7. Exact Stage 1 traceability matrix

Every row below is specification/architecture status, not implemented. The original source reference is preserved in the Source column. Existing code already satisfying a portion remains subject to Stage 4 proof.

| ID | Area | Source | P | Requirement | Current baseline | Acceptance proof |
|---|---|---|---|---|---|---|
| M17-001 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Blocking, reporting and moderation remain free and never require a subscription | Partially implemented | Free/trial/expired user can block and submit a report; no paywall |
| M17-002 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | No advertisements or upsells appear in reporting, safety review or sensitive moderation | Partially implemented | Inspect UI and route guards; ads suppressed |
| M17-003 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | A report must not silently expose a reporter to the target user | Partially implemented | No reporter identity in target-facing responses or notices |
| M17-004 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Reports cover accounts, profiles, Moments, comments, messages and suspicious behavior | Partially implemented | All six categories produce stable case references |
| M17-005 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Canonical reasons: spam, harassment, threat, scam, impersonation, nudity, violence, safety concern, Other | Partially implemented | Each supported surface displays all nine choices or an approved contextual subset |
| M17-006 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Never silently map legacy “inappropriate_content” to a more severe unchosen reason | Partially implemented | Legacy reason preserved, canonical taxonomy mapping reviewed |
| M17-007 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | A user may report and block in either order without exposing private content | Partially implemented | Report-before-block and block-before-report controlled tests |
| M17-008 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Normal user reports remain private; moderation decisions are reviewed human processes | Partially implemented | Report cannot directly suspend another user or publish case status |
| M17-009 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | Safety threats are escalated into protected human review without inventing an emergency dispatch | Partially implemented | High-severity report creates flagged queue record, not SOS call |
| M17-010 | Product & Safety | PRD-004; PRD-105–107; PRD p.12; ADMIN p.45 | P0 | M17 does not create a second blocking/social graph implementation | Partially implemented | Existing M4 `blocks` and authorization policy reused |
| M17-011 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Preserve the approved Report User Flutter screen including cards, icons, colors and typography | Partially implemented | Pixel/widget regression against existing `ReportUserScreen` |
| M17-012 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Report User screen submission is authenticated and server-confirmed, never a fake delayed success | Partially implemented | No-callback demo path cannot claim report submitted in production |
| M17-013 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | User/profile report entry from existing person/profile options | Partially implemented | Route into existing Report User screen without new dialog |
| M17-014 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Moment report entry reuses existing viewer action and reporting sheet | Partially implemented | Original viewer action remains; report arrives in M17 queue |
| M17-015 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Comment report entry uses the existing private comment menu | Partially implemented | Comment reference and authorized context validated |
| M17-016 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Message report entry remains in existing conversation details and per-message menu | Partially implemented | User can report selected encrypted conversation/message |
| M17-017 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Suspicious-behavior report may reference an account without inventing a public feed | Partially implemented | Incident category accepted through trusted device API |
| M17-018 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Reason selector uses existing approved radio/selection components | Partially implemented | No new color, font, popup, sheet or nav system |
| M17-019 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Optional details have bounded validation and preserve user input when offline/retry | Partially implemented | Offline UI displays draft/unconfirmed; no fake success |
| M17-020 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Report preview explains optional and explicit evidence disclosure | Partially implemented | Clear consent; optional evidence default false |
| M17-021 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Success UI distinguishes server receipt from reviewer action | Partially implemented | Report received does not imply target actioned |
| M17-022 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Blocked/unavailable/deleted target yields neutral message without account enumeration | Partially implemented | 404-neutral response; reporter draft retained |
| M17-023 | Entry Points & Mobile | PRD-106–107; FLOWS pp.30-32; QA p.65 | P0 | Accessible touch targets, focus, labels and dynamic type | Partially implemented | TalkBack/VoiceOver and compact screen tests |
| M17-024 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | All new report requests require authenticated active trusted-device sessions | Partially implemented | Anonymous/untrusted/revoked access denied |
| M17-025 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Submission accepts a server-valid target type and typed target reference | Partially implemented | Forged target UUID or out-of-scope content denied |
| M17-026 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Client-generated request ID ensures retry/idempotency under network ambiguity | Partially implemented | Same client key returns same report, conflicts rejected |
| M17-027 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Legacy M7 and M13 ingestion remains backward-compatible with current endpoints | Partially implemented | Existing M7/M13 automated tests still pass |
| M17-028 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Unified case creation occurs exactly once per legacy report source | Partially implemented | Unique source_kind/source_id and transactional ingestion |
| M17-029 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Reasons and optional details are validated, size-bounded and normalized safely | Partially implemented | Invalid reason/oversized detail rejected without case |
| M17-030 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Reporter may view only own coarse report status, no private moderation notes | Partially implemented | Cross-user GET denied; no reviewer identity exposed |
| M17-031 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Search and listing are paginated with bounded filters and stable ordering | Partially implemented | Cursor or stable page tests; no unbounded queue |
| M17-032 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Case API returns opaque case ref and receipt state only on submission | Partially implemented | No immediate promise of disciplinary outcome |
| M17-033 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Report edits and withdrawal policy explicitly defined; withdraw cannot delete already-reviewed evidence without policy | Partially implemented | Withdrawal is a decision gate; do not implement silently |
| M17-034 | Intake API & Idempotency | PRD-106–107; TRD pp.20-24; QA p.62 | P0 | Rate limits deter duplicate abuse without silencing genuine high-risk reports | Partially implemented | Risk-based rate buckets and error UX tests |
| M17-035 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Only explicitly user-selected/volunteered E2EE message evidence is decryptable for review | Partially implemented | Moderator cannot browse arbitrary chat content |
| M17-036 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | No server-side plaintext extraction from normal E2EE message payloads | Partially implemented | Static/privacy tests prohibit decrypt endpoint without evidence |
| M17-037 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | M7 consented JPEG preview is reused; moderation evidence flag remains default-off until policy approved | Partially implemented | Feature flag/consent/audit tests |
| M17-038 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Evidence uploads are private, encrypted at rest and virus/format/size checked | Partially implemented | Malformed and oversized evidence denied |
| M17-039 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Evidence access requires purpose, case assignment, RBAC and a tamper-evident access event | Partially implemented | Every evidence access logged with reason and actor |
| M17-040 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Evidence access URLs are short-lived, single-purpose and cannot reveal other content | Partially implemented | Signed capability and IDOR tests |
| M17-041 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Reporter identifiers minimized in admin queue; no global private content browsing | Partially implemented | Moderator list renders pseudonymous reference only |
| M17-042 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | High-risk evidence cannot leak into analytics, push, logs, exported CSV or user notifications | Partially implemented | Log/export inspection and redaction tests |
| M17-043 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Audit chain cannot be silently edited through moderator/admin UI | Partially implemented | Append-only audit, hash or integrity check |
| M17-044 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Malicious reports themselves are rate-limited and auditable without public user punishment | Partially implemented | Repeated false report test does not auto-ban |
| M17-045 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Legal hold and erasure policy are explicitly approved before permanent retention or purge | Partially implemented | Decision D-04 before release |
| M17-046 | Evidence & Privacy | ADMIN p.44; SEC pp.50-51; DEV §11.12; PRD-106 | P0 | Cross-account cache and downloaded evidence cleanup on session revoke | Partially implemented | Account-switch and token-revocation tests |
| M17-047 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Dedicated top-level Moderation & Reporting Admin section; not nested in Accounts or Config | Partially implemented | Sidebar visible only with moderation.read permission |
| M17-048 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P1 | Overview shows safe aggregate open cases, severity, backlog and response deadlines | Partially implemented | Counts reconcile with cases without private data |
| M17-049 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P1 | Queue filters support source, priority/severity, state, reason and review age | Partially implemented | Filters constrained, paginated and tested |
| M17-050 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Case detail shows target-safe metadata, report timeline and evidence consent state | Partially implemented | Unauthorized admin/outsider receives 403 |
| M17-051 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Case state transitions are locked and revision checked | Partially implemented | Concurrent review cannot double-action |
| M17-052 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Assignment/claim workflow supports reviewer ownership and conflict-of-interest checks | Partially implemented | Two reviewers racing cannot claim same revision |
| M17-053 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Deduplicate/link repeat cases against same target while preserving individual reporter receipts | Partially implemented | Reporter identities remain separate and encrypted |
| M17-054 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Severity determination supports threat/safety queue priority with human override | Partially implemented | Severity audit recorded; no automatic emergency service claim |
| M17-055 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P1 | SLA policies configurable by Admin; no invented fixed time guarantees | Partially implemented | Config validation + escalation worker job tests |
| M17-056 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P1 | Case notes and internal rationale are immutable revisions/append-only history | Partially implemented | Previous note version remains auditable |
| M17-057 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P0 | Cases originating in M7/M13 remain visible through legacy Admin routes during transition | Partially implemented | No broken links; no duplicate enforcement |
| M17-058 | Moderation Queue | SCHEMA p.39; ADMIN p.44; DEV §11.12 | P1 | Case close and reopen events preserve timeline; no hidden destruction | Partially implemented | Timeline and revision tests |
| M17-059 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Warn, temporary limit, suspend, restore and approved content restrict actions are first-class | Partially implemented | Actions audited with reason, scope and expiry |
| M17-060 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Message-only restrictions reuse existing M13 send policy and keep encrypted message privacy | Partially implemented | M13 restriction tests remain green |
| M17-061 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Moment restrictions reuse M7 consented-evidence gates and media grant invalidation | Partially implemented | No media/cache/realtime visibility after enforcement |
| M17-062 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Global account suspension reuses existing M2/Identity status/session revocation path | Partially implemented | Device sessions revoked according to policy |
| M17-063 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Block remains user-controlled via M4 and is not conflated with Admin suspension | Partially implemented | No global blocks inserted by moderation accidentally |
| M17-064 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Every action needs reason, current case revision, admin permission and confirmation | Partially implemented | No unauthorized or stale enforcement |
| M17-065 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | High-impact/permanent sanctions require policy-configured dual approval or reauthentication | Partially implemented | Two-person approval where enabled |
| M17-066 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Time-bound restrictions expire deterministically via scheduled job | Partially implemented | No stale active restriction after expiry |
| M17-067 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Restore reverses action cleanly without restoring user-deleted content | Partially implemented | Grant/visibility and historical action tests |
| M17-068 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Punishment cannot be auto-triggered solely by number of reports | Partially implemented | Human review/policy threshold negative tests |
| M17-069 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P1 | Reporter receives generic closure status only where policy permits | Partially implemented | No staff name, target identity metadata, private rationale |
| M17-070 | Enforcement & Actions | SCHEMA p.39; ADMIN pp.43-44; SEC p.51; PRD-105 | P0 | Target receives an appropriate notice and appeal link where policy permits | Partially implemented | Notifications carry no sensitive evidence |
| M17-071 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Affected account can submit appeal against a valid appealable action | Not implemented | Other account/outsider cannot appeal |
| M17-072 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Appeals require stable action id and time window controlled by explicit policy | Not implemented | Expired appeal denied with informative state |
| M17-073 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Appeal text and optional evidence are private, size bounded and consent driven | Not implemented | No arbitrary media or content access |
| M17-074 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Appeal review uses independent reviewer where conflict policy requires | Not implemented | Reviewer cannot silently overturn own high-impact action |
| M17-075 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Appeal state machine includes submitted, under_review, upheld, overturned, closed | Not implemented | Invalid transitions fail with 409 |
| M17-076 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Overturn produces explicit compensating action and session/visibility reconciliation | Not implemented | No stale restriction remains after overturn |
| M17-077 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Appeals preserve original enforcement and audit history; never erase prior decisions | Not implemented | Read-only historical timeline test |
| M17-078 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Mobile appeal entry uses existing Profile/Settings approved styles | Not implemented | No new UI framework or bottom sheet |
| M17-079 | Appeals & Fairness | SCHEMA p.39; ADMIN pp.43-44 | P0 | Reporter appeal tracking does not reveal target private data or case evidence | Not implemented | Role-specific views enforce ACL |
| M17-080 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Independent Admin RBAC scopes for read, review, enforce, appeal, evidence, audit and policy | Partially implemented | Role matrix unauthorized tests |
| M17-081 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Admin MFA/current session and CSRF required for every mutation | Partially implemented | No unauthenticated action |
| M17-082 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Moderator role has no raw location, billing, ads, SOS media or arbitrary messages by default | Partially implemented | Cross-module denied tests |
| M17-083 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Sensitive access by Safety role and Moderator role remains separate | Partially implemented | M16 safety module unaffected |
| M17-084 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Report/case/evidence retention and legal holds governed by documented policy, not invented TTL | Partially implemented | Expiry and hold tests after decision |
| M17-085 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Account deletion flow includes moderation FKs and explicit evidence/appeal hold strategy | Partially implemented | M3 erasure mapping and migration tests |
| M17-086 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Scheduling works under Hostinger bounded Artisan cron; no unprovisioned Redis requirement | Partially implemented | Queue maintenance command deterministic and idempotent |
| M17-087 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Moderation feature flags and policy settings are schema-validated and audited | Partially implemented | No disabling blocking/reporting via monetization flag |
| M17-088 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | All endpoints use no-store privacy headers and scoped IDs | Partially implemented | Response cache/privacy tests |
| M17-089 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | CI covers fresh migrations, rollback/reapply, existing M4/M7/M13/M16 regressions | Partially implemented | GitHub Actions MariaDB tests |
| M17-090 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P1 | Mobile CI covers existing Report User/Moment/Messaging screen snapshots and new contracts | Partially implemented | flutter analyze + widget + Android/iOS builds |
| M17-091 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Staging two-account report/block/enforce/appeal end-to-end evidence required for release | Partially implemented | Negative IDOR and account revocation tested |
| M17-092 | Security, Retention & Ops | ADMIN pp.43-44; SEC pp.49-51; QA pp.62-65 | P0 | Backups only before first authorized M17 production deployment; do not deploy in Stages 1-4 | Partially implemented | Verified one-time backup and no-production-change log |

## 8. Decisions and release gates

| ID | State | Issue | Proposed rule | Owner | Deadline |
|---|---|---|---|---|---|
| D01 | Open | Report submission source identity | Use authoritative existing target ACL; define signed after-block reference behavior | Product + Security | Before Stage 2 public API |
| D02 | Open | Canonical reasons vs existing M7/M13/Flutter legacy enums | Adopt nine PRD-107 reasons for new UI, retain raw legacy reasons without silent coercion | Product | Before Stage 3 |
| D03 | Open | Severity thresholds and queue SLA | Configurable by policy, no invented numerical SLA | Trust & Safety | Before Stage 2 queue settings |
| D04 | P0 release gate | Retention of reports/evidence/audits/appeals and legal holds | Define exact TTL, deletion/export obligations and hold roles | Legal + Privacy | Before migration purge and production |
| D05 | Open | Reported-user notices and reporter outcome disclosure | Define minimal messages that do not expose reporter identities | Product + Legal | Before Stage 3 notifications |
| D06 | Open | Evidence preview and user-consent policies | Keep M7 preview/restriction flags OFF until approved; no generalized E2EE browsing | Privacy + Security | Before enabling evidence access |
| D07 | Open | High-impact sanction approval | Define which actions require recent MFA/two-person approval | Trust & Safety + Security | Before Stage 2 enforcement |
| D08 | Open | Appeal eligibility/time windows and reviewer independence | Configure explicit review and appeal policy; no assumed numbers | Product + Legal | Before Stage 3 appeals |
| D09 | Architecture | Legacy M7/M13 report convergence | Source-linked idempotent cases plus unchanged legacy endpoints and reads | Engineering | Stage 2 |
| D10 | P0 safety gate | False reports and suspected false SOS | No automatic blocking of real emergency SOS; signal only for human review | Safety + Security | Before release |
| D11 | Architecture | Admin layout and navigation | Dedicated Moderation & Reporting sidebar with approved Blade styles | Engineering | Stage 2 |
| D12 | Architecture | Flutter UI authority | Reuse ReportUserScreen, Moments sheet, conversation menus, shared popups | Engineering | Stage 3 |
| D13 | P0 release gate | Original master spec vs implementation stack | Flutter + Laravel 13 + MariaDB + existing Blade approved; preserve behavior | Engineering + Client | Stage 1 confirmed |
| D14 | Open | Evidence asset storage on Hostinger | Private storage; encrypt; verify object-store credentials and malware scanning capabilities | DevOps + Security | Before Stage 2 evidence upload |
| D15 | Open | Admin case export policy | Redacted metadata export to moderators; full evidence requires purpose/legal permission | Privacy + Auditor | Before Stage 2 exports |
| D16 | P0 release gate | Account deletion + reporting evidence constraints | M3 erasure policy integration, no silent FK cascade or infinite retention | Privacy + Legal | Before production |
| D17 | P0 release gate | M16 safety + M17 abuse coupling | Only reviewed risk flags, never disable free SOS at M17 ingestion | Safety + Trust | Before integration |
| D18 | Release gate | Physical and backend verification | Pass M4/M7/M13/M16 regressions, device screenshots, rollback, incident drill | QA + Security | Stage 4 |

## 9. Acceptance scenarios and Stage 4 proof

| Case | Area | Scenario | Expected result | Layer | Priority |
|---|---|---|---|---|---|
| M17-QA-01 | Intake | Trusted active user reports valid account | 201 receipt, single case | API | P0 |
| M17-QA-02 | Intake | Anonymous/revoked device submits report | 401/403; no case | Security | P0 |
| M17-QA-03 | Intake | Same client_id retry after lost response | Same receipt, no duplicate case | API | P0 |
| M17-QA-04 | Intake | Reuse client_id with conflicting reason | 409; no overwritten report | API | P0 |
| M17-QA-05 | Intake | Unrelated UUID or denied audience target | Neutral 404; no existence leak | Security | P0 |
| M17-QA-06 | Intake | All six PRD-106 report targets | Six report intake paths produce valid case source links | E2E | P0 |
| M17-QA-07 | Intake | All nine PRD-107 reasons | Reasons map without silent invention | Contract | P0 |
| M17-QA-08 | Intake | Legacy four-option Report User UI integration | Real server receipt; no delayed fake success | Flutter | P0 |
| M17-QA-09 | Intake | Free/trial/expired subscription report | Available without paywall | E2E | P0 |
| M17-QA-10 | Evidence | Submit message with no volunteered evidence | Accepted, admin cannot read message content | Security | P0 |
| M17-QA-11 | Evidence | Voluntary selected message evidence with consent | Only selected evidence readable with purpose+audit | Security | P0 |
| M17-QA-12 | Evidence | Send evidence without explicit consent | 422; reject and do not persist plaintext | API | P0 |
| M17-QA-13 | Evidence | M7 consented preview gate OFF | No preview disclosure; report still possible | Security | P0 |
| M17-QA-14 | Evidence | Admin attempts unreported private chat access | Denied, no unreported content route | Security | P0 |
| M17-QA-15 | Evidence | Moderator accesses case evidence without assignment | 403, no content shown | Security | P0 |
| M17-QA-16 | Evidence | Evidence access with purpose, ticket, MFA | Single audited read; no raw data in logs | Security | P0 |
| M17-QA-17 | Evidence | Evidence cross-case reference swap | 403/404; no IDOR | Security | P0 |
| M17-QA-18 | Queue | M7 report arrives in unified queue | Exactly one linked case, M7 route unchanged | Integration | P0 |
| M17-QA-19 | Queue | M13 report arrives in unified queue | Exactly one linked case, M13 route unchanged | Integration | P0 |
| M17-QA-20 | Queue | Same source consumed by concurrent workers | One case/source link only | Integration | P0 |
| M17-QA-21 | Queue | Queue sort by severity and SLA | Deterministic ordering; bounded page | API | P0 |
| M17-QA-22 | Queue | Two moderators race to claim a case | One succeeds; stale revision 409 | Integration | P0 |
| M17-QA-23 | Queue | Moderator dismisses report with reason | Case and append-only audit, no sanction | API | P0 |
| M17-QA-24 | Queue | Threat/safety report raises severity | Queue priority raised; no automatic SOS dispatch | Integration | P0 |
| M17-QA-25 | Enforcement | M13 message send restriction applied | M13 existing policy blocks send | Integration | P0 |
| M17-QA-26 | Enforcement | M7 Moment restricted after approved evidence | Audience grants revoked; unrelated Moments intact | Integration | P0 |
| M17-QA-27 | Enforcement | Global account suspension | Trusted sessions revocation per M2 policy | Security | P0 |
| M17-QA-28 | Enforcement | Permanent sanction without second approval when configured | Denied; no partial action | Security | P0 |
| M17-QA-29 | Enforcement | Timed sanction expires | Restored within bounded scheduled job pass | Integration | P0 |
| M17-QA-30 | Enforcement | Stale enforcement request | 409; no duplicate action | API | P0 |
| M17-QA-31 | Enforcement | Mass fabricated reports to auto-ban | No automatic punitive action | Security | P0 |
| M17-QA-32 | Appeals | Affected user appeals valid action | 201; appeal in queue | E2E | P0 |
| M17-QA-33 | Appeals | Unrelated user appeals action | 404/403; no private rationale | Security | P0 |
| M17-QA-34 | Appeals | Appeal accepted and sanction overturned | Compensating action; restriction removed | Integration | P0 |
| M17-QA-35 | Appeals | Appeal upheld | Prior action remains; event history preserved | Integration | P0 |
| M17-QA-36 | Appeals | Reviewer appeals own decision where conflict policy prohibits | Denied, audit trail | Security | P0 |
| M17-QA-37 | Privacy | Reporter block after report | No protected target data leaked | Security | P0 |
| M17-QA-38 | Privacy | Switch account while draft/evidence active | Old account material wiped/re-scoped | Flutter | P0 |
| M17-QA-39 | Privacy | Deletion while legal evidence hold active | Policy review; no silent cascade | Integration | P0 |
| M17-QA-40 | Privacy | Expired hold and approved retention purge | Only eligible data purged; audit remains | Integration | P0 |
| M17-QA-41 | UI | Report User screen compact 320x568 | No overflow; approved Inter/buttons | Flutter | P0 |
| M17-QA-42 | UI | M7 Moment report sheet snapshot | No redesign/regression | Flutter | P0 |
| M17-QA-43 | UI | Message report and blocked users path | Existing approved entry points work | Flutter | P0 |
| M17-QA-44 | Admin | Moderator cannot view precise location/SOS evidence | 403, no location fields | Security | P0 |
| M17-QA-45 | Admin | Finance/Growth tries moderation admin | 403, no sidebar section | Security | P0 |
| M17-QA-46 | Admin | Auditor tries a case mutation | 403, read-only audit | Security | P0 |
| M17-QA-47 | Admin | CSRF/MFA protection for enforcement | Denied without valid session | Security | P0 |
| M17-QA-48 | Admin | Every sensitive read includes purpose and audit | Read audit reviewable and immutable | Security | P0 |
| M17-QA-49 | Release | Fresh migration -> rollback -> reapply | No data loss or alteration to M1-M16 | CI | P0 |
| M17-QA-50 | Release | M4/M7/M13/M16 regression tests | All pass; source branches intact | CI | P0 |
| M17-QA-51 | Release | Physical Android/iOS accessibility and navigation tests | Screens match approved UI; no false success | Device | P0 |
| M17-QA-52 | Release | No deployment without explicit user authorization | Only branch/docs and CI artifacts exist | Process | P0 |

## 10. Four-stage delivery plan and safe operation

### Stage 1 — Requirements & Architecture

Exact PRD/TRD source mapping, existing code gap analysis, data/API/admin/UI architecture, test matrix

**Exit:** This PDF, workbook, handoff; no code or deploy.

### Stage 2 — Laravel + professional Admin

Additive MariaDB schema; unified reporting service; source adapters; queue; enforcement/appeal; independent approved admin area; audited evidence

**Exit:** Backend tests, migration rollback, Admin RBAC and documentation.

### Stage 3 — Flutter integration

Preserve existing Report User/Moment/conversation/Blocked Users UI; wire reports, categories, status, appeals; privacy-safe failures

**Exit:** Flutter widget/contract tests; no new design language.

### Stage 4 — Traceability + release audit

Row-by-row review, negative security tests, device QA, DB/regression CI, prepare backup/deployment commands

**Exit:** Reviewed evidence and blockers, no deployment without authorization.


**Stage 2 exit is code/CI only.** Build isolated M17 backend branch from current approved M16 baseline; fresh additive migrations and rollback; all read/review/evidence/enforcement/appeal actions audited; cross-module M4/M7/M13/M16 regressions; independent professional Admin sidebar. No production migration or deployment until authorization.

**Stage 3 exit:** Real Flutter submissions/status/appeals wired in **existing approved screens** without new popups/bottom sheets/typography/colors. Verify account switch, offline/unconfirmed states, reason taxonomy, Android/iOS builds, screen-reader/compact layouts.

**Stage 4 release gate:** Source-by-source PRD/TRD code traceability; RBAC/IDOR/E2EE/consent/audit/retention/appeal negatives; staging two-account drill; legal retention and moderation policy approval; first-module-production backup and deployment commands prepared BUT **DO NOT DEPLOY** without explicit authorization.

## 11. Dependencies, contradictions, and high-risk implementation notes

- M4 blocking is authoritative and immediately revokes profile/presence/location/media/chat sharing access where policy applies. M17 must not insert a second graph.
- User reports alone must not auto-ban a target. Severity is human-review priority, not a disciplinary verdict.
- PRD-107 has nine reasons; existing ReportUser screen has four. Extend enum/repository while maintaining Figma-approved ReportUser styling. Do not coerce vague legacy “inappropriate_content” to nude/violent reasons.
- The M7 Moment report supports consented preview controlled by flags; do not turn it on for all reports.
- The M13 messaging report stores explicitly selected evidence; no arbitrary admin reading of E2EE messages or keys.
- M16 SOS remains a separate life-safety workflow; abuse signals only inform reviewed cases and must never hard rate-limit real emergencies.
- Legal retention and evidence holds require policy approval. Account erasure must not silently cascade an incident or retain indefinite records.
- Admin MFA, review permission, reasons, CSRF and immutable audits are mandatory.
- Legacy endpoints and admin workflows remain backward-compatible until full Stage 4 sign-off.
- This Stage 1 handoff does not imply an implemented code module or production readiness.