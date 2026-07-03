#!/bin/bash
#
# CoreX OFF-BOX backup (AT-163 durability) — restic -> Hetzner Storage Box over SFTP.
# Closes the volume-loss exposure: everything irreplaceable on /dev/sda is copied
# OFF the box, encrypted, nightly, incremental, retention-pruned, restore-tested.
#
# Backs up (fresh each run):
#   - consistent UNCOMPRESSED mysqldumps of nexus_os (LIVE) + hfc_staging (restic dedups)
#   - /corex/storage/app + /corex-staging/storage/app  (WA media, documents, e-sign, viewing packs)
#   - /corex/.env + /corex-staging/.env
#   - /etc/nginx  (vhosts)
#   - the backup scripts + cron themselves (self-restoring)
#
# Publishes machine-readable state under /var/lib/corex-backup for the in-app Backups page
# to READ (the app never shells into restic): status.json, snapshots.json, runs.jsonl.
# These state files are non-secret and are made group-readable by the web user (www-data).
# The repo PASSWORD is NOT here — it stays root-only in /root/.corex-backup/ (0600).
#
# Runs as root from /etc/cron.d/corex-offbox-backup. nice/ionice'd so it never starves the app.
#
set -o pipefail
umask 077

# ---- configurable retention (AT-163: 7 daily / 4 weekly / 6 monthly) -----------------
KEEP_DAILY=${KEEP_DAILY:-7}
KEEP_WEEKLY=${KEEP_WEEKLY:-4}
KEEP_MONTHLY=${KEEP_MONTHLY:-6}
RUNLOG_KEEP=${RUNLOG_KEEP:-365}      # rolling operational run-history line cap
# --------------------------------------------------------------------------------------

LOG=/var/log/corex-offbox-backup.log
STATEDIR=/var/lib/corex-backup
HEALTH="$STATEDIR/last-success"      # epoch seconds of last fully-successful run
STATUS="$STATEDIR/status.json"       # current state (for Admin surfacing / health guard)
RUNLOG="$STATEDIR/runs.jsonl"        # append-only run history (the app reads this)
SNAPS="$STATEDIR/snapshots.json"     # restic snapshot list, refreshed each run (app reads; never shells restic)
RUNDIR=/mnt/HC_Volume_103099143/backup-staging   # transient DB dumps (volume has ~60G free); deleted after upload
LOCK=/var/run/corex-offbox-backup.lock
HEALTHSCRIPT=/usr/local/bin/corex-backup-health.sh
WWWGROUP=www-data                    # web app (php-fpm) group — may READ the non-secret state files

NICE="nice -n 15 ionice -c2 -n7"

. /root/.corex-backup/restic.env
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE RESTIC_CACHE_DIR RESTIC_COMPRESSION

# state dir is traversable+readable by the web group (NON-secret status only; password lives elsewhere)
mkdir -p "$STATEDIR"; chown root:"$WWWGROUP" "$STATEDIR" 2>/dev/null; chmod 750 "$STATEDIR"

START_EPOCH=$(date +%s)
# metrics captured from the restic run (populated on success)
M_FILES=0; M_PROCESSED=""; M_ADDED=""; M_STORED=""; M_SNAPID=""

log(){ echo "[$(date '+%F %T')] $*" >> "$LOG"; }

publish(){ # make a state file readable by the web group (still not world-writable)
  chown root:"$WWWGROUP" "$1" 2>/dev/null; chmod 640 "$1" 2>/dev/null
}

json_str(){ echo "$1" | tr '\n' ' ' | sed 's/\\/\\\\/g; s/"/\\"/g'; }

write_status(){ # state message
  local st="$1"; shift
  local msg="$*"
  local last="never"
  [ -f "$HEALTH" ] && last="$(cat "$HEALTH")"
  printf '{"state":"%s","message":"%s","last_success_epoch":"%s","updated":"%s","repo":"%s","retention":"%sd/%sw/%sm","schedule":"nightly 03:30"}\n' \
    "$st" "$(json_str "$msg")" "$last" "$(date '+%F %T')" "$RESTIC_REPOSITORY" "$KEEP_DAILY" "$KEEP_WEEKLY" "$KEEP_MONTHLY" > "$STATUS"
  publish "$STATUS"
}

append_run(){ # state message  — one JSON line into the rolling history
  local st="$1"; shift; local msg="$*"
  local dur=$(( $(date +%s) - START_EPOCH ))
  printf '{"ts":"%s","epoch":%s,"state":"%s","duration_s":%s,"files":%s,"processed":"%s","added":"%s","stored":"%s","snapshot":"%s","message":"%s"}\n' \
    "$(date '+%F %T')" "$(date +%s)" "$st" "$dur" "${M_FILES:-0}" "$M_PROCESSED" "$M_ADDED" "$M_STORED" "$M_SNAPID" "$(json_str "$msg")" >> "$RUNLOG"
  # rolling cap (operational log, not user data)
  if [ "$(wc -l < "$RUNLOG" 2>/dev/null || echo 0)" -gt "$RUNLOG_KEEP" ]; then
    tail -n "$RUNLOG_KEEP" "$RUNLOG" > "$RUNLOG.tmp" && mv "$RUNLOG.tmp" "$RUNLOG"
  fi
  publish "$RUNLOG"
}

fail(){ # message
  log "ERROR $*"
  write_status FAIL "$*"
  append_run FAIL "$*"
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

# ---- 2) restic backup (capture output for metrics AND the log) -----------------------
OUTF=$(mktemp)
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
  > "$OUTF" 2>&1
BRC=$?
cat "$OUTF" >> "$LOG"

# transient dumps are now off-box — remove them
rm -f "$RUNDIR"/*.sql

if [ "$BRC" -ne 0 ]; then rm -f "$OUTF"; fail "restic backup returned rc=$BRC"; fi

# parse metrics from the restic summary
M_FILES=$(grep -oE 'processed [0-9]+ files' "$OUTF" | grep -oE '[0-9]+' | head -1)
M_PROCESSED=$(grep -oE 'processed [0-9]+ files, [0-9.]+ [KMGT]iB' "$OUTF" | sed -E 's/.*files, //' | head -1)
M_ADDED=$(grep -oE 'Added to the repository: [0-9.]+ [KMGT]iB' "$OUTF" | sed -E 's/.*repository: //' | head -1)
M_STORED=$(grep -oE '\([0-9.]+ [KMGT]iB stored\)' "$OUTF" | tr -d '()' | sed 's/ stored//' | head -1)
M_SNAPID=$(grep -oE 'snapshot [0-9a-f]+ saved' "$OUTF" | awk '{print $2}' | head -1)
rm -f "$OUTF"

# ---- 3) retention prune --------------------------------------------------------------
$NICE restic forget --tag nightly --prune \
  --keep-daily "$KEEP_DAILY" --keep-weekly "$KEEP_WEEKLY" --keep-monthly "$KEEP_MONTHLY" \
  >>"$LOG" 2>&1
FRC=$?
[ "$FRC" -eq 0 ] || log "WARN restic forget/prune rc=$FRC (backup itself succeeded)"

# ---- 4) refresh the snapshot list for the app (app never shells into restic) ---------
if restic snapshots --json > "$SNAPS.tmp" 2>>"$LOG" && [ -s "$SNAPS.tmp" ]; then
  mv "$SNAPS.tmp" "$SNAPS"; publish "$SNAPS"
else
  rm -f "$SNAPS.tmp"; log "WARN could not refresh snapshots.json"
fi

# ---- 5) record success ---------------------------------------------------------------
date +%s > "$HEALTH"; chmod 640 "$HEALTH"; publish "$HEALTH"
write_status OK "backup complete; retention ${KEEP_DAILY}d/${KEEP_WEEKLY}w/${KEEP_MONTHLY}m"
append_run OK "snapshot ${M_SNAPID:-?} added ${M_ADDED:-?} (stored ${M_STORED:-?})"
log "OK off-box backup complete (files=${M_FILES} added=${M_ADDED} stored=${M_STORED} snap=${M_SNAPID})"
exit 0
