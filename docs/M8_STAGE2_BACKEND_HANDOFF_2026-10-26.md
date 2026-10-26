# Orbit M8 — Stage 2 Backend Handoff (2026-10-26)

Branch: feature/m8-home-dashboard-backend-2026-10-26
Base: d7d74b05b54db0620f519d9c1a8b825fc610f4e5
Flutter branch (reserved for Stage 3): feature/m8-home-dashboard-flutter-2026-10-26
Never merge either branch to consolidated until Stages 2–4 have passed.

## Implemented
- OFF-by-default ORBIT_M8_DASHBOARD_ENABLED flag and optional M1 m8.dashboard rollout gate.
- Private trusted-device GET /api/v1/dashboard/summary; bounded M4 friends, M5 Circle and M7 Moment authorization-preserving metadata.
- Viewer-scoped per-section state and no-store response; no plaintext media, location or third-party audience disclosed.
- PUT /api/v1/me/dashboard/preferences with active-M5 Circle validation.
- POST /api/v1/me/dashboard/cards/{key}/dismiss, 24-hour idempotent per-account suppression for future approved card keys.
- Additive migration for dashboard_preferences and dashboard_card_dismissals.
- GET /admin/home-dashboard limited by MFA and config.manage RBAC, non-sensitive status only.
- tests/Feature/OrbitM8DashboardTest.php contains targeted API and admin tests.

## Scope boundaries
- Live Presence, location, Plans, Ping, SOS, activity and contextual cards remain disabled pending real providers.
- No Firebase changes; feature toggles remain OFF by default.
- No production migration, deploy, branch merge, or local code execution happened here.

## Required relevant tests, on disposable local/staging backend checkout
    php -l app/Modules/Dashboard/Services/DashboardSummaryService.php
    php -l app/Modules/Dashboard/Http/Controllers/DashboardSummaryController.php
    php -l app/Modules/Dashboard/Http/Controllers/AdminDashboardController.php
    php -l database/migrations/2026_10_26_000001_create_m8_dashboard_preferences.php
    php -l tests/Feature/OrbitM8DashboardTest.php
    php artisan migrate:status
    php artisan test --compact --filter=OrbitM8DashboardTest
    php artisan test --compact --filter='OrbitM4SocialGraphTest|OrbitM5CirclesTest|OrbitM7MomentsTest'

Apply fixes on the same M8 backend branch; QA evidence needed before completion claims.
