#!/usr/bin/env bash
#
# scripts/lane-test.sh — the ONE sanctioned way to run PHPUnit on this box.
#
# Six+ lanes share one MySQL instance. This script:
#   1. Takes the shared flock on /tmp/corex-lane-test.lock (same file every
#      lane's ad-hoc `flock ...` invocations already used — interoperates
#      with runs already in flight).
#   2. While waiting, prints who holds the lock and for how long.
#   3. Resolves this worktree's own TEST_DB_DATABASE (same precedence as
#      tests/bootstrap.php) and, if that schema doesn't exist in MySQL,
#      (re)creates it and bootstraps it from database/schema/mysql-schema.sql
#      before handing off to `php8.2 artisan test` — a lane whose schema was
#      dropped (disk clean-up, fresh worktree, etc.) self-heals instead of
#      hard-failing with "Unknown database".
#   4. Runs `php8.2 artisan test <args>`.
#   5. Releases the lock on exit, including on kill/timeout — the lock is
#      held via an open file descriptor (fd 200), so it is released by the
#      kernel the moment every process holding that fd (this script AND the
#      php/phpunit children it starts) is gone. No stale-lock bookkeeping
#      needed, and nothing here can leak a held lock past a kill -9.
#
# Usage:
#   scripts/lane-test.sh tests/Feature/Admin/RentalCatalogueUnitTest.php
#   scripts/lane-test.sh --filter=some_test_method tests/Feature/Foo.php
#   scripts/lane-test.sh --status
#
# Never calls artisan test directly, never deletes the lock file, never
# touches or kills a process belonging to another lane/worktree.

set -euo pipefail

LOCK_FILE=/tmp/corex-lane-test.lock
INFO_FILE=/tmp/corex-lane-test.lock.info
WHITELIST_RE='^hfc_dash_test(_[0-9]+)?$'
POLL_INTERVAL=5
PHP_BIN="${PHP_BIN:-php8.2}"

log() { echo "[lane-test] $*" >&2; }
die() { echo "[lane-test] $*" >&2; exit 1; }

print_status() {
    if [[ -f "$INFO_FILE" ]]; then
        local worktree pid started_epoch started_human cmd now elapsed
        worktree=$(sed -n '1p' "$INFO_FILE")
        pid=$(sed -n '2p' "$INFO_FILE")
        started_epoch=$(sed -n '3p' "$INFO_FILE")
        started_human=$(sed -n '4p' "$INFO_FILE")
        cmd=$(sed -n '5p' "$INFO_FILE")
        now=$(date +%s)
        elapsed=$(( now - started_epoch ))
        if kill -0 "$pid" 2>/dev/null; then
            echo "  holder worktree : $worktree"
            echo "  holder pid      : $pid"
            echo "  started         : $started_human  (${elapsed}s ago)"
            echo "  command         : $cmd"
        else
            echo "  (info file present but holder pid $pid is gone — lock is actually free; stale info file from a non-graceful exit)"
        fi
    else
        echo "  (no holder info recorded — lock is free, or held by a run from before this script existed)"
    fi
}

if [[ "${1:-}" == "--status" ]]; then
    exec 200>"$LOCK_FILE"
    if flock -n 200; then
        echo "[lane-test] lock is FREE right now."
        flock -u 200
        exit 0
    fi
    echo "[lane-test] lock is HELD:"
    print_status
    echo "[lane-test] other processes waiting on/holding this lock file (best-effort, via fuser):"
    fuser -v "$LOCK_FILE" 2>&1 | sed 's/^/  /' || true
    exit 0
fi

[[ $# -ge 1 ]] || die "usage: $0 <artisan test args...>  |  $0 --status"

WORKTREE="$(pwd)"
[[ -f "$WORKTREE/artisan" ]] || die "run this from a Laravel worktree root (no ./artisan found in $WORKTREE)"

# --- Resolve TEST_DB_DATABASE with the SAME precedence as tests/bootstrap.php ---
DB="${TEST_DB_DATABASE:-}"
if [[ -z "$DB" && -f "$WORKTREE/.env" ]]; then
    DB=$(grep -E '^[[:space:]]*TEST_DB_DATABASE[[:space:]]*=' "$WORKTREE/.env" | tail -1 \
        | sed -E 's/^[^=]*=[[:space:]]*//' | sed -E 's/^["'"'"']|["'"'"']$//g')
fi
DB="${DB:-hfc_dash_test}"
[[ "$DB" =~ $WHITELIST_RE ]] || die "TEST_DB_DATABASE resolved to '$DB', not in the allowed hfc_dash_test(_N) whitelist. Refusing."

# --- Read DB credentials from .env without ever printing them ---
read_db_creds() {
    "$PHP_BIN" -r '
        require "vendor/autoload.php";
        $d = Dotenv\Dotenv::createImmutable(getcwd());
        $d->safeLoad();
        fwrite(STDOUT, ($_ENV["DB_HOST"] ?? "127.0.0.1")."\t".($_ENV["DB_PORT"] ?? "3306")."\t".($_ENV["DB_USERNAME"] ?? "")."\t".($_ENV["DB_PASSWORD"] ?? "")."\n");
    '
}
IFS=$'\t' read -r DB_HOST DB_PORT DB_USER DB_PASS < <(read_db_creds)

mysql_q() {
    MYSQL_PWD="$DB_PASS" mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"
}

ensure_schema() {
    local count
    count=$(mysql_q -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '${DB}'" 2>/dev/null || echo 0)
    if [[ "$count" != "1" ]]; then
        log "schema '$DB' not found (dropped, or first run on this worktree) — (re)creating and bootstrapping from database/schema/mysql-schema.sql"
        mysql_q -e "CREATE DATABASE IF NOT EXISTS \`${DB}\`"
        if [[ -f "$WORKTREE/database/schema/mysql-schema.sql" ]]; then
            MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB" < "$WORKTREE/database/schema/mysql-schema.sql"
            log "bootstrapped '$DB' from snapshot"
        else
            log "WARNING: database/schema/mysql-schema.sql not found — artisan test will fall back to a full migration replay for this run"
        fi
    fi
}

# --- Acquire the shared lock (poll so we can print who holds it meanwhile) ---
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    log "lock held by another lane — waiting (checking every ${POLL_INTERVAL}s)..."
    while ! flock -n 200; do
        print_status >&2
        sleep "$POLL_INTERVAL"
    done
    log "lock acquired."
fi

{
    echo "$WORKTREE"
    echo "$$"
    echo "$(date +%s)"
    echo "$(date -Is)"
    echo "php8.2 artisan test $*"
} > "$INFO_FILE"

CHILD_PID=""
cleanup() {
    if [[ -n "$CHILD_PID" ]] && kill -0 "$CHILD_PID" 2>/dev/null; then
        kill -TERM "$CHILD_PID" 2>/dev/null || true
    fi
}
trap cleanup INT TERM

ensure_schema

log "running: php8.2 artisan test $* (schema: $DB)"
"$PHP_BIN" artisan test "$@" &
CHILD_PID=$!
wait "$CHILD_PID"
EXIT_CODE=$?

exit "$EXIT_CODE"
