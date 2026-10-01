#!/usr/bin/env bash
# Host-side release for the Git deployment on shared hosting (TASK-FND-016,
# docs/OPERATIONS/DEPLOYMENT-RUNBOOK.md). Runs inside the application directory,
# which is a Git clone of the `release` branch (prebuilt: production Composer
# dependencies and compiled assets are committed there, so the host needs
# neither Composer nor Node).
#
#   deploy/host-release.sh update [--ref <commit-or-tag>]
#   deploy/host-release.sh rollback <commit-or-tag>
#
# Environment (all optional):
#   PHP_BIN     PHP 8.3+ binary          (default: php)
#   REMOTE      Git remote               (default: origin)
#   BRANCH      release branch           (default: release)
#   SMOKE_URL   public https URL; when set, deploy:smoke runs after the release
#   SMOKE_ARGS  extra deploy:smoke options (e.g. --allow-insecure, rehearsals only)
#
# Safety: only commits reachable from the remote release branch can be deployed;
# a backup is taken before any code switch; preflight runs on the NEW code before
# migrating and the old code is restored if it fails; migrations are never
# reversed (forward-fix only); a failed migration leaves the site in maintenance
# mode for a person to decide.
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
REMOTE="${REMOTE:-origin}"
BRANCH="${BRANCH:-release}"
SMOKE_URL="${SMOKE_URL:-}"
SMOKE_ARGS="${SMOKE_ARGS:-}"

cd "$(dirname "$0")/.."

say() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
artisan() { "$PHP_BIN" artisan "$@"; }

require_clone() {
    [[ -d .git ]] || die "This directory is not a Git clone of the release branch."
    [[ -f .env ]] || die "No .env here. Create it from .env.example first (it is never part of a release)."
    if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        die "Tracked files were modified on the host. Refusing to overwrite them; inspect with 'git status'."
    fi
}

# Captures the list first: under pipefail, grep -q closing the pipe early would make a match look like a failure.
has_command() {
    local commands
    commands="$(artisan list --raw 2>/dev/null)" || return 1
    grep -q "^$1\b" <<<"$commands"
}

record() {
    mkdir -p storage/logs
    printf '%s %s %s -> %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" "$3" >> storage/logs/releases.log
}

rebuild_caches() {
    artisan config:cache
    artisan route:cache
    artisan view:cache
}

smoke() {
    if [[ -n "$SMOKE_URL" ]]; then
        say "Smoke test $SMOKE_URL"
        # shellcheck disable=SC2086 # SMOKE_ARGS is intentionally word-split
        artisan deploy:smoke "$SMOKE_URL" $SMOKE_ARGS
    else
        say "SMOKE_URL not set; run 'php artisan deploy:smoke https://your-domain' from anywhere."
    fi
}

resolve_release_commit() {
    # $1 = ref. Must exist and be reachable from the fetched remote release branch.
    local sha
    sha="$(git rev-parse --verify --quiet "$1^{commit}")" || die "Unknown release reference: $1"
    git merge-base --is-ancestor "$sha" FETCH_HEAD || die "$1 is not part of the $REMOTE/$BRANCH history; only published releases can be deployed."
    printf '%s' "$sha"
}

cmd_update() {
    local ref=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --ref) ref="${2:?--ref needs a value}"; shift 2 ;;
            *) die "Unknown option: $1" ;;
        esac
    done

    require_clone
    local previous target
    previous="$(git rev-parse HEAD)"

    say "Fetching $REMOTE/$BRANCH"
    git fetch --quiet --tags "$REMOTE" "$BRANCH"
    target="$(resolve_release_commit "${ref:-FETCH_HEAD}")"

    if [[ "$target" == "$previous" ]]; then
        say "Already on $(git rev-parse --short "$target"); nothing to do."
        return 0
    fi

    say "Releasing: $(git log -1 --format=%s "$target")"

    say "Maintenance mode on"
    artisan down --retry=60 || true

    if has_command backup:run; then
        say "Backup before changing anything"
        if ! artisan backup:run; then
            artisan up || true
            die "The backup failed, so nothing was changed. Fix the backup first (a release must not run migrations without a recoverable backup)."
        fi
    else
        say "The running version has no backup command (first release); make sure a backup exists elsewhere."
    fi

    say "Switching code to $(git rev-parse --short "$target")"
    git reset --hard --quiet "$target"

    say "Preflight on the new code"
    if ! artisan deploy:preflight; then
        git reset --hard --quiet "$previous"
        artisan up || true
        record update "$(git rev-parse --short "$target")" "REFUSED (preflight); restored $(git rev-parse --short "$previous")"
        die "Preflight failed. The previous code was restored and nothing was migrated."
    fi

    say "Migrating"
    if ! artisan migrate --force; then
        record update "$(git rev-parse --short "$target")" "MIGRATION FAILED; site left in maintenance mode"
        die "A migration failed. The site is left in MAINTENANCE MODE. Do not roll the code back blindly: the schema may be partly changed. Decide between a forward-fix and a verified restore (docs/OPERATIONS/DR-RUNBOOK.md)."
    fi

    say "Rebuilding caches"
    if ! rebuild_caches; then
        record update "$(git rev-parse --short "$target")" "CACHE REBUILD FAILED; site left in maintenance mode"
        die "Cache rebuild failed. The site is left in maintenance mode."
    fi

    say "Maintenance mode off"
    artisan up
    record update "$(git rev-parse --short "$previous")" "$(git rev-parse --short "$target")"

    if ! smoke; then
        printf 'ERROR: the smoke test FAILED. The site is up but not verified.\n' >&2
        printf '       Code rollback:  deploy/host-release.sh rollback %s\n' "$(git rev-parse --short "$previous")" >&2
        printf '       (Migrations are not reversed; see the runbook for forward-fix.)\n' >&2
        exit 1
    fi

    say "Release complete: $(git rev-parse --short "$target")"
}

cmd_rollback() {
    local ref="${1:?usage: host-release.sh rollback <commit-or-tag>}"

    require_clone
    git fetch --quiet --tags "$REMOTE" "$BRANCH"
    local target current
    target="$(resolve_release_commit "$ref")"
    current="$(git rev-parse HEAD)"

    if [[ "$target" == "$current" ]]; then
        say "Already on $(git rev-parse --short "$target"); nothing to do."
        return 0
    fi

    say "Rolling the CODE back to $(git rev-parse --short "$target"). The database is not changed."
    artisan down --retry=60 || true
    git reset --hard --quiet "$target"
    rebuild_caches || { record rollback "$(git rev-parse --short "$current")" "CACHE REBUILD FAILED; maintenance mode"; die "Cache rebuild failed. The site is left in maintenance mode."; }
    artisan up
    record rollback "$(git rev-parse --short "$current")" "$(git rev-parse --short "$target")"

    say "Code restored. If the newer release had migrations, the schema is still the newer one: older code must remain compatible with it (see the runbook), otherwise forward-fix."
    smoke
}

case "${1:-}" in
    update) shift; cmd_update "$@" ;;
    rollback) shift; cmd_rollback "$@" ;;
    *) die "usage: host-release.sh update [--ref <commit-or-tag>] | rollback <commit-or-tag>" ;;
esac
