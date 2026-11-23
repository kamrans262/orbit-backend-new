# ORBIT — Module 17 Stage 4 Engineering Audit, Traceability and Release Handoff
**Date:** 24 November 2026  
**Flutter repository:** kamrans262/orbit-app-new, branch `feature/m17-moderation-flutter-2026-11-24`  
**Laravel/Admin repository:** kamrans262/orbit-backend-new, branch `feature/m17-moderation-backend-admin-2026-11-23`  
**Disposition:** **Engineering code audit passed with targeted fixes; production release and original-document requirements signoff NOT approved.**
**Deployment:** No production code, migrations, Flutter builds, policy flags, FCM/APNs settings, or Hostinger cron configuration were deployed or changed.

## 1. Source authority and traceability
The original master `Orbit_Complete_Organized_Specifications.pdf` and `Orbit development final document (1).docx` were **not available** for clause-by-clause reading. This review uses the existing **73-row M17 Stage 1 provisional traceability**, the complete Stage 2 Laravel and approved Blade code, the complete Stage 3 Flutter code, and observed automated CI evidence. Prior PRD/TRD labels and disposition columns are **provisional**, not proof that every original requirement was met.

Companion tracker: `docs/M17_STAGE4_REQUIREMENTS_TRACEABILITY_2026-11-24.csv`. Disposition summary: **36 engineering-supported rows; 20 partial/integration-evidence rows; 17 unresolved policy/production gates**. Even an engineering-supported row needs original-document confirmation before signoff.

## 2. Stage 4 code corrections made
- **Safe moderator attribution:** M7 comment reports reference the **comment author** rather than the parent Moment author; selected M13 message reports reference the verified message sender in the same conversation. Direct conversation reports without a selected message identify the single other member only when a two-member relationship is unambiguous. Ambiguous Circle reports stay unassigned rather than punishing an arbitrary participant.
- **Metadata-only scheduled indexing:** M17 source sync selects only specific M7/M13 routing fields. It does not fetch full report rows or accidentally select encrypted evidence, reporter narratives, chat plaintext, audio, location or keys. Evidence access still requires M17 permission, original M7/M13 permission, consent, an auditable ticket and source-module authorization.
- **M7 enforcement boundary:** Unified M17 cases for Moments/comments cannot directly issue a warning or M13 messaging restriction through M17. The original source-owned Moment moderation policy, evidence consent and restriction-release gate remain authoritative, and the approved Admin panel explains this rather than offering a misleading action form.
- **Moderator assignment:** Every assigned reviewer must currently have `moderation.triage` permission and an active administrator account, not just a valid ID.
- **Sanction target:** M17 cannot sanction an account that is inactive or pending deletion.
- **Private appeal association:** The affected user can receive their own `action_id` in the appeal history, preventing a second appeal prompt for the same recorded action, without gaining access to another user's report or the internal case.
- **Accurate user-facing expiry:** Flutter distinguishes recorded M13 restrictions from restrictions whose server expiration has elapsed and parses naive Laravel UTC timestamps as UTC rather than device-local time.
- **Audit integrity check:** Added `php artisan orbit:moderation:audit-verify`, a bounded **read-only, on-demand** HMAC-chain checker. The feature test intentionally edits a past case event and confirms the integrity checker flags it. It is not scheduled and cannot substitute for external immutable backups.

## 3. Verified automated evidence
**Laravel, code commit 503cfee9:** PHP lint, route registration, MariaDB migration, isolated M17-only rollback/reapply, M17-specific behavior and cross-module regression. Stage 4 code commit 503cfee9 passed **12 M17 tests / 128 assertions**, **104 prior-module tests / 1,042 assertions**, and **3 separate M13 Admin tests / 47 assertions**. Its tamper-detection test was included. The additional M7 sanction-boundary patch requires the subsequent latest branch run for final verification.

**Flutter, code commit 2fe87f58:** Stage 4 focused Dart analyzer, M17 API contracts, approved Report User widgets and navigation, M4/M14/M15/M16 regressions. The 2fe87f58 focused test job passed **11 M17 data tests**, **9 approved Report User widget tests**, and the existing navigation/M4/M14/M15/M16 regressions. Its Android and unsigned iOS compile jobs require individual final status checks.

The preceding Stage 3 Flutter code commit 9dc9021a passed Flutter CI including Android APK and unsigned iOS compile. These are compile checks, NOT end-to-end provisioned Firebase/APNs validation, production smoke tests, camera/media checks or physical-device acceptance.

## 4. Important release blockers (do not mislabel as completed)
1. **Original contract authority:** master PRD and TRD not provided; 73 provisional rows cannot receive final acceptance.
2. **Policy decisions:** account-level suspensions, safe reinstatement, modifications to an appealed sanction, M7 Moment cross-module restrictions, independent time-bound evidence grants, lawful retention/erasure and survivor/SOS protections need signoff or implementation. They are deliberately *not* silently enabled.
3. **Notifications:** M14 outcome notifications and actual device receipts are not integrated for M17. The authenticated private actions/appeals screens exist; do not describe that as an in-app delivery event or push guarantee.
4. **Source-state reconciliation:** original M7/M13 reports and M17 cases are independent by design; synchronized source resolution/retention and stale source access require a reviewed policy before automatic writes.
5. **Operational proof:** actual Hostinger schedule/queue, production secrets and `orbit:moderation:sync` runtime, concurrent moderation stress, restore drill, trusted/revoked device, mobile offline retry, Android/iOS screenshots, and report-to-appeal flows against a controlled backend were not run.
6. **Evidence audit:** an internal HMAC event chain can reveal changed/deleted middle events, but cannot prove the history wasn't completely truncated by an actor able to alter the database and signing secret. No externally anchored WORM log or restoration certification was shown.
7. **Current rollout:** `enforcement_enabled` is **0 by default** in the additive migration. Account reporting intake uses `ORBIT_MODERATION_INTAKE_ENABLED` (default true) after its schema is installed; the ability to toggle enforcement is separately permissioned and audited.

## 5. Release procedure prepared, NOT executed
**One backup at the start of the M17 production migration**, using the existing approved Hostinger/database backup workflow; do not require backups at every later code check. Confirm existing M1–M16 data and current deployment commit first.

Read-only operator checks, once user authorizes a release window:
```bash
cd ~/domains/indigo-caribou-178970.hostingersite.com/orbit-backend-new && git status --porcelain && git branch --show-current && php artisan migrate:status
```
The M17 backend branch must first be integrated **through the approved deployment branch** without replacing M1–M16. On explicit deployment authorization, the additive migration to execute is `2026_11_23_000001_create_orbit_m17_moderation.php`; after applying, check:
```bash
php artisan route:list --path=api/v1/moderation
php artisan route:list --path=admin/moderation
php artisan orbit:moderation:audit-verify
php artisan orbit:moderation:sync
```
Verify the five-minute cron uses the existing scheduler; no extra M17 secrets or duplicate cron are required. Do **not** use `migrate:fresh` or roll back a populated M17 migration casually: the M17-only rollback drops its stored reports, audit trails and appeals. On release failure, prefer code rollback with safe database preservation and explicit recovery approval.

For Flutter, integrate the M17 feature branch into the existing approved mobile branch only after backend API availability, then check `flutter analyze`, `flutter test test/features/moderation`, the approved Report User widgets and actual-device navigation. Android/iOS debug builds from GitHub CI are never automatically deployed to users.

## 6. Final readiness
**Stage 4 engineering implementation:** corrected, tested, and documented.  
**Stage 4 functional/requirements audit:** provisional; source authority and policy decisions unresolved.  
**Production certification:** **NOT complete**.  
**Deployment:** **NOT performed**.

Existing approved Orbit UI, components, colors, typography, routes, and M1–M16 ownership boundaries were preserved. No new admin styling or private E2EE content indexing was introduced.
