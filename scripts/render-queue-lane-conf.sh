#!/usr/bin/env bash
# =============================================================================
# render-queue-lane-conf.sh <lane> <env> <app_path>
#
# Prints (to stdout) the supervisor program for ONE queue lane on a given box, filled in from
# docs/infra/supervisor/corex-worker-lane.conf.template. It installs nothing, touches no supervisor and no service: the output is
# for whoever manages the box to review and save as /etc/supervisor/conf.d/corex-worker-<env>-<lane>.conf.
#
#   bash scripts/render-queue-lane-conf.sh thumbnails demo /mnt/HC_Volume_103099143/corex-demo
#
# Lanes it knows: only those whose settings are on record. An unknown lane is refused rather than guessed.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="${LANE_CONF_TEMPLATE:-$HERE/../docs/infra/supervisor/corex-worker-lane.conf.template}"

LANE="${1:-}"; ENV_NAME="${2:-}"; APP_PATH="${3:-}"
if [[ -z "$LANE" || -z "$ENV_NAME" || -z "$APP_PATH" ]]; then
    echo "usage: $0 <lane> <env: staging|demo|live> <app_path>" >&2; exit 2
fi
case "$ENV_NAME" in staging|demo|live) ;; *) echo "env must be staging, demo or live (got '$ENV_NAME')" >&2; exit 2 ;; esac
APP_PATH="${APP_PATH%/}"
[[ "$APP_PATH" == /* ]] || { echo "app_path must be absolute (got '$APP_PATH')" >&2; exit 2; }

# lane table: numprocs | worker options. thumbnails = the measured live sizing (docs/infra/supervisor/corex-worker-thumbnails.conf):
# 4 procs, --memory=512 because the job decodes the full-resolution image with GD.
case "$LANE" in
    thumbnails) NUMPROCS=4; OPTS="--sleep=3 --tries=2 --max-time=3600 --memory=512" ;;
    *) echo "no recorded settings for lane '$LANE' - add it to the lane table in $0 with the numbers from its live conf" >&2; exit 2 ;;
esac

[[ -r "$TEMPLATE" ]] || { echo "template not found: $TEMPLATE" >&2; exit 3; }
sed -e "s#__ENV__#$ENV_NAME#g" -e "s#__LANE__#$LANE#g" -e "s#__APP_PATH__#$APP_PATH#g" \
    -e "s#__NUMPROCS__#$NUMPROCS#g" -e "s#__WORKER_OPTS__#$OPTS#g" "$TEMPLATE"
