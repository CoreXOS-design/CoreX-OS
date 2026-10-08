#!/usr/bin/env bash
# =============================================================================
# CoreX OS — Deploy Script (DEPLOY-1 v2)
#
# Replaces the legacy four-line deploy with a single safe, ordered pipeline:
# pre-flight → off-server backup → maintenance → pull → storage permissions →
# migrate → reference-seed → build → cache+opcache → queue restart → verify →
# up. Aborts on any failure; the backup taken in step 2 is the rollback source.
#
# FAILURE POLICY — what happens to maintenance mode (2026-10-07):
#   Maintenance is released by an EXIT trap, decided by how far the deploy got
#   (MAINT_POLICY below) — never by whichever line happened to fail:
#     steps 1-3   nothing has changed yet      → released automatically.
#     steps 4-9   code pulled / DB being changed → HELD ON on purpose (a site
#                 running new code on a half-migrated schema is worse than a
#                 503); the failure report prints the rollback commands.
#     step 10+    migrated, built, cached      → the site ALWAYS comes back up.
#                 A worker or verify problem is reported loudly (exit 3/4)
#                 instead of locking users out.
#   Exit codes: 0 clean · 1 hard failure (see on_error) · 2 usage ·
#               3 deployed + site up, but queue workers NOT confirmed healthy ·
#               4 deployed + site up, but a post-deploy verification FAILED.
#   Why workers need care: while the site is in maintenance, every `queue:work
#   --max-time=N` worker exits 0 about 1.5 s after it starts (Laravel's paused
#   loop calls stopIfNecessary() without a start time, so the max-time test is
#   hrtime-since-host-boot ≥ N) and supervisor respawns it, hundreds of times a
#   minute for the whole window. `supervisorctl restart` racing that storm
#   intermittently exits 7 ("ERROR (abnormal termination)"). STEP 10 therefore
#   tolerates that exit code, verifies by polling supervisor with a bounded
#   retry, and the workers are re-verified as STABLE after the site is up
#   (no storm then). See docs/DEPLOY.md §4a.
#
# Usage:
#   /corex-staging/scripts/deploy.sh staging
#   /corex/scripts/deploy.sh production
#
# Prerequisites — see DEPLOY.md §"One-time server setup":
#   - mysql-client installed (for mysqldump)
#   - rsync, jq installed
#   - /etc/hfc-deploy.env exists (mode 0600). Required keys:
#       BACKUP_MODE="offsite"   # or "local" for staging-only.
#     Required IF BACKUP_MODE=offsite:
#       BACKUP_STORAGEBOX_USER=u123456
#       BACKUP_STORAGEBOX_HOST=u123456.your-storagebox.de
#       BACKUP_STORAGEBOX_PATH=/home/backups/hfc
#     Optional (otherwise app's DB_USERNAME / DB_PASSWORD from .env are used):
#       MYSQL_BACKUP_USER=hfc_backup
#       MYSQL_BACKUP_PASSWORD=<strong pw>
#   - SSH key registered with the Storage Box (~/.ssh/storagebox_ed25519)
#     [skip if BACKUP_MODE=local]
#   - AT-359: production deploys ALSO require a recent successful run of the
#     independent AT-163 off-box restic job (/etc/cron.d/corex-offbox-backup,
#     nightly 03:30) — checked via /var/lib/corex-backup/status.json
#     (last_success_epoch within 30h). This is a SEPARATE mechanism from
#     BACKUP_MODE/BACKUP_STORAGEBOX_* above (which is this script's own,
#     currently-unprovisioned direct-SFTP path) and is the one that actually
#     gates production — see STEP 1f below.
#   - Passwordless sudo for the deploy user on:
#       /bin/systemctl reload php8.2-fpm
#       /bin/systemctl reload nginx
#       /usr/bin/supervisorctl
#       /bin/systemctl restart hfc-queue* / corex-worker* (if present)
#       /bin/chown -R www-data:www-data storage bootstrap/cache   (STEP 5, 2026-09-28)
#       /bin/chmod -R ug+rwX storage bootstrap/cache               (STEP 5, 2026-09-28)
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
    # Post hfc→corex server rename (2026-06-06): staging lives at /corex-staging,
    # prod at /corex. DB names + backup dirs intentionally keep the hfc name.
    DIR="/corex-staging"
    BRANCH="Staging"
    DB_NAME_DEFAULT="hfc_staging"
    EXPECT_APP_ENV="staging"
else
    DIR="/corex"
    BRANCH="main"
    DB_NAME_DEFAULT="hfc_prod"
    EXPECT_APP_ENV="production"
fi

START_TS=$(date +%Y%m%d-%H%M%S)
# Where the pre-deploy dumps are written (a Staging dump is ~190 MB and every deploy
# adds one — on the small root disk they filled it to 90%, 2026-10-08). Resolution:
#   1. BACKUP_DIR from the caller's environment / $DEPLOY_ENV_FILE (explicit wins), else
#   2. <data volume>/corex-backups/pre-deploy/<env> when the data volume is mounted, else
#   3. the legacy folder on the root disk.
# BACKUP_KEEP_LATEST = how many dumps of THIS database to keep (default 5); the prune
# runs only after a dump has been written AND passed gzip -t (see STEP 2).
BACKUP_FALLBACK_DIR="/var/backups/hfc"
BACKUP_DATA_VOLUME="${BACKUP_DATA_VOLUME:-/mnt/HC_Volume_103099143}"
BACKUP_KEEP_LATEST="${BACKUP_KEEP_LATEST:-5}"
BACKUP_DIR_EXPLICIT=0
if [[ -n "${BACKUP_DIR:-}" ]]; then
    BACKUP_DIR_EXPLICIT=1
elif mountpoint -q "$BACKUP_DATA_VOLUME" 2>/dev/null; then
    BACKUP_DIR="${BACKUP_DATA_VOLUME}/corex-backups/pre-deploy/${ENV_NAME}"
else
    BACKUP_DIR="$BACKUP_FALLBACK_DIR"
fi
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

# prune_pre_deploy_backups <dir> <db> <keep> <just_taken_file> [dry_run=0]
# Keep the newest <keep> dumps of <db> in <dir>; remove older ones. Touches ONLY regular
# files named exactly "<db>-pre-deploy-YYYYMMDD-HHMMSS.sql.gz" (this script's own naming):
# never the *-LATEST symlink, never another database's dumps, never any other file.
# Never removes <just_taken_file>. A bad <keep> (not a whole number >= 1) prunes nothing.
prune_pre_deploy_backups() {
    local dir="$1" db="$2" keep="$3" just_taken="$4" dry="${5:-0}"
    local prefix="${db}-pre-deploy-" f base stamp jt i=0 removed=0
    local -a matched=() victims=()
    if ! [[ "$keep" =~ ^[0-9]+$ ]] || (( keep < 1 )); then
        warn "BACKUP_KEEP_LATEST='$keep' is not a whole number >= 1 — pruning skipped"
        return 0
    fi
    if [[ ! -s "$just_taken" ]]; then
        warn "Pruning skipped: the dump just taken is missing or empty ($just_taken)"
        return 0
    fi
    jt="$(readlink -f "$just_taken")"
    while IFS= read -r f; do
        base="${f##*/}"
        [[ "$base" == "$prefix"* ]] || continue
        stamp="${base#"$prefix"}"
        [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}\.sql\.gz$ ]] || continue
        matched+=("$f")
    done < <(find "$dir" -maxdepth 1 -type f -name "${prefix}*.sql.gz" | LC_ALL=C sort -r)
    for f in ${matched[@]+"${matched[@]}"}; do
        i=$((i + 1))
        (( i <= keep )) && continue
        [[ "$(readlink -f "$f")" == "$jt" ]] && continue
        victims+=("$f")
    done
    for f in ${victims[@]+"${victims[@]}"}; do
        if [[ "$dry" == "1" ]]; then
            log "  [dry-run] would prune: $f"
        else
            rm -f -- "$f" && removed=$((removed + 1))
        fi
    done
    if [[ "$dry" == "1" ]]; then
        ok "Retention (dry-run): ${#matched[@]} dump(s) of $db, keep newest $keep, would prune ${#victims[@]}"
    else
        ok "Retention: ${#matched[@]} dump(s) of $db found, kept newest $keep, pruned $removed"
    fi
}

CURRENT_STEP="0 / not yet started"
MAINT_MODE_ON=0
# What the EXIT trap does with maintenance mode (see FAILURE POLICY in the header):
#   release            — nothing has changed yet (steps 1-3): lift it.
#   hold               — code pulled / DB being changed (steps 4-9): leave it ON.
#   release-with-warning — migrated + built (step 10+): lift it, however we got here.
MAINT_POLICY="release"
BACKUP_FILE=""
PREV_SHA=""
NEW_SHA=""
WARNINGS=()            # post-point-of-no-return problems that did NOT stop the deploy
VERIFY_FAILURES=()     # STEP 11 checks that failed (site is still brought up)
FAILURE_REPORTED=0     # set by on_error; lets on_exit say so when set -e fired where on_error cannot

# Lift maintenance mode. Always run from $DIR so it also works from a trap.
release_maintenance() {
    if ( cd "$DIR" && php artisan up ); then
        MAINT_MODE_ON=0
        return 0
    fi
    return 1
}

# Record a problem that must be loud in the final summary but must NOT abort.
note_problem() {
    WARNINGS+=("$*")
    warn "$*"
}

print_rollback_help() {
    if [[ -n "$BACKUP_FILE" && -s "$BACKUP_FILE" ]]; then
        log ""
        log "🔁 ROLLBACK (database restore from the pre-deploy backup):"
        log "    cd $DIR"
        # Restore using the SAME backup user/password (resolved from .env or
        # MYSQL_BACKUP_USER override). MYSQL_PWD avoids leaking the password
        # in the process list. If the backup user lacks privileges to
        # DROP/CREATE tables on restore, use a privileged user manually.
        log "    MYSQL_PWD=\"\$BACKUP_PASSWORD\" gunzip -c \"$BACKUP_FILE\" | mysql --user=\"${BACKUP_USER:-<user>}\" \"${DB_NAME:-<db>}\""
        if [[ -n "$PREV_SHA" && -n "$NEW_SHA" && "$PREV_SHA" != "$NEW_SHA" ]]; then
            log "    git reset --hard $PREV_SHA"
            log "    composer install --no-dev --optimize-autoloader"
            log "    sudo systemctl reload php8.2-fpm"
        fi
        log "    php artisan up"
        log ""
        if [[ "${BACKUP_MODE:-offsite}" == "offsite" ]]; then
            log "    Off-server copy: ${BACKUP_STORAGEBOX_HOST:-?}:${BACKUP_STORAGEBOX_PATH:-?}/$(basename "$BACKUP_FILE" 2>/dev/null || true)"
        else
            log "    ⚠ NO off-server copy (BACKUP_MODE=local). The only backup is the local file above —"
            log "      if the host disk dies, the backup is gone with it."
        fi
    fi
    log ""
    log "See DEPLOY.md §Rollback for the full procedure."
}

on_error() {
    local exit_code=$1 line=$2
    FAILURE_REPORTED=1
    echo "" | tee -a "$LOG_FILE"
    log "════════════════════════════════════════════════════════════"
    log "❌ DEPLOY FAILED at step '${CURRENT_STEP}'"
    log "   Script line:  $line"
    log "   Exit code:    $exit_code"
    if (( MAINT_MODE_ON )); then
        if [[ "$MAINT_POLICY" == "hold" ]]; then
            log "   Maintenance: STILL ON (held on purpose — the database and/or code may be half-changed). Users see the 503 page."
        else
            log "   Maintenance: will be LIFTED automatically on exit — nothing left to protect users from."
            log "   ⚠ The site will be UP after this failure. Review '${CURRENT_STEP}' above before trusting this deploy."
        fi
    fi
    if [[ -n "$BACKUP_FILE" && -s "$BACKUP_FILE" ]]; then
        print_rollback_help
    else
        if (( MAINT_MODE_ON )) && [[ "$MAINT_POLICY" == "hold" ]]; then
            log ""
            log "🔁 NO DB backup taken yet — only code/cache changes. Bring up with:"
            log "    cd $DIR && php artisan up"
        fi
        log ""
        log "See DEPLOY.md §Rollback for the full procedure."
    fi
    log "════════════════════════════════════════════════════════════"
    exit "$exit_code"
}

# EXIT trap — runs on EVERY way out of the script (success, `exit`, set -e abort,
# Ctrl-C, SIGTERM, ssh hang-up) and is the ONLY place maintenance is released on
# failure. Policy, not line number, decides (see MAINT_POLICY / header).
on_exit() {
    local rc=$?
    trap - EXIT ERR
    set +e
    # set -e can fire inside a function, where the ERR trap (no `set -E`) does not
    # run — so on_error never printed. Say so here rather than exit silently.
    # Exit 3/4 are the deliberate "landed, needs a human" codes, 129/130/143 signals.
    if (( rc != 0 && rc != 3 && rc != 4 && ! FAILURE_REPORTED )); then
        log ""
        log "❌ DEPLOY STOPPED at step '${CURRENT_STEP}' (exit $rc) — no detailed failure report; see the output above."
    fi
    if (( MAINT_MODE_ON )); then
        if [[ "$MAINT_POLICY" == "hold" ]]; then
            log "⚠ Maintenance mode left ON deliberately (step '${CURRENT_STEP}'): the database/code may be half-changed."
            log "  Roll back (above) or finish by hand, then: cd $DIR && php artisan up"
        elif release_maintenance; then
            if (( rc != 0 )); then
                log "⚠ Maintenance LIFTED on exit (step '${CURRENT_STEP}', exit $rc). The site is UP — do not assume this deploy is healthy."
            else
                log "  ✓ Maintenance lifted on exit"
            fi
        else
            log "❌ COULD NOT LIFT MAINTENANCE MODE — users still see the 503 page. Run NOW: cd $DIR && php artisan up"
            (( rc != 0 )) || rc=1
        fi
    fi
    exit "$rc"
}
trap 'on_error $? $LINENO' ERR
trap on_exit EXIT
# Turn signals into a normal exit so on_exit always runs (a dropped ssh session is
# SIGHUP — it used to leave the site in maintenance with nothing logged).
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

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

# Reusable: read a key from a Laravel-style .env file. Strips surrounding
# single OR double quotes. Returns the empty string if the key isn't set.
read_env_var() {
    local file="$1" key="$2"
    grep -E "^${key}=" "$file" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

ACTUAL_APP_ENV=$(read_env_var "$DIR/.env" APP_ENV)
[[ "$ACTUAL_APP_ENV" == "$EXPECT_APP_ENV" ]] || fail ".env APP_ENV='$ACTUAL_APP_ENV' but expected '$EXPECT_APP_ENV' for this deploy target."

# 1e. Resolve the database credentials the BACKUP step will use.
# Priority order (DEPLOY-2):
#   1. MYSQL_BACKUP_USER / MYSQL_BACKUP_PASSWORD from /etc/hfc-deploy.env
#      — dedicated backup user with the right privileges (best practice).
#   2. DB_USERNAME / DB_PASSWORD from the app's .env — the user the app
#      already uses (e.g. `nexus`). This is the default because the deploy
#      script already knows the app root, and the app user is guaranteed
#      to exist + have access to the app's database.
# NEVER falls back to root: requiring root coupled the deploy to the
# MySQL admin password which is often different from the app password
# (and rotating root would silently break deploys).
APP_DB_USERNAME=$(read_env_var "$DIR/.env" DB_USERNAME)
APP_DB_PASSWORD=$(read_env_var "$DIR/.env" DB_PASSWORD)
APP_DB_DATABASE=$(read_env_var "$DIR/.env" DB_DATABASE)

BACKUP_USER="${MYSQL_BACKUP_USER:-$APP_DB_USERNAME}"
BACKUP_PASSWORD="${MYSQL_BACKUP_PASSWORD:-$APP_DB_PASSWORD}"
# Prefer .env's DB_DATABASE over the deploy-script default (handles the
# case where the .env was customised to a non-standard DB name).
if [[ -n "$APP_DB_DATABASE" ]]; then DB_NAME="$APP_DB_DATABASE"; fi

[[ -n "$BACKUP_USER" ]] || fail "Cannot resolve backup DB user (neither MYSQL_BACKUP_USER nor DB_USERNAME is set)."
[[ -n "$BACKUP_PASSWORD" ]] || fail "Cannot resolve backup DB password (neither MYSQL_BACKUP_PASSWORD nor DB_PASSWORD is set)."
[[ -n "$DB_NAME" ]] || fail "Cannot resolve DB name (no DB_DATABASE in .env and no default)."

# 1f. Resolve backup mode + Storage Box requirement.
# BACKUP_MODE values (from /etc/hfc-deploy.env):
#   "offsite" (default) — mysqldump locally then rsync to Hetzner
#                         Storage Box. Required for production.
#   "local"             — mysqldump locally ONLY. Skips the rsync step
#                         AND does not require STORAGEBOX_* vars.
#                         INTENDED FOR STAGING VALIDATION ONLY.
BACKUP_MODE="${BACKUP_MODE:-offsite}"
case "$BACKUP_MODE" in
    offsite|local) ;;
    *) fail "Invalid BACKUP_MODE='$BACKUP_MODE' (expected 'offsite' or 'local')" ;;
esac

# HARD GUARD (AT-359): production deploys require a RECENT, successful
# off-box backup. This no longer keys off BACKUP_MODE/BACKUP_STORAGEBOX_* —
# that direct-SFTP path was never provisioned (vars left blank) and every
# production deploy needed a manual bypass. The REAL off-box backup is the
# independent nightly restic job (AT-163, /usr/local/bin/corex-offbox-backup.sh,
# cron 03:30) — it takes its own fresh mysqldump of nexus_os each run and
# ships it to the Hetzner Storage Box. Gate on ITS proven freshness instead.
if [[ "$ENV_NAME" == "production" ]]; then
    OFFBOX_STATUS_FILE="/var/lib/corex-backup/status.json"
    OFFBOX_MAX_AGE_S=$((30 * 3600))  # 30h — covers the nightly 03:30 run plus slack

    [[ -r "$OFFBOX_STATUS_FILE" ]] \
        || fail "Off-box backup status file missing/unreadable: $OFFBOX_STATUS_FILE (is the AT-163 corex-offbox-backup cron installed?). Production deploys require a recent off-box backup."

    OFFBOX_STATE=$(jq -r '.state // empty' "$OFFBOX_STATUS_FILE" 2>/dev/null)
    OFFBOX_LAST_SUCCESS=$(jq -r '.last_success_epoch // empty' "$OFFBOX_STATUS_FILE" 2>/dev/null)
    [[ "$OFFBOX_LAST_SUCCESS" =~ ^[0-9]+$ ]] \
        || fail "Off-box backup status has no valid last_success_epoch — cannot confirm a successful run ($OFFBOX_STATUS_FILE)."

    OFFBOX_AGE_S=$(( $(date +%s) - OFFBOX_LAST_SUCCESS ))
    if (( OFFBOX_AGE_S > OFFBOX_MAX_AGE_S )); then
        fail "Off-box backup is stale: last success $((OFFBOX_AGE_S / 3600))h ago (state=${OFFBOX_STATE:-unknown}, max $((OFFBOX_MAX_AGE_S / 3600))h). Check corex-offbox-backup (cron 03:30, AT-163) — see /var/log/corex-offbox-backup.log — before retrying."
    fi
    ok "Off-box backup fresh: last success $((OFFBOX_AGE_S / 3600))h ago (state=${OFFBOX_STATE:-unknown})"
fi

# Off-server mode: require all four Storage Box vars up-front.
# Local-only mode: skip — vars need not be set.
if [[ "$BACKUP_MODE" == "offsite" ]]; then
    : "${BACKUP_STORAGEBOX_USER:?BACKUP_STORAGEBOX_USER missing from $DEPLOY_ENV_FILE (or set BACKUP_MODE=local for staging-only)}"
    : "${BACKUP_STORAGEBOX_HOST:?BACKUP_STORAGEBOX_HOST missing from $DEPLOY_ENV_FILE}"
    : "${BACKUP_STORAGEBOX_PATH:?BACKUP_STORAGEBOX_PATH missing from $DEPLOY_ENV_FILE}"
fi

# 1g. Disk space ≥ 2 GiB free on the backup partition?
# The dump goes to $BACKUP_DIR, so the free-space test below is on THAT disk. If the
# data-volume folder cannot be created/written (and nobody asked for it explicitly),
# fall back to the legacy folder rather than abort the deploy.
if ! { mkdir -p "$BACKUP_DIR" 2>/dev/null && [[ -w "$BACKUP_DIR" ]]; }; then
    if (( BACKUP_DIR_EXPLICIT == 0 )) && [[ "$BACKUP_DIR" != "$BACKUP_FALLBACK_DIR" ]]; then
        warn "Cannot write to $BACKUP_DIR — falling back to $BACKUP_FALLBACK_DIR (root disk)"
        BACKUP_DIR="$BACKUP_FALLBACK_DIR"
        mkdir -p "$BACKUP_DIR"
    else
        fail "Backup folder is not writable: $BACKUP_DIR"
    fi
fi
FREE_KB=$(df -P "$BACKUP_DIR" | awk 'NR==2 {print $4}')
(( FREE_KB > 2 * 1024 * 1024 )) || fail "Less than 2 GiB free at $BACKUP_DIR (have $((FREE_KB/1024)) MiB, on $(df -P "$BACKUP_DIR" | awk 'NR==2 {print $1}'))"

PREV_SHA=$(git rev-parse HEAD)
ok "Branch=$BRANCH, dir=$DIR, .env APP_ENV=$EXPECT_APP_ENV, tools=present, $((FREE_KB/1024)) MiB free"
ok "Pre-pull HEAD: $PREV_SHA"
ok "Backup as DB user '$BACKUP_USER' on DB '$DB_NAME' (mode=$BACKUP_MODE)"

# =============================================================================
# STEP 2 — BACKUP (BEFORE touching anything)
# =============================================================================
CURRENT_STEP="2 / backup"
if [[ "$BACKUP_MODE" == "offsite" ]]; then
    step 2 "full DB backup → local + Hetzner Storage Box"
else
    step 2 "full DB backup → LOCAL ONLY (BACKUP_MODE=local; staging-validation use)"
fi

BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}-pre-deploy-${START_TS}.sql.gz"

# 2a. Local mysqldump.
# Dumps as the resolved backup user (MYSQL_BACKUP_USER override OR
# the app's DB_USERNAME from .env — see step 1e). Privileges required:
#   --single-transaction : SELECT on the schema (avoids LOCK TABLES on
#                          InnoDB — the standard app-user grant works).
#   --routines           : SHOW_ROUTINE (MySQL 8) or SELECT on mysql.proc
#                          (5.7). May fail for least-privilege app users;
#                          if it does, grant the user SHOW_ROUTINE or use
#                          MYSQL_BACKUP_USER with broader grants.
#   --triggers           : TRIGGER privilege on the schema. The repo
#                          has at least one trigger
#                          (knowledge_chunks_search_trigger), so this
#                          flag is mandatory — backups MUST be complete.
# Pipefail catches any failure in the gzip-piped chain. The MYSQL_PWD
# env var is used in preference to -p"$pw" so the password never appears
# in the process list (`ps`-visible) on the host.
MYSQL_PWD="$BACKUP_PASSWORD" mysqldump \
    --single-transaction --routines --triggers --quick \
    --add-drop-table --default-character-set=utf8mb4 \
    --ignore-table="${DB_NAME}.failed_jobs" \
    --ignore-table="${DB_NAME}.jobs" \
    --ignore-table="${DB_NAME}.sessions" \
    --ignore-table="${DB_NAME}.cache" \
    --ignore-table="${DB_NAME}.cache_locks" \
    --user="$BACKUP_USER" "$DB_NAME" \
  | gzip > "$BACKUP_FILE"

[[ -s "$BACKUP_FILE" ]] || fail "Local backup is empty: $BACKUP_FILE"
gzip -t "$BACKUP_FILE" || fail "Local backup failed its gzip integrity check: $BACKUP_FILE"
ok "Local backup: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"

# 2b. Off-server to Hetzner Storage Box (SFTP/rsync over SSH port 23).
# Failure here aborts BEFORE any code/DB changes — backups not stored
# off-server are not backups for disaster-recovery purposes.
# DEPLOY-2: conditional on BACKUP_MODE. Staging may run BACKUP_MODE=local
# before the Storage Box is provisioned; production REFUSES local mode
# (already aborted in step 1f).
if [[ "$BACKUP_MODE" == "offsite" ]]; then
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
else
    warn "BACKUP_MODE=local — skipping off-server copy. NOT permitted for production."
fi

# 2c. Convenience symlink so the failure trap and operator can find the
#     pre-deploy backup by name (independent of timestamp).
ln -sfn "$BACKUP_FILE" "${BACKUP_DIR}/${DB_NAME}-pre-deploy-LATEST.sql.gz"
ok "Latest-pointer: ${BACKUP_DIR}/${DB_NAME}-pre-deploy-LATEST.sql.gz"

# 2d. Retention — only now (dump written, gzip -t passed, copied off-server if offsite).
#     A prune problem must never abort a deploy; the dump just taken is never removed.
prune_pre_deploy_backups "$BACKUP_DIR" "$DB_NAME" "$BACKUP_KEEP_LATEST" "$BACKUP_FILE" \
    || warn "Backup retention prune reported a problem (non-fatal) — check $BACKUP_DIR"

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

# From here the code on disk, then the DB, change. A failure in steps 4-9 leaves
# maintenance ON (see FAILURE POLICY in the header); only step 10+ releases it.
MAINT_POLICY="hold"

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
# STEP 5 — STORAGE PERMISSIONS (bug class fix, 2026-09-28)
# =============================================================================
CURRENT_STEP="5 / storage permissions"
step 5 "enforce storage/ + bootstrap/cache/ ownership + permissions"

# 2026-09-28 — storage/app/whistleblow/complaints turned up owned root:www-data
# mode 2755 (no group-write) on QA1, instead of the www-data:www-data 2775
# every other runtime-written storage/app subdir has. Root cause: some artisan
# invocation ran as root (a manual sudo session, most likely) and mkdir()'d
# that specific subdirectory before php-fpm (www-data) ever did — PHP's
# mkdir($path, 0755) calls throughout the app pass an explicit mode with no
# group-write bit, so ANY subdirectory a non-www-data process creates first
# carries this defect, not just this one. www-data (php-fpm) can then never
# write into it again — found via WhistleblowComplaintService::generatePdf()
# throwing "Permission denied" on approve(), blocking every new whistleblow
# complaint. Fixed every deploy, not once: chown/chmod the whole storage/ +
# bootstrap/cache/ tree back to www-data:www-data with group-write, so any
# directory that drifted (by any process, any time) self-heals within one
# deploy cycle rather than persisting indefinitely. Runs before composer
# install / migrate / seeders / caches below, since those can themselves
# write into storage/bootstrap-cache and should see correct ownership too.
#
# 2026-09-28 (Johan) — this step is DELIBERATELY NON-FATAL. Under this
# script's set -e + ERR trap, a bare `sudo chown ...` that fails (the two
# passwordless-sudo grants this needs are documented above but may not be
# provisioned on every host yet) would abort the ENTIRE deploy — turning a
# missing sudo grant into "no deploy can ever complete," which is worse
# than the storage-permission bug this step exists to fix. Wrapped in its
# own if/else instead: a failure here warns (never silently swallowed —
# full output printed) and the deploy continues. The storage-permission
# bug class stays possible on THIS host until the grant is added, but
# nothing else breaks because of it.
#
# 2026-09-28 (follow-up) — plain `sudo` still isn't safe here even wrapped
# in if/else: with the two grants unprovisioned, `sudo` (no `-n`) detects a
# controlling TTY (this script's own `use_pty` sudoers default guarantees
# one) and PROMPTS for a password on it instead of failing — the deploy
# hangs waiting for input nobody is going to type, never reaching the
# else branch at all. `sudo -n` (non-interactive) forces sudo to fail
# immediately whenever it would otherwise prompt, in every invocation
# context (TTY or not), so the if/else below actually gets to run.
if PERM_OUT="$(sudo -n chown -R www-data:www-data storage bootstrap/cache 2>&1 && sudo -n chmod -R ug+rwX storage bootstrap/cache 2>&1)"; then
    ok "storage/ + bootstrap/cache/ → www-data:www-data, group-writable (ug+rwX)"
else
    echo "$PERM_OUT"
    warn "storage/bootstrap-cache ownership+permission enforcement failed — continuing deploy anyway. Add the passwordless-sudo grants in this script's own prerequisites header, then re-run this step manually (or the next deploy)."
fi

# =============================================================================
# STEP 6 — COMPOSER + MIGRATE
# =============================================================================
CURRENT_STEP="6 / composer + migrate"
step 6 "composer install (--no-dev) + php artisan migrate --force"

composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist
ok "Composer dependencies installed"

php artisan migrate --force
ok "Migrations applied"

# =============================================================================
# STEP 7 — REFERENCE SEEDERS (explicit, NEVER db:seed)
# =============================================================================
CURRENT_STEP="7 / reference seeders"
step 7 "run reference seeders explicitly (NEVER db:seed)"

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
    # M6.2-FIX — HFC activity-calendar mappings. See seeder docblock for the
    # incident that drove moving this out of a one-time migration.
    'Database\Seeders\ActivityCalendarMappingSeeder'
    # SPINE-1 — system-default catalogue of instant-action slugs (same
    # table as M6.2 mappings, discriminated by trigger_kind='instant').
    'Database\Seeders\ActivityInstantActionsSeeder'
)
for seeder in "${REF_SEEDERS[@]}"; do
    log "  ⋯ Seeding: $seeder"
    php artisan db:seed --class="$seeder" --force >> "$LOG_FILE" 2>&1 \
        || fail "Reference seeder failed: $seeder (see $LOG_FILE for stderr)"
done
ok "All ${#REF_SEEDERS[@]} reference seeders applied"

# Permissions sync (idempotent — config/corex-permissions.php is the source).
#
# DEPLOY-PERM-SAFE 2026-06-04 — switched from --seed-defaults to
# --merge-defaults. The previous flag forceDelete()'d every row in
# role_permissions and re-inserted from the config defaults, which
# OVERWROTE agency customisations made via the Role Manager. Live
# incident: a user whose role was customised lost her sidebar access
# on deploy because her role's grants reset to defaults.
#
# --merge-defaults (SyncPermissions::mergeRoleDefaults) is additive
# only: for each role it diffs the config-expected key set against
# what's already in role_permissions and INSERTS only the missing
# keys. Existing rows (including customisations) are never touched,
# never updated, never deleted. New permissions added to the catalog
# DO reach roles per config defaults — but customised roles keep
# their customisations.
#
# Use --seed-defaults ONLY for first-time bootstrap or a deliberate
# reset, never for routine deploys.
php artisan corex:sync-permissions --merge-defaults >> "$LOG_FILE" 2>&1
ok "Permission keys synced (additive — customisations preserved)"

# =============================================================================
# STEP 8 — FRONTEND BUILD
# =============================================================================
CURRENT_STEP="8 / frontend build"
step 8 "npm ci + npm run build"

# `npm ci` is reproducible (uses package-lock.json verbatim); `npm install`
# would silently mutate package-lock.
npm ci
npm run build
ok "Frontend bundle rebuilt"

# Storage symlink (idempotent — Laravel skips if already exists).
php artisan storage:link >/dev/null 2>&1 || true
ok "storage:link OK"

# =============================================================================
# STEP 9 — CACHES + OPCACHE
# =============================================================================
CURRENT_STEP="9 / caches + opcache"
step 9 "clear all caches + FPM opcache flush"

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
# STEP 10 — QUEUE WORKERS
#
# POINT OF NO RETURN: the DB is migrated, the build and caches are done. From
# here no failure may keep users out (see FAILURE POLICY in the header), and
# nothing in this step may abort the deploy — a worker problem is recorded with
# note_problem() and surfaced in the summary / exit code instead.
# =============================================================================
CURRENT_STEP="10 / queue workers"
MAINT_POLICY="release-with-warning"
step 10 "signal + restart queue workers"

# --- supervisor helpers ------------------------------------------------------
# Every call is `sudo -n` (never prompts; same reasoning as STEP 5) and only uses
# the `status` and `restart` verbs the sudoers grant in DEPLOY.md §2d already
# covers — a straggler is kicked with `restart <name>`, which also starts a
# FATAL/BACKOFF/STOPPED process, so no `start` grant is needed.
# Tunable from /etc/hfc-deploy.env (or the caller's environment); defaults below.
: "${WORKER_STABLE_SECS:=3}"      # post-up: RUNNING at least this long counts as stable
: "${WORKER_SETTLE_ATTEMPTS:=4}"  # pre-up (site in maintenance): kept short — every poll extends the outage
: "${WORKER_WAIT_ATTEMPTS:=8}"    # post-up (site live, no downtime cost): attempts × interval ≈ 40 s
: "${WORKER_WAIT_INTERVAL:=5}"
WORKER_EXPECTED_COUNT=0
WORKERS_LAST_BAD=""

# Status lines of THIS environment's pool only (AT-357: the supervisord is shared).
pool_status() {
    sudo -n supervisorctl status 2>/dev/null \
        | awk -v p="$WORKER_POOL" '$0 ~ ("^" p "[:-]")' || true
}

# Print "<name> <state>" for every pool process that is NOT in the wanted state.
#   settling : RUNNING or STARTING is fine (pre-up: paused workers churn, so a
#              stable uptime is unprovable while the site is in maintenance).
#   stable   : must be RUNNING for >= WORKER_STABLE_SECS (site is up, no storm).
# A pool that lists fewer programs than before the restart is reported too —
# an empty list must never read as "all healthy".
workers_not_up() {
    local mode="$1" listing count
    listing="$(pool_status)"
    count=0
    if [[ -n "$listing" ]]; then count=$(printf '%s\n' "$listing" | wc -l); fi
    if (( count < WORKER_EXPECTED_COUNT )); then
        echo "(pool) MISSING — only $count of $WORKER_EXPECTED_COUNT programs listed"
    fi
    [[ -z "$listing" ]] && return 0
    printf '%s\n' "$listing" | awk -v mode="$mode" -v minup="$WORKER_STABLE_SECS" '
        {
            name = $1; state = $2; up = -1
            if (match($0, /uptime [0-9]+:[0-9]+:[0-9]+/)) {
                split(substr($0, RSTART + 7, RLENGTH - 7), t, ":")
                up = t[1] * 3600 + t[2] * 60 + t[3]
            } else if ($0 ~ /uptime [0-9]+ days?,/) {
                up = 999999
            }
            if (mode == "settling") { bad = (state != "RUNNING" && state != "STARTING") }
            else                    { bad = (state != "RUNNING" || up < minup) }
            if (bad) print name " " state
        }'
}

# Poll until every pool process is up, kicking stragglers, with a bounded retry.
# Terminal states (FATAL/STOPPED/EXITED/UNKNOWN) are restarted at once; a
# BACKOFF/STARTING/just-started process is left to supervisor's own autorestart
# and only kicked on the last attempt. Returns 0 = all up, 1 = gave up
# (WORKERS_LAST_BAD names what is still down).
await_workers() {
    local mode="$1" attempts="$2" i bad name state kick_out kick_rc final=0
    for (( i = 1; i <= attempts; i++ )); do
        bad="$(workers_not_up "$mode")"
        if [[ -z "$bad" ]]; then WORKERS_LAST_BAD=""; return 0; fi
        (( i == attempts )) && final=1
        log "  ⋯ workers not up yet (attempt $i/$attempts, $mode):"
        printf '%s\n' "$bad" | sed 's/^/        /' | tee -a "$LOG_FILE"
        while IFS=' ' read -r name state _; do
            [[ -n "$name" && "$name" != "(pool)" ]] || continue
            case "$state" in
                FATAL|STOPPED|EXITED|UNKNOWN) ;;
                *) (( final )) || continue ;;
            esac
            kick_rc=0
            kick_out="$(sudo -n supervisorctl restart "$name" 2>&1)" || kick_rc=$?
            printf '%s\n' "$kick_out" | tee -a "$LOG_FILE"
            (( kick_rc == 0 )) || log "        (restart $name exited $kick_rc — re-checked on the next poll)"
        done <<< "$bad"
        sleep "$WORKER_WAIT_INTERVAL"
    done
    bad="$(workers_not_up "$mode")"
    WORKERS_LAST_BAD="$bad"
    [[ -z "$bad" ]]
}

# 10a. Laravel-level signal — workers stop cleanly after their current job.
# Always safe; works even when no host-level worker manager is installed.
if php artisan queue:restart; then
    ok "Laravel queue:restart signal sent"
else
    note_problem "php artisan queue:restart failed — running workers were NOT signalled to reload (host-level restart below still applies)"
fi

# 10b. Host-level worker manager — auto-detect. AT-357: the box's supervisord
# is SHARED across environments (corex-worker-live x2, corex-worker-live-mail,
# corex-worker-live-matching, corex-worker-staging all show up in one
# `supervisorctl status`), so the old broad "corex-worker" prefix match could
# pick ANY of them via `sort -u | head -1` — a staging deploy restarting
# live's worker, and even on live, only the alphabetically-first pool ever
# got restarted (mail/matching silently kept running old code). Match ONLY
# this environment's own pool name(s), and restart every match, not just one.
WORKER_MECHANISM=""
WORKERS_HEALTHY=""    # "yes" only once a manager's workers were verified up
# AT-357 follow-up (env-derive, 2026-08-06): the worker-pool prefix is DERIVED
# from this deploy's already-computed environment (EXPECT_APP_ENV/BRANCH set at
# the top), never hardcoded, so one deploy.sh is correct on every environment.
# Fail safe: if the env cannot be mapped, ABORT rather than risk restarting
# another environment's worker pool.
case "$EXPECT_APP_ENV" in
    production) WORKER_POOL="corex-worker-live" ;;
    staging)    WORKER_POOL="corex-worker-staging" ;;
    *)          fail "Cannot derive worker pool: unexpected EXPECT_APP_ENV='$EXPECT_APP_ENV' (branch=$BRANCH)" ;;
esac
ok "Worker pool (derived from APP_ENV=$EXPECT_APP_ENV): $WORKER_POOL"
if command -v supervisorctl >/dev/null 2>&1; then
    WORKER_LISTING="$(pool_status)"
    SUPER_PROGS=""
    if [[ -n "$WORKER_LISTING" ]]; then
        WORKER_EXPECTED_COUNT=$(printf '%s\n' "$WORKER_LISTING" | wc -l)
        SUPER_PROGS=$(printf '%s\n' "$WORKER_LISTING" | awk '{print $1}' | cut -d: -f1 | sort -u)
    fi
    if [[ -n "$SUPER_PROGS" ]]; then
        # 2026-10-07 — `supervisorctl restart` exits 7 ("ERROR (abnormal
        # termination)") whenever a worker dies inside supervisor's 1 s start
        # window, and while the site is in maintenance EVERY worker does (header).
        # That used to hit `set -e` and abort the deploy here with the site down.
        # The exit code is now logged, never fatal; the verified state below is
        # what decides whether the workers are OK.
        while IFS= read -r prog; do
            restart_rc=0
            restart_out="$(sudo -n supervisorctl restart "${prog}:*" 2>&1)" || restart_rc=$?
            printf '%s\n' "$restart_out" | tee -a "$LOG_FILE"
            if (( restart_rc != 0 )); then
                warn "supervisorctl restart ${prog}:* exited $restart_rc — expected while workers churn in maintenance; verifying below"
            fi
        done <<< "$SUPER_PROGS"
        WORKER_MECHANISM="supervisord programs: $(printf '%s' "$SUPER_PROGS" | tr '\n' ' ')"
        if await_workers settling "$WORKER_SETTLE_ATTEMPTS"; then
            WORKERS_HEALTHY="yes"
            ok "All $WORKER_EXPECTED_COUNT worker processes accepted by supervisor (final stability re-checked after the site is up)"
        else
            note_problem "Queue workers not confirmed after restart (pre-up): $(printf '%s' "$WORKERS_LAST_BAD" | tr '\n' ';')"
        fi
    fi
fi
if [[ -z "$WORKER_MECHANISM" ]] && command -v systemctl >/dev/null 2>&1; then
    SYSTEMD_UNIT=$(sudo -n systemctl list-units --type=service --no-pager --plain 2>/dev/null \
        | awk '{print $1}' \
        | grep -E "^${WORKER_POOL}[a-z0-9.-]*\.service$" \
        | head -1 || true)
    if [[ -n "$SYSTEMD_UNIT" ]]; then
        WORKER_MECHANISM="systemd unit $SYSTEMD_UNIT"
        if sudo -n systemctl restart "$SYSTEMD_UNIT"; then
            WORKERS_HEALTHY="yes"   # re-verified (is-active) after the site is up
        else
            note_problem "systemctl restart $SYSTEMD_UNIT failed — workers may still be running old code"
        fi
    fi
fi
if [[ -z "$WORKER_MECHANISM" ]]; then
    WORKER_MECHANISM="queue:restart only (no host worker manager detected)"
    warn "No supervisord/systemd queue worker found — workers must self-restart via queue:restart signal"
fi
ok "Worker mechanism: $WORKER_MECHANISM"

# =============================================================================
# STEP 11 — VERIFY
#
# Every check runs even if an earlier one failed, and a failure is recorded, not
# fatal: by now the DB is migrated, so locking users out does not undo anything.
# A failed check turns the final result into exit 4 + a loud banner naming it.
# =============================================================================
CURRENT_STEP="11 / verify"
step 11 "verify deployment"

# 11a. HEAD pinned.
CHECK_SHA=$(git rev-parse HEAD) || CHECK_SHA="(unreadable)"
if [[ "$CHECK_SHA" == "$EXPECTED_SHA" ]]; then
    ok "HEAD = $CHECK_SHA (matches origin/$BRANCH)"
else
    VERIFY_FAILURES+=("HEAD drifted after deploy: $CHECK_SHA != $EXPECTED_SHA")
    log "  ✗ HEAD drifted: $CHECK_SHA != $EXPECTED_SHA"
fi

# 11b. Reference tables non-empty. Per DEPLOY-1 decision 5 an empty reference
# table is a failed deploy. Implementation is a small helper PHP that
# bootstraps Laravel and exits non-zero on any empty table.
if php "$DIR/scripts/deploy-verify-reference-tables.php" | tee -a "$LOG_FILE"; then
    ok "Reference tables non-empty"
else
    VERIFY_FAILURES+=("Reference-table check failed (an empty reference table — see the VERIFY output above)")
    log "  ✗ Reference-table check failed"
fi

# 11c. Compiled-view spot check — view:cache in step 9 would have aborted on any
# Blade syntax error, but re-compile once more to be sure the new code paths
# compile against the live data layer.
if php artisan view:cache >/dev/null 2>&1; then
    ok "Compiled views fresh"
else
    VERIFY_FAILURES+=("view:cache re-compile failed — compiled views are broken")
    log "  ✗ view:cache re-compile failed"
fi

# =============================================================================
# STEP 12 — END MAINTENANCE
# =============================================================================
CURRENT_STEP="12 / up"
step 12 "exit maintenance mode"
if release_maintenance; then
    ok "Site is live"
else
    # on_exit retries once more; if that fails too it prints the manual command.
    fail "php artisan up failed"
fi

# 12b. Workers, second look — the site is up now, so paused-worker churn has
# stopped and a RUNNING worker means a genuinely stable one. This is the check
# that actually proves the restart: bounded retry, stragglers kicked, never fatal.
if [[ "$WORKER_MECHANISM" == supervisord* ]]; then
    if await_workers stable "$WORKER_WAIT_ATTEMPTS"; then
        ok "Workers stable after go-live: $WORKER_EXPECTED_COUNT/$WORKER_EXPECTED_COUNT RUNNING (>= ${WORKER_STABLE_SECS}s)"
    else
        WORKERS_HEALTHY=""
        note_problem "Queue workers NOT stable after go-live: $(printf '%s' "$WORKERS_LAST_BAD" | tr '\n' ';') — run: sudo supervisorctl restart <name>"
    fi
elif [[ "$WORKER_MECHANISM" == systemd* ]]; then
    if systemctl is-active --quiet "${WORKER_MECHANISM#systemd unit }"; then
        ok "Worker unit active after go-live"
    else
        WORKERS_HEALTHY=""
        note_problem "Worker unit ${WORKER_MECHANISM#systemd unit } is NOT active after go-live — run: sudo systemctl restart ${WORKER_MECHANISM#systemd unit }"
    fi
fi

# =============================================================================
# STEP 13 — SUMMARY
# =============================================================================
CURRENT_STEP="13 / summary"
DURATION=$SECONDS
TAG="deploy-${ENV_NAME}-${START_TS}"
git tag -a "$TAG" -m "Deploy $NEW_SHA to $ENV_NAME" 2>/dev/null || true

echo "" | tee -a "$LOG_FILE"
log "════════════════════════════════════════════════════════════"
if (( ${#VERIFY_FAILURES[@]} )); then
    log "🚨 DEPLOYED, BUT VERIFICATION FAILED — $ENV_NAME — THE SITE IS UP"
    for f in "${VERIFY_FAILURES[@]}"; do log "   ✗ VERIFY: $f"; done
elif (( ${#WARNINGS[@]} )); then
    log "⚠️  DEPLOY COMPLETED WITH WARNINGS — $ENV_NAME — the site is up"
else
    log "✅ DEPLOY OK — $ENV_NAME"
fi
for w in "${WARNINGS[@]+"${WARNINGS[@]}"}"; do log "   ⚠ WARNING: $w"; done
log "   Commit:          $PREV_SHA → $NEW_SHA"
log "   Tag:             $TAG (local; push manually if desired)"
log "   Backup (local):  $BACKUP_FILE"
if [[ "$BACKUP_MODE" == "offsite" ]]; then
    log "   Backup (remote): ${BACKUP_STORAGEBOX_HOST}:${BACKUP_STORAGEBOX_PATH}/$(basename "$BACKUP_FILE")"
else
    log "   Backup (remote): (none — BACKUP_MODE=local)"
fi
log "   Backup user:     $BACKUP_USER (DB=$DB_NAME)"
log "   Workers:         $WORKER_MECHANISM${WORKERS_HEALTHY:+ — verified healthy}"
log "   Duration:        ${DURATION}s"
log "   Timestamp:       $(date -Iseconds)"
log "════════════════════════════════════════════════════════════"
if [[ "$BACKUP_MODE" == "local" ]]; then
    log ""
    log "⚠ LOCAL-ONLY BACKUP — no off-server copy. NOT permitted for production."
    log "   Provision a Hetzner Storage Box and switch /etc/hfc-deploy.env"
    log "   to BACKUP_MODE=offsite (see DEPLOY.md §2c) before the next prod deploy."
fi
if (( ${#VERIFY_FAILURES[@]} )); then
    log ""
    log "🚨 DECIDE NOW: the site is serving traffic on a deploy whose verification failed."
    print_rollback_help
    exit 4
fi
if (( ${#WARNINGS[@]} )); then
    log ""
    log "⚠ Exit code 3: deploy landed and the site is up, but the warnings above need a human."
    exit 3
fi
