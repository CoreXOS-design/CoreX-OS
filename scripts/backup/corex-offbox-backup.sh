#!/bin/bash
#
# CoreX OFF-BOX backup (AT-163 durability) — restic -> Hetzner Storage Box over SFTP.
# Closes the volume-loss exposure: everything irreplaceable on /dev/sda is copied
# OFF the box, encrypted, nightly, incremental, retention-pruned, restore-tested.
#
# Backs up (fresh each run):
#   - consistent gzipped mysqldumps of nexus_os (LIVE) + hfc_staging
#   - /corex/storage/app + /corex-staging/storage/app  (WA media, documents, e-sign, viewing packs)
#   - /corex/.env + /corex-staging/.env
#   - /etc/nginx  (vhosts)
#   - the backup scripts + cron themselves (self-restoring)
#
# Runs as root from /etc/cron.d/corex-offbox-backup. nice/ionice'd so it never starves the app.
# Repo password + repo URL are root-only in /root/.corex-backup/ (never in git, never printed).
#
set -o pipefail
umask 077

# ---- configurable retention (AT-163: 7 daily / 4 weekly / 6 monthly) -----------------
KEEP_DAILY=${KEEP_DAILY:-7}
KEEP_WEEKLY=${KEEP_WEEKLY:-4}
KEEP_MONTHLY=${KEEP_MONTHLY:-6}
# --------------------------------------------------------------------------------------

LOG=/var/log/corex-offbox-backup.log
STATEDIR=/var/lib/corex-backup
HEALTH="$STATEDIR/last-success"      # epoch seconds of last fully-successful run
STATUS="$STATEDIR/status.json"       # machine-readable status (for Admin surfacing / health guard)
RUNDIR=/mnt/HC_Volume_103099143/backup-staging   # transient DB dumps (volume has ~60G free); deleted after upload
LOCK=/var/run/corex-offbox-backup.lock
HEALTHSCRIPT=/usr/local/bin/corex-backup-health.sh

NICE="nice -n 15 ionice -c2 -n7"

. /root/.corex-backup/restic.env
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE RESTIC_CACHE_DIR

mkdir -p "$STATEDIR"; chmod 700 "$STATEDIR"

log(){ echo "[$(date '+%F %T')] $*" >> "$LOG"; }

write_status(){ # state message
  local st="$1"; shift
  local msg="$*"
  local last="never"
  [ -f "$HEALTH" ] && last="$(cat "$HEALTH")"
  printf '{"state":"%s","message":"%s","last_success_epoch":"%s","updated":"%s","repo":"%s"}\n' \
    "$st" "$(echo "$msg" | tr '"' "'" )" "$last" "$(date '+%F %T')" "$RESTIC_REPOSITORY" > "$STATUS"
  chmod 600 "$STATUS"
}

fail(){ # message
  log "ERROR $*"
  write_status FAIL "$*"
  # Best-effort alert (log/status file is the primary, app-independent channel).
  [ -x "$HEALTHSCRIPT" ] && "$HEALTHSCRIPT" alert "off-box backup FAILED: $*" >>"$LOG" 2>&1
  rm -f "$RUNDIR"/*.sql 2>/dev/null
  exit 1
}

# ---- single-instance lock ------------------------------------------------------------
exec 9>"$LOCK" || { log "cannot open lock"; exit 1; }
flock -n 9 || { log "another backup run in progress — aborting"; exit 1; }

log "START off-box backup (restic $(restic version 2>/dev/null | awk '{print $2}'))"
write_status RUNNING "backup in progress"

mkdir -p "$RUNDIR"; chmod 700 "$RUNDIR"

# ---- 1) fresh consistent DB dumps into the run (UNCOMPRESSED on purpose) --------------
# Stored as plain .sql so restic's content-defined chunking dedups them night-to-night
# (a gzip stream would change wholesale on any row change → no dedup). restic compresses
# them at rest via RESTIC_COMPRESSION=auto. Integrity = mysqldump rc + the trailing
# "-- Dump completed" marker mysqldump writes only on a clean finish.
for DB in nexus_os hfc_staging; do
  OUT="$RUNDIR/${DB}.sql"
  mysqldump -u root --single-transaction --quick --routines --triggers --events "$DB" > "$OUT" 2>>"$LOG"
  rc=$?
  { [ "$rc" -eq 0 ] && [ -s "$OUT" ] && tail -c 200 "$OUT" | grep -q "Dump completed"; } \
    || fail "mysqldump $DB failed or incomplete (rc=$rc)"
  log "dumped $DB ($(du -h "$OUT" | cut -f1))"
done

# ---- 2) restic backup ----------------------------------------------------------------
$NICE restic backup \
  --tag nightly --tag corex \
  --exclude-caches \
  --exclude '/corex/storage/framework' \
  --exclude '/corex-staging/storage/framework' \
  "$RUNDIR" \
  /corex/storage/app \
  /corex-staging/storage/app \
  /corex/.env \
  /corex-staging/.env \
  /etc/nginx \
  /usr/local/bin/corex-offbox-backup.sh \
  /usr/local/bin/corex-backup-health.sh \
  /usr/local/bin/nexus_os-nightly-backup.sh \
  /etc/cron.d/corex-offbox-backup \
  >>"$LOG" 2>&1
BRC=$?

# transient dumps are now off-box — remove them
rm -f "$RUNDIR"/*.sql

[ "$BRC" -eq 0 ] || fail "restic backup returned rc=$BRC"

# ---- 3) retention prune --------------------------------------------------------------
$NICE restic forget --tag nightly --prune \
  --keep-daily "$KEEP_DAILY" --keep-weekly "$KEEP_WEEKLY" --keep-monthly "$KEEP_MONTHLY" \
  >>"$LOG" 2>&1
FRC=$?
[ "$FRC" -eq 0 ] || log "WARN restic forget/prune rc=$FRC (backup itself succeeded)"

# ---- 4) record success ---------------------------------------------------------------
date +%s > "$HEALTH"; chmod 600 "$HEALTH"
write_status OK "backup complete; retention ${KEEP_DAILY}d/${KEEP_WEEKLY}w/${KEEP_MONTHLY}m"
log "OK off-box backup complete"
exit 0
