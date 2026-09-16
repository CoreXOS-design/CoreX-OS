#!/usr/bin/env bash
# CoreX — 2026-09-16 Prod promotion: deploy + every post-deploy apply in ONE run.
#
# Run FROM YOUR LAPTOP, from the repo root, after the fix branch is on origin/Prod:
#
#     ssh corex bash -s < .ai/deploy/2026-09-16-prod-promotion-deploy.sh
#
# (`corex` is the ~/.ssh/config alias for root@62.238.31.82 with the corex_prod key.)
#
# What it does, in order (stops at the first failure — nothing after a failed step runs):
#   0. Pre-flight: on the live box, on branch Prod, APP_ENV/APP_URL correct, mail guard satisfied.
#   1. Creates the data-volume cache dir the rental PDF cache needs (owned by www-data).
#   2. Dumps the live database to /root before anything changes (prod has no automatic backups).
#   3. git pull → migrate --force IMMEDIATELY (pages error between those two steps — see audit §3).
#   4. deploy:sync-reference-data (gives roles the nine new permission keys).
#   5. npm run build (stylesheet changed; public/build is git-ignored).
#   6. Clears caches, reloads php-fpm, restarts every worker group (trailing colons — they are groups).
#   7. The data applies, each dry-run FIRST so the numbers are in the log, then applied:
#        deals:correct-share-percent-defect      — internal sides back to 100 % (audit §2.2 / M11)
#        properties:backfill-p24-imported        — stamps pre-split P24 imports (M15: this MOVES the
#                                                  agency's own sold/let P24-origin stock to Imported Stock)
#        core-matches:backfill-set-aside-lost-buyers — Johan's 2026-09-15 ruling
#        properties:sync-gallery-categories      — repairs "0 photos" in the mobile app
#   8. Prints a verification block (HEAD, pending migrations, worker status, tail of the log).
#
# Nothing here goes to live without Johan's explicit order for this exact run (CLAUDE.md rule 5).

set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
APP=/corex
LOG=/root/corex-promotion-$(date +%F-%H%M).log
exec > >(tee -a "$LOG") 2>&1

step() { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31mSTOP: %s\033[0m\n' "$*"; exit 1; }

cd "$APP"

step "0. Pre-flight"
[ "$(git rev-parse --abbrev-ref HEAD)" = "Prod" ] || die "not on branch Prod (on $(git rev-parse --abbrev-ref HEAD))"
grep -q '^APP_ENV=production$' .env            || die "APP_ENV is not production"
grep -qE '^APP_URL=https://(www\.)?corexos\.co\.za/?$' .env || die "APP_URL must be https://corexos.co.za (OutboundMailGuard vetoes all mail otherwise)"
grep -q '^APP_KEY=base64:' .env                || die "APP_KEY missing"
[ -z "$(git status --porcelain | grep -v '^??')" ] || die "live checkout has local modifications — resolve first"
command -v pdftoppm >/dev/null || die "pdftoppm missing (poppler-utils)"
command -v node >/dev/null     || die "node missing"
php -m | grep -qi '^gd$'       || die "PHP gd extension missing"
DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"'"'")
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"'"'")
DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'"'")
echo "HEAD before: $(git rev-parse --short HEAD)   DB: $DB_NAME"

step "1. Data-volume cache directory (rental PDF cache)"
DV_ROOT=$(grep -E '^DATA_VOLUME_ROOT=' .env | cut -d= -f2- | tr -d '"'"'" || true)
DV_ROOT=${DV_ROOT:-/mnt/HC_Volume_103099143}
mkdir -p "$DV_ROOT/corex-data-volume"
chown www-data:www-data "$DV_ROOT/corex-data-volume"
chmod 775 "$DV_ROOT/corex-data-volume"
ls -ld "$DV_ROOT/corex-data-volume"

step "2. Database dump (before anything changes)"
DUMP=/root/${DB_NAME}-pre-promotion-$(date +%F-%H%M).sql.gz
mysqldump --single-transaction --quick --routines --triggers -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$DUMP"
[ -s "$DUMP" ] || die "dump is empty: $DUMP"
echo "dump: $DUMP ($(du -h "$DUMP" | cut -f1))"

step "3. Code + migrations (back to back — do not interrupt)"
git fetch --all --prune
git pull --ff-only origin Prod
echo "HEAD after: $(git rev-parse --short HEAD)"
composer dump-autoload -o --no-interaction 2>/dev/null || true
php artisan migrate --force
[ "$(php artisan migrate:status | grep -c Pending || true)" = "0" ] || die "migrations still pending"

step "4. Reference data (permission keys, global rows)"
php artisan deploy:sync-reference-data

step "5. Front-end build"
npm run build

step "6. Caches, php-fpm, workers"
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan cache:clear
systemctl reload php8.3-fpm
supervisorctl restart \
  corex-worker-live: corex-worker-live-matching: corex-worker-live-mail: \
  corex-worker-live-buyer-matching: corex-worker-live-webhooks: \
  corex-worker-live-thumbnails: corex-worker-live-transcription: \
  corex-worker-p24import: corex-worker-p24images:

step "7a. Commission share-percent correction — DRY RUN"
php artisan deals:correct-share-percent-defect
step "7a. Commission share-percent correction — APPLY"
php artisan deals:correct-share-percent-defect --apply
step "7a. Commission share-percent correction — RE-CHECK (expect: no deals affected)"
php artisan deals:correct-share-percent-defect

step "7b. Imported Stock backfill — DRY RUN"
php artisan properties:backfill-p24-imported
step "7b. Imported Stock backfill — APPLY"
php artisan properties:backfill-p24-imported --apply

step "7c. Core Matches: set aside matches of buyers already Lost — DRY RUN"
php artisan core-matches:backfill-set-aside-lost-buyers --dry-run
step "7c. Core Matches: set aside matches of buyers already Lost — APPLY"
php artisan core-matches:backfill-set-aside-lost-buyers

step "7d. Gallery categories repair — DRY RUN"
php artisan properties:sync-gallery-categories --dry-run
step "7d. Gallery categories repair — APPLY"
php artisan properties:sync-gallery-categories

step "8. Verification"
echo "HEAD:    $(git rev-parse --short HEAD) ($(git log -1 --format=%s | cut -c1-80))"
echo "pending: $(php artisan migrate:status | grep -c Pending || true)"
php artisan route:list --name=corex.properties.imported-stock 2>/dev/null | tail -n +1 | grep -c imported-stock | sed 's/^/imported-stock route: /'
supervisorctl status | awk '{print $1, $2}'
echo "--- last 20 log lines with ERROR/WARNING ---"
grep -hE '\.(ERROR|WARNING|CRITICAL):' storage/logs/laravel.log 2>/dev/null | tail -20 || true
echo
echo "Done. Full transcript: $LOG"
echo "Manual follow-ups (not scripted): Role Manager → tick core_matches.reassign for BM/admin roles;"
echo "  RollupService::refreshPeriod() in Tinker for periods the commission correction touched;"
echo "  open deal 1818's settle screen — checksum green, external payable on the internal side R 0.00."
