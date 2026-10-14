# Staging Deploy Checklist — Presentations V2 + Phase 3i/3j + Phase 8/9a
> Created Phase 9a hardening pass · 2026-06 · build cycle: e1cc850 → Phase 9a
> Branch: `HFC2402` → merge to `Staging` for deploy

The 11 phases shipped in this cycle (Phase 3a–3j + Phase 4, 5, 6, 7, 8, plus the Phase 3 AI summary and Phase 9a pre-staging polish) introduce 8 new tables, 13 new routes, 2 daily scheduled jobs, 8 queued notifications, and a substantial UI refresh on the property + presentation show pages. This checklist walks Andre through what changes outside the codebase before/during deploy.

---

## H1 — Environment variables required on staging

Add to staging `.env` (production already has the first three). New variables introduced this cycle marked **NEW**.

```ini
# Existing — confirm present on staging
APP_URL=https://corex.hfcoastal.co.za    # MUST match the public domain — affects route(absolute) rendering in emails
MAIL_FROM_ADDRESS=noreply@hfcoastal.co.za
MAIL_FROM_NAME="Home Finders Coastal"

# NEW — required for AI summary (Phase 3 of Presentations V2)
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-6        # default; set to claude-opus-4-7 only if cost permits

# OPTIONAL — Phase 3f geocoder waterfall
GOOGLE_GEOCODING_API_KEY=                # leave empty to skip Google leg; OSM Nominatim fallback runs regardless

# Confirm — mail driver, must be production-ready
MAIL_MAILER=smtp                         # or 'mailgun' / 'ses' if migrating; see H2
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
```

**Critical**: `APP_URL` mismatch silently produces broken links in 8 different email notifications. Verify by sending a `PresentationFirstViewedNotification` test from Tinker after deploy and clicking the action button.

---

## H2 — Queue worker configuration

Every notification in this cycle is `ShouldQueue`. Without a queue worker the agent never sees "first viewed" emails, the daily nudge job stalls silently, and the on-deal-registered outcome auto-detection fails.

**Supervisord config** (`/etc/supervisor/conf.d/hfc-queue.conf`):
```ini
[program:hfc-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/hfc/artisan queue:work --queue=default --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
stopwaitsecs=3600
```

Deploy hook should `supervisorctl restart hfc-queue:*` after `php artisan migrate` so newly-shipped notification classes are picked up.

**failed_jobs table cleanup** — add a weekly cron:
```bash
0 4 * * 0  cd /var/www/hfc && php artisan queue:prune-failed --hours=168
```

---

## H3 — Scheduled jobs (production crontab)

Single cron entry, Laravel's scheduler handles the rest:
```bash
* * * * * cd /var/www/hfc && php artisan schedule:run >> /dev/null 2>&1
```

The following scheduled jobs are now active (registered in `routes/console.php`):

| Job | When | What it does |
|---|---|---|
| `PromptOutcomeCaptureJob` | daily 08:30 | Nudges agents about presentations >30d old with no outcome (Phase 8) |
| `LockOldOutcomesJob` | daily 02:45 | Locks outcomes recorded >90d ago for analytics integrity (Phase 8) |
| **`PurgeOldSnapshotViewsJob`** | daily 03:15 | **NEW (Phase 9a)** — POPIA 90-day retention for `presentation_snapshot_views` |
| existing jobs | — | unchanged (`SyncMarketingInsightsJob`, `signatures:send-reminders`, etc) |
| **`leases:advance-terms`** | daily 00:35 | A signed renewal goes active on its start date (the lease it renews expires with it); a tenancy ends on its move-out date once the out-inspection is on file (rental-renewals.md §22). **Needs the scheduler: without the cron above, renewals and move-outs never happen by themselves.** A Staging / QA box with no cron uses the admin button "Run today's lease date checks now" on the lease screen (§22.1). |

**Manual-only commands** (do NOT schedule on production):
- `geocoding:backfill` — Phase 3f, run weekly via cron OR manually after large data imports
- `deals:link-properties` — Phase 3i, manual after deal-data ingests
- `deals:populate-sale-price` — Phase 3i, manual one-shot
- `demo:seed-spatial` — DO NOT RUN on production; only on dedicated demo environment

---

## H4 — Storage configuration

Three new write paths introduced this cycle:

| Path | Purpose | Phase |
|---|---|---|
| `storage/app/presentations/{id}/*` | PDF snapshots, compiled artifacts | Pre-existing |
| **`storage/app/properties/{agency_id}/{property_id}/sg/*`** | **NEW** — Phase 3j SG TIF storage |
| `storage/app/agent_uploads/*` | Pre-existing | — |

Ensure:
- `storage/` writable by `www-data` (or whatever PHP-FPM runs as)
- `php artisan storage:link` is run (symlinks `public/storage` → `storage/app/public`)
- The new properties/{...}/sg/ directories are created on demand by the SG service — no pre-seed needed

Backup policy recommendation:
- **Daily**: `storage/app/presentations/` (compiled PDFs are not regeneratable from third-party feeds without API costs)
- **Daily**: `storage/app/properties/*/sg/` (TIFs from a third-party government site that may purge)
- **Weekly**: Full `storage/` snapshot for disaster recovery

---

## H5 — DNS + URL configuration

Public presentation URLs (`/p/{token}`) are issued under `APP_URL`. If a clean share domain is desired (e.g. `share.hfcoastal.co.za`), set up:
- DNS A record / CNAME pointing to the same server
- SSL cert covering the share domain (Let's Encrypt via certbot)
- Either an Nginx server-block alias OR set `APP_URL` to the share domain (the latter would break the corex.hfcoastal.co.za admin entry — discuss with Johan before deciding)

The Phase 6 delivery emails embed `{presentation_url}` placeholders rendered from `route('presentation.public.show', $token)`. APP_URL controls this absolutely.

---

## H6 — SG proxy outbound connectivity (Phase 3j)

The staging server must be able to outbound HTTPS to `csg.dlrrd.gov.za`. Hetzner cloud instances usually allow this by default; confirm by:

```bash
# From the staging server shell
curl -sS -I --max-time 15 "https://csg.dlrrd.gov.za/esio/searchindex.htm"
# Expect: HTTP/1.1 200 OK
```

If blocked:
- Check Hetzner Cloud Firewall rules for the instance
- Check any host-level iptables
- User-Agent is set to `CoreXOS/1.0 (real estate platform; respect@corexos.co.za)` — confirm this isn't being filtered by a corporate proxy

The SG search service has a 24h cache, so most agent searches won't hit SG. But the FIRST search for any new parcel WILL — staging must reach the upstream or all SG search UI states fail.

---

## H7 — Data setup on staging

**Recommended approach**: production-data copy with sanitised contact details, not demo seeds.

```bash
# On production (read-only mysqldump)
mysqldump -u root -p hfc_prod \
  --ignore-table=hfc_prod.failed_jobs \
  --ignore-table=hfc_prod.jobs \
  --ignore-table=hfc_prod.sessions \
  | gzip > hfc_prod_$(date +%Y%m%d).sql.gz

# Transfer to staging
scp hfc_prod_*.sql.gz hfc-staging:/tmp/

# On staging — DESTROY existing DB first
mysql -u root -p -e "DROP DATABASE IF EXISTS hfc_staging; CREATE DATABASE hfc_staging;"
gunzip -c /tmp/hfc_prod_*.sql.gz | mysql -u root -p hfc_staging

# Sanitise contact emails + phones so staging tests don't email real sellers
php artisan tinker --execute="
\App\Models\Contact::query()->update(['email' => DB::raw(\"CONCAT('staging-', id, '@example.test')\")]);
\App\Models\Contact::query()->update(['phone' => '+27000000000']);
\App\Models\User::where('role', '!=', 'super_admin')->update(['email' => DB::raw(\"CONCAT('staging-', id, '@example.test')\")]);
"

# Run migrations on top
php artisan migrate --force
```

Demo seed data should NOT touch this environment — it lives on a separate demo-site environment per CLAUDE.md non-negotiable #12.

---

## H8 — Andre walkthrough — first-deploy commands

After `git pull`, on the staging host:

```bash
# 1. Composer + node — only if Composer dependencies changed
composer install --no-dev --optimize-autoloader

# 2. Database migrations (8 new tables this cycle — listed below)
php artisan migrate --force

# 3. Caches — must clear after route + view additions
php artisan view:clear
php artisan route:clear
php artisan config:clear
php artisan cache:clear

# 4. Re-cache for production performance
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Storage symlink (idempotent)
php artisan storage:link

# 6. Restart queue workers
sudo supervisorctl restart hfc-queue:*

# 7. Optional — backfill Phase 3i deal property links (manual one-shot per agency)
php artisan deals:link-properties --agency=1 --dry-run    # review the counts
php artisan deals:link-properties --agency=1              # apply

# 8. Optional — populate Phase 3i canonical sale_price (manual one-shot)
php artisan deals:populate-sale-price --agency=1
```

### New tables introduced this cycle
Migrations are timestamped — they apply in order. The eight new tables:

| Table | Phase | Migration timestamp |
|---|---|---|
| `presentation_snapshot_links` | 4 | 2026_05_27_080001 |
| `presentation_snapshot_views` | 4 | 2026_05_27_080002 |
| `presentation_teaser_leads` | 5 | 2026_05_28_080001 |
| `presentation_deliveries` | 6 | 2026_05_29_080001 |
| `presentation_refresh_requests` | 7 | 2026_05_31_080003 |
| `presentation_outcomes` | 8 | 2026_06_01_080001 |
| `presentation_outcome_prompts` | 8 | 2026_06_01_080002 |
| `deal_link_review_queue` | 3i | 2026_06_02_080002 |
| `property_sg_documents` | 3j | 2026_06_03_080002 |
| `sg_search_cache` | 3j | 2026_06_03_080003 |

(Plus assorted `ADD COLUMN` migrations on existing tables — these all use `Schema::hasColumn` guards so they're idempotent.)

### New env vars (recap from H1)
- `ANTHROPIC_API_KEY` — required for AI summary
- `ANTHROPIC_MODEL` — defaults to claude-sonnet-4-6
- `GOOGLE_GEOCODING_API_KEY` — optional (geocoder falls back to OSM)

---

## Items recommended for Andre's eyes before staging deploy

1. **Outcome auto-detection on deal registration**: the `DealRegisteredForOutcomeObserver` runs synchronously on every deal save. If the Phase 3i backfill linked some deals to the wrong property (admin review queue still has 67 pending matches), those will auto-record incorrect `won_sale` outcomes once a deal status changes. Confirm with Johan whether to **pause** the observer registration in `AppServiceProvider.php:243` until the link-review queue is fully resolved.

2. **Public route caching**: Laravel's response cache (if enabled) would cache `/p/{token}` GETs by URL — that breaks per-session lead capture. Confirm response cache is OFF for the `/p/*` namespace.

3. **Honeypot field naming collision**: both the teaser lead capture form and the refresh-request form use `company_name` as the honeypot field. If a marketing automation tool scans both forms and tries to fill it, both endpoints will reject as bot — fine for honeypot but means automated end-to-end QA must skip those fields.

4. **Demo data isolation**: a few migrations (notably Phase 3h) added `is_demo` flags to 8 tables. On staging seeded from production data, `is_demo=false` everywhere. If staging needs demo overlays, run `php artisan demo:seed-spatial --agency=1` separately — but this is not recommended for a staging environment that mirrors prod.

5. **Activity event log retention**: `agent_activity_events` has no retention policy. After a year of full activity logging it may grow large. Not a launch blocker, but flag for a future retention job.

6. **Andre's branch vs HFC2402**: confirm any work on Andre's branch in this period has been merged before deploy. The HFC2402 branch is current as of build cycle end (commit `fc14b5b`).
