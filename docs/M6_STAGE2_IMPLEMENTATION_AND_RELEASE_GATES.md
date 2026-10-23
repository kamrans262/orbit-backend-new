# M6 Stage 2 — implementation and release verification

Source baseline: Orbit M6 Stage 1 approved decisions v1.1 (149 requirements; 20 decisions; 56 acceptance scenarios) and engineering authored versioned media API. **No code or production deployment other than this existing feature branch.**

## Implemented code
- Seven additive media tables: objects, upload sessions, ciphertext variants, explicit audience grants, wrapped per-device key envelopes, deletion jobs, and event outbox.
- Private authenticated API: multipart initiation/status/parts/complete/abort, media metadata, short-lived ciphertext read, device envelope access, per-user grant/revocation/deletion and encrypted variant upload initiation.
- No public object key/secret in JSON; no server-side image/video decryption or thumbnail processing.
- Privacy gate consults live trusted session, M4 friend/block graph and M5 Circle consent. SOS reads fail closed until M16 approved responder consumer.
- R2/S3 multipart service and scheduled verification/expiry/purge worker; no Firebase dependency. Checksum verified over entire stored ciphertext stream before ready status.
- Blade Admin: permission-gated read-only aggregate counts and technical UUID lookup, no original media/private names/recipient list/access URL or key envelopes.
- M3 erasure integrator blocks account purge until M6 owned ciphertext is physically deleted.

## Requirements that cannot be certified without real-world evidence
- The SDK dependency is installed via an M6-branch-only GitHub Actions lock generation workflow. Confirm successful lock commit and repeat backend CI against its exact SHA.
- Real R2 private bucket, CORS, credential provision, multipart SHA-256 compatibility, ACL revocation, upload size bounds and network failures must be verified on staging before enabling the feature. Default ORBIT_MEDIA_ENABLED=false.
- Flutter client-side codec stripping/crypto/key wrapping/multipart replay and Android/iOS device-specific tests are Stage 3; no false completion.
- M7 Moments, M13 Messaging, M16 SOS and M23 Camera consumers are independently gated modules. Stage 2 provides the shared media contract, not fabricated working publishing/chat/SOS.
- No automatic Firebase activation or production changes. Device signatures, prod secrets and Hostinger deployments remain separately approved release gates.

## Checks
Run php artisan migrate --pretend and php artisan test --filter=OrbitM6MediaTest in staging, followed by php artisan test and 56 case tracker once Flutter is connected. Run php artisan orbit:media:process only on disposable staging until storage data retention is certified.
