# ORBIT — M17 Stage 2 Laravel Backend & Moderation Admin
**Date:** 2026-11-23 | **Repository:** kamrans262/orbit-backend-new
**Active branch:** `feature/m17-moderation-backend-admin-2026-11-23`
**Scope:** Backend/Admin only; no Flutter design changes and absolutely no deployment.

## Engineering outcome
Built M17's additive, private-by-default moderation system on top of Stage 1. The existing M4 block graph, M7 Moment report/evidence access, M13 E2EE message reporting and messaging restriction services, M14 notifications, M15 Plans and M16 SOS remain in their original modules. No new global chat decrypt/export capability, duplicated evidence store, admin style system, or hidden emergency suspension was introduced.

### Stage 2-owned source files
- `app/Modules/Moderation/Services/ModerationService.php`: trusted-device authenticated account reporting; idempotent 201/200 receipt; encrypted optional details; rate limiting; report-after-own-block without unblocking; bounded idempotent M7/M13 source indexing; tracked case events and encrypted reviewer notes; revisioned case transitions, claim/reassignment, scope-limited sanctions and independent appeals.
- `app/Modules/Moderation/Http/Controllers/ModerationController.php`: seven private mobile API handlers, no reporter identity or evidence exposed to subject.
- `app/Modules/Moderation/Http/Controllers/AdminModerationController.php`: permission-bound operational overview, queue, case detail, reviewer notes, sanctions, consented evidence dispatch via original module owners, independent appeals, settings and audit.
- `resources/views/orbit-admin/moderation.blade.php`: only approved Orbit Blade layout, buttons, cards, filters, tables and nav registry. **No CSS, design tokens or Flutter screens changed.**
- `database/migrations/2026_11_23_000001_create_orbit_m17_moderation.php`: **ten additive M17-only tables**: moderation_reports, moderation_cases, moderation_case_sources, moderation_case_events, moderation_case_notes, moderation_actions, moderation_appeals, moderation_evidence_access, moderation_controls and moderation_control_audits. Independent rollback preserves all earlier modules.
- `config/orbit_moderation.php`: account intake independent of enforcement; **enforcement_enabled = 0** after migration.
- `routes/api.php`, `routes/admin.php`, `routes/console.php`: new scoped routes and five-minute metadata-only reference sync.
- `resources/views/orbit-admin/partials/sidebar-navigation.blade.php`: standalone **Moderation & Reporting** section using the existing navGroups and messages SVG icon. Old Moments and Messaging Admin links remain intact.
- `tests/Feature/OrbitM17ModerationTest.php`: M17 behavioral coverage, including owner-only read, safe blocked report, concurrency/revision, origin evidence boundary, sanitized Admin rendering, encryption, notes, assignment, independent appeal and reversal.
- `tests/Feature/OrbitMessagingAdminTest.php`: test-harness-only removal of shared throttle middleware from a behavioral test; **production M13 middleware remains unchanged**.

### Existing mobile API contracts (Stage 3 consumer)
All under `/api/v1` and existing `AuthenticateOrbitToken` plus additional M17 trusted-device check:
| Method | Route | Result |
|---|---|---|
| GET | `/moderation/capabilities` | Private operational readiness |
| POST | `/moderation/reports` | Account report; `client_id`, `target_kind=account`, `target_id`, existing Flutter `reason`, optional `additional_details`; idempotent `201/200` |
| GET | `/me/moderation/reports` | Owner-private receipts only |
| GET | `/moderation/reports/{report}` | Owner-private receipt or anti-probing `404` |
| GET | `/me/moderation/actions` | Affected subject's scope-limited actions |
| POST | `/moderation/actions/{action}/appeals` | Subject-only, one encrypted idempotent appeal |
| GET | `/me/moderation/appeals` | Subject-only status and outcome |

Existing `/me/blocks`, Moment report routes and Messaging report routes remain unchanged. M17 does **not** allow direct client case search or generic plaintext evidence upload.

### Admin workspace / Permissions
Navigation: Overview, Report Queue, Cases & Investigations, Enforcement Actions, Appeals, Settings and Audit Trail. Laravel `web` stack handles session, MFA and CSRF, plus separate action route RBAC:
- `moderation.read`: workspace and metadata-only listings
- `moderation.triage`: revision-safe claim, assignment, investigation state and encrypted reviewer notes
- `moderation.decide`: gated warning or M13 messaging-send restriction, no full account/SOS suspension
- `moderation.appeals`: independent reviewer, cannot review an own original decision
- `moderation.evidence`: M17 privilege **plus** original `moments.moderate` or `messages.moderate`, source consent, ticket, confirmation, source audit and M17 access receipt
- `moderation.manage`: bounded sync and reasoned, audited `enforcement_enabled` toggle requiring typed `ENABLE`
- `moderation.audit`: metadata-only global event and setting-change history

### Status and QA evidence
The companion `M17_STAGE2_REQUIREMENTS_CODE_EVIDENCE_2026-11-23.csv` keeps all 73 provisional Stage 1 rows, appending code evidence and the remaining integration/policy gates.
The GitHub Actions workflow `.github/workflows/orbit-m17-moderation-tests.yml` runs MariaDB 11.8, PHP 8.4 syntax/route validation, complete migration + isolated M17 rollback/reapply, dedicated feature tests and M4/M7/M13/M14/M15/M16 regressions. In the preceding green run: **8 M17 tests (100 assertions), 104 cross-module tests (1,042 assertions), and 3 existing Messaging Admin tests (47 assertions)**. The newly added reviewer-reassignment test requires the subsequent latest-commit CI run for final verified count.

### Explicitly unfinished, not falsely declared complete
1. **Stage 3 Flutter:** Replace `ReportUserScreen`'s no-handler 250-ms fake success with the real M17 API callback, stable `client_id` retries and error/receipt states; preserve the approved screen and BlockUsersScreen pixel geometry. Surface member sanctions/appeals only through approved existing UI.
2. **Source authority:** Master PRD/TRD PDF and internal DOCX were unavailable. Prior PRD-073/105/106 labels remain provisional until source-by-source inspection.
3. **Policy-gated moderation:** Account-wide suspension, Moment enforcement via unified M17 case, appeal modification, retention/erasure holds, supervisory overrides and release of additional evidence types are **not** silently enabled. Keep original M7/M13 flags.
4. **User outcome notifications:** Affected users can query authenticated actions/appeals; M14 user-facing delivery/outbox and delivery receipts are not implemented here. No push receipt invented.
5. **Stage 4:** Legal privacy/evidence retention, M16 emergency boundary, high-load lock behavior, complete audit chain/restore, physical device and cross-module block caching need further verification.

**Do not deploy:** no Hostinger SSH, production migration, privilege granting, cron configuration, Firebase configuration or mobile release has been executed. Stage 4 prepares the authorized one-time module backup/migration and rollback instructions.
