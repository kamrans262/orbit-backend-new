# Orbit M5 — Native Circle invitation links and device release gate

**Implementation:** M5 Flutter PR #6; Backend PR #10. This is a *test procedure*, not a claim of real signed-device acceptance.

## Code is prepared
- Flutter: `OrbitInviteLinkRuntime` consumes warm/cold App Links via `app_links`, validates the official Orbit HTTPS host/path, holds the recipient-bound code until a trusted session reaches Home, and opens explicit Circle preview/confirmation (never auto-joins).
- Backend: `GET /invite/circle/{token}` is a non-indexable, non-referring fallback for exactly 40 alphanumeric characters. It never authenticates, joins, or reveals the member roster.
- iOS: `GET /.well-known/apple-app-site-association` advertises both `/invite/friend/*` and `/invite/circle/*` only after a verified app ID is configured; entitlements already include the production-association hostname.
- Android: `GET /.well-known/assetlinks.json` advertises the actual package and certificate SHA-256; the manifest contains `autoVerify` link handlers for both private link routes.
- These URLs do not carry additional member/location/message details.

## Required operator-controlled identity configuration
The Flutter Android `applicationId` currently reads `com.example.orbit_ui` and **must not be assumed to be the final signed package**. Release signing currently uses a debug configuration; it is **not production acceptance**. iOS team/bundle identity and signed build distribution have not been independently verified.

Set the following **only from the actual signed artifacts** at rollout, not guesses:
```dotenv
ORBIT_IOS_APP_ID=<APPLE_TEAM_ID>.<FINAL_IOS_BUNDLE_ID>
ORBIT_ANDROID_PACKAGE=<FINAL_ANDROID_PACKAGE>
ORBIT_ANDROID_SHA256=<SHA256_COLON_SEPARATED_FINGERPRINT_FROM_RELEASE_CERTIFICATE>
```

Maintain the canonical host `indigo-caribou-178970.hostingersite.com` until an approved domain migration adjusts the exact trusted-host parsers, Android manifest, Apple entitlements and both association responses atomically.

## Production / native acceptance commands (run by release engineer)
- `curl -i https://indigo-caribou-178970.hostingersite.com/.well-known/apple-app-site-association`
- `curl -i https://indigo-caribou-178970.hostingersite.com/.well-known/assetlinks.json`
- `adb shell pm get-app-links <FINAL_ANDROID_PACKAGE>`
- `adb shell am start -a android.intent.action.VIEW -d "https://indigo-caribou-178970.hostingersite.com/invite/circle/<40_CHAR_RECIPIENT_CODE>"`
- `xcrun simctl openurl booted "https://indigo-caribou-178970.hostingersite.com/invite/circle/<40_CHAR_RECIPIENT_CODE>"` (simulator behavior is not equivalent to signed physical-device Universal Links validation)

**Required two-account trials**: sender A invites friend B; link on recipient B previews only safe Circle metadata after trusted login; Cancel does not join; explicit Join adds B once; wrong recipient / replay / expired / revoked / blocked / untrusted session receives neutral error. Cold-start signed-out and warm-start paths must both pass, and browser fallback must not disclose Circle contents.

## Distinct release gates
1. Android APK CI success proves build only—not verified package association or user consent flow.
2. iOS plist XML check is not a signed Apple Universal Link acceptance test.
3. Approved design screenshots are not available from the original audit; compare Android/iOS screenshots manually against the approved source.
4. The 40 original M5 CT cases require recorded manual evidence. Backend unit tests and Flutter widget tests do not replace manual acceptance.
5. No Firebase code or configuration is included. The `OrbitCircleChangeSource` interface accepts metadata-only invalidation hints, requiring authenticated server re-read; a future verified transport implementation must provide its own subscriber ACL/revocation tests.

**Security:** Never store recipient codes in analytics, logs, push messages, screenshots, or URL query parameters. Production signing credentials must not be committed to Git.
