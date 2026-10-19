# Orbit M3 — Erasure and offline acceptance gate

Status: DRAFT / NOT DEPLOYED. No production erasure has been authorized or run.

## Verified implementation scope
M3 identity/profile account deletion requests have an existing 30-day grace period, recent-login confirmation, revoke-all and cancellation before eligibility. The new processor implements opt-in cleanup for **current M1–M3 database-owned personal data only**. Its dry-run review checks request state, eligibility, active retention holds, schema coverage, and operator permission. Completed transactions write a non-identifying hashed receipt, preserving admin audit and not claiming whole-product deletion.

The available 2026-10-19 handoff is not the original signed-off retention specification. Before production enablement verify the grace period, data export rules, legal holds, backup retention, and external data owners against the actual client source. Do not infer regulatory retention deadlines from current code.

## Erasure safety release gate
- Config: ORBIT_M3_ERASURE_EXECUTION_ENABLED defaults to false.
- No scheduler and no automatic erasure. Never run destructive commands on Hostinger until explicit approval, independent backup, retention review and operator ticket.
- Read-only dry run: php artisan orbit:deletions:review REQUEST_UUID
- Execution requires an explicit execute flag, an approved non-sensitive ticket and an active admin operator with BOTH users.delete and deletions.process permissions. The exact CLI invocation is available from php artisan help orbit:deletions:review.
- The processor checks that no new unregistered user-owned FK tables appeared, and blocks when any active legal retention hold is present.
- Grace period and cancelled requests cannot be erased. It uses lock ordering consistent with cancellation, performs current-schema transactional cleanup, retains anonymized request-ID receipt, and is idempotent.
- Pending requests cannot be bypassed using the ordinary Admin Delete User button.
- Additive migration: 2026_10_20_000003_create_m3_erasure_receipts_and_holds.php. Review SQL with migrate --pretend --force and independently back up DB before live migration.
- Admin deletion queue distinguishes pending records from completed current-scope receipts.

## Offline read-only profile gate
- Secure storage caches only verified profile fields (ID, name, username, bio), owner ID and timestamp, NOT tokens or privacy settings in snapshot payload.
- Reading requires local refresh-token presence, correct owner and maximum one-hour age. Reject corrupt, foreign, future and expired snapshots.
- If login verification fails due to network/temporary server error, only the offline-only profile route is available, never full Home.
- Server denial (401/403/409) cannot grant offline authorization. Logout and account switch clear cache. Reconnect verifies with backend before Home.
- This is NOT the Orbit Relay offline sync, location, messaging, or cached privacy authorization.

## Acceptance matrix
1. Test duplicate signup, login, user profile edits and notification/privacy settings with expendable accounts.
2. Disable network after successfully opening profile, relaunch app, verify ONLY read-only offline profile.
3. Reconnect: access Home only after server confirms session; revoke token and retry: no offline Home.
4. Switch accounts or sign out and confirm previous cached profile is inaccessible.
5. In isolated test DB: request deletion, reauthenticate, check restricted access and cancellation.
6. In isolated test DB: verify requests cannot execute during grace period, on hold, after cancellation, or without two permissions.
7. Process a mature consented fixture ONLY in isolated test DB; verify user-linked graph cleanup, other users intact, receipt anonymity and idempotency.
8. Check Android/iOS physical-device behavior, offline restart, 1-hour TTL and mobile error states manually.
9. Check real Hostinger migration status, route list, response code and Admin UI on expendable accounts, with safe backups.
10. Do NOT claim all-product erasure: media, backups, messages, circles, payments, legal archives, Firebase, and Relay have separate owners and acceptance gates.

## Deployment and truthful status
These changes are draft GitHub work. CI on isolated runners is not a live Hostinger deployment, Android/iOS acceptance, or compliance certification. Do not run Laravel PHPUnit/RefreshDatabase against Hostinger live data. Record real-device evidence and signoff before marking M3 as accepted.
