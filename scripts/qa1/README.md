# QA1 nightly sync — live → qa1 with sanitize gate

Refreshes the **disposable** qa1 database (`corex_qa1`) from **live** (`nexus_os`) so
Johan's QA box mirrors production, then **sanitizes** the copy so qa1 can never reach the
real world, **proves** the sanitize with a hard gate, and only then resumes the qa1 site + worker.

## Files
| File | Role |
|------|------|
| `sync-from-live.sh` | Orchestrator. Guards → dump live (read-only) → quiesce qa1 → load → sanitize → storage rsync → **proof gate** → resume → evidence pack. |
| `sanitize.sql` | Runs against `corex_qa1` after load: purge `jobs`/`job_batches`/`failed_jobs`, neuter WhatsApp devices, clear PP feed cursors, drop sessions/cache. |

## Post-load reseed + preserve (why a sync no longer eats QA-only config)
A live→qa1 restore overwrites qa1 with production data — which has **none** of the
not-yet-live QA config. So between the sanitize and the proof-gate/resume the sync does two things:

- **Reseed (deterministic config)** — `php artisan db:seed --class=QaConfigSeeder` re-lays the
  DR2 pipeline templates, the distribution matrix (`DealStageDocumentRuleSeeder`), and the
  deal-property-sync settings. Idempotent; refuses on production.
- **Preserve (user-maintained data)** — snapshotted from qa1 **before** the load and re-inserted
  **after**: `sessions` (Johan + the rig stay logged in across syncs) and the attorney/supplier
  directory (`agency_service_providers` + `agency_service_provider_contacts`).

The proof gate now also fails (qa1 stays down) if templates or distribution rules came back empty.

### THE CLASS RULE
> **Any feature not yet live MUST register its QA seed in the post-load step, or the next sync eats it.**
> - Deterministic config (defaults) → add its seeder to `database/seeders/QaConfigSeeder.php`.
> - User-maintained data (no canonical default) → add its table to `PRESERVE_TABLES` in `sync-from-live.sh`.

## Guarantees
- **Live is read-only** — `mysqldump --single-transaction`; live is never locked or written.
- Only **qa1** is touched. Staging, andre's `qatesting2`, and the live/staging workers are never touched.
- The script **refuses** unless target `APP_ENV=qa`, target DB ≠ live DB, qa1 mail already routes to
  Mailpit (`127.0.0.1:1025`), and qa1 `WAHA_BASE_URL` is empty.
- qa1 goes into **maintenance + worker stop BEFORE the load**; both resume **only** after the proof
  gate passes. A failed gate leaves qa1 **down on purpose** — a half-sanitized box never serves.
- Live-intended queued jobs imported from live are **purged before the worker can pick them up**.

## Sanitize proof gate (all must pass or qa1 stays down)
1. `jobs` table = 0 (live-intended queued work purged).
2. 0 active WhatsApp devices.
3. qa1 `WAHA_BASE_URL` empty.
4. `P24_IMPORT_ENABLED=false` and `PP_SANDBOX=true` in qa1 `.env`.
5. **Mail routing proof** — a mail sent through the qa1 app lands in **Mailpit**, proving it does
   not use real SMTP.

## Run (supervised)
```bash
sudo scripts/qa1/sync-from-live.sh --dry-run    # guards + plan, no changes
sudo scripts/qa1/sync-from-live.sh --confirm    # real run; writes evidence pack to /var/tmp/qa1-sync/
```

## Cron — OFF until Johan's word
The nightly copy is **NOT armed**. Per Johan (2026-07-11) it arms **Monday night** (little new live
data over the weekend). Do not install the cron line until he says so.

When armed, add (root crontab), NOT before:
```
# QA1 nightly refresh — 02:00 Monday. ARMED ONLY ON JOHAN'S WORD.
0 2 * * 1  /corex-qa1/scripts/qa1/sync-from-live.sh --confirm >> /var/log/qa1-sync.log 2>&1
```
