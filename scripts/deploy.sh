#!/bin/bash
# ============================================================
# CoreX OS — Server Deploy Script
# Usage:
#   /hfc/scripts/deploy.sh          (deploys to /hfc from main)
#   /hfc/scripts/deploy.sh staging  (deploys to /hfc-staging from Staging)
# ============================================================

set -e

ENV="${1:-production}"

if [ "$ENV" = "staging" ]; then
    DIR="/hfc-staging"
    BRANCH="Staging"
else
    DIR="/hfc"
    BRANCH="main"
fi

echo "=== Deploying $BRANCH to $DIR ==="

cd "$DIR"

# Always fetch + reset to remote — never git pull (avoids divergent branch issues)
echo "--- Fetching latest from origin/$BRANCH..."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

echo "--- Installing composer dependencies (no dev)..."
composer install --no-dev --no-interaction --optimize-autoloader 2>&1

echo "--- Running migrations..."
php artisan migrate --force

echo "--- Syncing permissions..."
php artisan corex:sync-permissions

echo "--- Clearing caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

echo "--- Building frontend assets..."
npm run build

echo "=== Deploy complete: $BRANCH → $DIR ==="
