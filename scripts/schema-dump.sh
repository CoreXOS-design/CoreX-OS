#!/usr/bin/env bash
#
# scripts/schema-dump.sh — the ONE sanctioned way to regenerate
# database/schema/mysql-schema.sql (CLAUDE.md non-negotiable #12a, STANDARDS −1b).
#
#   scripts/schema-dump.sh            # run from a worktree; takes no arguments
#
# Never hand-edit the snapshot and never run a bare `artisan schema:dump` against a lane's
# own test schema. Both have produced bad snapshots (hand edits: charset noise, a missing
# migration's tables; dump of a lane schema: the lane-test bookkeeping table, a half-migrated
# lane's data). This script is self-contained and deterministic:
#
#   1. Creates its OWN scratch schema (hfc_dash_test_<digits>) on the tests-only MySQL
#      instance (creds: /root/.lanetest-mysql-credentials — it refuses to run without
#      them, so it can never reach QA1's, Staging's, live's or any real database) and
#      drops it again on exit, success or failure.
#   2. Migrates that schema FROM SCRATCH (`migrate --schema-path=<nonexistent>` so the
#      existing snapshot is NOT loaded first — the whole point is to run every migration,
#      including the ones that INSERT reference rows).
#   3. `artisan schema:dump` -> structure + the `migrations` rows. That command never dumps
#      table DATA, so on its own it records every data-seeding migration as "already run"
#      while the rows it inserted are absent (the 2026-10-06 timeline-defaults failure).
#   4. Finds every table that holds GLOBAL rows straight after a from-scratch migrate (no
#      seeders run, so every such row was inserted by a migration) and appends them, one
#      INSERT per row in primary-key order, just before the `migrations` rows. A lane test
#      schema built from the snapshot is then equivalent to a real migrated one.
#      GLOBAL = a table with no agency_id column, or the agency_id IS NULL rows of one.
#      Agency-scoped rows (agency 1 = HFC's backfilled lost-deal reasons, VAT types,
#      settings, ...) and the `agencies` row itself are deliberately NOT carried: they are
#      one tenant's data, not reference data (CLAUDE.md rule 9 — nothing may assume one
#      agency), and a test that creates its own agency must start from an empty tenant space.
#   5. Strips noise deterministically (DEFINER clauses #12a; redundant column CHARACTER SET) and refuses — rather than
#      silently fixing — a dump that contains the lane-test bookkeeping table.
#   6. Runs scripts/check-schema-snapshot.sh, and only on success moves the result over the
#      snapshot. The dump is written to a temp file in database/schema/ (same filesystem ->
#      atomic rename). On ANY failure the existing snapshot is untouched.
#
# Cost: ~15-20 minutes (all ~1,600 migrations, one by one). It holds the tests-instance
# SETUP lock (/tmp/corex-lane-test.lock) throughout, so lane test bootstraps wait for it
# (warm test runs do not). Run it once per batch of merged migrations, not per migration.
# Needs this worktree's OWN vendor/ (composer install inside it; never a symlink).

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/check-schema-snapshot.sh
source "$HERE/check-schema-snapshot.sh"

PHP_BIN="${PHP_BIN:-php8.2}"
ROOT="$(cd "$HERE/.." && pwd)"
TARGET="$ROOT/database/schema/mysql-schema.sql"
TMP="$ROOT/database/schema/.mysql-schema.sql.tmp.$$"
RAW="$TMP.raw"
DATA="$TMP.data"
COUNTSQL="$TMP.countsql"
CREDS=/root/.lanetest-mysql-credentials
LOCK_FILE=/tmp/corex-lane-test.lock
FINGERPRINT_TABLE='_corex_lane_test_fingerprint'
# Tables whose rows must never be copied into the snapshot even if populated:
#   migrations / _corex_lane_test_fingerprint — bookkeeping (migrations rows are appended by schema:dump itself)
#   agencies                       — the tenant root row (agency 1 = HFC); a test creates its own
#   communication_host_circuit_breakers — one row for HFC's own mail host (mail.hfcoastal.co.za), written by a
#                                    migration: one tenant's environment, not reference data
#   training_lessons               — children of agency-scoped training_courses, which are not carried; the
#                                    lessons would be orphans pointing at courses that do not exist
#   roles / role_permissions       — the permission model itself. Test fixtures create a user with a role NAME
#                                    and no role row, and the permission checks treat the empty tables as
#                                    "use the defaults"; populated system roles flip that and 403 the fixture
#                                    users (70 RentalCrewLinks failures, 2026-10-06). A test that needs roles builds them.
# When a new data-seeding migration adds a tenant-specific or orphan-prone table, add it here — the
# "reference tables carried" line this script prints is the list to review.
SKIP_DATA_TABLES_RE='^(migrations|_corex_lane_test_fingerprint|agencies|communication_host_circuit_breakers|training_lessons|roles|role_permissions)$'

log() { echo "[schema-dump] $*" >&2; }
die() { echo "[schema-dump] $*" >&2; exit 1; }

[[ $# -eq 0 ]]                       || die "takes no arguments (it always builds its own scratch schema). Got: $*"
[[ -f "$ROOT/artisan" ]]             || die "no artisan at $ROOT"
[[ -f "$ROOT/vendor/autoload.php" ]] || die "no vendor/ in $ROOT — run composer install inside this worktree first"
[[ ! -L "$ROOT/vendor" ]]            || die "vendor/ is a symlink — worktrees need their own composer install (CLAUDE.md vendor isolation rule)"
[[ -f "$CREDS" ]]                    || die "$CREDS not found — this script only runs against the tests-only MySQL instance on the cc1-cc6 box"

DB_USER=$(grep -E '^LANETEST_DB_USER=' "$CREDS" | tail -1 | cut -d= -f2-)
DB_PASS=$(grep -E '^LANETEST_DB_PASSWORD=' "$CREDS" | tail -1 | cut -d= -f2-)
DB_HOST=127.0.0.1
DB_PORT=3317
[[ -n "$DB_USER" && -n "$DB_PASS" ]] || die "could not read LANETEST_DB_USER / LANETEST_DB_PASSWORD from $CREDS"

# Scratch schema: digits only so it passes the hfc_dash_test_<N> whitelist and the
# `lanetest` user's grant (`hfc_dash_test_%`). 7 + epoch seconds + pid -> unique per run.
SCRATCH="hfc_dash_test_7$(date +%s)$$"
[[ "$SCRATCH" =~ ^hfc_dash_test_[0-9]+$ ]] || die "internal: scratch name '$SCRATCH' failed the whitelist"

mysql_q() { MYSQL_PWD="$DB_PASS" mysql -N -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$@"; }
SCRATCH_CREATED=0
cleanup() {
    rm -f "$TMP" "$RAW" "$DATA" "$COUNTSQL"
    if [[ "$SCRATCH_CREATED" == "1" ]]; then
        if mysql_q -e "DROP DATABASE IF EXISTS \`${SCRATCH}\`" >/dev/null 2>&1; then
            log "dropped scratch schema $SCRATCH"
        else
            log "WARNING: could not drop scratch schema $SCRATCH — drop it by hand"
        fi
    fi
}
trap cleanup EXIT

# Same setup lock lane-test.sh holds while it bootstraps a schema: serialises heavy DDL
# against the tests instance. Held for the whole run (fd 200, released by the kernel on exit).
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    log "tests-instance setup lock is held by another run (a lane bootstrapping a schema) — waiting"
    flock 200
fi

mysql_q -e "CREATE DATABASE \`${SCRATCH}\`"
SCRATCH_CREATED=1
log "scratch schema $SCRATCH created on the tests instance (:$DB_PORT) — migrating from scratch (~15-20 min)"

# Every DB_* is overridden (DB_SOCKET="" and DB_CONNECTION=mysql too) for the reasons
# lane-test.sh's run_migrate documents: a worktree .env must not be able to redirect this.
artisan_scratch() {
    DB_CONNECTION=mysql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USERNAME="$DB_USER" DB_PASSWORD="$DB_PASS" \
        DB_DATABASE="$SCRATCH" DB_SOCKET="" "$PHP_BIN" artisan "$@"
}

cd "$ROOT"
artisan_scratch migrate --force --schema-path=/nonexistent/none.sql >&2 \
    || die "from-scratch migrate failed — existing snapshot left untouched"

artisan_scratch schema:dump --path="$RAW" >&2 || die "artisan schema:dump failed — existing snapshot left untouched"

# --- Reference rows: GLOBAL rows of every table populated by a seeder-free from-scratch migrate ---
# Global = the table has no agency_id column (whole table), or only its `agency_id IS NULL` rows.
mysql_q -e "SET SESSION group_concat_max_len = 4000000;
    SELECT GROUP_CONCAT(CONCAT('SELECT ''', t.table_name, ''' AS t, COUNT(*) AS n FROM \`', t.table_name, '\`',
        IF(c.column_name IS NULL, '', ' WHERE agency_id IS NULL')) SEPARATOR ' UNION ALL ')
    FROM information_schema.tables t
    LEFT JOIN information_schema.columns c
           ON c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.column_name = 'agency_id'
    WHERE t.table_schema = '${SCRATCH}' AND t.table_type = 'BASE TABLE'" \
    > "$COUNTSQL"
DATA_TABLES=$(mysql_q "$SCRATCH" < "$COUNTSQL" | awk -F'\t' '$2 > 0 {print $1}' | sort | grep -Ev "$SKIP_DATA_TABLES_RE" || true)

: > "$DATA"
if [[ -n "$DATA_TABLES" ]]; then
    {
        echo "/*!40101 SET NAMES utf8mb4 */;"
        echo "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;"
        echo "/*!40014 SET UNIQUE_CHECKS=0 */;"
    } >> "$DATA"
    while IFS= read -r tbl; do
        WHERE="1"
        if [[ "$(mysql_q -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='${SCRATCH}' AND table_name='${tbl}' AND column_name='agency_id'")" == "1" ]]; then
            WHERE="agency_id IS NULL"
        fi
        MYSQL_PWD="$DB_PASS" mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
            --compact --no-create-info --skip-triggers --skip-extended-insert --complete-insert \
            --order-by-primary --hex-blob --set-gtid-purged=OFF --column-statistics=0 \
            --where="$WHERE" "$SCRATCH" "$tbl" >> "$DATA" \
            || die "mysqldump of reference rows ($tbl) failed — existing snapshot left untouched"
    done <<< "$DATA_TABLES"
fi
log "reference tables carried ($(printf '%s\n' "$DATA_TABLES" | grep -c . || true)): $(printf '%s ' $DATA_TABLES)"

# --- Assemble: structure ... + reference rows + migrations rows (the guard needs migrations LAST) ---
FIRST_MIG_LINE=$(grep -n '^INSERT INTO `migrations` ' "$RAW" | head -n 1 | cut -d: -f1 || true)
[[ -n "$FIRST_MIG_LINE" ]] || die "raw dump has no migrations INSERT rows — existing snapshot left untouched"
if head -n $(( FIRST_MIG_LINE - 1 )) "$RAW" | grep -q '^INSERT INTO '; then
    die "raw dump already contains INSERTs before the migrations rows — script assumption broken, existing snapshot left untouched"
fi
{
    head -n $(( FIRST_MIG_LINE - 1 )) "$RAW"
    cat "$DATA"
    tail -n +"$FIRST_MIG_LINE" "$RAW"
} > "$TMP"

# --- Deterministic noise strip ---
# DEFINER (#12a): schema:dump bakes the dumping user into every CREATE TRIGGER.
sed -i -E 's#/\*!50017 DEFINER=`[^`]+`@`[^`]+`\*/ ##g' "$TMP"
if grep -q 'DEFINER=' "$TMP"; then die "DEFINER clause survived the strip — existing snapshot left untouched"; fi
# Charset: a column re-declared with ->change() (e.g. an enum) dumps with a redundant
# `CHARACTER SET utf8mb4` that every other column omits. Every table is utf8mb4/unicode_ci,
# so dropping it changes nothing but keeps fresh dumps diff-clean against each other.
sed -i 's/ CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci/ COLLATE utf8mb4_unicode_ci/g' "$TMP"
if grep -q ' CHARACTER SET ' "$TMP"; then die "an unexpected column-level CHARACTER SET survived the strip (not utf8mb4/unicode_ci?) — existing snapshot left untouched"; fi
# The lane-test bookkeeping table must never be in a snapshot (it would reset a lane's fingerprint).
if grep -q "$FINGERPRINT_TABLE" "$TMP"; then die "dump contains $FINGERPRINT_TABLE — existing snapshot left untouched"; fi

check_schema_snapshot "$TMP" || die "new dump failed the completeness check — existing snapshot left untouched"

mv -f "$TMP" "$TARGET"
log "wrote $TARGET ($(wc -c < "$TARGET") bytes, $(grep -c '^CREATE TABLE ' "$TARGET") tables, $(grep -c '^INSERT INTO ' "$TARGET") INSERT rows)"
