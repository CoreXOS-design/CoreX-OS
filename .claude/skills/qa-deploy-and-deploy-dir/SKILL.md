---
name: qa-deploy-and-deploy-dir
description: How QA1 is deployed with scripts/qa-deploy.sh, what must never be done inside /corex-qa1, the migrate guard, and how to read or test against QA1 data safely. Use when touching /corex-qa1, landing work on QA1, running tinker/artisan against QA1, or planning a migration.
---
# QA1 deploy + the deploy directory

## `/corex-qa1` is the live QA1 deploy target — never scratch (CLAUDE.md #8b)
It serves `qatesting1.corexos.co.za` (DB `corex_qa1`). Checking a branch out there serves it to live QA1 traffic within seconds (opcache + Blade view-cache re-read disk) and makes everyone's testing meaningless; a lane's investigation branch once stranded QA1 and a deploy built unreviewed commits. Rules:
- It stays on branch `QA1`, permanently. The ONLY git operation allowed in it is the fast-forward pull `scripts/qa-deploy.sh` performs (`git pull --ff-only origin QA1`).
- All other work — features, fixes, investigations, spec-only commits — in a worktree under `/mnt/HC_Volume_103099143/corex-worktrees/<branch>` (skill `worktree-and-lane-test`).
- Before trusting what QA1 serves: `git rev-parse --abbrev-ref HEAD` inside it is `QA1` and `git rev-parse HEAD` equals `git rev-parse origin/QA1`. (A post-checkout hook lives in `scripts/qa1-git-hooks/`.)
- **Deploy pre-flight must never stash/remove `resources/views/docuperfect/web-templates/cds/template-*.blade.php`** (runtime-generated, load-bearing e-sign templates; untracked ≠ debris). Rotated logs are safe to move. Exclude the glob or `.gitignore` it; never stash it to satisfy a dirty-tree check.

## Landing
Only cc1 writes `origin/QA1` (skill `landing-checks`). cc1's cycle: worktree → merge current `origin/QA1`, confirm `git merge-base --is-ancestor origin/QA1 HEAD` → fast-forward push → `cd /corex-qa1 && ./scripts/qa-deploy.sh` (ff pull → permissions → npm build only if frontend changed → migrate → reference data → permission keys → clear caches → reload the shared php8.2-fpm pool → restart QA1 workers; idempotent, never reports success when a step failed) → re-verify HEAD == `origin/QA1`. **`scripts/deploy.sh` is BANNED on qa1/qa2** (agency-blind seeder `forceDelete`). If `npm ci` aborts with ENOTEMPTY, another deploy was running — re-run. If a push says "Internal Server Error", check githubstatus.com; `origin/QA1` may move while you wait.

## Migrations never run from a worktree against QA1
`artisan` refuses `migrate*`/`db:wipe` whenever `DB_DATABASE` resolves to `corex_qa1` unless that checkout's own `.env` has `QA1_DEPLOY_CHECKOUT=true` (only `/corex-qa1`). Write + commit + push the migration; it is applied by `qa-deploy.sh`. The guard stops accidents, not a determined `--database=` bypass.

## Touching QA1 data safely
- Run artisan/tinker in `/corex-qa1` as `www-data` (`su -s /bin/bash www-data -c "HOME=/tmp/<writable> php8.2 artisan …"`), never root (root-owned logs/cache break QA1). www-data can't read the root-only scratchpad: copy scripts to a world-readable folder.
- Scripts live in the scratchpad, are idempotent, and refuse unless `config('app.url')` contains `qatesting1`.
- "Does it work on real data" with no residue: wrap in `DB::beginTransaction(); try { … } finally { DB::rollBack(); }` in tinker (not around anything that mails or writes files; delete any written file before rollback; assert counts are back).
- To try NEW worktree code on QA1 data read-only: export QA1's `DB_*` into the process env in a scratchpad script, set `APP_URL` to qatesting1, run tinker from the worktree — nothing written to disk, nothing printed.
- **Never save/delete a `Property` in tinker with `QUEUE_CONNECTION=sync`** (buyer-matching pass, 23+ min). Reuse a ZZ property, use `Property::withoutEvents(...)`, archive with plain `update(['deleted_at'=>now()])`.
- `leases:*` commands have no scheduler on QA1: hand-run with `--dry-run` first (an unscoped run once flipped real QA1 leases).
- Smoke through the real nginx/php-fpm path: `php8.2 scripts/fetch-authenticated-page.php --app-root=/corex-qa1 --user-id=<fixture> --url=https://qatesting1.corexos.co.za/<path> --out=<file> --php-bin=php8.2` (php8.2 = the pool that serves QA1).
- QA mail/outbound behaviour: skill `test-email-rules`. QA is web-only: queue/scheduler-dependent features get their first QA on Staging.
