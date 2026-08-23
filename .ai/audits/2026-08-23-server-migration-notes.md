# Server migration notes — for whoever sets up the new box

Written 2026-08-23 during a disk-cleanup pass on the current box (85-86% full on a
38G system disk). The live sites move to new hardware this week. These are the
things we found that are artefacts of *this* box, not properties of CoreX —
don't carry them over as-is.

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

## 4. Other artefacts of this box, not of CoreX

- **DISK CRITICAL alerting is a bare cron script**, not real monitoring:
  `*/5 * * * * /mnt/HC_Volume_103099143/bin/disk-alarm.sh` checks `/` and the
  data volume against 85%/92% thresholds and does a plain `wall` broadcast to
  every terminal on the box with no debounce — it re-fires every 5 minutes for
  as long as the condition holds, which is why every pane got spammed tonight.
  Worth replacing with real monitoring (or at least a fire-once/hour debounce)
  on the new box rather than recreating the same script verbatim.
- **Two ad-hoc mysqldumps under `/var/backups/hfc`** (137M `nexus_os`, 71M
  `hfc_staging`, both "pre-deploy" dumps) sit on the root filesystem, in
  violation of the project's own disk-hygiene rule (dumps belong on the data
  volume). Separate from these, there's a proper root cron
  (`/etc/cron.d/nexus_os-backup`, nightly 02:30 → `/mnt/HC_Volume_103099143/
  db-backups`, 14-day retention) and an off-box restic-to-Hetzner job
  (`/etc/cron.d/corex-offbox-backup`, 03:30 nightly + a 09:15 health check).
  Both of those are real infrastructure that needs to be recreated on the new
  box (new SFTP/restic credentials for the off-box leg); the ad-hoc
  `/var/backups/hfc` dumps do not — they're one-off deploy artefacts.
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
- **No logrotate for Laravel's own logs.** `/etc/logrotate.d/` has entries for
  nginx, php-fpm, mysql — nothing for `storage/logs/*.log`. Live's
  `laravel.log` is 112MB and growing; Staging's is 43MB. Add a logrotate
  config for both on the new box from day one (daily/weekly rotation,
  compress, sane retention) instead of letting it grow unbounded again.
- **`/var/www` carries 4.5GB of separate, unrelated live sites** on this same
  box (`home-finders-coastal` — the actual `hfcoastal.co.za` site — plus
  `agent-targets`, `hfc-website` i.e. `themandatecompany.co.za`, and
  `corex-os-website` i.e. `corexweb.co.za`/the port-8095 default vhost). All
  four are confirmed live via nginx's own active config, not just old
  checkouts. If the migration is CoreX-only, these need their own decision —
  they don't move automatically just because CoreX does, and they weren't
  touched during tonight's cleanup for exactly that reason.
- **A `/video-boom` cron job** (`node cleanup-temp.js`, nightly at 02:00) is
  an entirely unrelated tenant on this box — not CoreX, don't carry it over
  by habit when copying root's crontab.

---
*Companion to tonight's disk-cleanup inventory (git worktrees, ~4.7GB reclaimable
on `/` from stale worktrees under `/` and `/tmp` — see conductor chat log for
the full per-worktree table; nothing was deleted without Johan's confirmation).*
