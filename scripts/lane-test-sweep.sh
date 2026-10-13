#!/usr/bin/env bash
#
# scripts/lane-test-sweep.sh — drop test schemas (hfc_dash_test_<N>) whose worktree is gone.
#
# Why: every worktree that runs scripts/lane-test.sh gets its own persistent schema (~115 MB) in the
# tests-only MySQL, and nothing removed it when the worktree was removed. By 13 Oct 2026 there were
# 96 orphans (11 GB). Three ways this runs:
#   scripts/lane-test-sweep.sh                    sweep: drop every orphan schema (weekly cron; lane-test.sh also
#                                                 starts one in the background at most once a day)
#   scripts/lane-test-sweep.sh --dry-run          say what a sweep would drop, drop nothing
#   scripts/lane-test-sweep.sh --drop-mine [dir]  drop THIS worktree's schema (run it before `git worktree remove`;
#                                                 also reachable as `scripts/lane-test.sh --drop-schema`)
#
# Safety — a schema is dropped only if ALL of these hold (the same checks used for the 13 Oct clean-up):
#   1. its name is hfc_dash_test_<digits> (never the bare shared hfc_dash_test, never anything else);
#   2. it is NOT the name derived (cksum of the path) from ANY checkout that still exists — found by scanning the data
#      volume (depth <= 4) and the app roots for an `artisan` file, NOT from `git worktree list` (that only shows one
#      repository's worktrees; on 13 Oct 2026 it hid three live ones and their schemas were dropped by mistake);
#   3. it is NOT named by a TEST_DB_DATABASE line in any .env under the data volume (depth <= 4) or those roots;
#   4. no lane-test run holds its schema lock right now;
#   5. nothing in its data folder was touched in the last MIN_AGE_MINUTES (default 120) — a lane may be running.
# It never touches an application database: the only DROP it issues is for a name passing check 1.
#
# Env: LANETEST_DATADIR (default /mnt/HC_Volume_103099143/lanetest-mysql-data), MIN_AGE_MINUTES, LANETEST_SWEEP_LOG.

set -uo pipefail

MODE=sweep
DIR="$(pwd)"
case "${1:-}" in
    --dry-run)   MODE=dry ;;
    --drop-mine) MODE=mine; DIR="${2:-$(pwd)}" ;;
    "")          ;;
    *)           echo "usage: $0 [--dry-run | --drop-mine [worktree-dir]]" >&2; exit 2 ;;
esac

DATADIR="${LANETEST_DATADIR:-/mnt/HC_Volume_103099143/lanetest-mysql-data}"
MIN_AGE_MINUTES="${MIN_AGE_MINUTES:-120}"
CREDS=/root/.lanetest-mysql-credentials
SCHEMA_LOCK_PREFIX=/tmp/corex-lane-test.schema
NAME_RE='^hfc_dash_test_[0-9]+$'
DATA_VOLUME=/mnt/HC_Volume_103099143
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say() { echo "[lane-test-sweep] $*"; }

[[ -f "$CREDS" ]] || { say "no $CREDS — nothing to do on this machine."; exit 0; }
[[ -d "$DATADIR" ]] || { say "tests-only MySQL data folder $DATADIR not found — refusing to guess; nothing dropped."; exit 0; }
U=$(grep -E '^LANETEST_DB_USER=' "$CREDS" | tail -1 | cut -d= -f2-)
P=$(grep -E '^LANETEST_DB_PASSWORD=' "$CREDS" | tail -1 | cut -d= -f2-)
mysql_q() { MYSQL_PWD="$P" timeout 180 mysql -N -h127.0.0.1 -P3317 -u"$U" "$@"; }

name_for() { echo "hfc_dash_test_$(printf '%s' "$1" | cksum | awk '{print $1}')"; }

# --- every checkout that exists right now (scanned fresh on each run, never cached) ---
CHECKOUTS=$(mktemp)
KEEP=$(mktemp); trap 'rm -f "$KEEP" "$CHECKOUTS"' EXIT
{
    find "$DATA_VOLUME" -maxdepth 4 -name artisan -not -path '*/vendor/*' -not -path '*/node_modules/*' 2>/dev/null | xargs -r -n1 dirname
    printf '%s\n' /corex /corex-qa1 /corex-qa2 /corex-staging
} | sort -u > "$CHECKOUTS"

# --- the schema names that must be kept ---
while read -r w; do
    [[ -d "$w" ]] || continue
    # --drop-mine: the worktree being retired must not protect its own schema
    [[ "$MODE" == mine && "$w" == "$DIR" ]] && continue
    name_for "$w"
done < "$CHECKOUTS" >> "$KEEP"
# explicit TEST_DB_DATABASE overrides in any checkout's .env
find "$DATA_VOLUME" /corex /corex-qa1 /corex-qa2 /corex-staging -maxdepth 4 -name .env \
    -not -path '*/node_modules/*' -not -path '*/vendor/*' 2>/dev/null \
  | while read -r f; do
        # a deleted-worktree check for mode=mine: this worktree's own .env override does not protect it
        [[ "$MODE" == mine && "$f" == "$DIR/.env" ]] && continue
        grep -hE '^[[:space:]]*TEST_DB_DATABASE[[:space:]]*=' "$f" 2>/dev/null | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^["'"'"']|["'"'"']$//g'
    done >> "$KEEP"
sort -u "$KEEP" -o "$KEEP"

schema_lock_held() { [[ -e "${SCHEMA_LOCK_PREFIX}.$1.lock" ]] && ! ( flock -n 9 ) 9<"${SCHEMA_LOCK_PREFIX}.$1.lock" 2>/dev/null; }

drop_one() { # $1 name
    local n="$1" folder="$DATADIR/$1" mb
    [[ "$n" =~ $NAME_RE ]] || { say "REFUSE $n (not hfc_dash_test_<digits>)"; return 1; }
    if [[ "$MODE" != mine ]]; then
        grep -qx "$n" "$KEEP" && return 0
        if [[ -n "$(find "$folder" -mmin "-$MIN_AGE_MINUTES" -print -quit 2>/dev/null)" ]]; then say "skip $n (touched in the last ${MIN_AGE_MINUTES} min)"; return 0; fi
    fi
    if schema_lock_held "$n"; then say "skip $n (a lane-test run holds its schema lock)"; return 0; fi
    mb=$(du -sm "$folder" 2>/dev/null | cut -f1)
    if [[ "$MODE" == dry ]]; then say "would drop $n (${mb:-?} MB)"; return 0; fi
    if mysql_q -e "DROP DATABASE \`$n\`" 2>/tmp/lane-test-sweep.err; then
        say "dropped $n (${mb:-?} MB)"; DROPPED=$((DROPPED + 1)); FREED=$((FREED + ${mb:-0}))
    else
        say "FAILED $n: $(head -c 200 /tmp/lane-test-sweep.err)"
    fi
}

DROPPED=0; FREED=0
if [[ "$MODE" == mine ]]; then
    [[ -f "$DIR/artisan" ]] || { say "$DIR is not a Laravel worktree root."; exit 2; }
    N=$(grep -E '^[[:space:]]*TEST_DB_DATABASE[[:space:]]*=' "$DIR/.env" 2>/dev/null | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^["'"'"']|["'"'"']$//g')
    [[ -n "$N" ]] || N=$(name_for "$DIR")
    # Another live checkout using the same name (a shared override such as hfc_dash_test_1) must keep it.
    for other in $(cat "$CHECKOUTS"); do
        [[ "$other" == "$DIR" || ! -d "$other" ]] && continue
        o=$(grep -E '^[[:space:]]*TEST_DB_DATABASE[[:space:]]*=' "$other/.env" 2>/dev/null | tail -1 | sed -E 's/^[^=]*=[[:space:]]*//; s/^["'"'"']|["'"'"']$//g')
        [[ -n "$o" ]] || o=$(name_for "$other")
        [[ "$o" == "$N" ]] && { say "skip $N — also used by $other"; exit 0; }
    done
    drop_one "$N"
else
    for folder in "$DATADIR"/hfc_dash_test_*; do
        [[ -d "$folder" ]] || continue
        drop_one "$(basename "$folder")"
    done
fi
say "done ($MODE): dropped=$DROPPED freed=${FREED}MB"
