---
name: worktree-and-lane-test
description: Create a lane worktree, bootstrap it so PHP tests run, and run tests with scripts/lane-test.sh (the only sanctioned way); also worktree cleanup, vendor isolation and test-DB rules. Use when starting work in a new worktree, running any PHPUnit test, or removing a worktree.
---
# Worktree + lane-test

## Create
- All lane work lives in a worktree on the data volume: `git -C <repo> worktree add -b <lane>-<topic>-<YYYY-MM-DD> /mnt/HC_Volume_103099143/corex-worktrees/<branch> origin/<base>` (base = `origin/QA1` for Johan's lane work). Branch name begins with the lane (`cc2-…`). **Never `git checkout`/`switch` inside `/corex-qa1`, `/corex-staging` or `/corex`.**
- A branch cut from `origin/QA1` auto-tracks it: run `git branch --unset-upstream` (or push with explicit `git push -u origin <branch>`) so a bare `git push` can never land on QA1.
- Never `git stash` for scratch work: the stash stack is ONE list shared by every worktree on the box.

## Bootstrap (a fresh worktree is NOT test-ready)
1. `mkdir -p bootstrap/cache storage/framework/{cache,sessions,views,testing} storage/logs` (git doesn't carry empty dirs).
2. `cp .env.example .env && php8.2 artisan key:generate --force` (else MissingAppKeyException on web-middleware tests). `.env` is gitignored.
3. **Own vendor: `composer install` INSIDE the worktree. Never symlink, bind-mount or `cp -r` another checkout's `vendor/`.** Compiled classmap/bootstrap-cache files embed absolute paths and have already pointed live Staging at a scratch worktree. Check `readlink -f vendor` equals `<worktree>/vendor` before any composer command; composer cache makes the install fast. Then `php8.2 artisan package:discover`.
4. `node_modules` is absent: only Word-import tests (mammoth/DocxParser/DocumentImporter/ImporterAi/CorexDocumentRenderer) need it — `lane-test.sh` stops with one line naming the fix (`ln -s /corex-qa1/node_modules node_modules`, read-only use, never `npm install` through it). **Never run `npm run build` to make a feature test pass** — `Tests\TestCase` already calls `withoutVite()`; a `ViteManifestNotFoundException` is a bug in that test's setup.

## Run tests — `scripts/lane-test.sh` ONLY
- `bash scripts/lane-test.sh <ONE test file>` — never `php artisan test`/`vendor/bin/phpunit` directly, never a directory or the full suite (CLAUDE.md #13), nothing >60 s. `--status` (queue, no join), `--fresh <file>` (full rebuild, if a run left bad state), `--drop-schema`.
- It runs on the dedicated tests-only MySQL (docker `corex-lanetest-mysql`, 127.0.0.1:3317, creds `/root/.lanetest-mysql-credentials`; docs `/root/LANETEST-MYSQL.md`) — never the shared instance serving live/Staging/QA/demo; its relaxed durability settings must NEVER be applied there. Schemas are persistent (fingerprint of snapshot + migrations): first run on a worktree pays a cold bootstrap (minutes), later runs take seconds. Schema name = per-worktree cksum unless `TEST_DB_DATABASE` is set in the worktree `.env`; two worktrees with the same name wait for each other.
- Up to 2 runs at once (`LANE_TEST_MAX_SLOTS=3` overrides one run); the setup lock is held only while a schema is created/migrated. **Never kill another lane's run; never Ctrl-C mid-DDL** (half-migrated schemas). If a stuck holder is implausibly old, report it. Killing your own wrapper takes its process group down.
- **One test process per worktree database at a time** — a foreground run plus a subagent/fork run on the same DB self-deadlocks (ERROR 1213, metadata-lock waits). Dispatching a subagent that tests ⇒ you don't test in the foreground until it finishes.
- Prefer plain `migrate` over `migrate:fresh` where it will do. Cold bootstrap dying with `ERROR 2013 Lost connection` ⇒ the tests MySQL restarted under another lane's import: wait until `mysql -h127.0.0.1 -P3317 -e "select 1"` answers (loop in a script file), re-run.
- **Whole-suite/discovery fatals ≠ your change:** one test file with an invalid declaration (a private helper named `post`/`get`/`put`/`delete`/`run`/`assert*` shadowing an inherited method) fatals *discovery* for everyone but not single-file runs. Check before naming a helper: `php -r "require 'vendor/autoload.php'; print_r(get_class_methods(Tests\TestCase::class));"`; prove/disprove with `phpunit --list-tests`.
- **Mine vs baseline failure:** reason from the diff first. If you must compare, run the same single file in a throwaway worktree off `origin/QA1` (steps 1–4 above, then remove it). Many presentation tests fail at baseline (`QueryException … agency_id doesn't have a default`) — compare, don't chase.
- **Rule 18:** a worktree's `php artisan serve` never points at a shared DB (`corex_qa1`, Staging, live, another lane's): ghost rows whose files die with the worktree. Test in place on the shared site over its real URL, or on an isolated throwaway `hfc_dash_test_*` schema — never a mix. A worktree command must never `migrate` against `corex_qa1` (`artisan` refuses it).

## Clean up — same session, once the work has landed and is verified
`bash scripts/lane-test.sh --drop-schema` from the worktree (drops its ~115 MB schema), then `git worktree remove <path>` (never `rm -rf` — leaves stale metadata). `git worktree list` from one repo doesn't show other repos' worktrees; find checkouts by scanning for `artisan`.
**Disk:** `/` is a separate small disk; worktrees, dumps (`mysqldump` multi-GB — write to the data volume, never `/root`) and test schemas go on `/mnt/HC_Volume_103099143`. Check `df -h` first; find hogs with `du -x` from the mount root, not a glob over `/*`. Chromium renders only through `scripts/html-to-pdf.mjs`. Re-measure data-volume headroom when working in this area; report a material drop (was 86% / 27 G free on 13 Sep; 85% / 29 G on 24 Oct).
