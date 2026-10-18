# Orbit — Milestone 01 | Configuration & Platform Foundation

## Client documents / scope
- Master TRD, page 17: modular monolith, server authorization, no embedded secrets, feature flags and remote configuration.
- Master TRD, page 19: Config domain, remote config/feature flags/kill switches.
- Master Admin & Monetization, pages 44 and 47: rollout percentage, platform/version/country/cohort targeting, change history, schema validation, emergency disable, rollback.
- Master Security, pages 49–50: separate admin identity and MFA, short session timeout, sensitive data control, protected audit logs.
- User-approved stack: **Flutter/Dart, Laravel 13/PHP, MySQL/MariaDB, Blade + HTML/CSS/vanilla JS**.

## Deliverables
- Dedicated M1 schema, non-destructive additive migrations, UUID7 identities.
- Separate admin authentication with password + mandatory time-based OTP, independently scoped Laravel guard, 15-minute inactivity expiry, throttling, and console-only first administrator provisioning.
- Feature flags: default off, deterministic percentage rollout, platform/country/min-app-version/cohort matching, independent kill switch, preview, audit reason and revision rollback.
- Remote configuration: JSON type validation, draft/published states, server-only/client-visible toggle, revision rollback.
- Flutter-readable *public hints* API: GET /api/v1/config/bootstrap (not a source of security/entitlement authorization).
- Laravel Blade admin pages for dashboard, flag editor, config editor, preview, MFA/login, with responsive custom CSS and JavaScript.
- Automated tests and GitHub Actions CI on isolated MariaDB service.

## Security boundaries
- No preseeded administrator; run `php artisan orbit:admin:provision` on a private local/staging terminal after migrations.
- Admin password + MFA enrollment secret must never be pasted to GitHub, tracked files or public logs.
- Production must use HTTPS, secure-cookie/session settings, environment-specific secrets, backups and nonpublic admin provisioning.
- Client-visible remote configuration must NEVER contain provider credentials or private secrets. Admin operator approval/review is necessary.
- Feature flag values are *presentation hints*, not authorization: server API policies enforce user relationships, billing, privacy and SOS independently.
- Configuration audit is append-only in application code with hash-linked entries. Full immutable/tamper-evident production protection, alerting and durable audit storage require operational hardening beyond M1.

## Local staging setup — NOT production
1. Checkout `feature/m1-foundation-config` into the existing backend project.
2. Create and verify a **separate empty development MySQL/MariaDB database**; do not reuse production credentials or database.
3. `composer install` and configure your local `.env` (APP_KEY, DB_* and secure session settings as appropriate).
4. Make a backup before any change to a database containing existing data. Only then run `php artisan migrate` against the development database.
5. `php artisan orbit:admin:provision` — enroll the printed key into an authenticator app.
6. `php artisan serve` and open `http://127.0.0.1:8000/admin/login`.
7. Create a disabled test feature flag; verify preview, edit with reason, enable, kill switch, history and rollback.
8. Open `http://127.0.0.1:8000/api/v1/config/bootstrap?platform=android&app_version=1.0.0&country=PK` and verify client-visible published config only.
9. Run `php artisan test`. CI executes tests with a fresh disposable MariaDB database.

## Acceptance & known dependencies
- [ ] GitHub Actions syntax and integration checks pass.
- [ ] User verifies responsive Blade admin UI and sign-in/MFA on local staging.
- [ ] MariaDB 11.8 migrations, revision rollback and API smoke test pass.
- [ ] Determine Hostinger queue, Redis, WebSocket, protected admin session and cron capabilities before dependent milestones.
- [ ] Production backup, restore, permission isolation and audit immutability verified *before* any production release.
- [ ] Flutter configuration bootstrap integration to be tested when Flutter networking bootstrap is wired.

Other requirements (including SOS, E2EE, Relay, full admin console, ads and purchase verification) remain pending in their documented modules. Do not mark them as complete as part of M1.
