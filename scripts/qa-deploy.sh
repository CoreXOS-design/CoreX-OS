#!/usr/bin/env bash
#
# qa-deploy.sh — canonical QA1 code deploy (standing deploy-hand tool).
#
#   Usage:  cd /corex-qa1 && ./scripts/qa-deploy.sh
#
# Deploys whatever is on origin/QA1 to the qa1 host: fast-forward pull → (only if
# frontend changed) npm build → migrate → reference data → clear caches → reload
# the shared php8.2-fpm pool → restart the qa1 worker. Idempotent; safe to re-run.
#
# NOT for staging/live. Refuses to run anywhere but the qa1 checkout. The general
# scripts/deploy.sh is BANNED on qa1 — this is the blessed path.
#
set -uo pipefail

APP_DIR="/corex-qa1"
BRANCH="QA1"
FPM="php8.2-fpm"                 # qa1 shares the php8.2 pool with staging/demo
WORKER="corex-qa1-queue"        # systemd unit (NOT supervisor)

# ── Guard: only ever the qa1 checkout ────────────────────────────────────────
if [ "$(pwd)" != "$APP_DIR" ]; then
    if [ -d "$APP_DIR" ]; then cd "$APP_DIR"; else
        echo "ABORT: $APP_DIR not found — this tool only deploys the qa1 host."; exit 1
    fi
fi
if ! grep -q "corex_qa1\|qatesting1" .env 2>/dev/null; then
    echo "ABORT: .env does not look like qa1 (no corex_qa1/qatesting1) — refusing."; exit 1
fi

echo "== qa-deploy: $(pwd) → origin/$BRANCH =="
OLDHEAD="$(git rev-parse HEAD)"

echo "-- 1. fetch + fast-forward pull --"
git fetch origin "$BRANCH" 2>&1 | tail -1
git pull --ff-only origin "$BRANCH" 2>&1 | tail -3
NEWHEAD="$(git rev-parse HEAD)"
echo "   $OLDHEAD → $NEWHEAD"
if [ "$OLDHEAD" = "$NEWHEAD" ]; then
    echo "   (no new commits — running deploy steps anyway to activate current code)"
fi

echo "-- 2. frontend build ONLY if assets changed (qa1 serves built assets) --"
if [ "$OLDHEAD" != "$NEWHEAD" ] && git diff --name-only "$OLDHEAD" "$NEWHEAD" \
     | grep -qE '^(resources/js/|resources/css/|vite\.config|package(-lock)?\.json|tailwind\.config)'; then
    echo "   frontend changed → npm ci && npm run build"
    npm ci  2>&1 | tail -3
    npm run build 2>&1 | tail -5
else
    echo "   no frontend changes → skip npm build"
fi

echo "-- 3. composer install ONLY if composer.lock changed --"
if [ "$OLDHEAD" != "$NEWHEAD" ] && git diff --name-only "$OLDHEAD" "$NEWHEAD" | grep -q '^composer\.lock'; then
    composer install --no-dev --no-interaction --prefer-dist 2>&1 | tail -3
else
    echo "   composer.lock unchanged → skip"
fi

echo "-- 4. migrate (idempotent) --"
php artisan migrate --force 2>&1 | tail -4

echo "-- 5. reference data (global seeder-owned rows; idempotent) --"
php artisan deploy:sync-reference-data 2>&1 | tail -3

echo "-- 6. clear caches --"
php artisan config:clear 2>&1 | tail -1
php artisan route:clear 2>&1 | tail -1
php artisan view:clear 2>&1 | tail -1

echo "-- 7. reload $FPM (clears opcache) --"
sudo systemctl reload "$FPM" 2>&1 | tail -1 || systemctl reload "$FPM" 2>&1 | tail -1

echo "-- 8. restart qa1 worker --"
sudo systemctl restart "$WORKER" 2>&1 | tail -1 || systemctl restart "$WORKER" 2>&1 | tail -1
php artisan queue:restart 2>&1 | tail -1

echo "-- 9. smoke: app boots (route table resolves) --"
php artisan route:list >/dev/null 2>&1 && echo "   route table OK" || { echo "   ROUTE TABLE FAILED — investigate"; exit 1; }

echo "== qa-deploy DONE @ $NEWHEAD =="
