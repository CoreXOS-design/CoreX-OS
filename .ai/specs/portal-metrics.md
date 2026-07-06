# Portal Metrics — P24 Listing Statistics (Views) Integration

**Date:** 2026-07-06
**Author:** Johan (via Claude)
**Status:** Approved for build — implements the placeholder the Property Intelligence
Hub already anticipates (`PropertyIntelligenceService::getPortalPerformance()` docblock).
**Module:** Market Intelligence (Property Intelligence Hub → Intelligence tab)

---

## 1. What this does and why

The Property edit page → **Intelligence tab** has a "Portal Views (30d)" stat card
(`resources/views/corex/properties/show.blade.php`). Until now it read from a stub
(`getPortalPerformance()`) that returned hardcoded zeros, with a docblock promising:
*"When portal analytics integration is built (P24 Stats API, PP Dashboard API), this
method will query a dedicated `property_portal_metrics` table."*

This spec builds exactly that for **Property24**, which exposes a per-listing
statistics API (`viewCount`, `alertCount`, and a per-day lead breakdown). CoreX
syndicates to P24 and holds the P24 listing number in `properties.p24_ref`, so we can
pull the real view count back for every one of our live listings and surface it on the
Property pillar.

### Private Property reality (important)
Private Property's AgentImport SOAP API exposes **no** views/impressions/statistics
operation. It surfaces only listing lifecycle events (`GetListingEventFeedByBranch`)
and delivers buyer leads by webhook. There is therefore **nothing to pull** for PP
views. The UI states this honestly ("PP: — not provided by portal") rather than
implying a real zero. The `property_portal_metrics.portal` enum still carries a `pp`
value so the table is ready the day PP (or any future portal) exposes stats.

---

## 2. Pillars

| Pillar | Read | Write |
|--------|------|-------|
| **Property** | `p24_ref` / `p24_listing_number`, `agency_id` | enriched with portal engagement (via `property_portal_metrics`) |

Reads the Property pillar's P24 listing number; writes portal engagement data keyed
back to `properties.id`. No new island — the metrics table hangs off the Property.

---

## 3. Data model

New table `property_portal_metrics` — one row per **(property, portal, metric_date)**.
P24 aggregates daily and publishes next-day; we store daily rows and sum over the
requested window (default 30 days) at read time.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | pk |
| `agency_id` | FK agencies, cascade | multi-tenancy (non-negotiable #7) |
| `property_id` | FK properties, cascade | the pillar key |
| `portal` | enum('p24','pp') | only `p24` is written today |
| `portal_listing_number` | string(64) | the P24 listingNumber we queried |
| `metric_date` | date | the day these metrics belong to |
| `view_count` | unsigned int | P24 `viewCount` |
| `alert_count` | unsigned int | P24 `alertCount` (buyers who saved / alert on this) |
| `tel_leads` | unsigned int | P24 `telLeads` |
| `sms_leads` | unsigned int | P24 `smsLeads` |
| `request_details_leads` | unsigned int | P24 `requestDetailsLeads` |
| `total_leads` | unsigned int | P24 `totalLeads` |
| `total_contact_leads` | unsigned int | P24 `totalContactLeads` |
| `price` | decimal(15,2) nullable | P24 `price` snapshot for that day |
| `synced_at` | timestamp | last pull that touched this row |
| timestamps + softDeletes | | no hard deletes (non-negotiable #1) |

Indexes: unique `(property_id, portal, metric_date)`; `(agency_id, portal, metric_date)`.

Model: `App\Models\PropertyPortalMetric` — `BelongsToAgency`, `SoftDeletes`,
`PORTAL_P24`/`PORTAL_PP` constants, `property()` relation.

---

## 4. Ingestion

- **Client:** `Property24ApiClient::getListingStatistics($listingNumber, $startDate,
  $endDate, $propertyId)` → `GET /listing/v53/listings/{listingNumber}/statistics`
  (dates `Y-m-d`, `endDate` exclusive). Returns the standard envelope; `data` is an
  array of daily `ListingStatistics`.
- **Service:** `P24StatsService::pullForAllAgencies($lookbackDays = 7)` — per agency
  with P24 credentials, iterate every Property with a non-empty numeric `p24_ref`
  (fallback `p24_listing_number`), pull the last `$lookbackDays` days, and
  `updateOrCreate` a metric row per returned day. A rolling window each run corrects
  P24's late/next-day aggregation without a full backfill.
- **Job:** `App\Jobs\Syndication\Property24\PullP24StatsJob` (mirrors
  `PullP24LeadsJob`), logged to the `property24` channel.
- **Schedule:** `routes/console.php` — daily at 04:00 (`p24-stats-pull`),
  `withoutOverlapping()`. Stats are daily granularity, so per-minute/5-min cadence
  (as leads use) would waste API calls.

---

## 5. Read path / UI

`PropertyIntelligenceService::getPortalPerformance($propertyId, $rangeDays = 30)` now
sums `view_count`, `alert_count`, `total_leads` from `property_portal_metrics` for
portal `p24` over the last `$rangeDays`. Return keys keep the existing `views`
(back-compat, = P24 views) plus `p24_views`, `favourites` (=alerts), `enquiries`
(=total_leads), `pp_supported => false`, `has_data`.

UI: the existing card (`show.blade.php`) shows the real P24 view number, primary label
"P24 Views (30d)", with a muted sub-line "PP: — not provided by portal". Card already
lives inside the permission-gated Intelligence tab; no new nav entry.

---

## 6. Permissions

The Intelligence tab is already gated by the existing property-view permission. No new
permission key — the metric is an enrichment of the already-authorised Property view.
The sync job runs server-side (scheduler), no user-facing route.

---

## 7. Acceptance criteria

1. `property_portal_metrics` migrates cleanly; `schema:dump` refreshed.
2. `getListingStatistics()` builds the correct URL and parses the daily array.
3. `P24StatsService` upserts one row per (property, portal, day); re-running is
   idempotent (updateOrCreate on the unique key).
4. `getPortalPerformance()` returns the real summed P24 views for a property that has
   metric rows, and `views => 0` with `pp_supported => false` when it has none.
5. The Intelligence card renders the real P24 number and the honest PP sub-line.
6. Scheduler registers `p24-stats-pull` daily.
7. One feature test covers the read path (metrics rows → summed views).

---

## 8. Files

**Create:** this spec; migration `create_property_portal_metrics_table`;
`app/Models/PropertyPortalMetric.php`;
`app/Services/Syndication/Property24/P24StatsService.php`;
`app/Jobs/Syndication/Property24/PullP24StatsJob.php`;
`tests/Feature/PortalMetrics/PortalPerformanceTest.php`.

**Modify:** `Property24ApiClient.php` (add `getListingStatistics`);
`PropertyIntelligenceService.php` (real `getPortalPerformance`);
`resources/views/corex/properties/show.blade.php` (card);
`routes/console.php` (schedule); `database/schema/mysql-schema.sql` (dump).
