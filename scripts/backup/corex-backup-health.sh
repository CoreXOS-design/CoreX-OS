#!/bin/bash
#
# CoreX off-box backup HEALTH GUARD (AT-163 durability).
#
# Two modes:
#   corex-backup-health.sh check            -> alert if last success is stale (> STALE_HOURS) or missing
#   corex-backup-health.sh alert "message"  -> raise an alert now (called by the backup script on failure)
#   (add --dry-run to either to print without sending mail)
#
# Alert channels (per AT-163 spec — "log-visible OR mail"):
#   PRIMARY  : a loud line in /var/log/corex-offbox-backup.log + status.json state=ALERT
#              (app-INDEPENDENT — survives a volume-death that also takes the app/DB down)
#   SECONDARY: best-effort email to Johan via the Laravel app mailer (wrapped; never fatal)
#
# Runs from /etc/cron.d/corex-offbox-backup (mid-morning, so a missed nightly is caught same day).
#
set -o pipefail
umask 077

STALE_HOURS=${STALE_HOURS:-36}          # AT-163: alert if the repo goes stale > 36h (default)
MAIL_TO=${MAIL_TO:-johan@hfcoastal.co.za}
APP_DIR=/corex
WWWGROUP=www-data

LOG=/var/log/corex-offbox-backup.log
STATEDIR=/var/lib/corex-backup
HEALTH="$STATEDIR/last-success"
STATUS="$STATEDIR/status.json"

# Configurable threshold from the LIVE app DB (nexus_os = the box-global source of
# truth, set on the in-app Backups page). Falls back to the default above.
_db_hours=$(mysql -u root -N -e "SELECT value FROM nexus_os.performance_settings WHERE \`key\`='backup_stale_alarm_hours' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1" 2>/dev/null)
if [[ "$_db_hours" =~ ^[0-9]+$ ]] && [ "$_db_hours" -ge 1 ]; then STALE_HOURS="$_db_hours"; fi

# status.json is non-secret and is read by the web app (www-data group).
publish(){ chown root:"$WWWGROUP" "$1" 2>/dev/null; chmod 640 "$1" 2>/dev/null; }

# repo URL for the alert body (root-only env)
[ -f /root/.corex-backup/restic.env ] && . /root/.corex-backup/restic.env 2>/dev/null

MODE="${1:-check}"; shift 2>/dev/null
DRYRUN=0
ARGS=()
for a in "$@"; do [ "$a" = "--dry-run" ] && DRYRUN=1 || ARGS+=("$a"); done
MSG="${ARGS[*]}"

log(){ echo "[$(date '+%F %T')] HEALTH $*" >> "$LOG"; }

write_status_alert(){ # message
  local last="never"; [ -f "$HEALTH" ] && last="$(cat "$HEALTH")"
  printf '{"state":"ALERT","message":"%s","last_success_epoch":"%s","updated":"%s","stale_hours_threshold":"%s"}\n' \
    "$(echo "$1" | tr '"' "'" )" "$last" "$(date '+%F %T')" "$STALE_HOURS" > "$STATUS"
  publish "$STATUS"
}

send_mail(){ # subject body
  local subj="$1"; local body="$2"
  if [ "$DRYRUN" -eq 1 ]; then
    log "DRY-RUN would email $MAIL_TO: $subj"
    return 0
  fi
  # Best-effort app mailer; time-boxed; never fatal to the health check.
  timeout 60 sudo -u www-data env HOME=/tmp php "$APP_DIR/artisan" tinker --execute="
    try {
      \Illuminate\Support\Facades\Mail::raw('$(echo "$body" | tr "'" ' ' )', function(\$m){
        \$m->to('$MAIL_TO')->subject('$(echo "$subj" | tr "'" ' ')');
      });
      echo 'MAIL_SENT';
    } catch (\Throwable \$e) { echo 'MAIL_FAILED: '.\$e->getMessage(); }
  " >>"$LOG" 2>&1 && log "mail attempt done ($MAIL_TO)" || log "mail attempt failed/timed out (non-fatal)"
}

raise(){ # message
  local m="$*"
  log "ALERT $m"
  write_status_alert "$m"
  send_mail "[CoreX] OFF-BOX BACKUP ALERT" "CoreX off-box backup health alert ($(hostname)) at $(date '+%F %T'):

$m

Repo: ${RESTIC_REPOSITORY:-unknown}
Last success: $( [ -f "$HEALTH" ] && date -d "@$(cat "$HEALTH")" '+%F %T' || echo never )
Log: $LOG on $(hostname).

This is the app-independent health guard. If the data volume itself is lost, this mail may not send — check the box directly."
}

case "$MODE" in
  alert)
    raise "${MSG:-unspecified failure}"
    exit 0
    ;;
  check)
    if [ ! -f "$HEALTH" ]; then
      raise "no successful off-box backup has EVER been recorded (missing $HEALTH)"
      exit 2
    fi
    now=$(date +%s); last=$(cat "$HEALTH"); age=$(( now - last )); max=$(( STALE_HOURS * 3600 ))
    if [ "$age" -gt "$max" ]; then
      raise "off-box backup is STALE — last success $(date -d "@$last" '+%F %T') ($(( age/3600 ))h ago, threshold ${STALE_HOURS}h)"
      exit 2
    fi
    log "OK last success $(date -d "@$last" '+%F %T') ($(( age/3600 ))h ago, within ${STALE_HOURS}h)"
    # Clear any prior ALERT once the condition resolves (keeps the Admin surface truthful).
    printf '{"state":"OK","message":"health check passed (%sh ago, within %sh)","last_success_epoch":"%s","updated":"%s"}\n' \
      "$(( age/3600 ))" "$STALE_HOURS" "$last" "$(date '+%F %T')" > "$STATUS"; publish "$STATUS"
    exit 0
    ;;
  *)
    echo "usage: $0 {check|alert <message>} [--dry-run]" >&2
    exit 64
    ;;
esac
