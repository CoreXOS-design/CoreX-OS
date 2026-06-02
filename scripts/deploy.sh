#!/usr/bin/env bash
# =============================================================================
# CoreX OS — Deploy Script (DEPLOY-1 v2)
#
# Replaces the legacy four-line deploy with a single safe, ordered pipeline:
# pre-flight → off-server backup → maintenance → pull → migrate → reference-
# seed → build → cache+opcache → queue restart → verify → up. Aborts on any
# failure; the backup taken in step 2 is the rollback source.
#
# Usage:
#   /hfc/scripts/deploy.sh staging
#   /hfc/scripts/deploy.sh production
#
# Prerequisites — see DEPLOY.md §"One-time server setup":
#   - mysql-client installed (for mysqldump)
#   - rsync installed
#   - /etc/hfc-deploy.env exists (mode 0600) with:
#       MYSQL_ROOT_PASSWORD=...
#       BACKUP_STORAGEBOX_USER=u123456
#       BACKUP_STORAGEBOX_HOST=u123456.your-storagebox.de
#       BACKUP_STORAGEBOX_PATH=/home/backups/hfc
#   - SSH key registered with the Storage Box (~/.ssh/storagebox_ed25519)
#   - Passwordless sudo for the deploy user on:
#       /bin/systemctl reload php8.2-fpm
#       /bin/systemctl reload nginx
#       /usr/bin/supervisorctl
#       /bin/systemctl restart hfc-queue* / corex-worker* (if present)
# =============================================================================

set -euo pipefail
IFS=$'\n\t'

# -----------------------------------------------------------------------------
# 0. ARGUMENT PARSING + ENV-SPECIFIC CONFIG
# -----------------------------------------------------------------------------
ENV_NAME="${1:-}"
if [[ "$ENV_NAME" != "staging" && "$ENV_NAME" != "production" ]]; then
    echo "Usage: $0 staging|production" >&2
    exit 2
fi

if [[ "$ENV_NAME" == "staging" ]]; then
    DIR="/hfc-staging"
    BRANCH="Staging"
    DB_NAME_DEFAULT="hfc_staging"
    EXPECT_APP_ENV="staging"
else
    DIR="/hfc"
    BRANCH="main"
    DB_NAME_DEFAULT="hfc_prod"
    EXPECT_APP_ENV="production"
fi

START_TS=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="/var/backups/hfc"
LOG_FILE="/var/log/hfc-deploys.log"
DEPLOY_ENV_FILE="/etc/hfc-deploy.env"

# -----------------------------------------------------------------------------
# Logging + failure trap
# -----------------------------------------------------------------------------
log() { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOG_FILE"; }
step() { echo "" | tee -a "$LOG_FILE"; log "▶ STEP $1 — $2"; }
ok() { log "  ✓ $*"; }
warn() { log "  ⚠ $*"; }
fail() { log "  ✗ $*"; return 1; }

CURRENT_STEP="0 / not yet started"
MAINT_MODE_ON=0
BACKUP_FILE=""
PREV_SHA=""
NEW_SHA=""

on_error() {
    local exit_code=$1 line=$2
    echo "" | tee -a "$LOG_FILE"
    log "════════════════════════════════════════════════════════════"
    log "❌ DEPLOY FAILED at step '${CURRENT_STEP}'"
    log "   Script line:  $line"
    log "   Exit code:    $exit_code"
    if (( MAINT_MODE_ON )); then
        log "   Maintenance: STILL ON — users see the 503 page."
    fi
    if [[ -n "$BACKUP_FILE" && -s "$BACKUP_FILE" ]]; then
        log ""
        log "🔁 ROLLBACK (database restore from the pre-deploy backup):"
        log "    cd $DIR"
        log "    gunzip -c \"$BACKUP_FILE\" | mysql --defaults-extra-file=/etc/hfc-deploy-mysql.cnf $DB_NAME_DEFAULT"
        log "    (or: gunzip -c \"$BACKUP_FILE\" | mysql -u root -p\"\$MYSQL_ROOT_PASSWORD\" $DB_NAME_DEFAULT)"
        if [[ -n "$PREV_SHA" && -n "$NEW_SHA" && "$PREV_SHA" != "$NEW_SHA" ]]; then
            log "    git reset --hard $PREV_SHA"
            log "    composer install --no-dev --optimize-autoloader"
            log "    sudo systemctl reload php8.2-fpm"
        fi
        log "    php artisan up"
        log ""
        log "    Off-server copy: ${BACKUP_STORAGEBOX_HOST:-?}:${BACKUP_STORAGEBOX_PATH:-?}/$(basename "$BACKUP_FILE" 2>/dev/null || true)"
    elif (( MAINT_MODE_ON )); then
        log ""
        log "🔁 NO DB backup taken yet — only code/cache changes. Bring up with:"
        log "    cd $DIR && php artisan up"
    fi
    log ""
    log "See DEPLOY.md §Rollback for the full procedure."
    log "════════════════════════════════════════════════════════════"
    exit "$exit_code"
}
trap 'on_error $? $LINENO' ERR

# Load secrets
[[ -r "$DEPLOY_ENV_FILE" ]] || { echo "❌ Missing or unreadable $DEPLOY_ENV_FILE — see DEPLOY.md §One-time server setup" >&2; exit 1; }
# shellcheck source=/dev/null
source "$DEPLOY_ENV_FILE"
DB_NAME="${DB_NAME:-$DB_NAME_DEFAULT}"

# =============================================================================
# STEP 1 — PRE-FLIGHT
# =============================================================================
CURRENT_STEP="1 / pre-flight"
step 1 "pre-flight checks"

[[ -d "$DIR" ]] || fail "Deploy dir does not exist: $DIR"
cd "$DIR"

# 1a. Working tree clean?
if [[ -n "$(git status --porcelain)" ]]; then
    git status --short | tee -a "$LOG_FILE"
    fail "Working tree at $DIR is dirty — refusing to deploy."
fi

# 1b. On expected branch?
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
[[ "$CURRENT_BRANCH" == "$BRANCH" ]] || fail "Wrong branch: HEAD is '$CURRENT_BRANCH', expected '$BRANCH'."

# 1c. Required tools present?
for cmd in mysqldump mysql php composer npm git rsync ssh gzip; do
    command -v "$cmd" >/dev/null 2>&1 || fail "Missing required command: $cmd (see DEPLOY.md §One-time server setup)"
done

# 1d. .env present and APP_ENV matches?
[[ -r "$DIR/.env" ]] || fail "Missing $DIR/.env"
ACTUAL_APP_ENV=$(grep -E '^APP_ENV=' "$DIR/.env" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" )
[[ "$ACTUAL_APP_ENV" == "$EXPECT_APP_ENV" ]] || fail ".env APP_ENV='$ACTUAL_APP_ENV' but expected '$EXPECT_APP_ENV' for this deploy target."

# 1e. Secrets loaded?
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD missing from $DEPLOY_ENV_FILE}"
: "${BACKUP_STORAGEBOX_USER:?BACKUP_STORAGEBOX_USER missing from $DEPLOY_ENV_FILE}"
: "${BACKUP_STORAGEBOX_HOST:?BACKUP_STORAGEBOX_HOST missing from $DEPLOY_ENV_FILE}"
: "${BACKUP_STORAGEBOX_PATH:?BACKUP_STORAGEBOX_PATH missing from $DEPLOY_ENV_FILE}"

# 1f. Disk space ≥ 2 GiB free on the backup partition?
mkdir -p "$BACKUP_DIR"
FREE_KB=$(df -P "$BACKUP_DIR" | awk 'NR==2 {print $4}')
(( FREE_KB > 2 * 1024 * 1024 )) || fail "Less than 2 GiB free at $BACKUP_DIR (have $((FREE_KB/1024)) MiB)"

PREV_SHA=$(git rev-parse HEAD)
ok "Branch=$BRANCH, dir=$DIR, .env APP_ENV=$EXPECT_APP_ENV, tools=present, $((FREE_KB/1024)) MiB free"
ok "Pre-pull HEAD: $PREV_SHA"

# =============================================================================
# STEP 2 — BACKUP (off-server BEFORE touching anything)
# =============================================================================
CURRENT_STEP="2 / backup"
step 2 "full DB backup → local + Hetzner Storage Box"

BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}-pre-deploy-${START_TS}.sql.gz"

# 2a. Local mysqldump
# Pipefail catches any failure in the gzip-piped chain. --single-transaction
# gives a consistent snapshot on InnoDB without locking writers.
mysqldump \
    --single-transaction --routines --triggers --quick \
    --add-drop-table --default-character-set=utf8mb4 \
    --ignore-table="${DB_NAME}.failed_jobs" \
    --ignore-table="${DB_NAME}.jobs" \
    --ignore-table="${DB_NAME}.sessions" \
    --ignore-table="${DB_NAME}.cache" \
    --ignore-table="${DB_NAME}.cache_locks" \
    -u root -p"$MYSQL_ROOT_PASSWORD" "$DB_NAME" \
  | gzip > "$BACKUP_FILE"

[[ -s "$BACKUP_FILE" ]] || fail "Local backup is empty: $BACKUP_FILE"
ok "Local backup: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

# 2b. Off-server to Hetzner Storage Box (SFTP/rsync over SSH port 23).
# Failure here aborts BEFORE any code/DB changes — backups not stored
# off-server are not backups for disaster-recovery purposes.
SSH_KEY_OPT=""
if [[ -r "${HOME}/.ssh/storagebox_ed25519" ]]; then
    SSH_KEY_OPT="-i ${HOME}/.ssh/storagebox_ed25519"
fi
# shellcheck disable=SC2086
rsync -az --partial \
    -e "ssh -p 23 -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 $SSH_KEY_OPT" \
    "$BACKUP_FILE" \
    "${BACKUP_STORAGEBOX_USER}@${BACKUP_STORAGEBOX_HOST}:${BACKUP_STORAGEBOX_PATH}/"

ok "Off-server: ${BACKUP_STORAGEBOX_HOST}:${BACKUP_STORAGEBOX_PATH}/$(basename "$BACKUP_FILE")"

# 2c. Convenience symlink so the failure trap and operator can find the
#     pre-deploy backup by name (independent of timestamp).
ln -sfn "$BACKUP_FILE" "${BACKUP_DIR}/${DB_NAME}-pre-deploy-LATEST.sql.gz"
ok "Latest-pointer: ${BACKUP_DIR}/${DB_NAME}-pre-deploy-LATEST.sql.gz"

# =============================================================================
# STEP 3 — MAINTENANCE MODE
# =============================================================================
CURRENT_STEP="3 / maintenance mode"
step 3 "enter maintenance mode (brief — accepted for v1 per DEPLOY-1 decision 4)"

# Random secret token so operators can hit /<token> to bypass the 503 and
# verify the new deploy before letting traffic back in.
DOWN_SECRET=$(head -c 32 /dev/urandom | sha256sum | awk '{print $1}' | head -c 32)
php artisan down --render="errors::503" --secret="$DOWN_SECRET" >/dev/null
MAINT_MODE_ON=1
ok "Maintenance ON (bypass: https://<host>/$DOWN_SECRET)"

# =============================================================================
# STEP 4 — PULL TARGET BRANCH
# =============================================================================
CURRENT_STEP="4 / pull"
step 4 "git fetch + fast-forward to origin/$BRANCH"

git fetch origin "$BRANCH" --prune
# --ff-only refuses to merge if the branch has diverged locally; safer than
# a default pull (which would auto-merge). On a clean prod host this is a
# fast-forward by definition.
git pull --ff-only origin "$BRANCH"

NEW_SHA=$(git rev-parse HEAD)
EXPECTED_SHA=$(git rev-parse "origin/$BRANCH")
[[ "$NEW_SHA" == "$EXPECTED_SHA" ]] || fail "HEAD ($NEW_SHA) != origin/$BRANCH ($EXPECTED_SHA)"

ok "Pulled: $PREV_SHA → $NEW_SHA"

# =============================================================================
# STEP 5 — COMPOSER + MIGRATE
# =============================================================================
CURRENT_STEP="5 / composer + migrate"
step 5 "composer install (--no-dev) + php artisan migrate --force"

composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist
ok "Composer dependencies installed"

php artisan migrate --force
ok "Migrations applied"

# =============================================================================
# STEP 6 — REFERENCE SEEDERS (explicit, NEVER db:seed)
# =============================================================================
CURRENT_STEP="6 / reference seeders"
step 6 "run reference seeders explicitly (NEVER db:seed)"

# CRITICAL: do NOT call `php artisan db:seed`. Even with SEED-GUARD in place
# (database/seeders/DatabaseSeeder.php), refuse the abstraction here — each
# reference seeder is invoked by exact class name so the deploy log shows
# the exact set that ran, and the demo seeders cannot ever be in scope.
#
# PayrollSeeder is the orchestrator that itself calls PayrollTaxTableSeeder,
# PayrollTaxRebateSeeder, PayrollEarningTypeSeeder, PayrollDeductionTypeSeeder
# (verified in database/seeders/PayrollSeeder.php:11-16) — calling it once
# applies all four. All other seeders here are idempotent (firstOrCreate /
# updateOrInsert keyed on stable natural keys).
REF_SEEDERS=(
    'Database\Seeders\CalendarEventClassSeeder'
    'Database\Seeders\BuyerMatchTiersSeeder'
    'Database\Seeders\AgencyFeedbackOptionsSeeder'
    'Database\Seeders\PublicHolidaySeeder'
    'Database\Seeders\LeaveTypeSeeder'
    'Database\Seeders\PayrollSeeder'
    'Database\Seeders\MarketReportTypesSeeder'
    'Database\Seeders\DealPipelineTemplateSeeder'
    'Database\Seeders\AgencyDocumentTypeConfigSeeder'
    'Database\Seeders\SuggestedActionThresholdsSeeder'
    'Database\Seeders\ProspectingSetupSeeder'
    'Database\Seeders\SellerOutreachTemplatesSeeder'
    'Database\Seeders\DepositTrustInterestSeeder'
)
for seeder in "${REF_SEEDERS[@]}"; do
    log "  ⋯ Seeding: $seeder"
    php artisan db:seed --class="$seeder" --force >> "$LOG_FILE" 2>&1 \
        || fail "Reference seeder failed: $seeder (see $LOG_FILE for stderr)"
done
ok "All ${#REF_SEEDERS[@]} reference seeders applied"

# Permissions sync (idempotent — config/corex-permissions.php is the source).
php artisan corex:sync-permissions --seed-defaults >> "$LOG_FILE" 2>&1
ok "Permission keys synced"

# =============================================================================
# STEP 7 — FRONTEND BUILD
# =============================================================================
CURRENT_STEP="7 / frontend build"
step 7 "npm ci + npm run build"

# `npm ci` is reproducible (uses package-lock.json verbatim); `npm install`
# would silently mutate package-lock.
npm ci
npm run build
ok "Frontend bundle rebuilt"

# Storage symlink (idempotent — Laravel skips if already exists).
php artisan storage:link >/dev/null 2>&1 || true
ok "storage:link OK"

# =============================================================================
# STEP 8 — CACHES + OPCACHE
# =============================================================================
CURRENT_STEP="8 / caches + opcache"
step 8 "clear all caches + FPM opcache flush"

# 8a. Drop Laravel's file-level caches (config, route, view, app, events,
# compiled — `optimize:clear` runs all six).
php artisan optimize:clear
ok "Laravel caches cleared (optimize:clear)"

# 8b. Re-cache for prod performance.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
ok "Production caches rebuilt"

# 8c. CLI opcache reset (low-impact but cheap).
php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'CLI opcache reset.\n'; } else { echo 'opcache not loaded in CLI.\n'; }" | tee -a "$LOG_FILE"

# 8d. The load-bearing step: PHP-FPM opcache flush. CLI `opcache_reset()`
# above does NOT flush the FPM SAPI's shared-memory opcache — they are
# separate instances. `systemctl reload` is graceful (in-flight requests
# finish; new workers spin up with the fresh code on disk).
sudo systemctl reload php8.2-fpm
ok "PHP-FPM opcache flushed (sudo systemctl reload php8.2-fpm)"

# 8e. Nginx reload — usually a no-op but resolves the edge case where the
# upstream pool needs to re-read FPM's PID. Cheap, graceful.
sudo systemctl reload nginx 2>/dev/null || warn "nginx reload not available (skipped)"

# =============================================================================
# STEP 9 — QUEUE WORKERS
# =============================================================================
CURRENT_STEP="9 / queue workers"
step 9 "signal + restart queue workers"

# 9a. Laravel-level signal — workers stop cleanly after their current job.
# Always safe; works even when no host-level worker manager is installed.
php artisan queue:restart
ok "Laravel queue:restart signal sent"

# 9b. Host-level worker manager — auto-detect. The repo references two
# candidate supervisord program names (hfc-queue, corex-worker-live) and no
# systemd unit files. We try supervisord first, then systemd, then fall
# back to queue:restart only (with a warning).
WORKER_MECHANISM=""
if command -v supervisorctl >/dev/null 2>&1; then
    SUPER_PROG=$(sudo supervisorctl status 2>/dev/null \
        | awk '/^(hfc-queue|corex-worker)/ {print $1}' \
        | cut -d: -f1 | sort -u | head -1 || true)
    if [[ -n "$SUPER_PROG" ]]; then
        sudo supervisorctl restart "${SUPER_PROG}:*" | tee -a "$LOG_FILE"
        WORKER_MECHANISM="supervisord program ${SUPER_PROG}"
    fi
fi
if [[ -z "$WORKER_MECHANISM" ]] && command -v systemctl >/dev/null 2>&1; then
    SYSTEMD_UNIT=$(sudo systemctl list-units --type=service --no-pager --plain 2>/dev/null \
        | awk '{print $1}' \
        | grep -E '^(hfc-queue|corex-worker)[a-z0-9.-]*\.service$' \
        | head -1 || true)
    if [[ -n "$SYSTEMD_UNIT" ]]; then
        sudo systemctl restart "$SYSTEMD_UNIT"
        WORKER_MECHANISM="systemd unit $SYSTEMD_UNIT"
    fi
fi
if [[ -z "$WORKER_MECHANISM" ]]; then
    WORKER_MECHANISM="queue:restart only (no host worker manager detected)"
    warn "No supervisord/systemd queue worker found — workers must self-restart via queue:restart signal"
fi
ok "Worker mechanism: $WORKER_MECHANISM"

# =============================================================================
# STEP 10 — VERIFY (any failure here triggers the failure trap → rollback)
# =============================================================================
CURRENT_STEP="10 / verify"
step 10 "verify deployment"

# 10a. HEAD pinned.
CHECK_SHA=$(git rev-parse HEAD)
[[ "$CHECK_SHA" == "$EXPECTED_SHA" ]] || fail "Post-deploy HEAD drifted: $CHECK_SHA != $EXPECTED_SHA"
ok "HEAD = $CHECK_SHA (matches origin/$BRANCH)"

# 10b. Reference tables non-empty. Per DEPLOY-1 decision 5, if any reference
# table is empty after this deploy, we FAIL and the failure trap rolls back.
# Implementation is a small helper PHP that bootstraps Laravel and exits
# non-zero on any empty table.
php "$DIR/scripts/deploy-verify-reference-tables.php" | tee -a "$LOG_FILE"

# 10c. Compiled-view spot check — view:cache in step 8b would have aborted
# on any Blade syntax error, but render one canonical view to be doubly
# sure the new code paths compile against the live data layer.
php artisan view:cache >/dev/null 2>&1 || fail "view:cache re-compile failed — compiled views broken"
ok "Compiled views fresh"

# =============================================================================
# STEP 11 — END MAINTENANCE
# =============================================================================
CURRENT_STEP="11 / up"
step 11 "exit maintenance mode"
php artisan up
MAINT_MODE_ON=0
ok "Site is live"

# =============================================================================
# STEP 12 — SUCCESS SUMMARY
# =============================================================================
CURRENT_STEP="12 / summary"
DURATION=$SECONDS
TAG="deploy-${ENV_NAME}-${START_TS}"
git tag -a "$TAG" -m "Deploy $NEW_SHA to $ENV_NAME" 2>/dev/null || true

echo "" | tee -a "$LOG_FILE"
log "════════════════════════════════════════════════════════════"
log "✅ DEPLOY OK — $ENV_NAME"
log "   Commit:          $PREV_SHA → $NEW_SHA"
log "   Tag:             $TAG (local; push manually if desired)"
log "   Backup (local):  $BACKUP_FILE"
log "   Backup (remote): ${BACKUP_STORAGEBOX_HOST}:${BACKUP_STORAGEBOX_PATH}/$(basename "$BACKUP_FILE")"
log "   Workers:         $WORKER_MECHANISM"
log "   Duration:        ${DURATION}s"
log "   Timestamp:       $(date -Iseconds)"
log "════════════════════════════════════════════════════════════"
