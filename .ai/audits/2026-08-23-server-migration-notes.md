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

## `/mnt/HC_Volume_103099143/corex-storage/` — optional housekeeping, NOT a one-way door

*(Added 2026-08-24, corrected the same day. An earlier version of this
section called this volume a "sole recovery source" for 11 signed documents
and said the migration was a one-way door that would permanently lose them
if handled wrong. That was wrong, and stated with more certainty than it
deserved — flagging it explicitly here so nobody who finds the old wording
in git history trusts it. The precise account, from Johan: agent Maggie
Venter attempted a real go-live on e-sign in March–May 2026, sending real
mandate/disclosure documents for real properties to real recipients. It
failed — too many errors for the documents to land with recipients — and
she abandoned e-sign for the manual/paper process. The 11
`signature_templates` rows this pointed at (Set A,
`.ai/audits/perf-sweep-and-blank-pdf-findings-2026-08-23.md` §4) are the
wreckage of that failed attempt: nothing completed, nothing was ever relied
on, no counterparty ever received or signed anything through them. Real
attempt, real intent, but no live legal record and no recovery obligation
— see the forensic follow-up investigating what actually failed,
`.ai/audits/2026-08-24-esign-failed-golive-forensics.md`. Separately, Set B
in that same section (42 `user_documents` rows — FFC certificates, ID
copies, tax clearance certs) turned out to be fill-and-print artefacts:
CoreX types text onto a document that's then printed, and these are
disposable by design, not a data-loss event.)*

Carrying this volume (19,405 files, 6,246,725,422 bytes ≈ 5.82 GiB as of
2026-08-24) over to the new box's data volume is fine to do as routine
housekeeping if it's convenient during the move, but it is **not** required,
not blocking, and not worth spending migration-night attention on. Nothing
on it is a recovery source for anything currently live.

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
- **A `/video-boom` cron job** (`node cleanup-temp.js`, nightly at 02:00) is
  an entirely unrelated tenant on this box — not CoreX, don't carry it over
  by habit when copying root's crontab.

## 7. Root-owned files — this is structural, not a one-off mistake (update from a
   full survey done later the same night; supersedes an earlier, thinner note here)

The original framing here ("someone ran composer as root once") undersold it.
A full survey found `/corex` 15,667 root-owned entries, `/corex-staging` 17,332,
`/corex-qa1` 9,791 — dominated by `node_modules` (~12k each), `vendor` (3,242 on
Staging/QA1, zero on live), source files, and 393-4,624 objects inside `.git`
itself.

**Actual root cause**: every Claude Code lane on this box runs as uid 0. Any
`git pull/fetch/merge/checkout`, `composer install`, `npm install`, or artisan
command a lane runs directly against a served checkout creates new files owned
by root — that's how Unix ownership works, not a mistake. It's continuous and
self-reinflicting: files touched minutes earlier by a live seeder fix showed up
as the newest root-owned entries in the same survey. A one-time `chown` of the
source tree is dirty again the moment anyone next runs `git`.

**The masked failure mode**: `git config --global --get-all safe.directory`
already lists `/corex`, `/corex-staging`, `/corex-qa1`, `/corex-qa2` — several
duplicated (confirmed: 4×/corex-staging, 4×/corex, 3×/corex-qa1 in the current
list), i.e. git's dubious-ownership guard has been hit and worked around
repeatedly by whitelisting, not by fixing the mixed ownership. **A fresh box
won't have that whitelist pre-populated** — day one on the new server will hit
`fatal: detected dubious ownership in repository` the first time anyone runs
git as a different effective user than whoever created the checkout.

**What's actually worth fixing vs. not**: `storage/` and `bootstrap/cache/`
MUST be `www-data` — that's the only place php-fpm (confirmed www-data-only via
pool config) writes at runtime, and mixed ownership there is what causes real
functional breakage (blocked unlink, can't overwrite a cache/log file). These
were fixed on QA1 and Staging same night (0 root-owned entries left in either,
confirmed `www-data:www-data`, `php artisan about` still boots). Verified
independently: QA1's `storage/` is `2775` (setgid — a root-created file there
self-corrects to group `www-data`); Staging's and live's `storage/` are only
`775`, no setgid, so a root-created file there does **not** self-correct.
Source tree / `vendor/` / `node_modules/` / `.git` internals are functionally
harmless root-owned as long as permission bits stay world/group-readable (they
do) — php-fpm never writes there, so a sweeping `chown` of those was
deliberately skipped: it would just be re-dirtied by the next git op from any
lane, isn't what's actually breaking anything, and staging/live had active
concurrent lane work at the time that a mid-checkout chown could collide with.

**For the new box**: (1) pre-seed `safe.directory` for whatever the new
checkout paths will be on day one, not after the first failure; (2) `chown
www-data:www-data` on `storage/` and `bootstrap/cache/`, **with `chmod g+s`
(2775)** so it self-maintains, as a standing post-deploy step, not a one-time
fix; (3) accept root ownership of the git-managed source tree as the normal
state of this workflow (lanes deploy as root, always will) rather than
fighting it — the fix is narrower than "chown everything," it's "keep the
runtime-writable dirs consistently www-data and stop being surprised root owns
the rest."

## 8. MySQL: grant `SET_USER_ID` to every app-DB user on day one — the test suite silently can't bootstrap without it

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
