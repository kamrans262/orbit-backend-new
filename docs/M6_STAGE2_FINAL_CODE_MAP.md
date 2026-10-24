# Orbit M6 — Stage 2 Backend Build Trace and Live Release Gate

Backend feature branch: feature/m6-media-backend-admin.
Flutter feature branch: feature/m6-media-flutter-integration.
**There are exactly two M6 branches.** This is not a production deployment, merge or active Firebase transport.

## Back-end implementation map
- Configuration and fail-closed private storage: config/orbit_media.php
- Additive MariaDB data model (nine tables): database/migrations/2026_10_24_000001_create_m6_media_storage.php
- R2/S3 multi-part signed ciphertext provider: app/Modules/Media/Services/MediaObjectStore.php
- Hostinger private shared-hosting ciphertext store: app/Modules/Media/Services/HostingerPrivateMediaStore.php
- Expired original and aborted/failed derivative recovery: app/Modules/Media/Http/Controllers/MediaController.php
- Server-side privacy/ACL enforcement: app/Modules/Media/Services/MediaPolicy.php
- REST routes and API operations: routes/api.php and app/Modules/Media/Http/Controllers/MediaController.php
- Verified ciphertext/retention worker: app/Modules/Media/Services/MediaLifecycleWorker.php and routes/console.php
- Blade Admin read-only encrypted storage metrics: app/Modules/Media/Http/Controllers/AdminMediaController.php and resources/views/orbit-admin/media.blade.php
- M3 erasure cross-module safeguards: app/Modules/Profile/Services/M3ErasureProcessor.php
- M6 feature and recovery tests: tests/Feature/OrbitM6MediaTest.php and tests/Feature/OrbitM6HostingerMediaTest.php and .github/workflows/orbit-m1-checks.yml
- Standard AWS SDK dependency: composer.json + composer.lock (locked by M6-only same-branch dependency resolution job).
- Engineering decisions and release gates: docs/M6_STAGE2_IMPLEMENTATION_AND_RELEASE_GATES.md

## Requirements evidence
The approved Stage 1 v1.1 audit contains 149 requirements, 20 resolved engineering decisions and 56 test scenarios. Stage 2 source implements the reusable backend and Admin storage domain. This document is **not** a claim that all 56 device/end-to-end scenarios passed: those must be recorded against the final Flutter integration, real R2 bucket and physical devices.

## High-priority verification before enabling ORBIT_MEDIA_ENABLED
1. Exact branch-head GitHub CI must pass MariaDB migration, PHP syntax, Laravel regression and M6 API tests. M3 account erasure must remain guarded.
2. Test Hostinger private storage in staging: disk quota, upload limits, authenticated 1 MiB chunk replay, on-disk hash verification, private downloads, scheduled cleanup and no public access. Test private R2 URLs and AWS SDK only if R2/S3 is selected.
3. Stage 3 mobile provides client-side AEAD media encryption, photo/video processing, device key wrapping, encrypted derivatives, a private-save action, and stateful resumable transfers. Verify actual devices, long-offline retry and user-visible flows; never claim backend ciphertext hashing proves clients encrypted correctly.
4. M7 Moments publishing, M13 message/voice composer, M16 SOS uploads and M23 advanced camera editing consumers require separate feature-level consent/action confirmation. M6 supplies their reusable storage integration, not fabricated downstream product behaviors.
5. Enforce controlled retention and lawful holds; verify that generated URLs cannot promise instantaneous revocation until TTL expires and that no already-downloaded ciphertext is remotely wiped.
6. Enable only after separate staging/production approval, Hostinger backups, actual storage credentials, and working scheduled lifecycle jobs. Keep Firebase disabled.

## Safe status semantics
Code present != backend CI green != real R2 verified != Flutter integrated != production released. Do not collapse these separate gates or mark Stage 2 fully released based solely on a source file.
