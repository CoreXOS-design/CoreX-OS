# Staging → main: Wave 3b tenancy + Payroll + Leave + Whistleblow + Buyer CRM + Tracked Properties

## Scope

| Metric | Value |
|---|---|
| Commits | **288** |
| Files changed | **1,149** |
| Lines | **+122,701 / -6,470** |
| New migrations | **226** |
| **Modified migrations** | **9** ⚠️ |
| New console commands | **34** |
| New events / listeners / observers | **47 / 12 / 12** |
| New / modified models | **73 / 111** |
| New views | **186** |
| New specs | **30** |

## Modules shipping

- **Payroll** (full module) — banking, tax tables, rebates, earnings, deductions, employees, runs, payslips
- **Leave management** (full module) — types, entitlements, applications, transactions, public holidays
- **Whistleblow / PPRA compliance** — complaints, evidence, audit log, tier recipients, email log
- **Client auth / portal** — `client_users`, OTP, access logs, signin attempts
- **Buyer CRM foundation** — preferences, risk scores, matching engine, lost-deal tracking, recovery
- **Tracked Properties + Universal Match-or-Create** (CLAUDE.md rule #10)
- **Calendar events overhaul** — multi-property support, invitations, feedback, audit log, class settings
- **Wave 3b tenancy** — ~60 migrations adding `agency_id` to existing tables; `BelongsToAgency` extended from 61 → 168 models
- **Seller outreach + WhatsApp** — templates, sends, clicks, callbacks, launch modes
- **P24 location tables** — towns, suburbs, property types, bedroom segments, price bands, location pickers
- **Domain events catalogue** — 26 events across 6 pillars + 21 firing points + `domain_event_log` table
- **API v1 migration** — all routes moved under `/api/v1/*` with legacy aliases (NN #7)
- **Portal leads** — P24 buyer-enquiry leads pulled every 15 min, persisted alongside PP leads
- **Mandate expiry**, **Property recommendations**, **Training/help**, **QR codes**, **Dev settings**, **Demo environment** (reset cycle, agency flagging)

## 🚨 BLOCKER — 9 migrations modified after merge to main

These migrations already exist on `main` (and have already run on prod). `php artisan migrate` will **not re-run them**, so any changes made to these files on Staging will **not** apply to production unless handled with a fixup migration.

```
database/migrations/2026_03_05_300002_add_defaults_to_property_setting_items.php
database/migrations/2026_03_17_100001_add_deal_to_named_fields_source_type.php
database/migrations/2026_03_22_184212_add_supervised_by_to_users_table.php
database/migrations/2026_03_23_074727_expand_status_enums_for_esign_v2.php
database/migrations/2026_03_23_182523_add_cancelled_to_signature_status_enums.php
database/migrations/2026_03_26_200000_create_fica_compliance_workflow.php
database/migrations/2026_04_21_130004_add_screening_document_types.php
database/migrations/2026_04_21_185000_create_deal_branches_table.php
database/migrations/2026_04_22_100000_fix_user_documents_agency_id.php
```

**Reviewer action required:** for each, decide whether:
1. The original was wrong on prod and a **fixup migration** is needed, or
2. The edits were dev-only (e.g. only matter for fresh test-DB rebuilds — see `[[project_test_db_mysql]]` memory) and prod is fine as-is.

Until this is resolved, **do not deploy**.

## Env vars to add to live `.env` before deploy

```
PP_IMAGE_BASE_URL=
WHISTLEBLOW_PPRA_LIVE_SEND=false
WHISTLEBLOW_DEMO_RECIPIENT=johan@hfcoastal.co.za
```

No `composer.json` or `package.json` changes — no new dependencies.

## New scheduled tasks (will run automatically once deployed)

```
agency-access:expire                everyMinute
mandates:expire                     daily 01:00
contacts:purge-retention            daily 02:00
corex:leave:accrue-daily            daily 02:00
corex:leave:cycle-rollover          daily 02:30
contacts:detect-duplicates          daily 03:30
buyers:recompute-states             daily 04:00
prospecting:recompute-matches       daily 04:00
matches:recompute                   daily 04:30
corex:leave:send-reminders          daily 06:00
corex:calendar:send-digests         daily 06:30
corex:calendar:reconcile            daily 03:00
properties:generate-recommendations weekly Mon 05:00
p24:sync-locations                  monthly 1st 02:00
PullP24LeadsJob                     every 15 min
```

All use `withoutOverlapping()`. Long-running ones use `onOneServer()` (requires Redis lock; verify live has it).

## Risk areas

- **Wave 3b tenancy backfill** — adds `agency_id` to ~60 existing tables. The `2026_05_23_000001_wave3b_backfill_orphan_agency_ids.php` migration must run cleanly before any of the `NOT NULL`-promoting migrations.
- **`BelongsToAgency` extended to 168 models** — any code that bypassed the global scope before will now be scoped. Watch for broken queries on agency-less consoles/jobs.
- **API routes moved under `/api/v1/*`** — legacy aliases kept, but any external integrations should be retested.
- **Sanctum logout hardening** — verify SPA logout flows still work.
- **MariaDB sql_mode override** — needed for ENUM/DEFAULT compatibility; confirm live MySQL config is compatible.

## Deploy checklist (post-merge)

```bash
# On live server (/hfc) — DO NOT RUN UNTIL ALL ITEMS ABOVE RESOLVED
cd /hfc
sudo -u www-data php artisan down --secret="<temp-bypass-token>"

# 1. Backup
mysqldump -u<user> -p<pw> hfc_dash | gzip > /hfc/storage/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz

# 2. Clean junk files (paste artifacts) from previous sessions
rm -- "elect('suburb', DB::raw('count(*) as n'))" \
      "entation_uploads', 'presentation_uploads.presentation_id', '=', 'presentations.id')" \
      "entations.deleted_at')" \
      "ers', 'users.id', '=', 'presentations.created_by_user_id')" \
      "tinct('presentations.id')" \
      "tings' => \$row->listings," \
      "ubMonths(24))"

# 3. Pull and install
git pull origin main
composer install --no-dev --optimize-autoloader

# 4. Add new env vars (PP_IMAGE_BASE_URL, WHISTLEBLOW_*)
nano .env

# 5. Migrate (review output carefully — 226 new migrations)
php artisan migrate --force --pretend > /tmp/migrate-plan.txt   # dry-run first
less /tmp/migrate-plan.txt
php artisan migrate --force

# 6. Caches
php artisan view:clear && php artisan route:clear && php artisan cache:clear
php artisan config:cache && php artisan route:cache && php artisan event:cache

# 7. Restart workers
sudo supervisorctl restart corex-worker-live:*

# 8. Smoke test
php artisan schedule:list                  # verify new schedules registered
php artisan route:list | grep api/v1       # verify v1 routes
curl -s http://localhost/api/v1/logged-user # session-auth check

# 9. Lift maintenance
php artisan up
```

## Rollback plan

```bash
cd /hfc && php artisan down
git reset --hard <main-sha-before-merge>
gunzip < /hfc/storage/backups/pre-deploy-<ts>.sql.gz | mysql -u<user> -p<pw> hfc_dash
composer install --no-dev --optimize-autoloader
php artisan view:clear route:clear cache:clear
php artisan config:cache route:cache
sudo supervisorctl restart corex-worker-live:*
php artisan up
```

## Sign-off required from

- [ ] **Johan** — review of 9 modified migrations + Wave 3b architecture
- [ ] **Andre** — confirm no andre-branch work needs to land first
- [ ] DB backup taken
- [ ] Maintenance window agreed with agents
- [ ] Rollback plan reviewed
