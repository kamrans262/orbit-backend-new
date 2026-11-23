# ORBIT | Module 17 — Moderation & Reporting
## Stage 1: Requirements, Architecture, API Contracts and Acceptance Gates

**Prepared:** 23 November 2026  
**Stage:** Requirements / design only — no code, migrations, UI replacements or deployment  
**Mobile:** Flutter/Dart, existing Orbit app (`kamrans262/orbit-app-new`)  
**Backend/Admin:** Laravel 13 / MariaDB / existing Blade Admin (`kamrans262/orbit-backend-new`)

> **Absolute visual rule:** The existing approved Flutter and Blade UI is the sole design baseline. M17 must **not** introduce a new UI style, theme, font, layout system, navigation paradigm, standalone reporting design or duplicated bottom sheet. New functionality may be wired into existing components, and a dedicated M17 Admin sidebar group may be added using the *existing* sidebar component.

### Scope authority / audit caveat
The original master PRD/TRD PDF and internal developer DOCX are **not accessible in this Stage 1 run**. We have inspected current authoritative Flutter and Laravel repository code and the prior Orbit handoff/traceability reference. Hence PRD-073, PRD-105 and PRD-106 are **references reported in the earlier M13 matrix, not independently re-verified against original pages**. Rows driven by engineering design are explicitly marked as proposals. Stage 1 architecture can be accepted provisionally, but a claim of *100% original PRD/TRD traceability* is **blocked** until the actual source files are cross-checked.

### Executive scope and boundaries
M17 unifies cross-application report ingestion, investigation, sanctions, appeals, evidence policy and audit while preserving implementations previously delivered in M4 (block), M7 (Moment reporting), M13 (encrypted messaging reporting), M14 (notifications), M16 (SOS safety) and the current Blade Admin. **Do not migrate, copy or decrypt private content to build a generic queue.** A central case is an index and decision record over the original module's report rows. Reports are not automatically proof of abuse; due process, consent, reviewer permissions and explicit action guards apply.

### Confirmed code audit
- Flutter `ReportUserScreen` uses the approved header/card/reason design. If `onSubmit` is null it sleeps for 250 ms then displays **“Report submitted. Thank you.”** without contacting the server. This is a verified **P0 false-success problem** to close in Stages 2/3; do not alter its visual design.
- The live Block Users screen is backed by `OrbitSocialRepository.blocks / block / unblock` and the current BlockUsersScreen.
- The existing M7 Moment viewer already offers report actions for Moment, comment and account, and separately opts into a consented preview. The backend has `moment_reports` and reasoned, audited, gated restriction/evidence paths.
- The M13 Conversation Options and Circle Chat Options already report conversations/messages with explicit plaintext-evidence consent. Backend uses `messaging_reports`, audit and MessagingSendRestrictionService. Arbitrary admin access to chat contents remains forbidden.
- The Admin nav uses the existing `navGroups` / `nav-item` / `nav-submenu` registry and permission checks; existing M7 reports are within Moments, existing M13 reports within Messaging. M17 provides one coordinated queue **without deleting or breaking those screens**.

### Required surfaces and reusability mapping
| Surface | Existing approved baseline | M17 change | Visual change |
|---|---|---|---|
| User report | `ReportUserScreen` + `ReportReason` | Real callback; API receipt/error; optional submit retry | **None** |
| Block list | `OrbitLiveBlockedUsersScreen` / `BlockUsersScreen` | Reuse M4 backend; multi-module block regression | **None** |
| Moment report | `OrbitLiveMomentViewer._reportSheet` | M17 case index of M7 report; keep preview consent | **None** |
| Conversation report | `OrbitMessagingConversationActions._report` | M17 case index of M13 report | **None** |
| Report confirmation/error | Existing Orbit snackbar and modal components | Show only server-proven result | **None** |
| Admin sidebar | `resources/views/orbit-admin/partials/sidebar-navigation.blade.php` | New sibling `Moderation & Reporting` group with existing `navGroups` style | Same components |
| Admin cases | Existing Blade `layout`, page headers, tables, filters, cards, dialogs | Add case queue, detail, appeals, audit and settings | Same CSS/icons |

### Stage 2 components — no replacement of existing modules
1. `ModerationIntakePolicy`: trusted-device session, current actor, target validation, abuse control, idempotency.
2. `ModerationSourceAdapter`: typed adapters to M7 Moment, M13 Message and new account report sources. **Source rows remain authoritative and are not duplicated.**
3. `ModerationCaseService`: dedup, triage, assignment, revisioned state, notes and audit.
4. `ModerationEnforcementService`: reasoned warnings and scoped sanctions; calls existing M7/M13/Identity services instead of overwriting their stores. Source-specific rollout flags default fail-closed.
5. `ModerationAppealService`: owned submission, independent adjudication, idempotent resolution and reversal.
6. `ModerationEvidenceGateway`: explicit permission, ticket, consent, no-store, narrow target, audit before releasing user-volunteered evidence. Admin cannot decrypt private messages or arbitrary Moments.
7. `ModerationRetentionWorker`: record deletion/erasure & hold decisions subject to approved policy; no automatic archival of decrypted private content.
8. `AdminModerationController`: separate `Moderation & Reporting` sidebar using existing Blade layout/filter/confirmation styles and RBAC.
9. `ModerationCaseNotifier`: generic M14 notification references; never include reporter identity or case evidence in push payloads.

### Suggested relational schema (new additive tables; exact columns confirmed in Stage 2)
- `moderation_reports`: new account-level report record (UUID; reporter_id; subject_user_id; reason; encrypted optional details; request_key_hash; payload_hash; status; created_at). Existing M7/M13 report tables remain untouched.
- `moderation_cases`: UUID; target_kind; target_id or pseudonymous subject key; case_status; priority; assignee_admin_id nullable; revision; created_at; updated_at; closed_at nullable. Exclude plaintext evidence, location, arbitrary message ciphertext.
- `moderation_case_sources`: UUID; case_id; source_module (`account`,`moment`,`messaging`); source_report_id; linked_at; UNIQUE(source_module,source_report_id). Avoid cascade that silently destroys legal evidence.
- `moderation_case_events`: immutable UUID; case_id; actor_kind; action; reason_code; revision_after; previous_event_hash; event_hash; created_at.
- `moderation_actions`: UUID; case_id; subject_user_id; scope (`warning`,`messaging_send`,`moment_visibility`,`account_access`); policy code; start/end; previous_action_id; idempotency key; state; issued_by; created_at.
- `moderation_appeals`: UUID; action_id; appellant_id; encrypted statement; status; reviewing_admin_id; decision_code; revision; created_at; decided_at.
- `moderation_evidence_access`: UUID; case_id; source_kind/source_report_id; admin_id; reason/ticket digest; consent_proof; accessed_at; auditable immutable.
- `moderation_controls` and `moderation_action_outbox`: review-safe feature settings and reliable notifications/action retries. Rollout default report/review enabled, destructive/restrictive enforcement disabled until approval.
- All FK actions, indices, delete/retention semantics and migration rollback boundaries must be approved, tested and documented. Avoid a fixed retention period until original/legal requirements verified.

### State machine (provisional, not yet original-PRD validated)
```text
report created/verified -> pending
pending -> triaged | dismissed
triaged -> investigating | dismissed
investigating -> actioned | dismissed
appeal submitted for eligible action -> appeal_pending
appeal_pending -> upheld | modified | reversed
(supervisor) actioned/dismissed -> reopened -> investigating
```
Constraints: stale revision `409`; case status and source report status are **not** the same enum. Enforcements are side effects only after reviewed authorization and cross-module policy validation. Any reversal references original action and cannot restore revoked/deleted private data. A disabled module flag never blocks normal report submission.

### API design contract (proposed, **NOT EXISTING**)
Base: `/api/v1`. All mobile APIs inherit current Orbit authenticated/trusted-device access, generic server response envelope and `Cache-Control: private, no-store` for sensitive resources. Input allowlist, UUID validation, server-owned status, no arbitrary report-row queries.

| Method | Proposed route | Purpose / authorization |
|---|---|---|
| POST | `/moderation/reports` | New **account-level** report: `{client_id, target_kind:'account', target_id, reason, additional_details?}`. Do not duplicate existing Moment or Messaging write routes; source adapters ingest their reports. 201 id+receipt, 200 duplicate same payload, 409 reused key with different payload. |
| GET | `/me/moderation/reports` | Optional owned-only report receipt listing; never exposes target investigation, reporter identities or private evidence. |
| GET | `/moderation/reports/{report}` | Owned-only receipt state; anti-enumeration 404. |
| POST | `/moderation/actions/{action}/appeals` | Authenticated affected subject only; idempotent `client_id`, limited optional encrypted statement. |
| GET | `/me/moderation/appeals` | Appeal receipts and outcome only; no investigator/internal evidence. |
| GET | `/moderation/capabilities` | Safe client feature readiness only, **never** used as permission oracle. |

The **existing** `/me/blocks` routes, `/moments/{moment}/reports`, `/moments/{moment}/comments/{comment}/reports`, `/conversations/{conversation}/reports` remain supported. No changes to wire schemas until tested against existing Flutter clients.

Admin (Laravel web + session + MFA + CSRF + RBAC):
| Method | Proposed Blade route | Permission |
|---|---|---|
| GET | `/admin/moderation` | `moderation.read` |
| GET | `/admin/moderation/cases` | `moderation.read` |
| GET | `/admin/moderation/cases/{case}` | `moderation.read` |
| POST | `/admin/moderation/cases/{case}/triage` | `moderation.triage` + revision |
| POST | `/admin/moderation/cases/{case}/decision` | `moderation.decide` + explicit reason, confirmation, revision |
| POST | `/admin/moderation/cases/{case}/evidence` | `moderation.evidence` + evidence consent/ticket and audit |
| GET/POST | `/admin/moderation/appeals[/{appeal}]` | `moderation.appeals` |
| GET | `/admin/moderation/audits` | `moderation.audit` |
| GET/POST | `/admin/moderation/settings` | `moderation.manage` (write requires validated reason) |

Expected API errors: 401 unauthenticated, 403 forbidden/restricted, 404 private or gone, 409 revision/idempotency conflict, 422 invalid/unsafe input, 429 abuse throttle with reasonable recovery, 503 system unavailable. No false success after network failure. Denied evidence access must not disclose its existence.

### Admin sidebar plan (same design; illustrative structure)
```text
Moderation & Reporting
  Overview
  Report Queue
  Cases & Investigations
  Enforcement Actions
  Appeals
  Settings
  Audit Trail
```
Add using existing `navGroups` and icon partial, not a new design system or a new Accounts/Configurations submenu. Do not remove pre-existing Messaging reports/restrictions or Moments monitoring during initial M17 rollout. Their views may link to the canonical case after parity testing; no forced data migration.

### Security threat model / invariants
- **E2EE:** no server-side decrypt, bulk chat export, key access, or arbitrary Moment browsing; user volunteered content only.
- **Permission:** at every collection/list/detail/action/evidence step re-evaluate admin RBAC and reporter/subject ownership; records may become inaccessible after block or deletion.
- **Evidence:** M7/M13 consent gates remain in control; limited ticket/explicit confirmation, no-store and time-bound authorization; all access auditable. No reconstitution of private evidence by joining encrypted message source tables.
- **Moderation integrity:** reported content is allegation, not proof; distinct reviewer/appeal guard, structured decisions, immutable audit, no silent evidence deletion.
- **Blocking and SOS:** M4 block revokes relevant interpersonal access promptly. Emergency features must keep M16's independent authorization and not be globally disabled by account sanction without reviewed safety policy.
- **Erasure:** deletion requests and lawful evidence holds remain separate; no indefinite retention invented in Stage 1.
- **Availability:** reporter can submit even when sanction engine rollout is disabled; tenant-wide enforcement switches cannot weaken basic block controls.

### Acceptance/CI suite blueprint
1. Existing ReportUserScreen full visual snapshot and widget test; submit with no handler cannot claim success; server-created receipt is only success.
2. M4 block tests: two devices, bilateral block, cross-M7/M13/M5/M10/notification cache and expired URL denial.
3. M7 report intake, comment context, idempotency; consented preview, ticket and audit; restriction flag and revocation.
4. M13 direct/group report, message identity, user-selected evidence only; reject wrong thread, forged plaintext/consent, side-channel and broad admin content access.
5. Canonical queue ingests existing M7/M13 rows once without mutating legacy statuses; both backfill and live insertion.
6. Case triage, assignment, concurrent revision 409, append-only audit hash, no duplicate side effects.
7. Reviewer permissions matrix and MFA/CSRF; menu hidden and endpoints still 403; evidence role separate from read/triage.
8. Sanctions: existing M13 restriction and optional M7 visibility adapter, expiry/reversal, multi-device auth/session re-evaluation and preserved SOS safety.
9. Appeals: own action only, one active appeal policy, independent adjudicator, reversible action, reporter anonymity.
10. Retention and erasure, audit continuity, no private material in analytics/logs/FCM or HTML caches.
11. Laravel unit/feature tests, MariaDB additive migration/rollback/reapply, all M1–M16 regression tests and Flutter analyzer/widget/Android+iOS build checks.
12. Device acceptance: end-to-end report, offline timeout/retry, consent, block, appeal and admin feedback without UI redesign.

### Stage handoff and release rules
**Stage 2:** implement additive schema and Laravel services; professional Admin area reusing the existing `orbit-admin` Blade layout; preserve M4/M7/M13/M14/M16; tests and status evidence before Stage 3. **Stage 3:** wire existing Flutter screens, report, block and appeals only through existing components; remove false success without altering layout; tests. **Stage 4:** source-by-source audit and privacy test gate; prepare backup/migration/deployment commands. No deployment without user authorization, no automatic production configuration or destructive DB changes.

### Open decisions (explicitly not silently resolved)
1. Original PRD/TRD exact M17 requirement IDs/pages and relative priority; client source PDF/DOCX not available for reinspection.
2. Full canonical sanction matrix, account suspension severity and whether user warning/appeal UI needs specific customer-approved entry points.
3. Legal and privacy retention/erasure limits for reported content and appeals, including SOS overlapping holds.
4. Moderator access to any additional media types beyond existing explicit JPEG/text consent; default deny.
5. Reviewer assignment/separation of duties and appeal SLA: no timing promised or imposed without source/policy.
6. Report reason taxonomy mapping across existing Account (4), Moment (10) and Message (5) labels without altering approved UI.
7. Prior `moment_reports.report_target` indicates account reports can be Moment-context-based; cross-module account reports need one canonical target/duplicate policy.

### Authoritative evidence inventory
- **AUTHORITATIVE MISSING:** `Orbit_Complete_Organized_Specifications.pdf` — Original 65-page master PDF; not available to inspect; PRD reference labels from older audit are provisional
- **AUTHORITATIVE MISSING:** `Orbit development final document (1).docx` — Original developer doc; not available to inspect; do not treat handoff as substitute
- **AUTHORITATIVE MISSING:** `Orbit_Complete backend intro in monetization.docx` — Original admin/backend supplemental doc; not available to inspect
- **VERIFIED LIVE CODE:** `Flutter lib/features/profile/presentation/screens/report_user_screen.dart` — Approved Report User visual; onSubmit omitted => fake success after Future.delayed
- **VERIFIED LIVE CODE:** `Flutter lib/features/profile/presentation/screens/orbit_live_blocked_users_screen.dart` — Already live blocked-user API list with approved BlockUsersScreen
- **VERIFIED LIVE CODE:** `Flutter lib/features/moments/presentation/screens/orbit_live_moment_viewer.dart` — Report Moment/comment/account + consented JPEG preview route
- **VERIFIED LIVE CODE:** `Flutter lib/features/messaging/presentation/orbit_messaging_conversation_actions.dart` — M13 message/conversation report with consented optional plaintext
- **VERIFIED LIVE CODE:** `Laravel app/Modules/Moments/Http/Controllers/AdminMomentController.php` — M7 report moderation and approval-gated evidence/restriction
- **VERIFIED LIVE CODE:** `Laravel app/Modules/Messaging/Http/Controllers/AdminMessagingController.php` — M13 reports, audit, evidence confirmation and send restriction
- **VERIFIED LIVE CODE:** `Laravel database/migrations/2026_10_25_000001_create_m7_moments.php` — moment_reports and actions persisted; retain unchanged
- **VERIFIED LIVE CODE:** `Laravel database/migrations/2026_10_30_000004_create_orbit_messaging.php` — messaging_reports and audits persisted; retain unchanged
- **VERIFIED LIVE CODE:** `Laravel app/Modules/Messaging/Services/MessagingSendRestrictionService.php` — Already supports suspend and restore
- **VERIFIED LIVE CODE:** `Laravel resources/views/orbit-admin/partials/sidebar-navigation.blade.php` — NavGroups registry, css and icon partial; M17 uses identical registry
- **SECONDARY HANDOFF:** `ORBIT_PROJECT_HANDOFF_2026-10-18.md` — M17 scope and PRD authority, privacy guardrails, no destructive deployment
- **SECONDARY AUDIT:** `Orbit_M13_Stage1_Requirements_Traceability_2026-10-30.xlsx` — References PRD-073, PRD-105, PRD-106; original page confirmation still needed

### Traceability register
The companion Excel and CSV contain **73** case-by-case controls. Each row includes baseline, implementation owner, test, code anchor and decision. Do not call missing-source references verified.