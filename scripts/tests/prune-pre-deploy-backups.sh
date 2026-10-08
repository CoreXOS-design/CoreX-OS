#!/usr/bin/env bash
# =============================================================================
# prune-pre-deploy-backups.sh — proves the keep-latest-N prune in scripts/deploy.sh
# on a throwaway folder of FAKE files. Touches nothing outside a mktemp dir.
#
#   bash scripts/tests/prune-pre-deploy-backups.sh          # dry-run listing, then asserts
#
# It extracts prune_pre_deploy_backups() from deploy.sh so it tests the real text.
# =============================================================================
set -uo pipefail
IFS=$'\n\t'
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY="$HERE/../deploy.sh"
T="$(mktemp -d "${TMPDIR:-/tmp}/prune-test.XXXXXX")"
trap 'rm -rf "$T"' EXIT
LOG_FILE="$T/log"; : > "$LOG_FILE"
log() { echo "$*" | tee -a "$LOG_FILE"; }
ok() { log "  ✓ $*"; }
warn() { log "  ⚠ $*"; }
# shellcheck disable=SC1090
source <(sed -n '/^prune_pre_deploy_backups() {/,/^}/p' "$DEPLOY")
declare -F prune_pre_deploy_backups >/dev/null || { echo "could not extract prune function"; exit 99; }

PASS=0; FAIL=0
check() { if eval "$2"; then PASS=$((PASS+1)); echo "  PASS  $1"; else FAIL=$((FAIL+1)); echo "  FAIL  $1"; fi; }

D="$T/pre-deploy"; mkdir -p "$D"
mk() { head -c 64 /dev/zero | gzip > "$D/$1"; }
for ts in 20260820-201458 20260928-134730 20260929-020524 20260930-061514 20261006-135218 \
          20261007-074917 20261007-145422 20261008-101404 20261008-132458; do mk "hfc_staging-pre-deploy-$ts.sql.gz"; done
mk nexus_os-pre-deploy-20260822-111156.sql.gz                    # another DB's dump
mk hfc_staging-pre-deploy-20261008-132458.sql.gz.part            # not our naming (suffix)
mk hfc_staging-pre-deploy-manual-before-migration.sql.gz         # not our naming (hand-made)
mk hfc_staging2-pre-deploy-20260101-000000.sql.gz                # similar prefix, different DB
echo notes > "$D/README.txt"
ln -s "$D/hfc_staging-pre-deploy-20261008-132458.sql.gz" "$D/hfc_staging-pre-deploy-LATEST.sql.gz"
JUST="$D/hfc_staging-pre-deploy-20261008-132458.sql.gz"
before="$(ls -1 "$D" | wc -l)"

echo "== DRY RUN (keep 5) — nothing may be removed"
prune_pre_deploy_backups "$D" hfc_staging 5 "$JUST" 1
check "dry-run removed nothing" '[[ "$(ls -1 "$D" | wc -l)" == "$before" ]]'
check "dry-run listed 4 victims (9 matching, keep 5)" '[[ "$(grep -c "would prune:" "$LOG_FILE")" == 4 ]]'
check "dry-run never lists the just-taken dump" '! grep "would prune:" "$LOG_FILE" | grep -q 20261008-132458'

echo "== REAL RUN on the fake folder (keep 5)"
prune_pre_deploy_backups "$D" hfc_staging 5 "$JUST" 0
check "5 newest hfc_staging dumps remain" '[[ "$(ls -1 "$D"/hfc_staging-pre-deploy-2*.sql.gz | wc -l)" == 5 ]]'
check "oldest 4 are gone" '! ls "$D"/hfc_staging-pre-deploy-20260820-201458.sql.gz "$D"/hfc_staging-pre-deploy-20260928-134730.sql.gz "$D"/hfc_staging-pre-deploy-20260929-020524.sql.gz "$D"/hfc_staging-pre-deploy-20260930-061514.sql.gz 2>/dev/null | grep -q .'
check "just-taken dump kept" '[[ -f "$JUST" ]]'
check "LATEST symlink untouched and still resolves" '[[ -L "$D/hfc_staging-pre-deploy-LATEST.sql.gz" && -e "$D/hfc_staging-pre-deploy-LATEST.sql.gz" ]]'
check "other DB dump untouched" '[[ -f "$D/nexus_os-pre-deploy-20260822-111156.sql.gz" ]]'
check "similar-prefix DB (hfc_staging2) untouched" '[[ -f "$D/hfc_staging2-pre-deploy-20260101-000000.sql.gz" ]]'
check ".part file untouched" '[[ -f "$D/hfc_staging-pre-deploy-20261008-132458.sql.gz.part" ]]'
check "hand-named dump untouched" '[[ -f "$D/hfc_staging-pre-deploy-manual-before-migration.sql.gz" ]]'
check "README untouched" '[[ -f "$D/README.txt" ]]'

echo "== just-taken dump older by name than the keepers is still never removed"
mk hfc_staging-pre-deploy-20250101-000000.sql.gz
prune_pre_deploy_backups "$D" hfc_staging 2 "$D/hfc_staging-pre-deploy-20250101-000000.sql.gz" 0
check "protected just-taken dump survives keep=2" '[[ -f "$D/hfc_staging-pre-deploy-20250101-000000.sql.gz" ]]'

echo "== bad N prunes nothing"
n="$(ls -1 "$D" | wc -l)"
prune_pre_deploy_backups "$D" hfc_staging abc "$JUST" 0
prune_pre_deploy_backups "$D" hfc_staging 0 "$JUST" 0
check "N='abc' and N=0 removed nothing" '[[ "$(ls -1 "$D" | wc -l)" == "$n" ]]'

echo "== missing just-taken dump prunes nothing"
prune_pre_deploy_backups "$D" hfc_staging 1 "$D/does-not-exist.sql.gz" 0
check "no dump taken -> nothing pruned" '[[ "$(ls -1 "$D" | wc -l)" == "$n" ]]'

echo; echo "RESULT: $PASS passed, $FAIL failed"
[[ "$FAIL" == 0 ]]
