# M5 — CT-01…CT-40 release evidence ledger

**Origin:** Original Orbit M5 audit of 2026-10-21, CT-01–CT-40. The source defines each acceptance scenario. This ledger does not change their meaning or invent test passes.

- Backend CI runs PHP syntax and all automated M1–M5 tests against MariaDB. Flutter CI runs Dart analysis/widget tests and builds a debug Android APK. These are **code checks**, not proof that all manual CT cases passed.
- Firebase transport, downstream sensitive data consumers and native signed links are intentionally OFF until verified credentials, safety tests and signing identities exist.
- Every case requires an explicit reproducible `PASS` record with evidence and tester, including relevant signed Android/iOS screenshots, API logs without secrets, browser proof and performance report. Default is **NOT VERIFIED**.
- Copy the CSV template `docs/M5_CT40_EVIDENCE_TEMPLATE.csv`, fill its 40 cases and run `python3 scripts/m5_ct_acceptance_gate.py <csv_path>`. The gate intentionally rejects an untouched template. The CSV is evidence metadata; keep private logs/identities out of Git.

| Case | Original acceptance scenario | Required evidence | Status |
|---|---|---|---|
| CT-01 | Create first Circle / Owner persists | API + Android/iOS create/relaunch | NOT VERIFIED |
| CT-02 | Skip onboarding Circle | Android/iOS onboarding | NOT VERIFIED |
| CT-03 | Multiple independent Circles | API + device | NOT VERIFIED |
| CT-04 | Invalid name and duplicate retry | API + Flutter retry | NOT VERIFIED |
| CT-05 | Owner rename/icon/color persists | API + device | NOT VERIFIED |
| CT-06 | Nonowner edit prohibited | API denial + UI | NOT VERIFIED |
| CT-07 | Friend invitation and explicit acceptance | 2-account API/device | NOT VERIFIED |
| CT-08 | Recipient-bound code join | 2-account API/device | NOT VERIFIED |
| CT-09 | Expired/revoked/replayed code rejects | API denial | NOT VERIFIED |
| CT-10 | Decline and revoke | 2-account API/device | NOT VERIFIED |
| CT-11 | Concurrent duplicate joining | DB concurrency | NOT VERIFIED |
| CT-12 | Owner/Admin/Member/Restricted role matrix | API + device | NOT VERIFIED |
| CT-13 | Member removal and access loss | API + Firebase device | NOT VERIFIED |
| CT-14 | Leave Circle | API + device | NOT VERIFIED |
| CT-15 | Transfer Owner exactly once | DB/API + device | NOT VERIFIED |
| CT-16 | Owner leave safeguard | API denial | NOT VERIFIED |
| CT-17 | Cross-Circle IDOR separation | API denial | NOT VERIFIED |
| CT-18 | Block during common Circle | API + Firebase device | NOT VERIFIED |
| CT-19 | Block denies new invitation | API denial | NOT VERIFIED |
| CT-20 | Default location OFF | Privacy API + device | NOT VERIFIED |
| CT-21 | Per-Circle permission independence | API consent test | NOT VERIFIED |
| CT-22 | Global/per-Circle Ghost precedence | Policy + downstream consumers | NOT VERIFIED |
| CT-23 | Temporary sharing expiry | Policy + downstream consumers | NOT VERIFIED |
| CT-24 | Restricted member cannot write | API + future consumer | NOT VERIFIED |
| CT-25 | No demo data on Circle list | Android/iOS visual | NOT VERIFIED |
| CT-26 | Real invitation status tabs | Android/iOS visual | NOT VERIFIED |
| CT-27 | No fake map/presence in detail | Android/iOS visual | NOT VERIFIED |
| CT-28 | Offline snapshot read-only | Android/iOS airplane | NOT VERIFIED |
| CT-29 | Logout/account-swap offline isolation | Android/iOS 2 accounts | NOT VERIFIED |
| CT-30 | Instant realtime subscriber revocation | Actual Firebase physical devices | NOT VERIFIED |
| CT-31 | Native invitation link after login | Signed Android/iOS and DNS | NOT VERIFIED |
| CT-32 | Temporary expiry/archive/restore | Clock+API+device | NOT VERIFIED |
| CT-33 | Deletion/erasure FK integrity | Disposable DB | NOT VERIFIED |
| CT-34 | Admin RBAC/least privilege | Admin browser | NOT VERIFIED |
| CT-35 | M1–M4 regression | Full backend/Flutter CI+devices | NOT VERIFIED |
| CT-36 | API IDOR matrix | Negative API tests | NOT VERIFIED |
| CT-37 | Invitation rate limits and spam | API staging | NOT VERIFIED |
| CT-38 | Permission change failure rollback | Network fault physical device | NOT VERIFIED |
| CT-39 | Free SOS simultaneous / Plus custom gate | Policy + future SOS | NOT VERIFIED |
| CT-40 | Safe Hostinger release dry-run | Backup + migrate pretend + rollback | NOT VERIFIED |

**Release rule:** 100% sign-off requires all 40 independently verified passes plus signed native tests, measured normal metadata p95 under representative staging load (<250ms), an approved backup + migration dry-run, correct Firebase RTDB rules, and matching deployed backend/mobile hashes. The record may be BLOCKED for legitimately unavailable downstream M6+ services, but then sign-off cannot claim that separate product functionality is complete. Do not enable automatic purge or location/Moments/chat/SOS consumers just to obtain a green ledger.
