# CoreX OFF-BOX BACKUP — DISASTER RESTORE RUNBOOK (AT-163)

**Written for a 2am disaster. Follow top to bottom. Copies of this file: on the box at
`/root/.corex-backup/RESTORE-PROCEDURE.md`, in Jira AT-163, and in the GitHub repo
(`.ai/specs/wa-media-durability-transcription.md`, PART 8).**

## WHAT THIS PROTECTS
Nightly `restic` backup (encrypted, off-box) of everything irreplaceable on the `/dev/sda`
volume → Hetzner **Storage Box u626487** over SFTP. Closes the total-volume-loss exposure.

- **Repo:** `sftp:corex-storagebox:/home/corex-restic` (Storage Box `u626487.your-storagebox.de`, SSH **port 23**)
- **Contents of each nightly snapshot:** fresh `nexus_os` + `hfc_staging` SQL dumps, `/corex/storage/app`
  + `/corex-staging/storage/app` (WA media, documents, e-sign, viewing packs), both `.env` files, `/etc/nginx`,
  the backup scripts + cron.
- **Retention:** 7 daily / 4 weekly / 6 monthly.
- **Schedule:** nightly 03:30; health check 09:15 (`/etc/cron.d/corex-offbox-backup`).

## THE THREE THINGS YOU NEED (all root-only on the box; if the box is gone, see "FRESH BOX")
1. **Repo password** → `/root/.corex-backup/restic-password` (0600). **Without it the backup is unrecoverable.**
   Keep an offline copy (password manager). It is NOT in git and NOT in chat.
2. **SSH key** for the Storage Box → `/root/.ssh/storagebox_backup` (+ `.pub` in the Hetzner panel).
3. **SSH alias** `corex-storagebox` → `/root/.ssh/config` (HostName, User u626487, Port 23, that IdentityFile).

---

## A) QUICK HEALTH / "IS THE BACKUP OK?"
```bash
cat /var/lib/corex-backup/status.json          # {"state":"OK",...} + last_success
/usr/local/bin/corex-backup-health.sh check    # exit 0 = fresh (<36h); exit 2 = ALERT
tail -n 40 /var/log/corex-offbox-backup.log
```

## B) LIST SNAPSHOTS / BROWSE
```bash
set -a; . /root/.corex-backup/restic.env; set +a
restic snapshots                 # snapshot IDs + times
restic ls latest | less          # every file in the latest snapshot
```

## C) RESTORE ONE FILE (e.g. a lost WA voice note / document)
```bash
set -a; . /root/.corex-backup/restic.env; set +a
# ⚠ restore to the VOLUME (60G free), NOT /root (OS disk is small and WILL fill).
restic restore latest --target /mnt/HC_Volume_103099143/restore \
  --include /corex/storage/app/private/communications/1/whatsapp/ab/<sha256>
# File lands at /mnt/HC_Volume_103099143/restore/corex/storage/app/... — copy it back into place, fix owner:
#   chown -R www-data:www-data <restored path>
```
WA media is content-addressed (filename == sha256), so integrity is self-verifying:
`sha256sum <restored-file>` must equal its filename.

## D) RESTORE A DATABASE (nexus_os = LIVE)
```bash
set -a; . /root/.corex-backup/restic.env; set +a
restic restore latest --target /mnt/HC_Volume_103099143/restore \
  --include /mnt/HC_Volume_103099143/backup-staging/nexus_os.sql
# Sanity check the dump:
tail -c 120 /mnt/HC_Volume_103099143/restore/mnt/HC_Volume_103099143/backup-staging/nexus_os.sql   # must end "-- Dump completed"
# Load it (DESTRUCTIVE to the target DB — be sure):
mysql -u root nexus_os < /mnt/HC_Volume_103099143/restore/mnt/HC_Volume_103099143/backup-staging/nexus_os.sql
# staging: same with hfc_staging.sql → mysql -u root hfc_staging < ...
```

## E) RESTORE EVERYTHING (volume died, same box)
```bash
set -a; . /root/.corex-backup/restic.env; set +a
restic restore latest --target /mnt/HC_Volume_103099143/restore
# Then move each tree back into place and fix ownership:
#   /corex/storage/app, /corex-staging/storage/app  → chown -R www-data:www-data
#   /corex/.env, /corex-staging/.env                → chmod 640, chown www-data
#   /etc/nginx                                        → then: nginx -t && systemctl reload nginx
#   the two .sql dumps                                → load per (D)
```

## FRESH BOX (the whole server is gone) — bootstrap then restore
1. Provision a new box, attach a volume at `/mnt/HC_Volume_103099143`.
2. `apt-get update && apt-get install -y restic openssh-client mysql-client`
3. Recreate the **SSH key** (`/root/.ssh/storagebox_backup`) from your offline copy, or generate a new one and
   add its `.pub` to the Storage Box panel. Recreate the **SSH alias** block in `/root/.ssh/config`:
   ```
   Host corex-storagebox
       HostName u626487.your-storagebox.de
       User u626487
       Port 23
       IdentityFile /root/.ssh/storagebox_backup
       IdentitiesOnly yes
   ```
4. Put the **repo password** back at `/root/.corex-backup/restic-password` (0600) from your offline copy.
5. Recreate `/root/.corex-backup/restic.env`:
   ```
   export RESTIC_REPOSITORY="sftp:corex-storagebox:/home/corex-restic"
   export RESTIC_PASSWORD_FILE="/root/.corex-backup/restic-password"
   export RESTIC_CACHE_DIR="/root/.cache/restic"
   export RESTIC_COMPRESSION="auto"
   ```
6. `set -a; . /root/.corex-backup/restic.env; set +a; restic snapshots` — you should see the history.
7. Restore per (E), reload the DBs per (D), redeploy the app code from GitHub (`git clone` + `.env` restored).

## VERIFY THE REPO IS HEALTHY (run occasionally)
```bash
set -a; . /root/.corex-backup/restic.env; set +a
restic check --read-data-subset=10%     # structure + re-hash 10% of actual data; "no errors were found"
```

## IF THE STORAGE BOX PASSWORD/KEY IS LOST
- Lost **repo password** → the backup is cryptographically unrecoverable. There is no reset. (Keep the offline copy.)
- Lost **SSH key** → generate a new keypair, paste the new `.pub` into the Hetzner Storage Box panel; the repo/data are unaffected.
