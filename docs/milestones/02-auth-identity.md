# Orbit Milestone 02 — Identity, Verification, Sessions & Devices

## Client requirement anchors (not a substitute for full tracker)
- Master TRD §8: provider-assertion verification, short access sessions, rotating device-bound refresh sessions, device/session inventory.
- Master Workflows §3: email/phone/Apple/Google choice, verification, identity resolution, profile basics, optional onboarding permissions.
- Master Data Schema §Identity: user_identities, devices, sessions; status, revoked timestamps, lookup indexes.
- Master Security §2-3: hashed refresh tokens, revoke per device, replay detection, separate admin auth.
- Internal Development §11.10: OTP request/verify; 15-minute bearer access, 60-day rotating refresh; device registration.
- Internal Development §11.11: rate limits and §14.6-14.7: device trust, fresh OTP per device, registration and revocation.
- User-provided credentials (NOT in Git): Hostinger SMTP exists; Twilio trial SID/token exist, SMS sender/from number unverified.

## Implemented in this branch
- UUID Orbit account tables added *without modifying the existing stock Laravel users table*; UUID identities and devices.
- Six-digit email/SMS OTP request/verify with hashed expiring codes, invalidation, rate limits, no code echoed.
- SMTP email via server-side Laravel Mail; SMS via the **Twilio Verify API** when TWILIO_VERIFY_SERVICE_SID is set; Twilio generates and verifies the code. Legacy Messages API with TWILIO_FROM_NUMBER remains a fallback only when no Verify Service SID is configured. Misconfigured Verify fails closed with 503 instead of silently falling back.
- Orbit-owned signed HS256 access JWT (15 minutes, scoped to Orbit mobile) with database session checks.
- Hashed 60-day refresh sessions, rotation, replay-family revocation, logout, per-device revocation.
- Device verification: OTP required on each new device, initial device trusted; later devices pending and require approval by a trusted registered-key device.
- Device registration stores only a 32-byte base64 X25519 public key; overwrites refused.
- Profile basics and session/device metadata APIs.
- Blade admin read-only user inventory and masked identity view under users.read permission.
- Automated Laravel/MariaDB tests.

## API contract
- POST /api/v1/auth/otp/request {channel:email|sms,recipient}
- POST /api/v1/auth/otp/verify {channel,recipient,code,device_public_id,platform,device_name}
- POST /api/v1/auth/refresh {refresh_token}
- POST /api/v1/auth/logout (Bearer access)
- GET /api/v1/me (Bearer), PATCH /api/v1/me/profile-basics
- POST /api/v1/devices/register {public_key,device_name?,push_token?}
- GET /api/v1/me/devices, PATCH /api/v1/me/devices/{id}, POST /api/v1/me/devices/{id}/approve, DELETE /api/v1/me/devices/{id}

## Safety / credentials
- Store TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_VERIFY_SERVICE_SID in private server .env only for Twilio Verify. The alternative Messages path requires TWILIO_FROM_NUMBER. Never copy secrets into chat, commits or logs. Do not commit or paste.
- Phone input uses E.164 (+923...); Twilio trial may only contact approved recipients.
- Never run `php artisan test` or `migrate:fresh` against the live Hostinger database.
- Do not accept a bearer token as admin auth; do not grant Orbit account access via admin session.
- Persistent device keys must be generated on-device; private keys must not be uploaded.
- Access JWT is independently checked against active, non-revoked backend session on every request.

## Outstanding client approvals / gates (DO NOT mark complete)
- Master supports Apple + Google provider login; implement verified provider adapter when Firebase/Apple/Google credentials are supplied.
- Master permits email/phone/Apple/Google; internal spec is phone-only OTP; sign-in UI/password fields require client UX decision.
- Twilio trial sender and verified destination require testing; SMTP deliverability needs end-to-end check with nonproduction recipient.
- Flutter secure hardware-backed keys, encrypted storage, token refresh, user session bootstrapping, UI integration need validated mobile builds.
- Device attestation and 10-second revocation propagation over realtime gateway require future infrastructure.
- User identity linking/account recovery, credential/email change and full account deletion are cross-module planned items.
- Operational cleanup: rate-limit event metrics, identity event retention, replay family history pruning.
- Mark user-facing auth functional completion only after live OTP, device registration, mobile tests and client sign-in flow acceptance.

## Additional Milestone 2 security/account flows
- POST /api/v1/auth/logout-all signs out all user sessions and preserves registered-device records.
- POST /api/v1/me/deletion-request {confirm:true} records a non-destructive deletion request, revokes all sessions and never falsely claims final erasure; approved retention/privacy deletion service is still required.
- POST /admin/users/{user}/status requires users.manage, a reason >=10 chars, and audits active/suspended changes. Suspension revokes all existing sessions.
- Admin-only controls cannot read OTPs, private messages, secret tokens, or private precise locations.
- The Apple/Google provider integrations and disputed password UI remain documented client dependencies; this code does not silently remove them.

- POST /api/v1/me/identities/link links a newly OTP-verified email/phone to an existing signed-in trusted-device account. Existing identity in a different account returns 409; no silent account merging.
- Full merging of existing independent accounts, sensitive identity unlink/change policy, and recovery from loss of every trusted device require separately agreed security flows.

## Password-enabled standard sign-up, without bypassing OTP/device trust
- New nullable `orbit_users.password_hash` column is added via forward-only migration; no existing account/password data is replaced.
- POST /api/v1/me/password enrolls a first-time password after OTP and trusted device registration within ten minutes; Hash::make is used, and existing password hashes cannot be overwritten.
- POST /api/v1/auth/password/otp/request validates the enrolled password and sends the same email/SMS OTP. It returns no token; only successful OTP verification and device checks can issue sessions.
- The approved Flutter Sign Up and Login layouts stay intact, while the primary buttons enter the same six-digit OTP journey with the supplied email/phone prefilled.
- Password recovery/change still requires a separate verified recovery workflow; Apple/Google are client-access deferred.
