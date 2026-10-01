#!/usr/bin/env bash
# Verifies a release artifact before it is uploaded (TASK-FND-016).
#
#   scripts/release/verify.sh build/release/innsync-<version>-<commit>.zip
#
# Proves: checksum matches, no secrets or development files shipped, required
# runtime files present, and the application boots in production mode WITHOUT
# development dependencies.
set -euo pipefail

ZIP="${1:?usage: verify.sh <artifact.zip>}"
[[ -f "$ZIP" ]] || { echo "not found: $ZIP" >&2; exit 1; }
FAIL=0
fail() { echo "FAIL  $*" >&2; FAIL=1; }
ok() { echo "OK    $*"; }

if [[ -f "$ZIP.sha256" ]]; then
    ( cd "$(dirname "$ZIP")" && sha256sum -c "$(basename "$ZIP").sha256" >/dev/null ) && ok "artifact checksum matches" || fail "artifact checksum mismatch"
else
    fail "missing $ZIP.sha256"
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"
APP="$(find "$WORK" -mindepth 1 -maxdepth 1 -type d | head -n1)"
cd "$APP"

( sha256sum -c SHA256SUMS --quiet ) && ok "every file matches SHA256SUMS" || fail "file checksum mismatch inside the artifact"

for forbidden in .env tests docs node_modules .git .github scripts resources/js vendor/phpunit vendor/laravel/pint vendor/fakerphp vendor/mockery storage/logs/laravel.log; do
    [[ ! -e "$forbidden" ]] && ok "absent: $forbidden" || fail "must not be shipped: $forbidden"
done
if find . -path ./vendor -prune -o -name '.env*' ! -name '.env.example' -print | grep -q .; then fail "an environment file is shipped"; else ok "no environment file (only .env.example)"; fi
if find . -name .git | grep -q .; then fail "Git metadata is shipped"; else ok "no Git metadata anywhere (including vendor)"; fi
SIZE_MB=$(du -sm . | cut -f1)
[[ "$SIZE_MB" -le "${MAX_UNPACKED_MB:-150}" ]] && ok "unpacked size ${SIZE_MB} MB" || fail "unpacked size ${SIZE_MB} MB exceeds the ${MAX_UNPACKED_MB:-150} MB sanity bound; look for accidentally included files (Git history, node_modules, test suites)"
if grep -rIl --exclude-dir=vendor -E '^(APP_KEY|DB_PASSWORD|BACKUP_ENCRYPTION_KEY)=.+' . 2>/dev/null | grep -v '\.env\.example$' | grep -q .; then fail "a secret-shaped assignment is present"; else ok "no secret-shaped assignments outside vendor"; fi

for required in artisan vendor/autoload.php public/index.php public/build/manifest.json bootstrap/app.php bootstrap/cache storage/framework storage/logs RELEASE-MANIFEST.json lang/en/errors.php lang/id/errors.php; do
    [[ -e "$required" ]] && ok "present: $required" || fail "missing: $required"
done

# The artifact must boot in production mode with no dev dependencies installed.
export APP_ENV=production APP_DEBUG=false APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
php artisan --version >/dev/null && ok "application boots (production, no dev dependencies)" || fail "application does not boot"
php artisan list --raw | grep -q '^deploy:preflight' && ok "deploy:preflight is available" || fail "deploy:preflight missing"
php artisan config:cache >/dev/null && php artisan route:cache >/dev/null && ok "config and route cache build" || fail "config/route cache failed"
php artisan config:clear >/dev/null; php artisan route:clear >/dev/null

[[ $FAIL -eq 0 ]] && { echo; echo "Artifact verified."; } || { echo; echo "Artifact verification FAILED." >&2; exit 1; }
