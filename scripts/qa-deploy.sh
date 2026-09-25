#!/usr/bin/env bash
#
# qa-deploy.sh — canonical QA1 code deploy (standing deploy-hand tool).
#
#   Usage:  cd /corex-qa1 && ./scripts/qa-deploy.sh
#
# Deploys whatever is on origin/QA1 to the qa1 host: fast-forward pull → (only if
# frontend changed) npm build → migrate → reference data → permission keys →
# clear caches → reload the shared php8.2-fpm pool → restart the qa1 worker.
# Idempotent; safe to re-run.
#
# NOT for staging/live. Refuses to run anywhere but the qa1 checkout. The general
# scripts/deploy.sh is BANNED on qa1 — this is the blessed path.
#
# THIS SCRIPT MUST NEVER REPORT SUCCESS WHEN A STEP FAILED, AND MUST NEVER
# SWALLOW A WARNING OR ERROR LINE. Summarise normal output all you like;
# never drop a failure. (2026-09-22, Johan — this script told the conductor
# a deploy had landed when a failed `git pull --ff-only` had left it
# unchanged, and separately swallowed 89 of 90 real WARNING lines from a
# step that only ever showed its last 3 lines. Every step below either
# checks its own exit code and aborts loudly, or greps WARNING/ERROR
# through unconditionally before truncating anything else — see each
# step's own comment for which.)
#
# A DEPLOY STEP DECIDES WHETHER TO RUN BY COMPARING AGAINST WHAT WAS LAST
# ACTUALLY DONE — NEVER BY WHETHER GIT'S HEAD MOVED. (2026-09-25.) Whether
# `git pull --ff-only` moved OLDHEAD to a different NEWHEAD says nothing
# about whether the commit being deployed was already built/installed —
# a commit can arrive resident-but-unbuilt (fast-forwarded by an earlier
# step, pushed straight from a worktree) just as easily as it arrives via
# this pull. Any step whose correctness depends on file content (a
# compiled asset bundle, an installed vendor tree — anything this script
# does not simply re-run unconditionally every time because doing so is
# cheap and idempotent) records what it last did in a marker file
# (`public/build/BUILT_FROM`, `vendor/BUILT_FROM`) and compares that marker
# against current HEAD, not against this run's OLDHEAD/NEWHEAD. Do not
# reintroduce an OLDHEAD-vs-NEWHEAD-only gate on a new step — it is exactly
# the bug this comment exists to stop.
#
#   Flags:  --force-build      always run npm ci && npm run build,
#                               regardless of marker/diff detection.
#           --force-composer   always run composer install, regardless of
#                               marker/diff detection.
#
set -uo pipefail

FORCE_BUILD=0
FORCE_COMPOSER=0
for arg in "$@"; do
    case "$arg" in
        --force-build)
            FORCE_BUILD=1
            ;;
        --force-composer)
            FORCE_COMPOSER=1
            ;;
    esac
done

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
# 2026-09-22 — this used to pipe straight to `tail -3` with no exit-code
# check. A failed pull leaves HEAD unmoved, so OLDHEAD==NEWHEAD, and the
# script fell into the "no new commits" branch below — reporting a clean
# no-op run for a run that actually failed to fetch the code at all. That
# is exactly how the conductor told Johan a fix had landed when it had
# not. A failed pull must abort here, loudly, full output, before anything
# downstream can run against stale code.
PULL_OUT="$(git pull --ff-only origin "$BRANCH" 2>&1)"
PULL_STATUS=$?
if [ $PULL_STATUS -ne 0 ]; then
    echo "$PULL_OUT"
    echo "ABORT: git pull --ff-only failed (exit $PULL_STATUS) — the working tree did NOT move. Refusing to report a clean run." >&2
    exit 1
fi
echo "$PULL_OUT" | tail -3
NEWHEAD="$(git rev-parse HEAD)"
echo "   $OLDHEAD → $NEWHEAD"
if [ "$OLDHEAD" = "$NEWHEAD" ]; then
    echo "   (no new commits — running deploy steps anyway to activate current code)"
fi

echo "-- 2. frontend build if assets changed OR the build marker doesn't match HEAD --"
# Any .blade.php counts as a frontend change too, not just resources/js|css —
# Tailwind's classes come from scanning Blade templates for class-name
# strings, not from resources/css source, so a Blade-only change introducing
# a class nobody has used before is invisible to this trigger otherwise: the
# class silently never enters the compiled bundle (found 2026-09-22, cc2's
# rental-inspections compare-view sm:block fix).
#
# 2026-09-25 — this used to decide "did the frontend change" ONLY from
# OLDHEAD→NEWHEAD git movement across THIS pull. When NEWHEAD was already
# resident locally before this script ran (fast-forwarded by an earlier
# step, or pushed straight into place from a worktree), OLDHEAD equals
# NEWHEAD, the diff below is empty, and the script concluded nothing had
# changed and skipped the build — while reporting success. The deployed
# CSS/JS then stayed stale. Proved wrong on 2026-09-24: a manual
# `npm run build` produced a different CSS hash (app-C3azoaVu.css vs
# app-0Lc-6vB9.css) than what was actually being served — real content had
# never reached the browser. Worked around by hand three separate times.
#
# Fix: stop trusting git head movement as a proxy for "was this built".
# `public/build/BUILT_FROM` records the exact commit the LAST successful
# build actually ran against. A build now also runs whenever that marker
# is missing or doesn't match the commit being deployed — independent of
# whether this particular pull moved HEAD at all. The original diff check
# is kept as-is (do not remove it): either signal alone is enough to
# trigger a build; only skip when the marker is present and matches HEAD.
BUILD_MARKER="public/build/BUILT_FROM"
MARKER_HEAD="$(cat "$BUILD_MARKER" 2>/dev/null || true)"

NEED_BUILD=0
BUILD_REASON=""
if [ "$FORCE_BUILD" = "1" ]; then
    NEED_BUILD=1
    BUILD_REASON="--force-build passed"
elif [ "$OLDHEAD" != "$NEWHEAD" ] && git diff --name-only "$OLDHEAD" "$NEWHEAD" \
     | grep -qE '^(resources/js/|resources/css/|vite\.config|package(-lock)?\.json|tailwind\.config)|\.blade\.php$'; then
    NEED_BUILD=1
    BUILD_REASON="frontend-relevant files changed between $OLDHEAD and $NEWHEAD"
elif [ -z "$MARKER_HEAD" ]; then
    NEED_BUILD=1
    BUILD_REASON="no build marker at $BUILD_MARKER — cannot prove the current bundle matches HEAD"
elif [ "$MARKER_HEAD" != "$NEWHEAD" ]; then
    NEED_BUILD=1
    BUILD_REASON="build marker ($MARKER_HEAD) does not match HEAD ($NEWHEAD)"
fi

if [ "$NEED_BUILD" = "1" ]; then
    echo "   build needed: $BUILD_REASON"
    echo "   → npm ci && npm run build"
    # 2026-09-22 — neither call checked its own exit status before; a
    # failed install or build just printed truncated output and the script
    # sailed on to migrate/caches/restart as if the bundle were current.
    # That is how a stale CSS bundle shipped for twelve days unnoticed.
    NPM_CI_OUT="$(npm ci 2>&1)"
    NPM_CI_STATUS=$?
    if [ $NPM_CI_STATUS -ne 0 ]; then
        echo "$NPM_CI_OUT"
        echo "ABORT: npm ci failed (exit $NPM_CI_STATUS)." >&2
        exit 1
    fi
    echo "$NPM_CI_OUT" | tail -3

    NPM_BUILD_OUT="$(npm run build 2>&1)"
    NPM_BUILD_STATUS=$?
    if [ $NPM_BUILD_STATUS -ne 0 ]; then
        echo "$NPM_BUILD_OUT"
        echo "ABORT: npm run build failed (exit $NPM_BUILD_STATUS) — asset bundle NOT rebuilt, refusing to report success." >&2
        exit 1
    fi
    echo "$NPM_BUILD_OUT" | tail -5

    echo "$NEWHEAD" > "$BUILD_MARKER"
    echo "   wrote $BUILD_MARKER = $NEWHEAD"
else
    echo "   marker $BUILD_MARKER matches HEAD ($NEWHEAD) — skip npm build"
fi

echo "-- 3. composer install if composer.lock changed OR the vendor marker doesn't match HEAD --"
# 2026-09-25 — same disease as step 2's old frontend-build gate: this used
# to decide "does vendor/ need reinstalling" purely from OLDHEAD→NEWHEAD
# movement across THIS pull. When the commit being deployed was already
# resident locally before this script ran, OLDHEAD==NEWHEAD, the diff was
# empty, and composer install was silently skipped even if composer.lock
# had genuinely changed relative to what vendor/ was last installed from.
# `vendor/BUILT_FROM` now records the commit the last successful
# `composer install` actually ran against; a mismatch against HEAD forces
# a reinstall independent of this pull's head movement, same as step 2.
COMPOSER_MARKER="vendor/BUILT_FROM"
COMPOSER_MARKER_HEAD="$(cat "$COMPOSER_MARKER" 2>/dev/null || true)"

NEED_COMPOSER=0
COMPOSER_REASON=""
if [ "$FORCE_COMPOSER" = "1" ]; then
    NEED_COMPOSER=1
    COMPOSER_REASON="--force-composer passed"
elif [ "$OLDHEAD" != "$NEWHEAD" ] && git diff --name-only "$OLDHEAD" "$NEWHEAD" | grep -q '^composer\.lock'; then
    NEED_COMPOSER=1
    COMPOSER_REASON="composer.lock changed between $OLDHEAD and $NEWHEAD"
elif [ -z "$COMPOSER_MARKER_HEAD" ]; then
    NEED_COMPOSER=1
    COMPOSER_REASON="no composer marker at $COMPOSER_MARKER — cannot prove vendor/ matches HEAD"
elif [ "$COMPOSER_MARKER_HEAD" != "$NEWHEAD" ]; then
    NEED_COMPOSER=1
    COMPOSER_REASON="composer marker ($COMPOSER_MARKER_HEAD) does not match HEAD ($NEWHEAD)"
fi

if [ "$NEED_COMPOSER" = "1" ]; then
    echo "   composer install needed: $COMPOSER_REASON"
    COMPOSER_OUT="$(composer install --no-dev --no-interaction --prefer-dist 2>&1)"
    COMPOSER_STATUS=$?
    if [ $COMPOSER_STATUS -ne 0 ]; then
        echo "$COMPOSER_OUT"
        echo "ABORT: composer install failed (exit $COMPOSER_STATUS)." >&2
        exit 1
    fi
    echo "$COMPOSER_OUT" | tail -3
    echo "$NEWHEAD" > "$COMPOSER_MARKER"
    echo "   wrote $COMPOSER_MARKER = $NEWHEAD"
else
    echo "   marker $COMPOSER_MARKER matches HEAD ($NEWHEAD) — skip composer install"
fi

echo "-- 4. migrate (idempotent) --"
# 2026-09-22 — a failed migration's real error (the actual SQL error, the
# migration filename, the stack trace) can easily exceed 4 lines; `tail -4`
# could cut it down to nothing useful while the script carried on to
# reference-data/permissions/caches against a half-migrated schema.
MIGRATE_OUT="$(php artisan migrate --force 2>&1)"
MIGRATE_STATUS=$?
if [ $MIGRATE_STATUS -ne 0 ]; then
    echo "$MIGRATE_OUT"
    echo "ABORT: migrate --force failed (exit $MIGRATE_STATUS)." >&2
    exit 1
fi
echo "$MIGRATE_OUT" | tail -4

echo "-- 5. reference data (global seeder-owned rows; idempotent) --"
# 2026-09-22 — this command has its own hard-failure path (AT-265: deploy
# halted if role_permissions is empty after provisioning), 6 lines of
# error+warn text that `tail -3` could cut mid-message. NOTE: unlike step
# 6's sync-permissions (which hand-writes the literal word "WARNING" into
# its own output string), this command's $this->error()/$this->warn() only
# apply ANSI colour — the plain text never contains the words WARNING or
# ERROR, so a grep for them here would silently match nothing and give
# false confidence. What actually protects this step: the AT-265 path
# genuinely returns self::FAILURE, so the command exits nonzero — check
# that and dump the FULL output on failure, never the truncated tail.
REFDATA_OUT="$(php artisan deploy:sync-reference-data 2>&1)"
REFDATA_STATUS=$?
if [ $REFDATA_STATUS -ne 0 ]; then
    echo "$REFDATA_OUT"
    echo "ABORT: deploy:sync-reference-data failed (exit $REFDATA_STATUS)." >&2
    exit 1
fi
echo "$REFDATA_OUT" | tail -3

# scripts/deploy.sh (staging/production) has always run this on every deploy
# (see its own step 6) — qa-deploy.sh never did, which is exactly why
# rental_work_orders.manage_quotes had no grant rows on QA1 and looked
# unbuilt (2026-09-22, cc6). --merge-defaults is additive only: it diffs
# each role's config-expected key set against what's already in
# role_permissions and inserts only the missing keys — existing rows,
# including any agency's own Role Manager customisations, are never
# touched, updated, or deleted. Never --seed-defaults here, same reasoning
# as scripts/deploy.sh.
#
# `tail -3` used to sit here, same as every other step — wrong for this one:
# this command emits one line per (role, agency), so a real WARNING can be
# any of dozens of lines up the scrollback and silently never printed. Found
# 2026-09-22: 90 "assistant" rows exist across every agency including the
# real one (Home Finders Coastal), so 89 of 90 WARNING lines were being
# swallowed on every run, on every deploy, since the day this step was
# added. Fix: never drop a WARNING/ERROR line, whatever else gets
# summarised down.
echo "-- 6. permission keys (additive — customisations preserved) --"
PERM_SYNC_OUT="$(php artisan corex:sync-permissions --merge-defaults 2>&1)"
echo "$PERM_SYNC_OUT" | grep -E "WARNING|ERROR" || true
echo "$PERM_SYNC_OUT" | tail -3

echo "-- 7. clear caches --"
php artisan config:clear 2>&1 | tail -1
php artisan route:clear 2>&1 | tail -1
php artisan view:clear 2>&1 | tail -1

echo "-- 8. reload $FPM (clears opcache) --"
sudo systemctl reload "$FPM" 2>&1 | tail -1 || systemctl reload "$FPM" 2>&1 | tail -1

echo "-- 9. restart qa1 worker --"
sudo systemctl restart "$WORKER" 2>&1 | tail -1 || systemctl restart "$WORKER" 2>&1 | tail -1
php artisan queue:restart 2>&1 | tail -1

echo "-- 10. smoke: app boots (route table resolves) --"
php artisan route:list >/dev/null 2>&1 && echo "   route table OK" || { echo "   ROUTE TABLE FAILED — investigate"; exit 1; }

echo "== qa-deploy DONE @ $NEWHEAD =="
