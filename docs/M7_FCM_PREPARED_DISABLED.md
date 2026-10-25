# Orbit M7 — Firebase Cloud Messaging prepared, NOT enabled

Firebase push is an optional transport. M7 private HTTPS inbox, ciphertext publishing, Moments feeds and private retention work independently of Firebase.

## Current behavior
- `ORBIT_M7_FCM_ENABLED=false` is mandatory until the client's Firebase project and app registrations have been provided and verified.
- There is no FCM initialization in Flutter when the authenticated bootstrap reports `enabled:false`, and no backend FCM HTTP call when the flag is off.
- All push messages contain a generic `type=moment_available` event only. Never send IDs, captions, names, location, Circle data, keys, or encrypted media in FCM.
- The client re-fetches data from Laravel after notification. Current consent, session, Circle and Moment ACL are enforced at read and send time.
- Moment notification opt-in and platform push opt-in must **both** be true. Per-Circle mute suppresses in-app and FCM notifications, but does not grant/revoke Moment media access.
- FCM HTTP v1 server **acceptance** is logged as `accepted_at`. It is not an on-device display or read receipt.

## Client inputs required for future activation
- Firebase project ID, Web API key, sender ID, Android Firebase app ID, iOS Firebase app ID, iOS bundle ID, plus Android/iOS app registrations with matching identifiers.
- Firebase service-account JSON file with project matching the configured project ID, stored outside `public_html`, with restrictive filesystem permissions; never commit the file or its private key.
- Apple APNs credentials/capabilities for iOS notifications and correct Google/Firebase application registrations; test both platforms with physical devices.
- Approved rollout, privacy permissions and opt-in policy.

## Backend .env (do NOT set to true yet)
```dotenv
ORBIT_M7_FCM_ENABLED=false
ORBIT_M7_FCM_PROJECT_ID=
ORBIT_M7_FCM_API_KEY=
ORBIT_M7_FCM_SENDER_ID=
ORBIT_M7_FCM_ANDROID_APP_ID=
ORBIT_M7_FCM_IOS_APP_ID=
ORBIT_M7_FCM_IOS_BUNDLE_ID=
ORBIT_M7_FCM_SERVICE_ACCOUNT_PATH=
```

After credentials and approvals are supplied, a controlled staging checklist must verify:
1. Migration `2026_10_25_000005_m7_future_fcm_delivery.php` and companion M7 migrations are applied.
2. `GET /api/v1/me/moments/push-bootstrap` returns disabled without both preferences or config.
3. Trusted-device FCM token registration, rotation, opt-out/unregister, expiry, revoked sessions and account switching.
4. Muted-Circle and blocked/expired/deleted Moment receives **no** new private data on the device.
5. Scheduled commands `orbit:moments:notifications` and `orbit:moments:push-send` run with no duplicate sends and validated FCM HTTP v1 acknowledgments.
6. Android and iOS native build, physical-device notification behavior and signed-off security review.

Do not enable Firebase from a mere CI green run or a missing service-account file. The features remain separately gated by `ORBIT_M7_MOMENTS_ENABLED` and privacy preferences.
