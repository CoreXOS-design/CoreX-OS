#!/usr/bin/env bash
#
# scripts/lane-test.sh — the ONE sanctioned way to run PHPUnit on this box.
#
# Six+ lanes share one tests-only MySQL instance. This script (2026-10-06
# concurrency model — see "LOCKS" below; before that ONE global lock was held
# for the whole run, so a single cold schema bootstrap queued every lane):
#   1. Takes THREE kinds of flock, in this order: a per-schema lock (held for
#      the whole run), one of MAX_SLOTS run slots (held for the whole run), and
#      the global setup lock /tmp/corex-lane-test.lock (held ONLY while the
#      schema is created/migrated, then released before PHPUnit starts).
#   2. While waiting, prints who holds what and for how long.
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
#   5. Releases every lock on exit, including on kill/timeout — each lock is
#      held via an open file descriptor (200 setup, 201 schema, 202 slot), so
#      it is released by the kernel the moment every process holding that fd
#      (this script AND the php/phpunit children it starts) is gone. The
#      *.info files are only labels for --status; whether a lock is held is
#      always decided by flock itself, never by an info file, so a killed run
#      can never leave a stale "held" state behind.
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
# LOCKS (all under /tmp, all flock on an open fd — nothing to clean up by hand):
#   schema  /tmp/corex-lane-test.schema.<DB>.lock  fd 201  whole run. Two
#           worktrees that resolve to the SAME schema name queue behind each
#           other instead of corrupting each other's data.
#   slot    /tmp/corex-lane-test.slot.<N>.lock     fd 202  whole run. At most
#           MAX_SLOTS (top of this file) runs execute at once; the next one
#           waits and prints which slots are held, by whom, for how long.
#   setup   /tmp/corex-lane-test.lock              fd 200  schema bootstrap /
#           migrate ONLY (the 200s+ cold path). Serialised on purpose: several
#           concurrent snapshot loads thrash the tests instance's small redo
#           log. Same file the old single lock used, so a run started by an
#           OLDER copy of this script (which held it for its whole run) still
#           makes new runs wait during setup — they interoperate.
#   Order is always schema -> slot -> setup, and a holder of any later lock
#   never waits for an earlier one, so the locks cannot deadlock.
#
# SCHEMA NAME: TEST_DB_DATABASE from the shell, else the worktree's .env, else
# a name DERIVED FROM THE WORKTREE PATH (hfc_dash_test_<cksum of the path>) —
# never the bare shared `hfc_dash_test`. The name is printed and exported as
# TEST_DB_DATABASE so tests/bootstrap.php uses exactly the schema chosen here.
#
# Usage:
#   scripts/lane-test.sh tests/Feature/Admin/RentalCatalogueUnitTest.php
#   scripts/lane-test.sh --filter=some_test_method tests/Feature/Foo.php
#   scripts/lane-test.sh --fresh tests/Feature/Foo.php   # force a full rebuild
#   scripts/lane-test.sh --status        # setup lock, every slot, every busy schema
#
# Never calls artisan test directly, never deletes the lock file, never
# touches or kills a process belonging to another lane/worktree.

set -euo pipefail

# Max concurrent test runs. Raise to 3 here later (or `LANE_TEST_MAX_SLOTS=3`
# for one run) once memory allows -- the box runs with swap full, so start low.
MAX_SLOTS="${LANE_TEST_MAX_SLOTS:-2}"

LOCK_FILE=/tmp/corex-lane-test.lock            # SETUP lock (schema bootstrap/migrate only)
INFO_FILE=/tmp/corex-lane-test.lock.info
SLOT_PREFIX=/tmp/corex-lane-test.slot          # .<N>.lock / .<N>.info
SCHEMA_PREFIX=/tmp/corex-lane-test.schema      # .<DB>.lock / .<DB>.info
WHITELIST_RE='^hfc_dash_test(_[0-9]+)?$'
POLL_INTERVAL=5
PHP_BIN="${PHP_BIN:-php8.2}"
FINGERPRINT_TABLE='_corex_lane_test_fingerprint'

log() { echo "[lane-test] $*" >&2; }
die() { echo "[lane-test] $*" >&2; exit 1; }

# Is this lock file currently held by anyone? (read-only open: never truncates,
# and the probe lock is dropped the instant the subshell exits.)
lock_held() { [[ -e "$1" ]] && ! ( flock -n 9 ) 9<"$1" 2>/dev/null; }

# Describe the holder recorded in an info file ("worktree / pid / started / cmd").
describe_holder() {
    local info="$1" lock="$2" worktree pid started_epoch started_human cmd now
    if [[ -f "$info" ]]; then
        worktree=$(sed -n '1p' "$info"); pid=$(sed -n '2p' "$info")
        started_epoch=$(sed -n '3p' "$info"); started_human=$(sed -n '4p' "$info")
        cmd=$(sed -n '5p' "$info"); now=$(date +%s)
        echo "      worktree : $worktree"
        echo "      pid      : $pid$(kill -0 "$pid" 2>/dev/null || echo '  (wrapper gone -- a child process still holds the lock)')"
        echo "      started  : $started_human  ($(( now - started_epoch ))s ago)"
        echo "      command  : $cmd"
    else
        echo "      (no holder info recorded -- held by an older copy of this script, or by a child of a killed run)"
    fi
}

print_status() {
    local i f busy=0 held=0
    echo "  setup lock (schema bootstrap/migrate): $(lock_held "$LOCK_FILE" && echo HELD || echo free)"
    lock_held "$LOCK_FILE" && describe_holder "$INFO_FILE" "$LOCK_FILE"
    echo "  run slots (max $MAX_SLOTS):"
    for (( i = 1; i <= MAX_SLOTS; i++ )); do
        f="${SLOT_PREFIX}.${i}.lock"
        if lock_held "$f"; then
            held=$(( held + 1 )); echo "    slot $i: HELD"; describe_holder "${SLOT_PREFIX}.${i}.info" "$f"
        else
            echo "    slot $i: free"
        fi
    done
    for f in "${SCHEMA_PREFIX}".*.lock; do
        [[ -e "$f" ]] || continue
        if lock_held "$f"; then busy=$(( busy + 1 )); echo "  schema in use: $(basename "$f" .lock | sed "s/^corex-lane-test.schema.//")"; fi
    done
    [[ $busy -gt 0 ]] || echo "  schemas in use: none"
}

if [[ "${1:-}" == "--status" ]]; then
    echo "[lane-test] status:"
    print_status
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

# --- Refuse a truncated snapshot BEFORE anything else (lock, DROP, CREATE, load) ---
#
# a5194c9a1 committed a half-written mysql-schema.sql (345 of 616 tables); lanes
# that loaded it got a half-built schema. Checked up front, for every path
# (first bootstrap, --fresh, fingerprint rebuild), so a bad file never creates,
# drops or half-loads a schema. A MISSING file keeps its existing fallback below.
SNAPSHOT="$WORKTREE/database/schema/mysql-schema.sql"
if [[ -f "$SNAPSHOT" ]]; then
    # shellcheck source=scripts/check-schema-snapshot.sh
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-schema-snapshot.sh"
    check_schema_snapshot "$SNAPSHOT" 2>/dev/null \
        || die "database/schema/mysql-schema.sql is truncated/incomplete ($SNAPSHOT) — refusing to build a test schema from it. Fix: merge origin/QA1 (it carries the repaired snapshot), then re-run."
fi

# --- Resolve TEST_DB_DATABASE with the SAME precedence as tests/bootstrap.php ---
DB="${TEST_DB_DATABASE:-}"
if [[ -z "$DB" && -f "$WORKTREE/.env" ]]; then
    DB=$(grep -E '^[[:space:]]*TEST_DB_DATABASE[[:space:]]*=' "$WORKTREE/.env" | tail -1 \
        | sed -E 's/^[^=]*=[[:space:]]*//' | sed -E 's/^["'"'"']|["'"'"']$//g' || true)
    # (|| true: a .env WITHOUT the key makes grep exit 1, which under pipefail+set -e
    #  used to kill this script silently with no output.)
fi
if [[ -z "$DB" ]]; then
    # No TEST_DB_DATABASE anywhere: do NOT fall back to the bare shared
    # `hfc_dash_test` (every such worktree would share ONE schema). Derive a
    # deterministic per-worktree name from the path; digits only, so it passes
    # the hfc_dash_test_<N> whitelist here, in tests/bootstrap.php and in the
    # `lanetest` user's grant (`hfc_dash_test_%`).
    DB="hfc_dash_test_$(printf '%s' "$WORKTREE" | cksum | awk '{print $1}')"
    log "no TEST_DB_DATABASE set for this worktree -- using derived per-worktree schema '$DB' (set TEST_DB_DATABASE in .env to choose your own)"
fi
[[ "$DB" =~ $WHITELIST_RE ]] || die "TEST_DB_DATABASE resolved to '$DB', not in the allowed hfc_dash_test(_N) whitelist. Refusing."
# tests/bootstrap.php gives the shell env first precedence: export the name chosen
# here so PHPUnit uses exactly this schema (matters when the name was derived).
export TEST_DB_DATABASE="$DB"

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
#
# DB_CONNECTION=mysql (2026-10-06): a worktree whose .env was copied from
# .env.example has DB_CONNECTION=sqlite, so this migrated into a throwaway sqlite
# file instead of the MySQL schema just loaded ("near MODIFY: syntax error").
# A worktree whose .env sets DB_SOCKET (pointing at the SHARED instance's
# socket) silently migrated there as the 'lanetest' user, who doesn't
# exist on that instance -- "Access denied", not a hostname problem.
run_migrate() {
    DB_CONNECTION=mysql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USERNAME="$DB_USER" DB_PASSWORD="$DB_PASS" DB_DATABASE="$DB" DB_SOCKET="" \
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

# --- Acquire: schema lock -> run slot -> (setup lock only around ensure_schema) ---
write_info() { # $1=file, rest = the artisan test args
    local f="$1"; shift
    {
        echo "$WORKTREE"
        echo "$$"
        echo "$(date +%s)"
        echo "$(date -Is)"
        echo "php8.2 artisan test $*  [schema $DB]"
    } > "$f"
}

SCHEMA_LOCK="${SCHEMA_PREFIX}.${DB}.lock"
SCHEMA_INFO="${SCHEMA_PREFIX}.${DB}.info"
SLOT_INFO=""
SLOT_N=""
CHILD_PID=""

cleanup() {
    if [[ -n "$CHILD_PID" ]]; then
        # Running: stop OUR test process GROUP (artisan test spawns phpunit as a
        # grandchild; killing only the top process would leave it running and still
        # holding this run's schema + slot locks). The wait below then ends this script.
        kill -TERM -- "-$CHILD_PID" 2>/dev/null || kill -TERM "$CHILD_PID" 2>/dev/null || true
    else
        # Still queueing (or in schema setup): a Ctrl-C / kill must end the wait,
        # not be swallowed by this handler. The EXIT trap releases our labels.
        exit 143
    fi
}
# Remove OUR info labels before the kernel drops our locks (a kill -9 skips this,
# harmlessly: --status trusts flock, and the next holder overwrites the label).
release_labels() {
    [[ -n "$SLOT_INFO" ]] && rm -f "$SLOT_INFO" 2>/dev/null || true
    rm -f "$SCHEMA_INFO" 2>/dev/null || true
}
trap cleanup INT TERM
trap release_labels EXIT

# 1. Schema lock: same schema name == same data, so strictly one run at a time.
exec 201>"$SCHEMA_LOCK"
if ! flock -n 201; then
    log "schema '$DB' is in use by another run (same TEST_DB_DATABASE) -- waiting so we don't corrupt each other's data:"
    describe_holder "$SCHEMA_INFO" "$SCHEMA_LOCK" >&2
    while ! flock -n 201; do sleep "$POLL_INTERVAL"; done
    log "schema lock acquired."
fi
write_info "$SCHEMA_INFO" "$@"

# 2. Run slot: at most MAX_SLOTS concurrent runs.
acquire_slot() {
    local i
    for (( i = 1; i <= MAX_SLOTS; i++ )); do
        exec 202>"${SLOT_PREFIX}.${i}.lock"
        if flock -n 202; then SLOT_N="$i"; return 0; fi
        exec 202>&-
    done
    return 1
}
if ! acquire_slot; then
    log "all $MAX_SLOTS run slots are busy -- waiting (checking every ${POLL_INTERVAL}s). Slots right now:"
    print_status >&2
    LAST_PRINT=$(date +%s)
    until acquire_slot; do
        sleep "$POLL_INTERVAL"
        if (( $(date +%s) - LAST_PRINT >= 30 )); then print_status >&2; LAST_PRINT=$(date +%s); fi
    done
fi
SLOT_INFO="${SLOT_PREFIX}.${SLOT_N}.info"
write_info "$SLOT_INFO" "$@"
log "slot $SLOT_N of $MAX_SLOTS acquired (schema: $DB)."

# 3. Setup lock: ONLY around schema create/migrate, released before PHPUnit.
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    log "schema setup lock held by another run -- waiting (setup is serialised; tests are not):"
    describe_holder "$INFO_FILE" "$LOCK_FILE" >&2
    while ! flock -n 200; do sleep "$POLL_INTERVAL"; done
    log "setup lock acquired."
fi
write_info "$INFO_FILE" "$@"

SCHEMA_SETUP_START=$(date +%s)
ensure_schema
SCHEMA_SETUP_SECS=$(( $(date +%s) - SCHEMA_SETUP_START ))
log "schema setup took ${SCHEMA_SETUP_SECS}s (ready=${SCHEMA_READY})"

# Release the setup lock NOW: the run itself only needs its schema + slot locks.
rm -f "$INFO_FILE" 2>/dev/null || true
flock -u 200
exec 200>&-

log "running: php8.2 artisan test $* (schema: $DB)"
# Own process group (setsid) so cleanup() can take the whole tree down on kill/Ctrl-C.
# (A non-interactive script's background job is never a group leader, so setsid
# execs in place and $! is both the pid and the group id.)
if command -v setsid >/dev/null 2>&1; then RUNNER=(setsid); else RUNNER=(); fi
LANE_TEST_SCHEMA_READY="$SCHEMA_READY" "${RUNNER[@]}" "$PHP_BIN" artisan test "$@" &
CHILD_PID=$!
wait "$CHILD_PID"
EXIT_CODE=$?

exit "$EXIT_CODE"
