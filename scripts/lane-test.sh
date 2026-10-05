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
#      tests/bootstrap.php) and keeps it PERSISTENT — never dropped at the
#      end of a run. A fingerprint (hash of database/schema/mysql-schema.sql
#      + every migration file's own content) is stored in a marker row
#      inside that schema:
#        - schema missing entirely  -> create it, import the snapshot dump
#          (the one-time expensive path — this IS the 277s cost, paid once
#          per lane, not per run), then store the fingerprint.
#        - schema exists, fingerprint UNCHANGED since last run -> nothing to
#          do. Tell the test process (LANE_TEST_SCHEMA_READY=1) that the
#          schema is already current, so it skips RefreshDatabase's own
#          migrate:fresh (see tests/bootstrap.php) instead of silently
#          paying the same cost again inside the PHP process.
#        - schema exists, fingerprint CHANGED (new/edited migrations landed)
#          -> a plain `artisan migrate --force` against THIS schema only
#          (never migrate:fresh — it only applies what's actually new, per
#          Standard −1h's own documented mitigation), then re-store the
#          fingerprint. Cheap when the delta is small.
#      A lane whose schema was dropped (disk clean-up, fresh worktree, etc.)
#      self-heals instead of hard-failing with "Unknown database".
#   4. Runs `php8.2 artisan test <args>`.
#   5. Releases the lock on exit, including on kill/timeout — the lock is
#      held via an open file descriptor (fd 200), so it is released by the
#      kernel the moment every process holding that fd (this script AND the
#      php/phpunit children it starts) is gone. No stale-lock bookkeeping
#      needed, and nothing here can leak a held lock past a kill -9.
#
# TRADE-OFF, stated plainly: tests now run via a real transaction against a
# PERSISTENT schema instead of a full wipe-and-reload every invocation (see
# tests/bootstrap.php). Any test method whose own data stays inside Laravel's
# wrapping transaction is unaffected — it's created, asserted, rolled back,
# same as always. A test that ESCAPES that transaction (raw DDL, an explicit
# commit, multi-connection work) can now leave residue for the NEXT run,
# where migrate:fresh would previously have wiped it away unconditionally.
# `--fresh` below is the recovery valve for exactly that.
#
# Usage:
#   scripts/lane-test.sh tests/Feature/Admin/RentalCatalogueUnitTest.php
#   scripts/lane-test.sh --filter=some_test_method tests/Feature/Foo.php
#   scripts/lane-test.sh --fresh tests/Feature/Foo.php   # force a full rebuild
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
FINGERPRINT_TABLE='_corex_lane_test_fingerprint'

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
            echo "  (stale info file from worktree $worktree, pid $pid — that process is gone, but the"
            echo "   lock is currently held by something else that didn't go through this script)"
        fi
    else
        echo "  (no holder info recorded — held by a run from before this script existed, or not via lane-test.sh)"
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

[[ $# -ge 1 ]] || die "usage: $0 <artisan test args...>  |  $0 --fresh <args...>  |  $0 --status"

FORCE_FRESH=0
if [[ "${1:-}" == "--fresh" ]]; then
    FORCE_FRESH=1
    shift
fi
[[ $# -ge 1 ]] || die "usage: $0 <artisan test args...>  |  $0 --fresh <args...>  |  $0 --status"

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

# --- Read DB credentials, without ever printing them ---
#
# Prefer the dedicated tests-only MySQL instance (/root/LANETEST-MYSQL.md,
# 2026-10-05) over the worktree's own .env, so test runs on THIS box never
# touch the shared instance that also serves live/Staging/QA1/QA2/demo.
# Falls back to the worktree's .env when that file doesn't exist (any other
# machine -- a developer's own local MySQL, Windows/Laragon included).
LANETEST_CREDS=/root/.lanetest-mysql-credentials
read_db_creds() {
    if [[ -f "$LANETEST_CREDS" ]]; then
        local user pass
        user=$(grep -E '^LANETEST_DB_USER=' "$LANETEST_CREDS" | tail -1 | cut -d= -f2-)
        pass=$(grep -E '^LANETEST_DB_PASSWORD=' "$LANETEST_CREDS" | tail -1 | cut -d= -f2-)
        printf '127.0.0.1\t3317\t%s\t%s\n' "$user" "$pass"
        return
    fi
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

# `artisan migrate` boots the full app and reads DB_HOST/PORT/USERNAME/
# PASSWORD/SOCKET from the worktree's own .env + process env -- it never
# goes through tests/bootstrap.php (PHPUnit-only). Must override ALL FIVE
# here, not just DB_DATABASE, or this would silently migrate against
# whatever the worktree's real .env points at instead of the resolved
# $DB_HOST/$DB_PORT/$DB_USER/$DB_PASS this script is actually using.
#
# DB_SOCKET="" is NOT optional (2026-10-05, cc2 bug report): Laravel's
# mysql connection config is 'unix_socket' => env('DB_SOCKET', ''), and a
# non-empty unix_socket wins over host/port entirely in the PDO connector.
# A worktree whose .env sets DB_SOCKET (pointing at the SHARED instance's
# socket) silently migrated there as the 'lanetest' user, who doesn't
# exist on that instance -- "Access denied", not a hostname problem.
run_migrate() {
    DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USERNAME="$DB_USER" DB_PASSWORD="$DB_PASS" DB_DATABASE="$DB" DB_SOCKET="" \
        "$PHP_BIN" artisan migrate --force 2>&1 | sed 's/^/  /' >&2
}

# Hash of the committed schema dump + every migration file's own content,
# order-independent (files sorted by name first). Changes iff the dump was
# regenerated OR any migration file was added/edited/removed.
compute_fingerprint() {
    {
        [[ -f "$WORKTREE/database/schema/mysql-schema.sql" ]] && sha256sum "$WORKTREE/database/schema/mysql-schema.sql"
        find "$WORKTREE/database/migrations" -maxdepth 1 -type f -name '*.php' -print0 \
            | sort -z | xargs -0 -r sha256sum
    } | sha256sum | awk '{print $1}'
}

stored_fingerprint() {
    mysql_q -e "SELECT fingerprint FROM \`${DB}\`.${FINGERPRINT_TABLE} WHERE id = 1" 2>/dev/null || true
}

store_fingerprint() {
    local fp="$1"
    mysql_q -e "
        CREATE TABLE IF NOT EXISTS \`${DB}\`.${FINGERPRINT_TABLE} (
            id TINYINT PRIMARY KEY,
            fingerprint CHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL
        );
        INSERT INTO \`${DB}\`.${FINGERPRINT_TABLE} (id, fingerprint, updated_at)
        VALUES (1, '${fp}', NOW())
        ON DUPLICATE KEY UPDATE fingerprint = '${fp}', updated_at = NOW();
    "
}

# Sets SCHEMA_READY=1 when the test process may skip RefreshDatabase's own
# migrate:fresh (see tests/bootstrap.php) because this function has already
# made sure the persistent schema matches the current dump + migrations.
SCHEMA_READY=0

ensure_schema() {
    local count current_fp existing_fp
    count=$(mysql_q -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '${DB}'" 2>/dev/null || echo 0)

    if [[ "$count" != "1" ]]; then
        log "schema '$DB' not found (dropped, or first run on this worktree) — (re)creating and bootstrapping from database/schema/mysql-schema.sql (one-time cost)"
        mysql_q -e "CREATE DATABASE IF NOT EXISTS \`${DB}\`"
        if [[ -f "$WORKTREE/database/schema/mysql-schema.sql" ]]; then
            MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB" < "$WORKTREE/database/schema/mysql-schema.sql"
            log "bootstrapped '$DB' from snapshot — applying any migration newer than the dump"
            run_migrate
            current_fp=$(compute_fingerprint)
            store_fingerprint "$current_fp"
            SCHEMA_READY=1
        else
            log "WARNING: database/schema/mysql-schema.sql not found — letting artisan test's own RefreshDatabase do a full migration replay for this run"
            SCHEMA_READY=0
        fi
        return
    fi

    if [[ "$FORCE_FRESH" == "1" ]]; then
        log "--fresh requested — dropping and rebuilding '$DB' from the snapshot"
        mysql_q -e "DROP DATABASE \`${DB}\`; CREATE DATABASE \`${DB}\`"
        if [[ -f "$WORKTREE/database/schema/mysql-schema.sql" ]]; then
            MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB" < "$WORKTREE/database/schema/mysql-schema.sql"
        fi
        run_migrate
        current_fp=$(compute_fingerprint)
        store_fingerprint "$current_fp"
        SCHEMA_READY=1
        return
    fi

    current_fp=$(compute_fingerprint)
    existing_fp=$(stored_fingerprint)

    if [[ -n "$existing_fp" && "$existing_fp" == "$current_fp" ]]; then
        log "schema '$DB' already current (fingerprint match) — skipping rebuild"
        SCHEMA_READY=1
        return
    fi

    log "schema '$DB' fingerprint changed (or never recorded) — running a plain migrate for the delta, not a full rebuild"
    run_migrate
    store_fingerprint "$current_fp"
    SCHEMA_READY=1
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

SCHEMA_SETUP_START=$(date +%s)
ensure_schema
SCHEMA_SETUP_SECS=$(( $(date +%s) - SCHEMA_SETUP_START ))
log "schema setup took ${SCHEMA_SETUP_SECS}s (ready=${SCHEMA_READY})"

log "running: php8.2 artisan test $* (schema: $DB)"
LANE_TEST_SCHEMA_READY="$SCHEMA_READY" "$PHP_BIN" artisan test "$@" &
CHILD_PID=$!
wait "$CHILD_PID"
EXIT_CODE=$?

exit "$EXIT_CODE"
