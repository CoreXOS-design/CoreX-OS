#!/usr/bin/env bash
#
# scripts/verify-agreement-wording.sh — word-for-word proof of what the client sees and signs (spec §11.15).
#
# Creates a throwaway Subscription Agreement (mail faked — nothing is sent), reads the recipient page in a REAL browser
# (puppeteer + chromium), then compares the recipient web page, the wet-ink download PDF and — with --seal — the sealed PDF
# after both parties sign, against the two signed-off source files as one continuous word sequence. The throwaway is
# retired afterwards. Exit code is non-zero when any difference is found.
#
# Run from the checkout whose database you are proving (QA1: /corex-qa1 is the deploy target — run it from a worktree
# with APP_ENV pointing at that environment's env file, or from the deployed checkout's own artisan if it is on the branch).
#
#   bash scripts/verify-agreement-wording.sh [--seal] [--source-dir /tmp/corex-agreement] [--pin 1.0]
#   --pin: the wording version the throwaway is pinned to (default: the seeded 1.0; QA1 also has later published versions)
#
set -uo pipefail
cd "$(dirname "$0")/.."

SEAL=""
PIN=1.0
SRC=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --seal) SEAL="--seal" ;;
        --source-dir) SRC=(--source-dir="$2"); shift ;;
        --pin) PIN="$2"; shift ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
    shift
done

PHP=${PHP:-php8.2}
OUT=$(mktemp -d)
trap 'rm -rf "$OUT"' EXIT

echo "== stored seed version =="
$PHP artisan platform-esign:verify-wording --stored "${SRC[@]}" || STORED_FAIL=1

echo "== throwaway agreement =="
$PHP artisan platform-esign:verify-wording --prepare --pin="$PIN" > "$OUT/prepare.txt" || { cat "$OUT/prepare.txt"; exit 1; }
DOC_ID=$(grep '^DOC_ID=' "$OUT/prepare.txt" | cut -d= -f2)
URL=$(grep '^URL=' "$OUT/prepare.txt" | cut -d= -f2-)
echo "agreement id $DOC_ID"

echo "== recipient page, real browser =="
node scripts/verify-agreement-web-text.cjs "$URL" "$OUT/web.txt" || { $PHP artisan platform-esign:verify-wording --doc="$DOC_ID" --cleanup >/dev/null 2>&1; exit 1; }

echo "== comparison =="
$PHP artisan platform-esign:verify-wording --doc="$DOC_ID" --web-text="$OUT/web.txt" $SEAL --cleanup "${SRC[@]}"
RC=$?
[[ -n "${STORED_FAIL:-}" ]] && RC=1
exit $RC
