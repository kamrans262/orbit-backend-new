#!/usr/bin/env bash
set -euo pipefail

# Guarded: destructive migration validation may NEVER target hosting or staging.
if [[ "$(printenv CI || true)" != "true" || "$(printenv APP_ENV || true)" != "testing" ||
      "$(printenv DB_DATABASE || true)" != "orbit_ci" ]]; then
  echo "STOP: M14 migration test requires CI=true APP_ENV=testing DB_DATABASE=orbit_ci" >&2
  exit 2
fi
if [[ "$(printenv DB_HOST || true)" != "127.0.0.1" ]]; then
  echo "STOP: Only local disposable MariaDB is accepted." >&2
  exit 2
fi

echo "[M14] Initialize disposable database from migrations."
php artisan migrate:fresh --force --no-interaction
php scripts/m14_assert_migration_schema.php installed

echo "[M14] Roll back only the five newest additive M14 migrations."
php artisan migrate:rollback --step=5 --force --no-interaction
php scripts/m14_assert_migration_schema.php rolledback

echo "[M14] Reapply M14 without modifying legacy schema."
php artisan migrate --force --no-interaction
php scripts/m14_assert_migration_schema.php installed
echo "[M14] Isolated rollback/reapply schema check passed."
