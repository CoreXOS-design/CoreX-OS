# CoreX Demo — Operations Runbook

Short, practical reference for running/maintaining `demo1.corexos.co.za`. Written
2026-09-01 during Thursday-webinar prep. Pairs with `.ai/DEMO-TESTING-INDEX.md`
(what to look at) — this doc is how to keep the box itself running.

**Box facts:** codebase `/mnt/HC_Volume_103099143/corex-demo`, DB `corex_demo`
(MySQL), PHP-FPM socket `php8.2-fpm.sock`, queue worker via supervisor program
`corex-worker-demo`, `APP_ENV=production` + `COREX_INSTANCE_ROLE=demo` (the gate
everything else keys off).

---

## 1. Re-enabling the 3am auto-reset (after Thursday)

It is currently **disabled, TWO INDEPENDENT WAYS** — both must be reversed
together:

1. `routes/console.php` around line 503–520, the
   `Schedule::command('demo:reset --scheduled')->dailyAt('03:00')...` block is
   commented out.
2. `dev_settings.demo_reset_frozen` = `1` — checked inside
   `DemoReset::handle()` itself, so it refuses to run even if invoked directly
   or the schedule entry above ever gets re-armed.

**Why two layers:** on 2026-09-01 the schedule was disabled as a working-tree
edit only, never committed. A `git reset` in this shared checkout silently
discarded it hours later, and the 3am job fired on webinar-eve against the
just-verified demo dataset. The `demo_reset_frozen` DB flag exists specifically
so a code-level revert like that can't silently re-arm real execution again —
see git log `03531e09a` / `f60116f3f` for the incident.

**To re-enable, do both:**

```bash
cd /mnt/HC_Volume_103099143/corex-demo
php artisan tinker --execute="\App\Models\DevSetting::set('demo_reset_frozen', '0');"
```
Then uncomment the schedule block in `routes/console.php` (remove the `//`
before `if (\App\Support\Instance::isDemo())` through the closing `}`), then:

```bash
php artisan config:clear
```

The scheduler itself (cron → `schedule:run`) never stopped — only this one
entry was disabled — so nothing else needs restarting. Confirm both are back:
`php artisan schedule:list` shows `demo-access.reset` again, AND
`php artisan tinker --execute="echo var_export(\App\Models\DevSetting::bool('demo_reset_frozen'), true);"`
shows `false`.

**COMMIT the `routes/console.php` change this time** — the uncommitted-edit
mistake is exactly what caused the incident above.

**Do this only after Johan explicitly says it's safe.**

Cadence once re-enabled: every 3rd day at 03:00 SAST (`DemoResetSchedule`,
anchor date is a `DevSetting` — `demo_reset_anchor_date`, default 2026-07-13).
The banner countdown on the demo site reads the same function, so it can't
disagree with what actually happens.

---

## 2. Running a controlled `demo:reset` by hand

```bash
cd /mnt/HC_Volume_103099143/corex-demo
php artisan demo:reset
```

What it does, in order (see `app/Console/Commands/Demo/DemoReset.php`):
1. **Refuses outright** if `Instance::isDemo()` is false — cannot be pointed at
   the wrong box, not even with a flag.
2. **Backs up first** — `mysqldump` of `corex_demo` to
   `storage/app/demo-backups/corex_demo-{Ymd-His}.sql`. If the backup fails for
   any reason (disk full, mysqldump missing, unwritable dir), it **refuses to
   wipe** — the reset never happens without a fresh backup in hand.
3. Keeps only the 3 newest backups from this command's own runs (older ones
   auto-deleted) — but see the note below, this rotation does NOT catch manual
   dumps taken outside this command.
4. `migrate:fresh --force` — drops every table, re-runs all migrations.
5. `deploy:sync-reference-data` — reseeds the reference rows migrations don't
   carry (seeders never run on a plain deploy).
6. `demo:seed --force` — rebuilds the full demo dataset.

No flags needed for a hand run — `--scheduled` is only for cron (it adds the
"only every 3rd day" check, which you don't want when deliberately hand-running).

**Before running it**, confirm the current data is worth throwing away — this
IS a full wipe, backup or not. If unsure, take a manual `mysqldump` first (see
§4) with your own descriptive filename so it survives the 3-backup rotation.

---

## 3. The demo deploy sequence

Demo is a `git pull`-only deploy (no CI/CD pipeline) against its own checkout —
NOT a symlinked or shared vendor with any other checkout (per the box-wide
vendor-isolation rule). Run every step, in order:

```bash
cd /mnt/HC_Volume_103099143/corex-demo

# 1. Confirm you're on the right branch and clean before pulling
git status --porcelain
git branch --show-current

# 2. Sync code
git fetch --all --prune
git pull            # or merge/rebase the intended branch — do NOT force

# 3. Dependencies (only if composer.lock changed)
composer install --no-dev --optimize-autoloader

# 4. Frontend build (only if resources/js or resources/css changed)
npm ci
npm run build

# 5. Migrate
php artisan migrate --force

# 6. Reference data (seeders never run on a plain deploy — AT-162)
php artisan deploy:sync-reference-data

# 7. Clear stale compiled/cached state
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan cache:clear

# 8. Rebuild config cache (production-mode perf)
php artisan config:cache

# 9. Confirm the demo gate/instance role is intact (should already be in .env —
#    do NOT edit .env yourself; this is a read-only check)
php artisan tinker --execute="echo \App\Support\Instance::isDemo() ? 'OK: isDemo true' : 'BROKEN: isDemo false';"

# 10. Reload PHP-FPM (demo runs php8.2-fpm)
sudo systemctl reload php8.2-fpm

# 11. Restart the queue worker (e-sign finalisation, mail, matching, etc. all run here)
sudo supervisorctl restart corex-worker-demo

# 12. Smoke-test: hit the site and confirm it's up
curl -sk -o /dev/null -w "%{http_code}\n" https://demo1.corexos.co.za/

# 13. Verify a real page renders (login as admin@demo.corexos.co.za and check
#     Market Intelligence loads) — this is the step that actually proves the
#     deploy worked, not just "the server responded."
```

Env-parity note (box-wide rule): if the deployed code needs a PHP extension or
version demo's `php8.2` pool doesn't have, it will 500 on that code path even
though the deploy "succeeded." Check `php8.2 -m` against what the new code
requires before declaring done.

---

## 4. Where the backups live

- **Automatic** (from `demo:reset`'s own backup step): `storage/app/demo-backups/corex_demo-{Ymd-His}.sql` — rotated to the newest 3 by `demo:reset` itself.
- **Manual dumps taken by lanes today are in the same directory** but with
  custom suffixes (e.g. `corex_demo-20260901-150305-pre-scoped-reseed.sql`) —
  these are NOT touched by the rotation (it only globs+trims after its own run,
  and only within the same invocation), so they persist until someone manually
  cleans them up. As of this writing there are 8 files in that directory
  totalling ~85MB — well within the data-volume's budget, but worth pruning
  once the webinar work settles (see box-wide disk-hygiene rule — dumps belong
  on the data volume, never `/root` or `/`, and this path already is on
  `/mnt/HC_Volume_103099143`, so no action needed there).
- To take a manual backup yourself (e.g. before a hand-run reset), same pattern
  the command uses:
  ```bash
  mysqldump --single-transaction --routines --triggers --add-drop-table \
    -u <db_user> -p corex_demo > /mnt/HC_Volume_103099143/corex-demo/storage/app/demo-backups/corex_demo-manual-$(date +%Y%m%d-%H%M%S)-<your-note>.sql
  ```
  (credentials from `.env` `DB_USERNAME`/`DB_PASSWORD` — don't hardcode them in
  a script or commit them anywhere.)
- To restore from one: `mysql -u <db_user> -p corex_demo < <path-to-dump>.sql`
  (this does NOT drop-then-recreate the DB itself, only its tables, since the
  dump includes `--add-drop-table`).
- **Full snapshots (DB + files together)** live under
  `storage/app/snapshots/<name>-<timestamp>/` — a DB-only dump is not enough,
  since signed PDFs and generated documents live on disk and a DB-only restore
  would leave rows pointing at files that no longer exist. Each snapshot
  directory has `database.sql`, `storage-app.tar.gz` (everything under
  `storage/app` except `demo-backups/` and `snapshots/` themselves), and
  `MANIFEST.txt` (git HEAD, key row counts, and `demo_reset_frozen`'s value at
  snapshot time). Restore = direct load of both — `mysql corex_demo <
  database.sql` + extract the tarball back over `storage/app`. **Never**
  `migrate:fresh`/`demo:seed` as part of a restore — that rebuilds
  `dev_settings` from migrations/seeders instead of replaying the snapshot's
  actual row, which is how a restore could silently un-freeze
  `demo_reset_frozen`. `pre-johan-testing-<timestamp>` (2026-09-02) is the
  current one, taken and restore-proven before Johan's destructive testing
  pass — see that snapshot's own `MANIFEST.txt` for what it captured.

---

## 5. Auto-upgrade timer — masked until after the webinar

`apt-daily-upgrade.timer` is currently **masked** (`systemctl mask`, not just
disabled) — 2026-09-02 incident: this box's routine unattended-upgrades run
patched `mysql-server` at 06:03-06:06 SAST, which restarted mysqld and killed
every long-running queue worker on live-testing, staging, and demo (they hit
`Connection refused` and exhausted supervisor's restart retries → FATAL). The
timer runs daily at ~06:00-06:20 SAST, hours before a webinar start — masking
it removes the risk of a repeat on webinar morning.

`apt-daily.timer` (the plain `apt-get update` index refresh, no install, no
restart capability) was deliberately left running — it cannot trigger this
class of incident.

**To re-enable after Thursday:**
```bash
systemctl unmask apt-daily-upgrade.timer
systemctl enable apt-daily-upgrade.timer
systemctl start apt-daily-upgrade.timer
```
Confirm with `systemctl list-timers apt-daily-upgrade.timer` — it should show
a future scheduled run again. **Do this only after Johan says it's safe** —
masking it indefinitely quietly stops security patching on this box, which is
its own risk once the webinar is behind us.

---

## Quick reference

| Task | Command |
|---|---|
| Hand-run a full reset | `php artisan demo:reset` |
| Re-enable 3am auto-reset | uncomment block in `routes/console.php` ~L503-520 |
| Reload web server | `sudo systemctl reload php8.2-fpm` |
| Restart queue worker | `sudo supervisorctl restart corex-worker-demo` |
| Check queue worker status | `sudo supervisorctl status corex-worker-demo` |
| Check instance role is correct | `php artisan tinker --execute="echo \App\Support\Instance::isDemo();"` |
| List backups | `ls -la storage/app/demo-backups/` |
