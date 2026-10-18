#!/usr/bin/env bash
#
# scripts/check-schema-snapshot.sh <file> — is this database/schema/mysql-schema.sql COMPLETE?
#
# Exit 0 = complete, 1 = truncated/empty/unreadable (one-line reason on stderr).
#
# Why: a half-written dump (626,688 bytes, 345 of 616 tables, cut mid-column)
# was committed in a5194c9a1 and failed every lane's first fresh-schema run.
# `artisan schema:dump` appends the `migrations` INSERT rows LAST, after every
# table, so a dump that was cut anywhere earlier cannot end with them.
#
# A complete snapshot: has at least one CREATE TABLE, and its last non-blank
# line is a terminated `INSERT INTO `migrations` ...;` statement.
#
# Used by scripts/lane-test.sh (before loading), scripts/schema-dump.sh (before
# moving a fresh dump into place). scripts/dev-check.ps1 carries the same rule
# in PowerShell — keep the three in step.

check_schema_snapshot() {
    local f="$1" last
    [[ -f "$f" && -r "$f" ]] || { echo "snapshot '$f' is missing or unreadable" >&2; return 1; }
    [[ -s "$f" ]] || { echo "snapshot '$f' is empty" >&2; return 1; }

    grep -q '^CREATE TABLE ' "$f" \
        || { echo "snapshot '$f' contains no CREATE TABLE statements" >&2; return 1; }

    # Last non-blank line, read from the tail only (the file is >1MB).
    last=$(tail -c 8192 "$f" | grep -v '^[[:space:]]*$' | tail -n 1 || true)
    if [[ "$last" =~ ^INSERT\ INTO\ \`migrations\`\ .*\;[[:space:]]*$ ]]; then
        return 0
    fi

    echo "snapshot '$f' is truncated: it does not end with a complete migrations INSERT (last line: '${last:0:80}')" >&2
    return 1
}

# Run directly (not sourced): check the file named on the command line.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    [[ $# -eq 1 ]] || { echo "usage: $0 <path/to/mysql-schema.sql>" >&2; exit 2; }
    check_schema_snapshot "$1"
fi
