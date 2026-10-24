---
name: promotion-runbook
description: Promotion flow QA1/QA2 to Staging to live - the QA gate, deploy sequence, scheduler check, PHP env-parity check, tags/dumps, and the traps found in past promotions. Use when promoting, merging Staging into QA1, deploying to Staging or live, or planning a release.
---
# Promotion runbook

**Authority:** nothing leaves its QA lane without that lane owner's explicit go (QA1 = Johan, QA2 = Andre); nothing goes Staging to live without Johan's specific order for that exact action (CLAUDE.md operating rule 5). The conductor/Andre runs promotions; a build lane never does.

## Flow
build + prove (lane) -> deploy QA1 (Johan's first QA, real-data clone, web-only) -> on pass, merge to Staging -> deploy Staging (final integration QA) -> live on Johan's explicit authorisation. QA sites are DISPOSABLE and NEVER a promotion source. Staging contains main. Queue/scheduler-dependent features (WA capture, transcription, notifications, digests, media backup) get first QA on Staging. Live tracks `origin/Prod`; `scripts/deploy.sh production` is still hard-coded to `main` (flagged, check before running).

## Rules
- **Never overwrite Staging with QA1's tree.** QA1 and Staging each carry work the other lacks (Staging-only work would be deleted). Promote by MERGE COMMIT in a worktree; resolve conflicts there. Check the post-merge file-deletion list against expectation.
- Never commit/merge/rebase/checkout inside `/corex`; live changes only via `git -C /corex merge --ff-only origin/main`; not a fast-forward = STOP and report. One lane at a time on Staging or live. Tag before any deploy (use a NEW tag name - earlier ones exist). Never `git reset --hard` a production checkout. Anything landing on live must be pushed to origin.
- Pre-deploy DB dump goes to the data volume (`/mnt/HC_Volume_103099143`), never `/root` or `/` (multi-GB). Remove merge worktrees (`git worktree remove`) in the same session once landed and verified.
- Run a branch's migrations and reference-data sync as part of the deploy; hold-backs are done with revert commits (releasing later means reverting the reverts).
- Find held commits by files touched, not by subject. Re-check any recorded commit hashes/numbers before trusting a past runbook - they are dated snapshots.

## Deploy sequence (Staging / live)
`git pull` -> `php artisan migrate --force` -> **`php artisan deploy:sync-reference-data`** (idempotent, global scope; the only carrier of seeder-owned GLOBAL reference rows - seeders do NOT run on pull deploys, AT-162) -> `view:clear` + `route:clear` + `config:clear` -> reload the target's php-fpm pool only -> restart the queue worker(s). Verify the reference rows exist on the target afterwards.

## Checks on every promotion
- **Scheduler:** target must have `schedule:run` in cron (`crontab -l`, `/etc/cron.d`) - date-driven rental jobs depend on it (notably `leases:advance-terms` 00:35). Staging's cron was off from 2026-08-21; if the target has none, say so in the report (owner-chasing and breach follow-up then run only on live).
- **Env parity (AT-169):** deploys are code-only, so a PHP extension or VERSION that staging has and live lacks 500s a code path (live php8.3 lacked `imagick` and broke PDF Redact). Diff `comm -13 <(php<live> -m|sort -u) <(php<staging> -m|sort -u)`; for each missing extension decide whether any promoted/live code uses it - if yes install `php<live-ver>-<ext>` and reload ONLY that pool; if not, leave it and note it. Flag PHP version drift (staging 8.2 vs live 8.3: `php8.2-imagick` != `php8.3-imagick`).
- **Outbound mail:** live needs `OUTBOUND_MAIL_REAL_SEND=1`, every other box must not have it (skill `test-email-rules`).
- **Smoke:** Staging has near-zero rental fault/job-card/inspection data, so a Staging smoke cannot prove those flows. `www-data` can't read the root scratchpad (copy scripts to the data volume); `export HOME=/tmp` breaks root's git safe.directory.
- One Jira ticket per build travels with it through every stage.
- Dated procedures live in `.ai/runbooks/` and `.ai/deploy/` (e.g. `staging-post-restore.md`, `p24-live-cutover.md`); the last full runbook was `/root/promotion-runbook-2026-10-20.md` (script 0 first).
