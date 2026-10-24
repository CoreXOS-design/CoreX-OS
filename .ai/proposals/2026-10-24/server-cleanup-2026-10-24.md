# Server clean-up inventory — 24 Oct 2026 (READ-ONLY, nothing was changed)

Box: 91.99.130.85 (Hetzner, aarch64, 16 cores, 30.5 GB RAM, 4 GB swap, root 37 GB, data volume 196 GB).
Real live CoreX = 62.238.31.82 (corexos.co.za). This box is build/test + a few public sites that still resolve here.
Method: process/service/config/file inspection only. No database was connected to. No secret values were printed (only key names, set/empty, and host names).
Raw probe scripts: `/tmp/claude-0/-corex-staging/dd24793d-cb1f-4bd2-9d23-30b1b6e8f702/scratchpad/s1..s10_*.sh`

---------------------------------------------------------------------------------------------------
## 0. TOP FLAGS — things that point at the real live server or real mail

1. **`/corex` ("live-testing") is wired to the REAL live server in three places.** This is what all `corex-worker-live*`, `corex-worker-p24import_*`, `corex-worker-p24images_*` programs run from, and its scheduler runs every minute (root crontab).
   - `APP_URL=https://corexos.co.za` (resolves to 62.238.31.82) and `P24_EXDEV_IMAGE_BASE_URL` = corexos.co.za. Any link or image URL it generates points at real live.
   - `WAHA_BASE_URL=http://62.238.31.82` with an API key set → it can talk to the real live WhatsApp gateway.
   - `P24_EXDEV_API_URL` = api.property24.com with `P24_EXDEV_SANDBOX=false` → the real Property24 production API, not a sandbox.
   - Also holds live OpenAI / Anthropic / Google / Meta keys (cost if jobs fire).
   - Good news: `DB_HOST=127.0.0.1` (local nexus_os, not the live DB), `MAIL_HOST=127.0.0.1:1025` (Mailpit, no real mail leaves), `P24_IMPORT_ENABLED=false` (inbox import off). No cached config file (bootstrap/cache has no config.php) so the .env values above are the ones actually used.
   - Not verified: whether the scheduler actually pushes to P24 or WhatsApp today. I did not look at what its scheduled commands do, and I did not connect to anything.
   - Compare: Staging, QA1, QA2 and Demo have NO P24 API URL and NO WAHA URL (QA2's WAHA is 127.0.0.1). Staging PP sandbox = true.
2. **Real mail transport on this box:** `/var/www/home-finders-coastal/.env` (hfcoastal.co.za, the HFC public site) sends through `mail.corexos.co.za:587` (102.222.124.20). The .env has `MAIL_MAILER=log` and then `MAIL_MAILER=smtp` again later, so the last one wins = real SMTP. That is expected for a public production site, and is another reason it belongs on the live server. `themandatecompany.co.za` (/var/www/hfc-website) uses `envoy.aserv.co.za`, also real mail. `corexweb.co.za` uses `mail.corexweb.co.za`.
3. **Firewall is OFF** (`ufw inactive`) and these listen on all interfaces: 8080 (code-server), 8766 (a python3 process), 1000 and 3000 (node), 631 (CUPS printing), 1050/1337/8082/8083/8084/8090/8095 (nginx test ports). If there is no Hetzner cloud firewall in front, they are open to the internet. I could not see the Hetzner firewall from here.
4. **No scheduler is running for Staging.** Root cron line is commented "DISABLED 2026-08-21 … re-enable deliberately"; the www-data cron line runs `cd /hfc-staging`, a folder that does not exist (log has been 0 bytes since 19 Sep). Staging is the one environment that needs to be current, so Johan should confirm this is intended.

---------------------------------------------------------------------------------------------------
## 1. MEMORY AND CPU

Memory: 31.3 GB total, **23.5 GB used**, 7.7 GB buff/cache, 7.8 GB available. Swap 4.0 GB of 4.0 GB used (swappiness 10). Load 0.6 on 16 cores — CPU is not the problem, memory is.
Up 66 days (reboot pending since 20 Aug — see section 3).

### Why memory looks full with "nothing" running
| What | RSS | Notes |
|---|---|---|
| MySQL main (`mysqld`) | **12.8 GB** (+2.3 GB in swap) | Buffer pool is set to 4 GB (persisted setting), so ~9 GB is bloat after 52 days up: 43 open connections, 16 MB sort buffers, performance_schema. A restart returns most of it. Serves nexus_os, hfc_staging, corex_qa1, corex_qa2, corex_demo, home_finders, corex_site and leftovers. |
| Lane-test MySQL (Docker `corex-lanetest-mysql`) | 2.6 GB (capped 3 GB) | Tests only, port 3317. KEEP. |
| 45 PHP queue workers | 3.9 GB | ~91 MB each. **24 of them are the p24import/p24images pools (8+4 for live-testing, 8+4 for staging) ≈ 2.2 GB** on a test box. |
| 6 Claude lanes (cc1–cc6) | 2.7 GB (+120 MB swap) | Up 18 days. Rules say 2 working + 1 spare. |
| php-fpm | 1.35 GB | 8.3 pool 803 MB (live-testing only), 8.2 pool 330 MB (qa1/qa2/staging/demo/hfcoastal/themandatecompany), 8.4 pool 215 MB (corexweb, performance). |
| hf-ai (uvicorn :3100) | 642 MB (+408 MB swap) | Feeds performance.hfcoastal.co.za. |
| nginx 224 MB, code-server 100 MB, Mailpit 94 MB (+50 swap), PM2 58 MB, 3 bg-removal uvicorn ~80 MB, Postgres 31 MB | | |

What is in swap (2.4 GB mysqld, 1.28 GB uvicorn mostly hf-ai, 122 MB claude, 50 MB mailpit) is stale pages, not thrash. Fixing the MySQL bloat and hf-ai fixes swap.

### Every long-running program, by environment
**Supervisor (41 programs, all user www-data):**
| Program (count) | Code dir | Env | APP_URL / DB |
|---|---|---|---|
| corex-worker-live (2), -live-matching (1), -live-mail (4), -live-webhooks (1), -live-buyer-matching (1) | /corex | live-testing | APP_URL https://corexos.co.za (REAL live name), DB_HOST 127.0.0.1, DB nexus_os, mail → Mailpit 127.0.0.1:1025 |
| corex-worker-p24import (8), -p24images (4) | /corex | live-testing | same as above |
| corex-worker-staging (1) | /corex-staging | staging | https://staging.corexos.co.za, 127.0.0.1, hfc_staging, mail → Mailpit |
| corex-worker-staging-p24import (8), -p24images (4) | /corex-staging | staging | same |
| corex-worker-demo (1) | /mnt/HC_Volume_103099143/corex-demo | demo | https://demo1.corexos.co.za, 127.0.0.1, corex_demo, mail → Mailpit |

The supervisor programs were all restarted ~11 minutes before this inventory (someone ran a supervisor reload); not by me.

**systemd:**
| Unit | Env / purpose | RSS-ish | Verdict |
|---|---|---|---|
| corex-qa1-queue, -buyer-matching, -images, -mail, -mail-slow | qa1 (/corex-qa1, corex_qa1) | 5 × ~92 MB | KEEP |
| corex-qa2-queue | qa2 (/corex-qa2, corex_qa2) | 81 MB | ASK (see QA2) |
| hfc-staging-queue | staging, `--queue=default` | 69 MB | Duplicate of corex-worker-staging (which already handles default) → PROBABLY REMOVE |
| corex-bgremoval / -staging / -live (uvicorn 3106/3107/3108) | 3 copies of the same bg-removal service, each 787 MB on disk | ~25 MB each | ASK — one would do on a test box |
| hf-ai (uvicorn 3100) | AI for performance.hfcoastal.co.za | 642 MB | public-site dependency → moves with it |
| myapi (uvicorn 8001, /opt/myapi, last touched 7 Feb) | unknown | 15 MB | ASK |
| datepage (node, /Andre/Sandbox), pm2 `myapp` (:1000) | Andre sandbox, last touched 22 Aug | ~21 MB | ASK |
| notif-api (gunicorn :8000) | **crash-looping: 1,786,435 restarts**, exit 203 (its venv is missing), ~4,450 journal lines/hour | 0 | PROBABLY REMOVE |
| corex-cc-bridge (node, /opt/corex-cc-bridge) | lane bridge | 5 MB | KEEP |
| mailpit (:1025/:8025, all envs' mail) | system | 94 MB | KEEP |
| code-server@root (:8080) | VS Code in browser, open to all interfaces | 100 MB | ASK (still used?) |
| docker + containerd | only the lane-test MySQL | 75 MB | KEEP |
| mysql, nginx, php8.2/8.3/8.4-fpm, postgresql@16 (70 MB of data, only `heartwood`?), cron, ssh, supervisor, pm2-root | system | | KEEP / see section 2 |
| avahi-daemon, ModemManager, wpa_supplicant, upower, snap.cups.cupsd, cups-browsed, multipathd, open-iscsi, auditd, atd, apport, snapd, ssl-cert, open-vm-tools (+qemu-guest-agent: only one is needed), unattended-upgrades | desktop/laptop leftovers | ~150 MB total | PROBABLY REMOVE (keep qemu-guest-agent, unattended-upgrades) |

**PM2:** docuperfect-api (/Docuperfect-live, docs.hfcoastal.co.za), heartwood + heartwood-queue (/heartwood, DB heartwood is 0 GB), tmc-backend (/tmc/backend, :8082 site), myapp (/Andre), docuperfect-api-dev (stopped, **7,813,444 restarts** logged). All status "online" with ~0 b memory shown because PM2 shows no pid for most (they may not actually be running — ASK).
**Docker:** one container, `corex-lanetest-mysql` (mysql:8.0.46, 2.55 GB / 3 GB limit, restart unless-stopped). One image 1.09 GB.
**tmux:** cc1–cc6 (Claude, acceptEdits). Night Watchdog not listed as a tmux session.
**Cron (root):** disk-alarm every 5 min; live-testing scheduler every minute (`/corex`); demo scheduler every minute; QA1 weekly sync (disabled 20 Jul); staging scheduler (DISABLED). /etc/cron.d: corex-housekeeping (tmp cleanup hourly, lane-test-sweep Sunday 04:10), corex-offbox-backup (03:30 restic + 09:15 health check), nexus_os-backup (02:30 nightly mysqldump), hfc-coastal-warm (every 5/10 min for hfcoastal.co.za cache/image warm), certbot, sysstat, e2scrub, php sessionclean. www-data cron: broken staging line (see flag 4).

---------------------------------------------------------------------------------------------------
## 2. INSTALLED SOFTWARE THAT LOOKS UNUSED

- **PHP:** 8.2-fpm (qa1, qa2, staging, demo, hfcoastal.co.za, themandatecompany.co.za), 8.3-fpm (live-testing only, 30 max children, 803 MB), 8.4-fpm (corexweb.co.za, performance.hfcoastal.co.za; also the CLI default `php` = 8.4 and the live-testing scheduler runs under 8.4), 8.5-cli only (no pool). Each fpm pool is `[www]` as www-data.
- **Databases:** one MySQL 8.0.46 (data on the volume), one MySQL 8.0.46 in Docker (tests), one PostgreSQL 16 (70 MB, almost certainly for heartwood/old stuff). No redis, no elasticsearch, no mongo, no memcached.
- **Node:** system node v24.15, nvm has v22.17 and v24.15 (223 MB nvm + 361 MB npm cache).
- **Python venvs:** hf-ai 399 MB, myapi 135 MB, 3 × bg-removal 619 MB each (+168 MB code each).
- **Whisper / transcribe:** `/opt/corex-transcribe/whisper.cpp` + `/mnt/.../corex-transcribe/models` (**4.4 GB**). No transcribe process or service is running; the script only exists as `scripts/transcribe/transcribe.sh` inside each checkout. ASK.
- **Docker images:** one (mysql:8.0.46, needed). No build cache, no volumes.
- **Snap:** chromium (used 21 Oct — lanes use it for render checks), cups, gnome-46-2404, gtk-common-themes, mesa-2404, core22/24/26, snapd. `/snap` 4.1 GB + `/var/lib/snapd` 2.2 GB on the small root disk. cups + gnome + mesa + gtk are almost certainly unused.
- **Others:** apache2 (+apache2-bin etc.) installed but disabled, never run; `google-cloud-sdk` 843 MB (last used 2 Jun); puppeteer cache 628 MB (used 23 Oct); `open-vm-tools` AND `qemu-guest-agent`; `chromium` apt package also upgradable (the snap is the one used).
- **Kernels:** 6.8.0-137 (running) and 6.8.0-138 (installed, needs reboot). /boot is only 198 MB. Nothing old to purge yet.

---------------------------------------------------------------------------------------------------
## 3. UPDATES

- Ubuntu 24.04.4 LTS, kernel 6.8.0-137-generic, aarch64. 24.04 is supported to 2029 — no need to jump release, just patch.
- **246 packages upgradable, 165 of them security.** apt lists were refreshed 23 Oct. unattended-upgrades is enabled but is clearly not keeping up (its log shows it starting then nothing installed; Ubuntu Pro/ESM not attached).
- **Reboot required since 20 Aug** (`linux-image-6.8.0-138-generic`, `linux-base`) — 65 days with a pending kernel security update.
- Upgradable includes docker-ce / containerd.io (will restart the lane-test MySQL container), curl, ca-certificates, ghostscript, chromium, apparmor, cloud-init.
- A reboot takes down all 6 lanes (tmux), every env for ~2–3 min, and cold-starts MySQL (which is what frees the 9 GB). Services are all `enabled` so they come back by themselves.

---------------------------------------------------------------------------------------------------
## 4. DISK

### 4a. Root `/` — 37 GB, 29.5 GB used, **6.2 GB free (79–83%)** — this is the disk at risk
| Item | Size | Note |
|---|---|---|
| /usr | 8.1 GB | incl. google-cloud-sdk 843 MB, firmware 729 MB, code-server 443 MB |
| /var/www | 7.0 GB | **home-finders-coastal 6.4 GB (storage 6.0 GB)**, agent-targets 311 MB, corex-os-website 166 MB, hfc-website 108 MB |
| /snap + /var/lib/snapd | 4.1 + 2.2 GB | snaps (see above) |
| /root | 3.8 GB | .claude 1.3 GB (projects/transcripts 1.2 GB), .cache 884 MB (puppeteer 628, restic 217), .vscode-server 461, .npm 361, .nvm 223, backups 167, ghost-trace 166, incident-2026-08-12 38, .pm2 97 |
| /tmp | 1.0 GB | /tmp/claude-0 471 MB + old walk/cape/qa1proof dirs |
| /var/backups | 1.1 GB | `/var/backups/hfc` = 4 staging pre-deploy dumps of 188 MB (12–14 Oct) + LATEST symlink |
| /var/log | 363 MB | journal 192 MB (cap 200 MB), syslog 72, audit 36, sysstat 15, btmp 14, auth 13 |
| /opt | 639 MB | |
| /home | 247 MB | |

Note: `/corex`, `/corex-staging`, `/corex-qa1`, `/corex-qa2` are bind mounts of the DATA volume, not root (confirmed with findmnt). They are not what is filling root.

### 4b. Data volume — 196 GB, 159 GB used, **28.7 GB free (81%)**
| Item | Size | Env / note |
|---|---|---|
| MySQL data dir | **40 GB** | 21 GB are binlogs (see 4d) |
| /corex (live-testing) | 28 GB | storage 28 GB (public 14 + private 14) |
| /corex-staging | 19 GB | storage 18 GB (public 15 + private 3.2) |
| /corex-qa1 | 19 GB | storage public 7.5 + private 9.8 |
| /corex-qa2 | 12 GB | storage public 5.9 + private 5.5 |
| corex-storage (June) | 6.1 GB | looks like an old copy of storage; one symlink in staging points into knowledge-documents. ASK |
| lanetest-mysql-data | 5.4 GB | 26 persistent test schemas. KEEP |
| corex-worktrees | 4.5 GB | 25 dirs; only 12 worktrees are registered, ≥13 are orphans |
| other old checkouts | ~6.5 GB | corex-dev 414M, corex-dev-2 204M, corex-dev-3 2.5G (+ -wt 196M), corex-dev-5 352M, -6 200M, wt-cc5-rentals-properties-audit 591M, corex-highlighter-* 382M+367M, corex-approval-legend-opacity 377M, corex-rental-app-reopen 367M, corex-mailguard 216M, corex-reconcile 204M, corex-at366 199M, promo-2026-10-08 180M |
| corex-transcribe/models | 4.4 GB | whisper |
| waha-validation | 1.6 GB | |
| containerd | 1.0 GB | docker image store for the test MySQL. KEEP |
| bgremoval-spike 850M + 3 bg-removal service copies 787M each | 3.2 GB | |
| corex-demo | 1.9 GB | demo, stays |
| Backups/dumps (see 4c) | ~8 GB | |
| 52 small dirs under 100 MB | 0.7 GB | |

Total file storage held by the 4 CoreX test envs: ~77 GB — **each env carries a full copy of live's uploaded files.** This is the biggest single saving available.

### 4c. Databases (from data files; no connection)
| Schema | Size | Used by | Last written / used |
|---|---|---|---|
| nexus_os | 3.86 GB | live-testing (/corex) | tables written 24 Oct 06:45; users table 22 Oct; 1,154 browser hits yesterday, last 01:38 today. **Still in use.** |
| hfc_staging | 3.75 GB | Staging | sessions 24 Oct 05:33, users 22 Oct. KEEP |
| corex_qa1 | 3.79 GB | QA1 | live right now (06:45 today), users 23 Oct. KEEP |
| corex_qa2 | 3.42 GB | QA2 (Andre's lane) | users 20 Oct; no browser hits yesterday or today (305 on 20 Oct); log last 21 Oct. **Looks idle ~3 days.** |
| corex_demo | 0.56 GB | Demo | 4+9 browser hits on 23/24 Oct but 866/1,971 on 22/21 Oct; users table 14 Oct. Used in bursts for demos. |
| home_finders | 0.06 GB | hfcoastal.co.za | live right now |
| corex_site | ~0 GB | corexweb.co.za | live today |
| hfc_dash_test_* ×14 in the main MySQL | ~1.3 GB | leftover test DBs (newest 12 Oct) | stale — tests use the Docker instance now |
| corex_demo_restoretest, restoretest2 | 0.25 GB | 2 Sep restore tests | stale |
| ppra_letter_dev, corex_qa1_wt_cc2photos, corex_qa1_oas2909_verify, corex_reconcile_tmp, corex_qa1_walkthrough, corex_freshproof | ~0.55 GB | one-off verification DBs (5 Jul – 5 Oct) | stale |
| hfc, heartwood, corex_site, mysql.ibd 1 GB (system) | | | |

**Log-type tables inside the big DBs (per DB, ~same in nexus_os/qa1/staging):** domain_event_log 700 MB, notification_dispatch_log 636 MB, notifications 325–372 MB, p24_import_rows 284 MB, property_audit_log 145 MB, oversight_nudges 124 MB, agent_activity_events 81 MB, agency_webhook_deliveries 69 MB. About **2.3 GB of each 3.8 GB DB is log/event data.** QA2 has a similar mix.

### 4d. MySQL binlogs
208 files, **21 GB**, 18–24 Oct (≈3.5 GB/day). `binlog_expire_logs_seconds=604800` (7 days), max 100 MB each. Backups are nightly mysqldump + restic + Hetzner snapshots, so point-in-time binlog recovery is not what protects this box.

### 4e. Backups on this box
| Where | Size | Schedule / retention | Verdict |
|---|---|---|---|
| **Off-box restic → Hetzner Storage Box** (`corex-offbox-backup.sh`, cron 03:30) | cache 217 MB here | nightly, 7 daily/4 weekly/6 monthly; last OK 24 Oct 03:32 (214,709 files, 49 MiB stored); health check 09:15 | **KEEP** — it dumps nexus_os + hfc_staging transiently to /mnt/…/backup-staging (deleted after upload) |
| `nexus_os-nightly-backup.sh` (02:30) → /mnt/…/db-backups | 1.4 GB | keeps 7 nightlies + pre-deploy dumps 7 days | PROBABLY REMOVE (redundant with restic + Hetzner snapshots; and nexus_os is live-testing) |
| /mnt/…/corex-backups (pre-deploy, queue-job-archives) | 1.7 GB | manual | ASK |
| /var/backups/hfc (4 staging pre-deploy dumps) | 1.1 GB, root disk | manual | PROBABLY REMOVE (keep newest) |
| db-refresh-20261022-092153 | 747 MB | 22 Oct | ASK |
| qa1-refresh-20261022 / qa1-backup-prerefresh-20260815 / qa1-pre-sync-dump | 447 + 282 + 235 MB | | PROBABLY REMOVE |
| at173-live-backup-20260720, at173-fica-backup, at366-live-dedupe-backup, db-refresh-2026-08-21, db-refresh-*, backups/, config-backups, backup-staging etc. | ~1.0 GB | July–Aug | PROBABLY REMOVE after ask |
| evidence-holding-2026-08-24 | 691 MB | 24 Aug "evidence" | **ASK — may be needed for the pane-injection investigation** |
| old-claude0-scratch-2026-08-20 | 517 MB | | PROBABLY REMOVE |
| open-webui-backup-2026-06-10.tar.gz | 964 MB | June | PROBABLY REMOVE |
| /root/backups/corex-qa1-git-backup-20260907 167 MB, /root/db-backups 5 MB, ghost-trace 166 MB, incident-2026-08-12 38 MB | | | ASK (root disk) |

### 4f. Logs
- Journal 192 MB (capped at 200 MB). syslog 72 MB, audit 36 MB. nginx: **one shared `access.log` (no Host field)**, daily rotation, 14 kept, ~10 MB total. Laravel: logrotate daily/14 days for all envs, `LOG_LEVEL=debug` everywhere except home-finders. Sizes: /corex 46 MB, staging 1.9 MB, qa1 160 KB, qa2 428 KB, demo 3.8 MB. **Logs on disk are small; the real "log" weight is the DB log tables (4c) and binlogs (4d).**
- notif-api's restart loop writes ~4,450 journal lines per hour; docuperfect-api-dev has 7.8 million recorded restarts (PM2 logs under /root/.pm2, 97 MB).
- MySQL error log is empty (rotated), 7-day logrotate.

---------------------------------------------------------------------------------------------------
## 5. PUBLIC SITES ON THIS BOX

Traffic caveat: nginx writes ONE shared access log with no Host column, so I cannot get exact per-site counts. "Browser hits yesterday" = requests whose Referer is that site (counts only page-follow-up requests, understates real traffic). "Errors (2 days)" = nginx error-log entries naming that host. Total across all sites: ~26,000 requests/day (18k–40k over 6 days), ~1,700 unique IPs, ~40% crawlers/scanners (`/.env` ~700/day, `/wp-` ~340, `/.git` ~300).

| Site (server_name) | Root dir | PHP pool | DB | Disk | Last browser hit | Rough daily traffic | Classification |
|---|---|---|---|---|---|---|---|
| hfcoastal.co.za, www.hfcoastal.co.za (+ IP 91.99.130.85, port 1050) | /var/www/home-finders-coastal/public | 8.2 | home_finders (0.06 GB) | 6.4 GB (storage 6.0) | 24 Oct 06:41 | **~5,500 browser hits** + most of the ~12k 200s (/media, /build, /property, /listings, /images); 279 errors | **PUBLIC PRODUCTION — SHOULD MOVE.** Uses real SMTP (mail.corexos.co.za). Cache-warm cron every 5/10 min. cert expires 26 Nov. |
| corexweb.co.za, www.corexweb.co.za | /var/www/corex-os-website/public | 8.4 | corex_site | 166 MB | 24 Oct 06:29 | ~120–140 | **PUBLIC PRODUCTION — SHOULD MOVE.** cert 5 Dec. Real SMTP (mail.corexweb.co.za) |
| themandatecompany.co.za, www | /var/www/hfc-website/public | 8.2 | none | 108 MB | 23 Oct 08:35 | ~6 (408 errors → scanners) | **PUBLIC PRODUCTION — SHOULD MOVE.** Real SMTP (envoy.aserv.co.za). cert expires 24 Nov (30 days!) |
| docs.hfcoastal.co.za | /Docuperfect-live/dist + proxy :5001 (pm2 docuperfect-api) | none (node) | n/a | unknown | no browser hits in 5 days (1–8/day older); 254 errors | ~0–8 | **PUBLIC PRODUCTION — SHOULD MOVE (or retire; ASK)** — cert 5 Dec |
| performance.hfcoastal.co.za (+ :8083 and :8088 internal) | /var/www/agent-targets/agent-targets/public | 8.4 | n/a (APP_ENV=local!) | 147 MB (+ hf-ai 399 MB venv, 642 MB RAM) | 23 Oct 10:39 | own log: 13 lines today, 69 yesterday | **PUBLIC PRODUCTION — SHOULD MOVE** (agents' performance site + AI proxy). Running with APP_ENV=local. |
| arweb1.duckdns.org | proxy → :3000 | none | n/a | unknown | no info | unknown | ASK (not CoreX; who owns it?) |
| corex.hfcoastal.co.za | /var/www/html (static redirect) | none | n/a | 20 KB | – | tiny | KEEP for now — retired, 308s to corexos.co.za; its cert SAN is on the demo1 cert (CLAUDE.md says leave DNS+cert alone) |
| demo1.corexos.co.za (+ mail.demo1.corexos.co.za Mailpit UI) | /mnt/…/corex-demo/public | 8.2 | corex_demo (0.56 GB) | 1.9 GB | 24 Oct 05:20 | bursts: 1,971 on 20 Oct, 866 on 21 Oct, 4–9 since | **DEMO — may stay here** |
| staging.corexos.co.za (+ staging.corex.hfcoastal.co.za http-only alias, port 8084) | /corex-staging/public | 8.2 | hfc_staging | 19 GB | 24 Oct 03:11 | 63 browser hits yesterday; 1,111 errors (scanners) | **DEV/TEST (the one that needs live data)** |
| qatesting1.corexos.co.za | /corex-qa1/public | 8.2 | corex_qa1 | 19 GB | 24 Oct 06:45 (right now) | ~2,900 browser hits | **DEV/TEST — active** |
| qatesting2.corexos.co.za | /corex-qa2/public | 8.2 | corex_qa2 | 12 GB | 20 Oct (305 hits that day); 0 since | ~0 | **DEV/TEST — idle ~3 days** (Andre's lane; ASK) |
| live-testing.corexos.co.za | /corex/public | **8.3** | nexus_os | 28 GB | 24 Oct 01:38 | 1,154 browser hits yesterday | **DEV/TEST — misnamed "live"; still used; wired to real live (flag 1)** |
| tmc :8082 (/tmc/dist + pm2 tmc-backend :5007), docuperfect-dev :1337 (/Docuperfect-dev), boomdev :80 default (proxy :3001, empty logs), notif-api :8090 (broken), corex-os-website :8095, block-ip default server | | | | | | 6–47 error hits to ports 8082/8090/1337 | ASK — look like old experiments; open to all interfaces |
| agent-targets.bak.20260205_142439 in sites-enabled | | | | | | | PROBABLY REMOVE (duplicate vhost file loaded by nginx) |

TLS certs expiring soonest: themandatecompany.co.za 30 days, hfcoastal.co.za 33 days, qatesting1/2 37 days, corexweb + docs 42 days. They renew on this box via certbot — if a site is moved, its cert/renewal must move with it first.

---------------------------------------------------------------------------------------------------
## 6. CLEAN-UP PLAN (safest first). Nothing below has been done.

"Johan yes?" = deletes an environment, a database, a backup, or changes what a public site does.

| # | Step | Frees | Risk | Undo | Johan yes? |
|---|---|---|---|---|---|
| 1 | Cap journald at 50 MB, vacuum; `apt-get clean`; clear `/root/.npm` cache; delete `/tmp` scratch dirs older than 7 days (not `/tmp/claude-0`) | root ~0.6–1.0 GB | none | regenerate on demand | no |
| 2 | Tame the worker pools: p24import 8→2 and p24images 4→1 for both live-testing and staging; remove duplicate `hfc-staging-queue` | RAM ~2.0 GB (21 workers × 91 MB + 69 MB) | low: slower bulk imports on test | set numprocs back, `supervisorctl update` | tell Johan, no yes needed (reversible tuning) |
| 3 | Disable the dead/desktop services: notif-api (crash loop), ModemManager, wpa_supplicant, avahi, upower, cups + cups-browsed, open-iscsi, multipathd, apport; purge apache2 (never ran); pick one of open-vm-tools / qemu-guest-agent | RAM ~150 MB, closes port 631, stops 100k+ journal lines/day | low | `systemctl enable --now <unit>` | no for the OS ones; ASK for notif-api in case it is wanted |
| 4 | Fix the real-live pointers on `/corex` (blank WAHA_BASE_URL, set P24_EXDEV sandbox true or blank, APP_URL to live-testing.corexos.co.za), then reload workers | removes risk, no space | low-medium: live-testing behaves differently (no WhatsApp/P24 push) | restore the .env (backup already at /mnt/…/corex-livetesting-env-backup-2026-10-18.env) | **YES** |
| 5 | MySQL: shorten binlog retention to 1 day (`SET PERSIST binlog_expire_logs_seconds=86400`) and purge older files | **data vol ~18 GB** | low (Hetzner snapshots + restic + nightly dumps remain) | cannot un-purge; setting reversible | **YES** (log deletion) |
| 6 | Take a fresh Hetzner snapshot, then `apt upgrade` (246 pkgs / 165 security) and **reboot** in a night window, after lanes are closed. Reboot also resets MySQL memory and swap | RAM ~9 GB, swap → ~0, kernel patched | medium: ~3 min downtime for all envs + all lane sessions lost; docker upgrade restarts test MySQL | snapshot restore | **YES** (timing) |
| 7 | After reboot: set MySQL `sort_buffer_size` to 2M, `performance_schema=OFF`, `innodb_buffer_pool_size` 4G is fine | RAM ~0.5–1 GB, stops regrowth | low | edit cnf, restart | no (part of 6) |
| 8 | Remove the stale schemas in main MySQL: 14 hfc_dash_test_*, 2 demo_restoretest, 6 one-off verify DBs | data ~2.1 GB | low (re-creatable) | restore from nothing — gone | **YES** |
| 9 | Delete backups listed PROBABLY REMOVE in 4e (qa1 refresh/pre-sync, old at173/at366, open-webui tar, old-claude0-scratch, old /var/backups/hfc dumps keep newest, db-backups nightlies) and drop the 02:30 nexus_os nightly cron; keep restic | data ~5 GB, root ~0.9 GB | low (Hetzner + restic hold copies) | restore from Hetzner snapshot/Storage Box | **YES** |
| 10 | Remove the ≥13 orphan worktrees and old checkouts (corex-dev*, wt-*, corex-* feature dirs) with `git worktree remove` / confirmed-unused dirs | data ~10–11 GB | medium: a lane might still use one | recreate branch checkout from git | **YES** (check with lanes first) |
| 11 | Stop 3 of 6 idle lanes (rule: 2 working + 1 spare) | RAM ~1.3 GB | none | start lane | **YES** (Johan's lanes) |
| 12 | Test envs get a small seed instead of full copies: prune the log tables (domain_event_log, notification_dispatch_log, notifications, p24_import_rows, property_audit_log, …) in nexus_os/qa1/qa2; replace the full uploaded-files copies in qa1/qa2/live-testing (≈19+12+28 GB) with a small sample set; keep Staging full | data **~35–45 GB**, DB ~1.7 GB × 3 | medium: tests relying on real files/photos need the sample set; needs a seeded sample that fits the "second agency" tests | re-clone from Staging/backup | **YES** |
| 13 | Retire live-testing (`/corex`, `live-testing.corexos.co.za`, nexus_os, 21 workers, php8.3-fpm, scheduler, bg-removal-live). Prereq: confirm who still uses it (1,154 hits yesterday) | data ~32 GB, RAM ~2.7 GB (workers 1.9 + fpm 0.8), also removes flag 1 completely | high-ish: someone's testing goes away | restic backup of nexus_os + code in git | **YES** |
| 14 | Retire QA2 if Andre confirms idle (code, 3.4 GB DB, 12 GB storage, worker) | data ~15 GB, RAM ~80 MB | medium | restic/Hetzner restore | **YES (Johan + Andre)** |
| 15 | Move public sites to the live server (hfcoastal.co.za first, then corexweb.co.za, themandatecompany.co.za, docs/performance, plus hf-ai and agent-targets): DNS, certs, mail config, DB home_finders/corex_site, files | root ~7 GB (6.4 GB hfcoastal), RAM ~1.2 GB (php8.4 pool 215 MB, hf-ai 1.0 GB incl. swap), removes real-mail senders from this box, closes ~12 open ports | high: public downtime risk, must be done carefully with DNS TTLs | revert DNS; keep the old copy until verified | **YES** (and its own plan) |
| 16 | Remove unused software on root: snap cups/gnome-46/gtk-common-themes/mesa-2404 (keep chromium if lanes need it, else drop it + puppeteer cache), google-cloud-sdk (843 MB), duplicate bg-removal service copies | root ~2–4 GB | low | `snap install` / `apt install` | ASK about chromium/puppeteer, gcloud and the whisper models (4.4 GB data) |
| 17 | Turn the firewall on (ufw: allow 22, 80, 443 only; nothing else) or confirm the Hetzner cloud firewall does this | security | medium: could lock out test ports/code-server | `ufw disable` | **YES** |
| 18 | `/root/.claude/projects` transcripts older than 30 days (1.2 GB) | root ~1 GB | none to the product, loses old lane transcripts | none | no |

### Expected result if steps 1–14 are approved
- Root: 6.2 GB free → roughly 10–12 GB free (more after step 15 and 16).
- Data volume: 28.7 GB free → roughly 110–130 GB free.
- Memory: ~23.5 GB used → roughly 8–10 GB used, swap near 0.

### What to do first if Johan only says yes to a few
(a) Steps 1–3 and 7 now (no yes needed); (b) step 5 + 6 together in one night (frees 18 GB and 9 GB RAM); (c) decide on live-testing (step 13) because it removes flag 1; (d) public-site move plan (step 15).

---------------------------------------------------------------------------------------------------
## 7. QUESTIONS FOR JOHAN (business, not technical)
1. Is anybody still using live-testing.corexos.co.za day-to-day? It saw ~1,150 page hits yesterday. If yes, may I stop it talking to real WhatsApp and real Property24?
2. Staging has had no scheduled tasks since 21 Aug. Should it stay switched off, or should it run?
3. QA2 has been quiet since 20 Oct. Is Andre finished with it, or still using it?
4. Which of these still matter to anyone: arweb1.duckdns.org, the TMC site (:8082), Docuperfect (docs.hfcoastal.co.za), the performance site, Heartwood, "Andre/Sandbox"? Or do the public ones all go to the live server?
5. Is the evidence folder from 24 Aug (691 MB) still needed?
6. OK to take a snapshot and reboot one night (all lanes and all test sites down ~3 minutes)? Which night?
