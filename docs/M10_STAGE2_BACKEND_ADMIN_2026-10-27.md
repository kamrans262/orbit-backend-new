# Orbit M10 — Stage 2 Laravel & Admin implementation

**Branch:** existing backend feature/m9-presence-backend-admin. No new branch created. Flutter consolidated branch untouched.  
**Feature switches:** ORBIT_M10_LOCATION_ENABLED=false, ORBIT_M10_LOCATION_REALTIME_ENABLED=false, ORBIT_M5_LOCATION_CONSUMER_ENABLED=false (all default OFF). No Hostinger deployment.

## Implemented
- Laravel/MariaDB additive schema for explicit per-person/per-Circle grants, encrypted short-TTL latest point and non-GPS consent-audit events.
- No permanent location_history or saved_places table and no log of precise coordinates.
- Trusted-device authenticated private APIs: capabilities, grants list/create/revoke/stop-all, sample ingest, authorized per-viewer map.
- M3 global Ghost/visibility and M4 mutual blocking/friendship plus M5 Circle consent, membership and restricted-role rechecks.
- Server-side approximate quantization; precise values never included in restricted JSON.
- Replay/future timestamp and accuracy bounds; session/device revalidation on every REST call.
- Time-based one-hour and local until-tonight expiry, manual stop and friend-only always opt-in.
- Per-Circle reviewed M5 permission updates; revoke updates and no-store private response headers.
- Admin aggregate operational read-only page /admin/location under config.manage+MFA; no personal GPS access or disclosure.
- Explicit maintenance command orbit:location:prune, not scheduled on production.
- Focused PHP syntax + MariaDB feature workflow, no destructive production tests.

## Deliberately blocked/not claimed
- Until leaving: geofence radius/hysteresis and exact departure policy require client approval; API rejects unsupported duration rather than inventing behavior.
- Standalone API & Realtime Specification missing: these route names are Stage 2 proposals, not extracted final client contract.
- No realtime location broadcaster/ticket/gateway implemented or activated; map returns updated/paused and realtime_available=false; never labels poll as LIVE.
- No raw GPS history, paid history, sponsor pins, SOS exception, route/navigation, background OS acquisition or Flutter native map yet.
- Global M3 privacy settings must separately be approved by the account; a Circle or friendship never starts sharing.
- Approximate grid (~0.02 degrees) and freshness/battery budgets need product+security QA; no false certification.
- M10 database migrations and flags must not run on Hostinger without separate reviewed deployment.

## Manual verification boundaries
- Test CI runs on disposable MariaDB only, not Hostinger production.
- Admin navigation uses existing Orbit Admin styles and requires config.manage.
- Runtime must not be enabled until reviewed Stage 3 OS permissions/UI, safe WSS gateway and external Redis availability, and device testing.
