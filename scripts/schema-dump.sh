#!/usr/bin/env bash
#
# scripts/schema-dump.sh — the ONE sanctioned way to regenerate
# database/schema/mysql-schema.sql (CLAUDE.md non-negotiable #12a).
#
# Run it against a FULLY MIGRATED throwaway test schema (migrate:fresh first):
#   DB_DATABASE=hfc_dash_test_95NN [DB_HOST=… DB_PORT=… …] scripts/schema-dump.sh
#
# What it does that a bare `artisan schema:dump` does not:
#   1. Dumps to a TEMP file in database/schema/ (same filesystem, so the final
#      step is an atomic rename) — never straight over the committed snapshot.
#   2. Strips the DEFINER clauses (#12a) from the temp file.
#   3. Runs scripts/check-schema-snapshot.sh on the temp file.
#   4. Only if that passes, `mv`s it over the real snapshot. On ANY failure the
#      existing snapshot is left untouched, the temp file is removed, and the
#      script exits non-zero.
#
# It never runs migrations and never touches a database other than the one
# artisan is already pointed at; the DB_DATABASE whitelist below stops it being
# pointed at a real (live/Staging/QA) database by mistake.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/check-schema-snapshot.sh
source "$HERE/check-schema-snapshot.sh"

PHP_BIN="${PHP_BIN:-php8.2}"
WHITELIST_RE='^hfc_dash_test(_[0-9]+)?$'
ROOT="$(cd "$HERE/.." && pwd)"
TARGET="$ROOT/database/schema/mysql-schema.sql"
TMP="$ROOT/database/schema/.mysql-schema.sql.tmp.$$"

die() { echo "[schema-dump] $*" >&2; exit 1; }
trap 'rm -f "$TMP"' EXIT

[[ -f "$ROOT/artisan" ]] || die "no artisan at $ROOT"
[[ -n "${DB_DATABASE:-}" ]] || die "set DB_DATABASE to the fully-migrated test schema to dump (hfc_dash_test_NN)"
[[ "$DB_DATABASE" =~ $WHITELIST_RE ]] || die "DB_DATABASE='$DB_DATABASE' is not an hfc_dash_test(_N) schema. Refusing to dump a real database."

cd "$ROOT"
"$PHP_BIN" artisan schema:dump --path="$TMP" >&2 || die "artisan schema:dump failed — existing snapshot left untouched"

# DEFINER strip (#12a): the same expression the PowerShell recipe uses.
sed -i -E 's#/\*!50017 DEFINER=`[^`]+`@`[^`]+`\*/ ##g' "$TMP"

check_schema_snapshot "$TMP" || die "new dump failed the completeness check — existing snapshot left untouched"

mv -f "$TMP" "$TARGET"
echo "[schema-dump] wrote $TARGET ($(wc -c < "$TARGET") bytes, $(grep -c '^CREATE TABLE ' "$TARGET") tables)" >&2
