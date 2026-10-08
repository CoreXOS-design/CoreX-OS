#!/usr/bin/env bash
# =============================================================================
# deploy-preflight-real-send-flag.sh — proves the OUTBOUND_MAIL_REAL_SEND pre-flight in scripts/deploy.sh:
#   production target WITHOUT the flag  -> refuses (non-zero) with a clear message
#   production target WITH the flag     -> passes
#   staging target WITH the flag        -> refuses (a copied live .env must not arm a test box)
#   staging target WITHOUT the flag     -> passes
#
#   bash scripts/tests/deploy-preflight-real-send-flag.sh
#
# Extracts preflight_real_send_flag() from deploy.sh (the real text), runs it against throwaway .env files in
# a mktemp dir. Touches nothing else; no deploy, no git, no service.
# =============================================================================
set -euo pipefail   # same options as deploy.sh: the missing-flag case must print its message, not die silently
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY="$HERE/../deploy.sh"
T="$(mktemp -d "${TMPDIR:-/tmp}/preflight-test.XXXXXX")"
trap 'rm -rf "$T"' EXIT
LOG_FILE="$T/log"; : > "$LOG_FILE"
fail() { echo "  ✗ $*" | tee -a "$LOG_FILE"; return 1; }
# shellcheck disable=SC1090
source <(sed -n '/^preflight_real_send_flag() {/,/^}/p' "$DEPLOY")
declare -F preflight_real_send_flag >/dev/null || { echo "could not extract preflight_real_send_flag"; exit 99; }

PASS=0; FAILN=0
expect() { # name, want(0|1), target, env-file
    local rc=0
    ( preflight_real_send_flag "$3" "$4" ) >/dev/null 2>&1 || rc=$?
    local got=0; [[ $rc -ne 0 ]] && got=1
    if [[ "$got" == "$2" ]]; then PASS=$((PASS+1)); echo "  PASS  $1"; else FAILN=$((FAILN+1)); echo "  FAIL  $1 (exit $rc)"; fi
}

printf 'APP_ENV=production\nAPP_URL=https://corexos.co.za\n' > "$T/prod-missing.env"
printf 'APP_ENV=production\nOUTBOUND_MAIL_REAL_SEND=1\n' > "$T/prod-flag.env"
printf 'APP_ENV=production\nOUTBOUND_MAIL_REAL_SEND=0\n' > "$T/prod-zero.env"
printf 'APP_ENV=production\nOUTBOUND_MAIL_REAL_SEND="true"\n' > "$T/prod-true.env"
printf 'APP_ENV=staging\n' > "$T/stg-missing.env"
printf 'APP_ENV=staging\nOUTBOUND_MAIL_REAL_SEND=1\n' > "$T/stg-flag.env"

expect "production, flag missing: REFUSES"            1 production "$T/prod-missing.env"
expect "production, flag =0: REFUSES"                  1 production "$T/prod-zero.env"
expect "production, flag =1: passes"                   0 production "$T/prod-flag.env"
expect "production, flag =\"true\": passes"            0 production "$T/prod-true.env"
expect "staging, flag absent: passes"                  0 staging    "$T/stg-missing.env"
expect "staging, flag set: REFUSES"                    1 staging    "$T/stg-flag.env"

echo "--- the refusal message names the one thing to do"
( preflight_real_send_flag production "$T/prod-missing.env" ) >/dev/null 2>&1 || true
grep -q "OUTBOUND_MAIL_REAL_SEND=1 is MISSING" "$LOG_FILE" && grep -q "BY HAND" "$LOG_FILE" \
    && { PASS=$((PASS+1)); echo "  PASS  message is explicit"; } || { FAILN=$((FAILN+1)); echo "  FAIL  message is explicit"; }

echo; echo "RESULT: $PASS passed, $FAILN failed"
[[ "$FAILN" == 0 ]]
