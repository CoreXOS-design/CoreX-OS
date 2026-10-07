#!/usr/bin/env bash
# =============================================================================
# deploy-sh-failure-paths.sh — proves how scripts/deploy.sh behaves when things
# fail AT OR AFTER the queue-worker step, without a real Staging/live deploy.
#
#   bash scripts/tests/deploy-sh-failure-paths.sh                 # test scripts/deploy.sh
#   bash scripts/tests/deploy-sh-failure-paths.sh --old <file>    # run the storm scenario
#                                                                 # against an OLD deploy.sh
#
# HOW: each scenario copies deploy.sh into a throwaway sandbox, rewrites ONLY its
# four absolute paths (DIR, LOG_FILE, DEPLOY_ENV_FILE, BACKUP_DIR) to sandbox
# paths, and runs the real script text with fake `git php composer npm sudo
# supervisorctl systemctl mysqldump …` first on PATH. The fakes keep a state file
# (maintenance on/off, supervisor program states) the assertions read back.
# Nothing here touches /corex*, supervisor, systemd, MySQL or any real service.
#
# WHAT THIS PROVES: the script's control flow — maintenance released/held by
# policy, exit codes, loud naming of what failed, bounded worker retry, foreign
# worker pools never touched. WHAT IT CANNOT PROVE: real supervisord timing (the
# fake models the storm's EFFECT — exit 7 / BACKOFF / FATAL — not its cause; the
# cause itself is reproduced separately against real Laravel, see the report) or
# real artisan/composer/npm behaviour.
# =============================================================================
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_UNDER_TEST="${DEPLOY_UNDER_TEST:-$HERE/../deploy.sh}"   # DEPLOY_UNDER_TEST=<file> runs the suite against another copy (negative control)
OLD_MODE=0
if [[ "${1:-}" == "--old" ]]; then OLD_MODE=1; SCRIPT_UNDER_TEST="$2"; fi
ROOT="$(mktemp -d "${TMPDIR:-/tmp}/deploy-test.XXXXXX")"
trap 'rm -rf "$ROOT"' EXIT

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "    ✓ $*"; }
bad()  { FAIL=$((FAIL+1)); echo "    ✗ $*"; }
expect_eq()       { if [[ "$2" == "$3" ]]; then pass "$1 ($2)"; else bad "$1: got '$2', want '$3'"; fi; }
expect_log()      { if grep -qF -- "$2" "$SB/deploys.log"; then pass "$1"; else bad "$1 — log lacks: $2"; fi; }
expect_no_log()   { if grep -qF -- "$2" "$SB/deploys.log"; then bad "$1 — log unexpectedly has: $2"; else pass "$1"; fi; }
maint_state()     { [[ -e "$SB/state/maint_on" ]] && echo ON || echo OFF; }

# -----------------------------------------------------------------------------
# Sandbox + fakes
# -----------------------------------------------------------------------------
make_sandbox() { # name
    SB="$ROOT/$1"; mkdir -p "$SB"/{bin,app/scripts,state,backups}
    printf 'APP_ENV=staging\nDB_USERNAME=u\nDB_PASSWORD=p\nDB_DATABASE=hfc_staging\n' > "$SB/app/.env"
    printf 'BACKUP_MODE="local"\n' > "$SB/hfc-deploy.env"
    : > "$SB/deploys.log"; echo aaaa1111 > "$SB/state/head"; echo 0 > "$SB/state/head_calls"; echo 0 > "$SB/state/viewcache_calls"; echo 0 > "$SB/state/up_calls"
    : > "$SB/state/sup.calls"; : > "$SB/state/php.calls"
    local i
    { # 13 staging programs (same shape as the real pool) + foreign live pool, some FATAL as on the real box
      echo "corex-worker-staging:corex-worker-staging_00 RUNNING"
      for i in 0 1 2 3; do echo "corex-worker-staging-p24images:corex-worker-staging-p24images_0$i RUNNING"; done
      for i in 0 1 2 3 4 5 6 7; do echo "corex-worker-staging-p24import:corex-worker-staging-p24import_0$i RUNNING"; done
      echo "corex-worker-live:corex-worker-live_00 FATAL"
      echo "corex-worker-live-mail:corex-worker-live-mail_00 FATAL"
      echo "corex-worker-demo:corex-worker-demo_00 RUNNING"
    } | awk -v n="$(date +%s)" '{print $1, $2, n-3600}' > "$SB/state/sup.state"
    write_fakes
    # the script under test, with ONLY its four absolute paths redirected
    sed -e "s#^    DIR=\"/corex-staging\"#    DIR=\"$SB/app\"#" \
        -e "s#^LOG_FILE=\"/var/log/hfc-deploys.log\"#LOG_FILE=\"$SB/deploys.log\"#" \
        -e "s#^DEPLOY_ENV_FILE=\"/etc/hfc-deploy.env\"#DEPLOY_ENV_FILE=\"$SB/hfc-deploy.env\"#" \
        -e "s#^BACKUP_DIR=\"/var/backups/hfc\"#BACKUP_DIR=\"$SB/backups\"#" \
        "$SCRIPT_UNDER_TEST" > "$SB/deploy-under-test.sh"
    local c
    for c in 'DIR="'"$SB"'/app"' 'LOG_FILE="'"$SB"'/deploys.log"' 'DEPLOY_ENV_FILE="'"$SB"'/hfc-deploy.env"' 'BACKUP_DIR="'"$SB"'/backups"'; do
        grep -qF -- "$c" "$SB/deploy-under-test.sh" || { echo "HARNESS ERROR: path rewrite missing: $c"; exit 99; }
    done
}

write_fakes() {
    local b="$SB/bin"
    cat > "$b/sudo" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "-n" ]] && shift
case "${1:-}" in chown|chmod) exit 0 ;; esac
exec "$@"
EOF
    cat > "$b/systemctl" <<'EOF'
#!/usr/bin/env bash
case "${1:-}" in
  list-units) exit 0 ;;
  is-active)  exit "${FAKE_SYSTEMD_ACTIVE_RC:-0}" ;;
  *)          exit 0 ;;
esac
EOF
    for c in composer npm rsync ssh mysql; do printf '#!/usr/bin/env bash\nexit 0\n' > "$b/$c"; done
    printf '#!/usr/bin/env bash\necho "-- fake dump"\n' > "$b/mysqldump"
    cat > "$b/git" <<'EOF'
#!/usr/bin/env bash
S="$SB/state"
case "$1 ${2:-}" in
  "status --porcelain") exit 0 ;;
  "rev-parse --abbrev-ref") echo Staging ;;
  "rev-parse HEAD")
      n=$(( $(cat "$S/head_calls") + 1 )); echo "$n" > "$S/head_calls"
      # call 1 = pre-flight, 2 = step 4, 3 = step 11a
      if [[ "$n" -ge 3 && "${FAKE_HEAD_DRIFT:-0}" == 1 ]]; then echo cccc3333; else cat "$S/head"; fi ;;
  "rev-parse origin/Staging") echo bbbb2222 ;;
  "fetch origin") exit 0 ;;
  "pull --ff-only") echo bbbb2222 > "$S/head" ;;
  "tag -a") echo "$*" >> "$S/tags" ;;
  *) exit 0 ;;
esac
EOF
    cat > "$b/php" <<'EOF'
#!/usr/bin/env bash
S="$SB/state"
echo "$*" >> "$S/php.calls"
if [[ "${1:-}" == */deploy-verify-reference-tables.php ]]; then
    if [[ "${FAKE_REFTABLES_RC:-0}" != 0 ]]; then echo "VERIFY_FAIL: table agencies is empty"; exit "$FAKE_REFTABLES_RC"; fi
    echo "VERIFY_OK 11 tables"; exit 0
fi
[[ "${1:-}" == "-r" ]] && { echo "CLI opcache reset."; exit 0; }
case "${2:-}" in
  down) touch "$S/maint_on" ;;
  up)   n=$(( $(cat "$S/up_calls") + 1 )); echo "$n" > "$S/up_calls"
        if [[ "$n" -le "${FAKE_UP_FAIL_COUNT:-0}" ]]; then echo "up failed (fake)" >&2; exit 1; fi
        rm -f "$S/maint_on"
        # leaving maintenance ends the storm: supervisor's respawned workers are stable from now
        awk -v n="$(date +%s)" '{ if ($2=="RUNNING") $3=n; print }' "$S/sup.state" > "$S/sup.tmp" && mv "$S/sup.tmp" "$S/sup.state" ;;
  queue:restart) exit "${FAKE_QUEUE_RESTART_RC:-0}" ;;
  migrate) exit "${FAKE_MIGRATE_RC:-0}" ;;
  db:seed) [[ -n "${FAKE_SIGNAL_AT_SEED:-}" ]] && kill -"$FAKE_SIGNAL_AT_SEED" "$(cat "$S/deploy.pid")"; sleep 0.2 ;;
  view:cache)
      n=$(( $(cat "$S/viewcache_calls") + 1 )); echo "$n" > "$S/viewcache_calls"
      [[ "$n" == "${FAKE_VIEWCACHE_FAIL_ON:-0}" ]] && exit 1 ;;
esac
exit 0
EOF
    cat > "$b/supervisorctl" <<'EOF'
#!/usr/bin/env bash
# Fake supervisor. State: "<group:name> <STATE> <since-epoch>".
# Scenario knobs (env): FAKE_SUP = clean | storm_blip | stuck | missing | nopool | blind
S="$SB/state"; F="$S/sup.state"; now=$(date +%s)
mode="${FAKE_SUP:-clean}"
case "${1:-}" in
  status)
      [[ "$mode" == "nopool" ]] && exit 0
      [[ "$mode" == "blind" ]] && exit 1
      # supervisor autorestart: BACKOFF -> STARTING -> RUNNING over successive looks
      awk -v n="$now" '{ if ($2=="BACKOFF") $2="STARTING"; else if ($2=="STARTING") { $2="RUNNING"; $3=n } print }' "$F" > "$S/sup.tmp" && mv "$S/sup.tmp" "$F"
      while read -r name state since; do
          [[ "$mode" == "missing" && "$name" == *p24import_07 && -e "$S/restarted" ]] && continue
          up=$(( now - since )); [[ -e "$S/maint_on" && "$state" == RUNNING ]] && up=0   # churn: never old while paused
          case "$state" in
            RUNNING)  printf '%-72s %-9s pid 1234, uptime %d:%02d:%02d\n' "$name" RUNNING $((up/3600)) $((up%3600/60)) $((up%60)) ;;
            STARTING) printf '%-72s %-9s\n' "$name" STARTING ;;
            BACKOFF)  printf '%-72s %-9s Exited too quickly (process log may have details)\n' "$name" BACKOFF ;;
            FATAL)    printf '%-72s %-9s Exited too quickly (process log may have details)\n' "$name" FATAL ;;
            *)        printf '%-72s %-9s\n' "$name" "$state" ;;
          esac
      done < "$F" ;;
  restart)
      echo "restart $2" >> "$S/sup.calls"; touch "$S/restarted"; rc=0
      [[ -n "${FAKE_SIGNAL_AT_RESTART:-}" ]] && kill -"$FAKE_SIGNAL_AT_RESTART" "$(cat "$S/deploy.pid")"; tmp="$S/sup.tmp"; : > "$tmp"
      while read -r name state since; do
          match=0
          case "$2" in
            *:\*) [[ "${name%%:*}" == "${2%%:*}" ]] && match=1 ;;
            *)    [[ "$name" == "$2" ]] && match=1 ;;
          esac
          if (( match )); then
              echo "$name: stopped"
              if [[ "$mode" == "storm_blip" && "$name" == *p24import_01 && ! -e "$S/blip_done" ]]; then
                  touch "$S/blip_done"; echo "$name: ERROR (abnormal termination)"; rc=7; state=BACKOFF
              elif [[ "$mode" == "stuck" && "$name" == *p24import_01 ]]; then
                  echo "$name: ERROR (abnormal termination)"; rc=7; state=FATAL
              else
                  echo "$name: started"; state=RUNNING; since=$now
              fi
          fi
          echo "$name $state $since" >> "$tmp"
      done < "$F"
      mv "$tmp" "$F"; exit $rc ;;
  *) exit 0 ;;
esac
EOF
    chmod +x "$b"/*
}

# run_scenario <fake-sup-mode> [VAR=val ...]  → sets RC; log in $SB/deploys.log
run_scenario() {
    local supmode="$1"; shift
    local envs=("$@")
    (
        cd "$SB/app" || exit 98
        # shellcheck disable=SC2030,SC2031
        export SB PATH="$SB/bin:$PATH" FAKE_SUP="$supmode" \
               WORKER_WAIT_INTERVAL=1 WORKER_WAIT_ATTEMPTS=4 WORKER_STABLE_SECS=2
        for kv in "${envs[@]+"${envs[@]}"}"; do export "${kv?}"; done
        bash "$SB/deploy-under-test.sh" staging > "$SB/stdout.txt" 2>&1 &
        echo $! > "$SB/state/deploy.pid"
        wait $!
    )
    RC=$?
}

foreign_untouched() {
    if grep -qE "live|demo" "$SB/state/sup.calls"; then bad "a restart touched another environment's pool: $(grep -E 'live|demo' "$SB/state/sup.calls" | head -2 | tr '\n' ' ')"
    else pass "no restart ever touched corex-worker-live*/demo (AT-357 pool isolation)"; fi
}

# =============================================================================
if (( OLD_MODE )); then
    echo "== OLD script ($SCRIPT_UNDER_TEST) in the exact incident shape: restart exits 7 'abnormal termination' =="
    make_sandbox old_storm; run_scenario storm_blip
    echo "    exit code: $RC   maintenance afterwards: $(maint_state)"
    grep -E "DEPLOY FAILED|Exit code|Maintenance:" "$SB/deploys.log" | sed 's/^/    | /'
    [[ "$RC" == 7 && "$(maint_state)" == ON ]] && echo "    → REPRODUCED: deploy aborted at the worker step and left the site in maintenance." \
                                               || echo "    → did NOT reproduce (rc=$RC maint=$(maint_state))"
    exit 0
fi

echo "== 1. clean run: exit 0, site up, workers verified =="
make_sandbox s1; run_scenario clean
expect_eq "exit code" "$RC" 0; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "success banner" "✅ DEPLOY OK — staging"; expect_log "workers stable after go-live" "Workers stable after go-live: 13/13"
foreign_untouched

echo "== 2. THE INCIDENT: restart exits 7 'abnormal termination' on one worker (storm) =="
make_sandbox s2; run_scenario storm_blip
expect_eq "exit code (no abort)" "$RC" 0; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "exit 7 tolerated and logged" "exited 7 — expected while workers churn"
expect_log "workers verified, not assumed" "worker processes accepted by supervisor"
expect_log "post-up stable check passed" "Workers stable after go-live"
expect_no_log "no failure report" "DEPLOY FAILED"; foreign_untouched

echo "== 3. a worker stays FATAL and will not restart: site must still come up, loudly (exit 3) =="
make_sandbox s3; run_scenario stuck
expect_eq "exit code" "$RC" 3; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "names the failed program (pre-up)" "p24import_01 FATAL"
expect_log "warning banner" "DEPLOY COMPLETED WITH WARNINGS"
expect_log "post-up warning names it too" "NOT stable after go-live"
expect_log "operator gets the fix command" "sudo supervisorctl restart <name>"
if grep -q "restart corex-worker-staging-p24import:corex-worker-staging-p24import_01" "$SB/state/sup.calls"; then pass "straggler was kicked by name (bounded retry)"; else bad "straggler never kicked"; fi
foreign_untouched

echo "== 4. queue:restart itself fails =="
make_sandbox s4; run_scenario clean FAKE_QUEUE_RESTART_RC=1
expect_eq "exit code" "$RC" 3; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "named" "queue:restart failed"

echo "== 5. verification fails (reference table empty + HEAD drift): site up, exit 4, both named =="
make_sandbox s5; run_scenario clean FAKE_REFTABLES_RC=1 FAKE_HEAD_DRIFT=1
expect_eq "exit code" "$RC" 4; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "banner" "DEPLOYED, BUT VERIFICATION FAILED"
expect_log "reference-table failure named" "Reference-table check failed"
expect_log "HEAD drift named" "HEAD drifted after deploy"
expect_log "rollback help printed" "ROLLBACK (database restore"

echo "== 6. view:cache re-compile (step 11c) fails: later checks still ran, site up =="
make_sandbox s6; run_scenario clean FAKE_VIEWCACHE_FAIL_ON=2
expect_eq "exit code" "$RC" 4; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "named" "view:cache re-compile failed"

echo "== 7. 'artisan up' fails once: the EXIT trap retries and lifts maintenance =="
make_sandbox s7; run_scenario clean FAKE_UP_FAIL_COUNT=1
expect_eq "maintenance (released by trap)" "$(maint_state)" OFF
if [[ "$RC" != 0 ]]; then pass "non-zero exit ($RC) — the failure is not hidden"; else bad "exit 0 despite failed up"; fi
expect_log "trap lifted it and said so" "Maintenance LIFTED on exit"

echo "== 8. migrations fail (step 6): maintenance is HELD, rollback printed — checks before the worker step NOT weakened =="
make_sandbox s8; run_scenario clean FAKE_MIGRATE_RC=1
if [[ "$RC" != 0 ]]; then pass "non-zero exit ($RC)"; else bad "exit 0 on migration failure"; fi
expect_eq "maintenance stays ON" "$(maint_state)" ON
expect_log "held on purpose" "held on purpose"
expect_log "rollback help printed" "ROLLBACK (database restore"
expect_no_log "worker step never reached" "STEP 10"

echo "== 9a. SIGTERM / ssh hang-up during step 7 (before the point of no return): maintenance HELD =="
make_sandbox s9a; run_scenario clean FAKE_SIGNAL_AT_SEED=TERM
expect_eq "exit code 143" "$RC" 143; expect_eq "maintenance held" "$(maint_state)" ON
expect_log "hold message" "left ON deliberately"
make_sandbox s9a2; run_scenario clean FAKE_SIGNAL_AT_SEED=HUP
expect_eq "SIGHUP exit code 129" "$RC" 129; expect_eq "maintenance held" "$(maint_state)" ON

echo "== 9b. SIGTERM / ssh hang-up during the worker restart (after the point of no return): site comes UP =="
make_sandbox s9b; run_scenario clean FAKE_SIGNAL_AT_RESTART=TERM
expect_eq "exit code 143" "$RC" 143; expect_eq "maintenance released" "$(maint_state)" OFF
expect_log "release said so" "Maintenance LIFTED on exit"
make_sandbox s9b2; run_scenario clean FAKE_SIGNAL_AT_RESTART=HUP
expect_eq "SIGHUP exit code 129" "$RC" 129; expect_eq "maintenance released" "$(maint_state)" OFF

echo "== 10. host with no supervisor pool at all: unchanged behaviour (no false alarm) =="
make_sandbox s10; run_scenario nopool
expect_eq "exit code" "$RC" 0; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "falls back as before" "queue:restart only (no host worker manager detected)"

echo "== 11. pool lists FEWER programs after restart (one vanished): an empty/short list is never 'healthy' =="
make_sandbox s11; run_scenario missing
expect_eq "exit code" "$RC" 3; expect_eq "maintenance" "$(maint_state)" OFF
expect_log "names the shortfall" "MISSING — only 12 of 13 programs listed"

echo "== 12. supervisor unreachable (status fails): treated as 'no manager', never fatal =="
make_sandbox s12; run_scenario blind
expect_eq "exit code" "$RC" 0; expect_eq "maintenance" "$(maint_state)" OFF

echo
echo "RESULT: $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
