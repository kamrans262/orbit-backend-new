# M5 Firebase Realtime Database — prepared but DISABLED

This file configures **only metadata invalidations**, never direct location, Moments, messaging, presence or SOS. It is not a Firebase credential, and does not activate the integration.

## Professional policy choices (approved to implement, not to activate)

- Always authenticate Orbit users with the existing trusted Laravel session. Laravel issues a **short-lived (15-minute)** Firebase custom-token exchange; Firebase identity uses the Orbit *session UUID* (not a permanent shared user UID). The custom token does not itself grant Circle membership.
- Firestore/Realtime DB data is **not an authorization or content source**. Only `/orbit_m5/revisions/<circle_uuid>` is client-readable, and only if the authenticated session's own entry exists in `/orbit_m5/acl/<circle_uuid>/<session_uuid>`. Clients cannot read ACLs, lists of Circles, content, or write any data. The server is the only ACL publisher.
- A backend transaction records a monotonic Circle revision + a non-sensitive outbox event. A server worker reconstructs current trusted, unrevoked device sessions from MariaDB, validates current membership/block/lifecycle via `CircleAccessPolicy`, then publishes ACL and revision **atomically** to Firebase RTDB. On publisher error, outbox remains pending; clients retain the 25-second server-authoritative foreground refresh fallback.
- Data in RTDB is limited to Circle UUID + revision number, session UUID + device UUID. No invitation code, name, membership roster, location, user preferences, content, or FCM token is present.
- **It remains DISABLED until client credentials/config and two-device security tests are provided.** It is not valid to claim instant revocation until a removed member's live RTDB listener reliably receives `permission_denied` on real Android/iOS devices, and Laravel GET/subscribe after removal also denies.

## Flags (all OFF by default)

```dotenv
ORBIT_M5_CIRCLES_ENABLED=false
ORBIT_M5_FIREBASE_ENABLED=false
ORBIT_M5_FIREBASE_SERVICE_ACCOUNT_PATH=
ORBIT_M5_FIREBASE_PROJECT_ID=
ORBIT_M5_FIREBASE_DATABASE_URL=
ORBIT_M5_FIREBASE_API_KEY=
ORBIT_M5_FIREBASE_APP_ID=
ORBIT_M5_FIREBASE_SENDER_ID=
```

The service-account file must be placed **outside** public_html and repository, readable only by the PHP account. Never commit `google-services.json`, Apple `GoogleService-Info.plist`, service-account keys or signing secrets to version control. Firebase client IDs/URLs are public identifiers; the service account private key is not.

**Activation checklist:** Firebase Auth custom token sign-in enabled; correct Android/iOS Firebase app registration; RTDB created in a selected region; deploy `firebase/database.rules.json` to the **matching** project; use dedicated least-privileged service-account IAM; supply verified environment values; install and test signed app; confirm restricted/untrusted/blocked/deleted/expired/left members cannot read revisions; test old-session and device-revocation cases, offline/reconnect and duplicate events. Configure one-minute Laravel scheduler only after rollout. An hourly cron does not satisfy the realtime goal.

**Caveat:** A polling outbox worker can take up to a minute plus network latency. This is a prepared safe fallback, **not proven instant**; when client Firebase is available, add and test immediate post-commit dispatch on all authorization-changing paths or equivalent verified event infrastructure. Never treat Firebase notifications as proof of content authorization. Native SDK may retain non-sensitive cached revisions offline; only Laravel can unlock protected data.

Sources: https://firebase.google.com/docs/auth/admin/create-custom-tokens and https://firebase.google.com/docs/database/rest/auth and https://firebase.google.com/docs/database/security.
