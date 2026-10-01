#!/usr/bin/env bash
# Builds the production release artifact (TASK-FND-016, docs/ARCHITECTURE/15).
#
# The artifact holds Composer production dependencies and prebuilt Vite assets,
# so the shared host needs neither Composer nor Node (ADR-0008). It is built from
# the committed files only (git archive), so local edits and untracked files can
# never leak into a release, and it contains no environment file or secret.
#
#   scripts/release/build.sh            # writes build/release/innsync-<version>-<commit>.zip
#   OUT_DIR=/tmp/out scripts/release/build.sh
#
# Requires: git, php 8.3+, composer, node/npm, zip, sha256sum.
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
OUT_DIR="${OUT_DIR:-$ROOT/build/release}"

for tool in git php composer npm zip sha256sum; do
    command -v "$tool" >/dev/null || { echo "missing required tool: $tool" >&2; exit 1; }
done

if [[ -z "${ALLOW_DIRTY:-}" ]] && [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "The working tree has uncommitted changes. Commit them first (the artifact is built from HEAD)." >&2
    exit 1
fi

COMMIT="$(git rev-parse --short=12 HEAD)"
EPOCH="$(git log -1 --format=%ct)"
VERSION="$(sed -n 's/^APP_VERSION=//p' .env.example | head -n1)"
[[ -n "$VERSION" ]] || { echo "APP_VERSION is not defined in .env.example" >&2; exit 1; }

NAME="innsync-${VERSION}-${COMMIT}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
APP="$STAGE/$NAME"
mkdir -p "$APP" "$OUT_DIR"

echo "==> Exporting committed files of $COMMIT"
git archive HEAD | tar -x -C "$APP"

cd "$APP"

echo "==> Installing production Composer dependencies"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts

echo "==> Pruning repository metadata and test suites from vendor packages"
# A source install can leave full Git histories behind; neither they nor package tests are needed at runtime.
find vendor -type d \( -name .git -o -name .github \) -prune -exec rm -rf {} +
find vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name Tests \) -prune -exec rm -rf {} +

echo "==> Discovering packages (throwaway key, never stored)"
APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" APP_ENV=production php artisan package:discover --ansi

echo "==> Building frontend assets"
npm ci
npm run build

echo "==> Removing everything the host does not need at runtime"
rm -rf node_modules tests docs scripts .github resources/js resources/css \
    package.json package-lock.json vite.config.ts tsconfig.json components.json \
    phpunit.xml .editorconfig .gitattributes .npmrc .node-version AGENTS.md CLAUDE.md README.md
# Runtime directories are shipped empty; the host fills them.
find storage bootstrap/cache -type f ! -name '.gitignore' -delete

cat > RELEASE-MANIFEST.json <<JSON
{
  "name": "innsync",
  "version": "$VERSION",
  "commit": "$(git -C "$ROOT" rev-parse HEAD)",
  "committed_at_epoch": $EPOCH,
  "php": ">=8.3",
  "frontend": "prebuilt (public/build)",
  "notes": "Deploy per docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md. Contains no .env or secrets."
}
JSON

echo "==> Recording checksums"
find . -type f ! -name SHA256SUMS -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SHA256SUMS

echo "==> Packaging"
find . -exec touch -h -d "@$EPOCH" {} +
cd "$STAGE"
rm -f "$OUT_DIR/$NAME.zip"
zip -X -q -r "$OUT_DIR/$NAME.zip" "$NAME"
( cd "$OUT_DIR" && sha256sum "$NAME.zip" > "$NAME.zip.sha256" )

echo
echo "Artifact: $OUT_DIR/$NAME.zip"
cat "$OUT_DIR/$NAME.zip.sha256"
