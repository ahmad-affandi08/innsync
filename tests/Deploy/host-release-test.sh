#!/usr/bin/env bash
# Tests deploy/host-release.sh without PHP, MySQL or a network: a fake `php`
# records every artisan call, and Git repositories on disk stand in for the
# remote `release` branch and the shared host.
set -uo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT
FAILS=0
PASSES=0

export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
LOG="$ROOT/artisan.log"
export LOG

cat > "$ROOT/fakephp" <<'PHP'
#!/usr/bin/env bash
# usage: fakephp artisan <command> [...]
shift
echo "$*" >> "$LOG"
case "$1" in
    list) [[ -n "${NO_BACKUP_CMD:-}" ]] && echo "migrate" || printf 'backup:run\nmigrate\ndeploy:preflight\n' ;;
esac
if [[ -n "${FAIL_ON:-}" && "$1" == "$FAIL_ON" ]]; then exit 1; fi
exit 0
PHP
chmod +x "$ROOT/fakephp"

ok() { PASSES=$((PASSES + 1)); printf 'PASS  %s\n' "$1"; }
bad() { FAILS=$((FAILS + 1)); printf 'FAIL  %s\n' "$1" >&2; }
check() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }

# ---- remote + publisher -------------------------------------------------
git init -q --bare -b release "$ROOT/origin.git"
git clone -q "$ROOT/origin.git" "$ROOT/pub" 2>/dev/null
publish() { # $1 = version label
    mkdir -p "$ROOT/pub/deploy"
    cp "$REPO/deploy/host-release.sh" "$ROOT/pub/deploy/host-release.sh"
    printf '%s\n' "$1" > "$ROOT/pub/VERSION"
    : > "$ROOT/pub/artisan"
    git -C "$ROOT/pub" checkout -q -B release
    git -C "$ROOT/pub" add -A
    git -C "$ROOT/pub" commit -q -m "Release $1"
    git -C "$ROOT/pub" push -q "$ROOT/origin.git" release
    git -C "$ROOT/pub" rev-parse HEAD
}
R1="$(publish 1.0.0)"
R2="$(publish 1.1.0)"
R3="$(publish 1.2.0)"

new_host() { # $1 = commit the host currently runs
    rm -rf "$ROOT/host"
    git clone -q -b release "$ROOT/origin.git" "$ROOT/host" 2>/dev/null
    git -C "$ROOT/host" reset -q --hard "$1"
    : > "$ROOT/host/.env"
    : > "$LOG"
    unset FAIL_ON NO_BACKUP_CMD SMOKE_URL SMOKE_ARGS
}
host() { # run the host script, capture output/exit
    OUT="$(cd "$ROOT/host" && PHP_BIN="$ROOT/fakephp" REMOTE=origin BRANCH=release deploy/host-release.sh "$@" 2>&1)"
    RC=$?
}
calls() { grep -v '^list' "$LOG" | awk '{print $1}' | paste -sd' ' -; }
head_of() { git -C "$ROOT/host" rev-parse HEAD; }

# ---- 1. normal update ---------------------------------------------------
new_host "$R1"; host update
check "update succeeds" "[[ $RC -eq 0 ]]"
check "update lands on the newest release" "[[ \$(head_of) == $R3 ]]"
check "order: down, backup, preflight, migrate, caches, up" "[[ \"\$(calls)\" == 'down backup:run deploy:preflight migrate config:cache route:cache view:cache up' ]]"
check "migrate runs with --force" "grep -q '^migrate --force' $LOG"
check "the release is recorded in storage/logs/releases.log" "grep -q ' -> ' $ROOT/host/storage/logs/releases.log"

# ---- 2. already current -------------------------------------------------
: > "$LOG"; host update
check "up-to-date is a no-op" "[[ $RC -eq 0 && ! -s $LOG ]]"

# ---- 3. smoke ------------------------------------------------------------
new_host "$R1"; export SMOKE_URL=https://hotel.example; host update
check "smoke runs after the release when SMOKE_URL is set" "grep -q '^deploy:smoke https://hotel.example' $LOG && [[ $RC -eq 0 ]]"
new_host "$R1"; export SMOKE_URL=http://127.0.0.1:8124 SMOKE_ARGS=--allow-insecure; host update
check "SMOKE_ARGS are passed to deploy:smoke" "grep -q '^deploy:smoke http://127.0.0.1:8124 --allow-insecure' $LOG"
new_host "$R1"; export SMOKE_URL=https://hotel.example FAIL_ON=deploy:smoke; host update
check "smoke failure exits non-zero, keeps the site up, and names the rollback command" "[[ $RC -ne 0 ]] && [[ \"\$(calls)\" == *' up deploy:smoke' ]] && grep -q 'rollback' <<<\"\$OUT\""

# ---- 4. preflight failure restores the old code ---------------------------
new_host "$R1"; export FAIL_ON=deploy:preflight; host update
check "preflight failure refuses the release" "[[ $RC -ne 0 ]]"
check "preflight failure restores the previous code" "[[ \$(head_of) == $R1 ]]"
check "preflight failure never migrates and brings the site back up" "[[ \"\$(calls)\" == 'down backup:run deploy:preflight up' ]]"

# ---- 5. migration failure leaves maintenance mode ---------------------------
new_host "$R1"; export FAIL_ON=migrate; host update
check "migration failure exits non-zero" "[[ $RC -ne 0 ]]"
check "migration failure leaves the site in maintenance mode" "[[ \"\$(calls)\" != *' up' ]] && grep -q 'MAINTENANCE' <<<\"\$OUT\""
check "migration failure does not roll the code back by itself" "[[ \$(head_of) == $R3 ]]"

# ---- 6. backup failure changes nothing --------------------------------------
new_host "$R1"; export FAIL_ON=backup:run; host update
check "backup failure aborts before any code change" "[[ $RC -ne 0 && \$(head_of) == $R1 ]]"
check "backup failure runs no preflight or migration, and re-opens the site" "[[ \"\$(calls)\" == 'down backup:run up' ]]"

# ---- 7. first release: running version has no backup command ---------------------
new_host "$R1"; export NO_BACKUP_CMD=1; host update
check "a version without backup:run still releases, with a warning" "[[ $RC -eq 0 ]] && ! grep -q '^backup:run' $LOG && grep -q 'no backup command' <<<\"\$OUT\""

# ---- 8. refusals -----------------------------------------------------------------
new_host "$R1"; echo "local edit" >> "$ROOT/host/VERSION"; host update
check "modified tracked files are never overwritten" "[[ $RC -ne 0 && ! -s $LOG ]] && grep -q 'modified' <<<\"\$OUT\""

new_host "$R1"; rm "$ROOT/host/.env"; host update
check "a missing .env refuses the release" "[[ $RC -ne 0 && ! -s $LOG ]]"

new_host "$R1"
git -C "$ROOT/host" checkout -q -b rogue; echo x > "$ROOT/host/rogue.txt"; git -C "$ROOT/host" add rogue.txt; git -C "$ROOT/host" commit -q -m "rogue"
ROGUE="$(git -C "$ROOT/host" rev-parse HEAD)"; git -C "$ROOT/host" checkout -q --detach "$R1"; : > "$LOG"
host update --ref "$ROGUE"
check "a commit outside the published release history is refused" "[[ $RC -ne 0 && ! -s $LOG ]] && grep -q 'not part of' <<<\"\$OUT\""
host update --ref does-not-exist
check "an unknown reference is refused" "[[ $RC -ne 0 ]]"
host update --bogus
check "an unknown option is refused" "[[ $RC -ne 0 ]]"

# ---- 9. update to a specific release ----------------------------------------------
new_host "$R1"; host update --ref "$R2"
check "update --ref deploys exactly that published release" "[[ $RC -eq 0 && \$(head_of) == $R2 ]]"

# ---- 10. rollback ------------------------------------------------------------------
new_host "$R3"; host rollback "$R2"
check "rollback restores the older code" "[[ $RC -eq 0 && \$(head_of) == $R2 ]]"
check "rollback rebuilds caches, does not migrate or back up" "[[ \"\$(calls)\" == 'down config:cache route:cache view:cache up' ]]"
check "rollback explains that the database is not reversed" "grep -q 'database is not changed' <<<\"\$OUT\""
host rollback "$R2"
check "rolling back to the current release is a no-op" "[[ $RC -eq 0 ]]"
new_host "$R3"; host rollback "$ROGUE"
check "rollback also refuses unpublished commits" "[[ $RC -ne 0 ]]"
host rollback
check "rollback needs an argument" "[[ $RC -ne 0 ]]"

echo
echo "$PASSES passed, $FAILS failed"
[[ $FAILS -eq 0 ]]
