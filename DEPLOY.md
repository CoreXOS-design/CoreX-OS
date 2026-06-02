# DEPLOY.md — How to Deploy CoreX OS to Staging or Live

> One safe, repeatable deploy. Read this end-to-end ONCE; refer back when
> you're about to push code. Designed so a non-programmer can run it.
>
> Single entry point: **`/hfc/scripts/deploy.sh staging`** or
> **`/hfc/scripts/deploy.sh production`**. Everything else in this file
> exists to make that one command safe.

---

## 1. WHEN TO DEPLOY

| Target | Command | When |
|---|---|---|
| Staging | `/hfc-staging/scripts/deploy.sh staging` | After any merge into the `Staging` branch. Test before promoting. |
| Live (production) | `/hfc/scripts/deploy.sh production` | Only after staging has been green for ≥ 24 hours, with Johan available, and **never on Friday after 14:00 SAST** (no time to roll back before the weekend). |

A deploy takes ~2–5 minutes. The site is briefly in maintenance mode
(503 page) during steps 3–11 — typically ~60 seconds. The deploy script
prints a 32-character bypass token; visit `https://<host>/<token>` to
preview the new code before traffic resumes.

---

## 2. ONE-TIME SERVER SETUP

Run **once per server** (production + staging hosts). Re-running is safe
but unnecessary.

### 2a. Install the MySQL client tools (for `mysqldump`)

```bash
sudo apt update
sudo apt install -y mysql-client rsync
mysqldump --version          # confirm: must print a version, not "command not found"
rsync --version              # confirm: ditto
```

### 2b. Create the deploy secrets file

`/etc/hfc-deploy.env` holds backup-mode + Storage Box credentials.
This file is **read by `scripts/deploy.sh` only**. It is NEVER committed
to git. Mode 0600 (readable only by root + the deploy user).

```bash
sudo install -m 0600 -o root -g root /dev/null /etc/hfc-deploy.env
sudo nano /etc/hfc-deploy.env
```

Paste — pick **one** of the two templates below depending on whether
this host has a Hetzner Storage Box yet:

#### Template A — production / staging WITH Storage Box (recommended)

```ini
# /etc/hfc-deploy.env
# DEPLOY-2 — backup mode (offsite = local mysqldump + rsync to Storage Box).
# Production MUST be 'offsite' — the deploy script refuses 'local' for prod.
BACKUP_MODE="offsite"

# Hetzner Storage Box for off-server backups
# (Find these in https://robot.your-server.de → Storage Box → access data)
BACKUP_STORAGEBOX_USER="u123456"
BACKUP_STORAGEBOX_HOST="u123456.your-storagebox.de"
BACKUP_STORAGEBOX_PATH="/home/backups/hfc"

# OPTIONAL — dedicated backup DB user (best practice). If unset, deploy.sh
# uses the app's DB_USERNAME + DB_PASSWORD from .env. See §2b-DB below.
# MYSQL_BACKUP_USER="hfc_backup"
# MYSQL_BACKUP_PASSWORD="<a strong password>"
```

#### Template B — staging WITHOUT Storage Box yet (validation-only)

```ini
# /etc/hfc-deploy.env
# DEPLOY-2 — local-only backup (staging validation, NOT for production).
# The deploy script will refuse to run in this mode if you pass
# `production` as the argument.
BACKUP_MODE="local"

# OPTIONAL — dedicated backup DB user (see Template A).
# MYSQL_BACKUP_USER="hfc_backup"
# MYSQL_BACKUP_PASSWORD="<a strong password>"
```

#### §2b-DB — which DB user does the backup use?

DEPLOY-2 resolves the mysqldump credentials in this priority order:

1. **`MYSQL_BACKUP_USER` + `MYSQL_BACKUP_PASSWORD`** from
   `/etc/hfc-deploy.env`. Use this when the host has a dedicated backup
   user with the right grants (best practice for hosts with
   least-privilege app users).
2. **`DB_USERNAME` + `DB_PASSWORD`** from the app's `.env` (e.g. `nexus`).
   This is the default — the deploy script already knows the app root,
   and the app's DB user is guaranteed to exist and have access to the
   app's database.

The script **never** falls back to MySQL root. Coupling deploys to the
admin password is fragile (rotating root would silently break deploys).

**Privileges required on the chosen backup user:**

| Flag | Privilege |
|---|---|
| `--single-transaction` | `SELECT` on the schema (the standard app-user grant — works for the `nexus`-style user out of the box). |
| `--routines` | `SHOW_ROUTINE` (MySQL 8.0+) or `SELECT` on `mysql.proc` (5.7). May fail for least-privilege app users. |
| `--triggers` | `TRIGGER` privilege on the schema. The CoreX repo has at least one trigger (`knowledge_chunks_search_trigger` in `2026_02_25_000001_create_knowledge_base_tables.php:74`) — **mandatory** for complete backups. |

If `mysqldump` complains about missing `SHOW_ROUTINE` or `TRIGGER`
during step 2, you have two choices:

```sql
-- Option A: grant the missing privs to the app user
GRANT SHOW_ROUTINE, TRIGGER ON nexus_os.* TO 'nexus'@'localhost';
FLUSH PRIVILEGES;

-- Option B: create a dedicated backup user (and set MYSQL_BACKUP_USER
-- in /etc/hfc-deploy.env)
CREATE USER 'hfc_backup'@'localhost' IDENTIFIED BY '<strong pw>';
GRANT SELECT, SHOW VIEW, SHOW_ROUTINE, TRIGGER, LOCK TABLES,
      EVENT ON nexus_os.* TO 'hfc_backup'@'localhost';
FLUSH PRIVILEGES;
```

Run the deploy after the grant; the script will succeed without further
config change.

### 2c. SSH key for the Storage Box

**Skip this section if your `/etc/hfc-deploy.env` sets `BACKUP_MODE="local"`.**
The SSH key is only needed when off-server backups are enabled
(`BACKUP_MODE="offsite"` — the default + the required mode for
production).

Hetzner Storage Boxes accept SSH on port 23 with an SSH key. The deploy
script reads the key at `~/.ssh/storagebox_ed25519` (falls back to
default SSH config if not present).

```bash
# As the deploy user (the user who will run deploy.sh):
ssh-keygen -t ed25519 -f ~/.ssh/storagebox_ed25519 -N ""

# Register the public key with the Storage Box (one-time, paste this
# command into the Storage Box "SSH key" section of the Hetzner robot,
# OR run their key-upload tool — both methods documented at
# https://docs.hetzner.com/storage/storage-box/backup-space-ssh-keys/):
cat ~/.ssh/storagebox_ed25519.pub
# Then run a one-shot connect to accept the host key:
ssh -p 23 -i ~/.ssh/storagebox_ed25519 \
    u123456@u123456.your-storagebox.de "mkdir -p /home/backups/hfc && echo OK"
```

Expect to see "OK". If you see "Permission denied" or "Connection
refused", the key isn't registered yet — re-check the Storage Box
admin panel.

### 2d. Passwordless sudo for the deploy user

The script needs sudo for two predictable commands: reload PHP-FPM and
reload Nginx. We grant **only those exact commands** — no general sudo
access — via `/etc/sudoers.d/hfc-deploy`:

```bash
sudo visudo -f /etc/sudoers.d/hfc-deploy
```

Paste (replace `johan` with the actual deploy user):

```sudoers
# /etc/sudoers.d/hfc-deploy
# DEPLOY-1 — narrow sudo grants for /hfc/scripts/deploy.sh
johan ALL=(root) NOPASSWD: /bin/systemctl reload php8.2-fpm
johan ALL=(root) NOPASSWD: /bin/systemctl reload nginx
# Queue-worker restart (auto-detected at deploy time — grant whichever
# is installed; harmless to grant both):
johan ALL=(root) NOPASSWD: /usr/bin/supervisorctl status
johan ALL=(root) NOPASSWD: /usr/bin/supervisorctl restart *
johan ALL=(root) NOPASSWD: /bin/systemctl list-units --type=service *
johan ALL=(root) NOPASSWD: /bin/systemctl restart hfc-queue*
johan ALL=(root) NOPASSWD: /bin/systemctl restart corex-worker*
```

Save (Ctrl-X, Y, Enter — `visudo` validates before letting you save).

### 2e. Backup + log directories

```bash
sudo mkdir -p /var/backups/hfc /var/log
sudo chown $(whoami):$(whoami) /var/backups/hfc /var/log/hfc-deploys.log 2>/dev/null || sudo touch /var/log/hfc-deploys.log
sudo chmod 0644 /var/log/hfc-deploys.log
```

### 2f. Confirm the queue-worker mechanism

The script auto-detects between supervisord (`hfc-queue` or
`corex-worker-live` program) and systemd (`hfc-queue*.service` or
`corex-worker*.service`). If neither is present, the script logs a
warning and relies on Laravel's `queue:restart` signal alone. To
verify what's installed on this host:

```bash
# Supervisord:
sudo supervisorctl status 2>/dev/null | grep -E '(hfc-queue|corex-worker)'
# Or systemd:
sudo systemctl list-units --type=service --no-pager | grep -E '(hfc-queue|corex-worker)'
```

If both come back empty, queue workers are not under any host-level
manager — queue jobs depend entirely on `php artisan queue:work` being
running somewhere (a screen/tmux session, a long-running cron, etc.).
Talk to Johan before deploying — workers need a proper home before
shipping more queued jobs.

---

## 3. PRE-DEPLOY CHECKLIST (3 minutes)

Before pressing the deploy button on production:

- ☐ The latest CI run on the target branch is green.
- ☐ The change has been on staging for ≥ 24 hours without issue.
- ☐ A daily backup from < 24 hours ago exists in `/var/backups/hfc/`
   (the cron in step 2 runs at 02:00 SAST — confirm it's recent).
- ☐ `.ai/CHAT_STARTER.md` recent-decisions log mentions what's about
   to ship.
- ☐ Any **destructive migrations** in this deploy (DROP COLUMN, DROP
   TABLE, NOT NULL conversion) have been approved by Johan.
- ☐ Johan is reachable for the next 30 minutes.

---

## 4. RUNNING THE DEPLOY

SSH into the server, then:

```bash
# STAGING:
/hfc-staging/scripts/deploy.sh staging

# LIVE (production):
/hfc/scripts/deploy.sh production
```

The script prints a numbered step list as it runs. **Watch for
`✅ DEPLOY OK` at the end.** If you see `❌ DEPLOY FAILED`, jump to
§6 Rollback immediately — do NOT panic, do NOT press anything, the
site is in maintenance mode and the pre-deploy backup is intact.

### What the 12 steps do (plain English)

| Step | What it does | If it fails |
|---|---|---|
| 1. Pre-flight | Confirms you're on the right server, on the right git branch, with a clean working tree, all tools installed, `.env` matching the target environment, and ≥ 2 GB free disk space. | Nothing has been touched. Investigate + fix; re-run. |
| 2. Backup | Dumps the entire database to `/var/backups/hfc/<db>-pre-deploy-<timestamp>.sql.gz` and uploads it to the Hetzner Storage Box via rsync over SSH. | Nothing has been touched. Common cause: SSH key not registered with Storage Box (§2c) or `mysqldump` not installed (§2a). |
| 3. Maintenance ON | Tells Laravel to serve a 503 page to everyone. The script prints a bypass token so YOU can preview the new code at `https://<host>/<token>`. | Rare — same fix as a normal "site is broken" — `php artisan up` to lift. |
| 4. Pull | `git fetch origin <branch>` then `git pull --ff-only origin <branch>`. Fails if the local branch has diverged. | Nothing destructive has happened yet — the database is unchanged. Investigate the divergence; re-run. |
| 5. Composer + migrate | Installs PHP dependencies and runs any new Laravel migrations. | DB has been touched. Go to §6 Rollback. |
| 6. Reference seeders | Runs the 13 reference seeders explicitly (one `--class=` call each). Demo seeders are NEVER touched. | DB may be partially seeded. Go to §6 Rollback. |
| 7. Frontend build | `npm ci && npm run build` — rebuilds the JS/CSS bundle. | Code is the new version but assets are stale. Restore assets via rollback. |
| 8. Caches + opcache | Clears every Laravel cache (config, route, view, app, events, compiled), re-caches them for prod performance, and reloads PHP-FPM so its opcache picks up the new code. | Stale code may continue to serve. Re-run the deploy or manually flush. |
| 9. Queue workers | Sends `queue:restart` to Laravel, then auto-detects the queue worker manager (supervisord or systemd) and restarts it. | New notification/job classes won't fire. Re-run or restart the workers manually. |
| 10. Verify | (a) HEAD matches `origin/<branch>`. (b) Every reference table has ≥ 1 row. (c) Compiled views recompile cleanly. **Any of these failing = automatic rollback.** | Trap fires → see §6 Rollback. |
| 11. Up | Lifts maintenance mode — traffic resumes. | Run `php artisan up` manually. |
| 12. Summary | Logs the success message with the deploy SHA, backup file path, worker mechanism, and timestamp. Tags the deploy in git for easy reference. | n/a |

---

## 5. POST-DEPLOY VERIFICATION (5 minutes)

After `✅ DEPLOY OK`:

1. Hit `https://corex.hfcoastal.co.za/up` → expect HTTP 200.
2. Log in as a real user → land on the dashboard.
3. **Calendar smoke test**: click `+ New Event` → set Type to "Property
   evaluation" → pick a property with linked contacts → confirm the
   contact pops into Attendees (CAL-4/CAL-5 smoke). Save → no 500.
4. Tail the Laravel log for 60 seconds:
   ```bash
   tail -f /hfc/storage/logs/laravel-$(date +%Y-%m-%d).log
   ```
   Expect zero new exceptions.
5. Confirm the git tag exists:
   ```bash
   cd /hfc && git tag --list "deploy-production-*" | tail -3
   ```

---

## 6. ROLLBACK

If `❌ DEPLOY FAILED` appears, OR post-deploy verification finds a
problem, restore from the pre-deploy backup taken in step 2. The site
is in maintenance mode for the entire rollback — users see a 503,
which is what we want.

### 6a. Code-only rollback (NO destructive migrations in this deploy)

```bash
cd /hfc                                  # or /hfc-staging
PREV_SHA=$(cat /var/log/hfc-deploys.log | tail -1 | awk '{print $3}')
# Confirm before running:
echo "Rolling back to: $PREV_SHA"

git reset --hard "$PREV_SHA"
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan view:cache
sudo systemctl reload php8.2-fpm
php artisan up
```

### 6b. Full rollback (destructive migrations were applied)

```bash
cd /hfc                                  # or /hfc-staging

# 1. Stay in maintenance.
php artisan down --render="errors::503" --secret="rollback-$(date +%s | sha256sum | head -c 32)"

# 2. Restore the database from the pre-deploy backup. The
#    deploy script prints the file path on failure; also available at:
LATEST=/var/backups/hfc/<db>-pre-deploy-LATEST.sql.gz
#    where <db> is `hfc_prod` (live) or `hfc_staging`. Substitute the
#    real filename — the script symlinks the most recent pre-deploy
#    backup as ${DB}-pre-deploy-LATEST.sql.gz.
source /etc/hfc-deploy.env
# Restore as the SAME user the dump was created with. Priority:
#   MYSQL_BACKUP_USER from /etc/hfc-deploy.env (if set)
#   else DB_USERNAME from /hfc/.env (e.g. 'nexus')
DUMP_USER="${MYSQL_BACKUP_USER:-$(grep -E '^DB_USERNAME=' /hfc/.env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")}"
DUMP_PW="${MYSQL_BACKUP_PASSWORD:-$(grep -E '^DB_PASSWORD=' /hfc/.env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")}"
DUMP_DB=$(grep -E '^DB_DATABASE=' /hfc/.env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")
MYSQL_PWD="$DUMP_PW" gunzip -c "$LATEST" | mysql --user="$DUMP_USER" "$DUMP_DB"
# (If the dump user lacks DROP/CREATE privs to restore, fall back to a
# privileged admin user — but the standard nexus/app user typically
# has ALL PRIVILEGES on the app database.)

# 3. Roll the code back to the pre-deploy SHA (printed by the failure trap):
git reset --hard <PREV_SHA>
composer install --no-dev --optimize-autoloader

# 4. Clear + rebuild caches; flush FPM opcache.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.2-fpm

# 5. Workers (whichever was used at deploy):
sudo supervisorctl restart hfc-queue:* 2>/dev/null || \
    sudo systemctl restart hfc-queue.service 2>/dev/null || \
    php artisan queue:restart

# 6. Lift maintenance:
php artisan up
```

If you don't have the pre-deploy backup file locally (e.g. the deploy
host's disk died), fetch it from the Storage Box — **only possible if
the deploy ran with `BACKUP_MODE="offsite"` (the default + production
default)**. If the deploy ran in `local` mode (staging-validation), the
backup ONLY exists on the local disk; if that's gone, there is no
backup to fetch.

```bash
source /etc/hfc-deploy.env
rsync -az -e "ssh -p 23 -i ~/.ssh/storagebox_ed25519" \
    "${BACKUP_STORAGEBOX_USER}@${BACKUP_STORAGEBOX_HOST}:${BACKUP_STORAGEBOX_PATH}/<db>-pre-deploy-<timestamp>.sql.gz" \
    /var/backups/hfc/
```

---

## 7. STAGING vs LIVE — environment differences

The `.env` file lives on the server (NEVER in git). Each environment's
`.env` is hand-managed. Confirm these values BEFORE deploying:

| Variable | Staging | Live (production) |
|---|---|---|
| `APP_ENV` | `staging` | `production` |
| `APP_DEBUG` | `true` (OK for staging) | **MUST be `false`** |
| `APP_URL` | `https://staging.corex.hfcoastal.co.za` (or whatever the actual staging host is) | `https://corex.hfcoastal.co.za` |
| `DB_DATABASE` | `hfc_staging` | `hfc_prod` |
| `MAIL_MAILER` | `log` or a sink (do NOT email real sellers from staging) | real SMTP |
| `QUEUE_CONNECTION` | `database` | `database` |
| `ANTHROPIC_API_KEY` | dev key (low quota) | prod key (full quota) |
| `GOOGLE_GEOCODING_API_KEY` (Google Static Maps) | optional, leave empty if not testing geocoding | should be set |
| `WHISTLEBLOW_PPRA_LIVE_SEND` | `false` (always) | `false` until lawyer-reviewed |
| `PP_PASSWORD`, `P24_IMAP_PASSWORD`, `PP_WEBHOOK_SECRET`, `FIREBASE_CREDENTIALS` | dev/sandbox creds | production creds |

The deploy script reads `APP_ENV` from `.env` and refuses to run if it
doesn't match the target environment (catches "ran the production
deploy from the staging shell" mistakes).

---

## 8. FUTURE IMPROVEMENTS (deferred)

- **Zero-downtime deploys (symlink-swap pattern).** Today's deploy takes
  the site offline for ~60 seconds during steps 3–11. A symlink-swap
  pattern (deploy into `/hfc/releases/<sha>` and atomically flip the
  `/hfc/current` symlink) would eliminate the maintenance window
  entirely. Not built in v1 — accepted per DEPLOY-1 decision 4. Add
  when downtime becomes a customer-visible complaint.
- **Daily cron backup.** The deploy-time backup is excellent for
  rollback but doesn't protect against between-deploy data loss. A
  separate cron (independent of deploys) should mysqldump daily at
  02:00 SAST and rsync to the Storage Box. Stub script in this repo
  TBD.
- **Hetzner Cloud Backups.** Volume-level snapshots run by Hetzner;
  paid add-on. Confirm enabled on the production instance (`91.99.130.85`).
- **Slack/email notification on deploy success/failure.** Currently the
  script writes to `/var/log/hfc-deploys.log` only — quiet success.

---

## CHANGELOG

| Date | Author | Change |
|---|---|---|
| 2026-06-02 | DEPLOY-1 | Initial v1. Replaces the legacy four-line deploy script. |
| 2026-06-02 | DEPLOY-2 | Backup now dumps as the app's `DB_USERNAME` from `.env` (e.g. `nexus`) instead of MySQL root. Optional `MYSQL_BACKUP_USER` override for hosts with a dedicated backup user. Added `BACKUP_MODE` (`offsite` default / `local` for staging-only) — `local` is HARD-REFUSED for production. |
