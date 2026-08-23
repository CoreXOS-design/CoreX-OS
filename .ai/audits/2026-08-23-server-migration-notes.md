# Server migration notes — for whoever sets up the new box

Written 2026-08-23 during a disk-cleanup pass on the current box (85-86% full on a
38G system disk). The live sites move to new hardware this week. These are the
things we found that are artefacts of *this* box, not properties of CoreX —
don't carry them over as-is.

## ⚠️ READ THIS FIRST: this box serves FOUR other production sites, not just CoreX

Confirmed live via nginx's own active config (`nginx -T`), not just leftover
checkouts — someone is actually visiting these right now:

| Domain | Docroot | Notes |
|---|---|---|
| `hfcoastal.co.za` / `www.hfcoastal.co.za` | `/var/www/home-finders-coastal` (3.8G) | the agency's own public website — also bound on port 1050 at the server's public IP |
| `themandatecompany.co.za` / `www.themandatecompany.co.za` | `/var/www/hfc-website` (182M) | |
| `corexweb.co.za` / `www.corexweb.co.za` | `/var/www/corex-os-website` (117M) | also the port-8095 `default_server` |
| `performance.hfcoastal.co.za` (+ port 8083, `127.0.0.1:8088`) | `/var/www/agent-targets` (484M) | multiple vhosts point at this one docroot |

**If whoever plans the move only knows about CoreX, this migration takes down
four other live sites with no warning.** Each of these needs its own explicit
decision — move it too, stand it up somewhere else, or knowingly retire it —
made before cutover, not discovered after. None of them were touched during
tonight's cleanup for exactly this reason.

## 1. Put `storage/app` on the data volume from day one

On this box, `storage/app` is ~27GB (14G property photos, 11G private client
documents) sitting on the small 38G system disk, while a 197G data volume
(`/mnt/HC_Volume_103099143`) sits alongside with 46G free. That split happened
by accretion, not design. On the new server, configure `storage/app` (or all of
`storage/`) to live on the data volume from the first deploy — a symlink
(`storage/app -> /data/corex-storage/app`) or a mounted filesystem path, set up
before the app ever writes a file there. Retrofitting this later means moving
27GB of *live client documents* on a running system; doing it at provisioning
time costs nothing.

## 2. Cache store is database-backed — put Redis on the new box

`CACHE_STORE=database` (and `SESSION_DRIVER=database`) right now — every
`Cache::get()` is a MySQL round-trip. Measured 11 per MIC page load tonight.
This is a provisioning requirement for the new server, not a code change:
install Redis, then flip `CACHE_STORE` (and ideally `SESSION_DRIVER`) to
`redis` once it's there. The application code already supports it; nothing
CoreX-side blocks this except that nothing has installed Redis yet.

## 3. Queue is database-backed too — 7 supervisor pools, 21 processes (live)

`QUEUE_CONNECTION=database`. Live runs 7 Supervisor programs / 21 worker
processes off `/etc/supervisor/conf.d/corex-worker.conf`:
default+bg_removal (×2), matching (×1), mail (×4), webhooks (×1),
buyer-matching (×1), p24import (×8), p24images (×4). Staging has its own
3 programs / 13 processes in the same file. This config does **not** travel
automatically — it needs to be recreated on the new box's Supervisor install.
If Redis goes in per #2, queues should probably move to `redis` too at the
same time rather than staying on `database` out of inertia.

## 4. No logrotate existed for Laravel's own logs — must exist from day one on the new box

Fixed tonight on this box (`/etc/logrotate.d/corex-laravel`, daily/14-day/
compress/copytruncate, covering both `/corex/storage/logs/*.log` and
`/corex-staging/storage/logs/*.log`), but the fact that **no such config
existed at all** — `/etc/logrotate.d/` had entries for nginx, php-fpm, mysql,
nothing for Laravel — is itself the finding. Live's `laravel.log` had grown to
112MB unbounded before tonight; Staging's to 43MB. Whoever provisions the new
box needs to ship a logrotate config for these logs as part of initial setup,
not add it after they're already 100MB+. `copytruncate` matters specifically
because these logs are held open continuously by long-running PHP-FPM/queue
workers that are never told to reopen their file handle.

## 5. Two pre-deploy mysqldumps under `/var/backups/hfc` — deliberately left in place

`nexus_os-pre-deploy-20260822-111156.sql.gz` (137M) and
`hfc_staging-pre-deploy-20260820-201458.sql.gz` (71M), 208MB total, sitting on
the root filesystem (`/var/backups`) rather than the data volume — technically
a violation of this project's own disk-hygiene rule (dumps belong on
`/mnt/HC_Volume_103099143`). **Deliberately not deleted or moved tonight**:
days before a server migration is exactly when you want a rollback point, and
moving them right before cutover is the wrong moment to touch them. On the new
box, mysqldump output should default to the data volume from the start so this
class of dump never lands on the system disk in the first place. Separately,
the *real* nightly backup mechanisms are correctly configured already and need
to be recreated on the new box, not just copied: `/etc/cron.d/nexus_os-backup`
(nightly 02:30 → `/mnt/HC_Volume_103099143/db-backups`, 14-day retention) and
the off-box restic-to-Hetzner job (`/etc/cron.d/corex-offbox-backup`, 03:30
nightly + a 09:15 health check) — the latter needs new SFTP/restic credentials
for whatever destination the new box uses.

## 6. Other artefacts of this box, not of CoreX

- **DISK CRITICAL alerting is a bare cron script**, not real monitoring:
  `*/5 * * * * /mnt/HC_Volume_103099143/bin/disk-alarm.sh` checks `/` and the
  data volume against 85%/92% thresholds and does a plain `wall` broadcast to
  every terminal on the box with no debounce — it re-fires every 5 minutes for
  as long as the condition holds, which is why every pane got spammed tonight.
  Worth replacing with real monitoring (or at least a fire-once/hour debounce)
  on the new box rather than recreating the same script verbatim.
- **Stale/disabled cron entries nobody has revisited**: Staging's own
  `schedule:run` cron has been commented out since 2026-08-21 ("staging DB
  refresh in progress") and never re-enabled; a QA1 sync job has been
  commented out since 2026-07-20. If Staging's scheduler being off for two
  days has caused a real gap (reminders, digests, anything time-based), that
  surfaces as "why didn't X run" rather than an obvious alarm. Worth deciding
  deliberately on the new box rather than copying a commented-out line and
  forgetting why.
- **Root-owned files inside www-data-served checkouts**: found live's own
  `storage/logs/*.log` with ~27 root-owned files (someone ran artisan/php
  directly as root against a served checkout instead of `sudo -u www-data`),
  and Staging's entire `vendor/` tree (3200+ files) root-owned from a
  `composer install` run as root on the shared checkout. Neither breaks
  anything today because permissions still happen to be readable, but it's
  exactly the class of problem that has bitten this project before (see the
  vendor/autoloader incidents already in `CLAUDE.md`). Start the new box with
  every write to a served checkout going through the FPM user, never root.
- **A `/video-boom` cron job** (`node cleanup-temp.js`, nightly at 02:00) is
  an entirely unrelated tenant on this box — not CoreX, don't carry it over
  by habit when copying root's crontab.

## 7. MySQL: grant `SET_USER_ID` to every app-DB user on day one — the test suite silently can't bootstrap without it

Found tonight (cc2) diagnosing "no lane can run the test suite": this box has
`log_bin_trust_function_creators=OFF` with binlog `ON`. `database/schema/mysql-schema.sql`
(the snapshot `RefreshDatabase` loads for a fast test-DB bootstrap) contains literal
`CREATE TRIGGER` DDL for the AT-321 / AT-321-C audit-backstop triggers. Creating a trigger
under those binlog settings requires `SUPER` or the narrower MySQL 8 `SET_USER_ID` dynamic
privilege — without it:

```
ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is
enabled (you *might* want to use the less safe log_bin_trust_function_creators
variable)
```

Laravel's schema-load pipes the whole snapshot through the plain `mysql` CLI in one
shot (`MySqlSchemaState::load()`), which aborts on the *first* SQL error — so this
isn't a soft/skippable failure, it's a **fatal, whole-bootstrap-aborting** one, and it
hits early (the `contacts` trigger sorts alphabetically near the top of the dump).
Confirmed directly as `nexus` (Staging's app-DB user) on a scratch trigger — reproduces
the exact error above. `corexqa1`, `corexqa2`, `corexdev`, and `corex_test` on this box
already carry `SET_USER_ID` and are unaffected; `nexus` does not have it and is broken.

**On the new server, grant this to every app-DB user at provisioning time, not after
someone discovers the suite is broken again:**

```sql
GRANT SET_USER_ID ON *.* TO '<app-db-user>'@'localhost';
-- and for any user connecting over TCP 127.0.0.1 too (Laravel's .env DB_HOST), e.g.:
GRANT SET_USER_ID ON *.* TO '<app-db-user>'@'127.0.0.1';
```

Not full `SUPER` — `SET_USER_ID` is the narrow privilege that covers exactly this case
(creating a routine/trigger/view with binlog on) and grants zero data access on its own;
it only unlocks trigger/routine creation in schemas the user can already write to.

**Also worth carrying over — a smaller, separate friction point:** app-DB test users on
this box only have `ALL PRIVILEGES` on specific, individually pre-created
`hfc_dash_test_<N>` schemas — no wildcard — except `corex_test`, which already has
`` GRANT ALL PRIVILEGES ON `hfc\_dash\_test%`.* `` (a wildcard grant). A worktree that
computes a brand-new numbered `TEST_DB_DATABASE` nobody has granted yet gets a flat
"Access denied," unrelated to the SUPER/`SET_USER_ID` issue above but equally blocking.
Grant every app-DB test user the same wildcard pattern from day one on the new box.

*(Update, 2026-08-23 21:11 SAST: Johan approved. `GRANT SET_USER_ID ON *.* TO 'nexus'@'localhost';`
is now applied on THIS box's live MySQL — confirmed via a `SHOW GRANTS`
diff showing exactly that one line added, and by an actual passing test run that created all
four AT-321/AT-321-C triggers under `nexus`'s own name (`Definer: nexus@localhost`), which is
the thing that used to fail with error 1419. Full before/after grants, the exact statement,
and the proof are in `.ai/audits/2026-08-23-test-suite-health-diagnosis.md` §4. This note's
own recommendation — grant this to every app-DB user at provisioning time on the NEW server —
still stands unchanged: this fixes the current box, it doesn't mean the new server inherits it
automatically.)*

---
*Companion to tonight's disk-cleanup: 45 stale git worktrees reclaimed
(~5.5GB) from `/` and `/tmp` after per-worktree verification (branch refs
confirmed to persist, or pushed to origin first); the current detached-HEAD
commit that had no branch was tagged `archive/qa1-comp-stock-cascade` before
its worktree was removed, so it stays reachable. Nothing was deleted without
Johan's explicit confirmation. Full per-worktree table in the conductor chat
log.*
