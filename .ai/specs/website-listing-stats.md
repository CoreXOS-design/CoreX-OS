# Website Listing Statistics — Spec

> Status: **BUILT** — AT-383 · 2026-08-26 · branch `AT-383-API-for-proeprties-hits-and-new-invite-demo-api-for-webinears`
> Author: Andre (drafted with Claude)
> Module owner: Platform / Integrations
> Related specs: [`agency-public-api.md`](agency-public-api.md), [`portal-metrics.md`](portal-metrics.md), [`listings.md`](listings.md)

---

## 1. What this feature does and why

CoreX already syndicates listings **out** to an agency's own website through the Agency Public API
(`GET /api/v1/website/*`) and already receives enquiries **back** (`POST /api/v1/website/leads`).

What it never received back was **performance**. The agency's own website is the one portal where
CoreX had no idea whether a listing was being seen at all — while Property24 and Private Property
both feed daily views/leads into `property_portal_metrics` and render on the Intelligence tab's
"Portal Engagement Over Time" chart.

This feature closes that hole. The website counts engagement locally (page views, results-page
impressions, contact clicks, enquiries), batches those counters, and POSTs them to CoreX **hourly**.
CoreX stores a daily time series per listing and surfaces it next to the leads it already gets — so
an agent can see which mandates the agency's own site is actually working, and which need a price
review or fresh photos.

The website does **not** call CoreX per page view. It batches and pushes on a schedule.

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Property** | Resolves each incoming `listing_id` / `reference` to Agency Stock (`properties`) | Daily website engagement per listing → Intelligence tab, listing index column |
| **Agent** (`User`) | — | Agent's Today dashboard card totals across the listings they own |
| **Contact** | — | — (enquiry *counts* only; the enquiry itself still arrives via `POST /leads`) |
| **Deal** | — | — |

## 3. The contract (fixed — the website is already built to it)

### 3.1 Endpoint

```
POST /api/v1/website/listings/stats
Authorization: Bearer {website API key}
Content-Type: application/json
Accept: application/json
```

Same credential as the read endpoints (`auth:agency-api` guard → `AgencyApiKey` → `AgencyScope`
resolves the tenant). Gated by the master website-live switch (`website.live`) and by a **new
`stats:write` scope**, handled exactly like `branches:read` / `leads:write`
(`->middleware('website.scope:stats:write')`). A key without the scope gets **403**.

The path is the only part the website can change without a deploy (`COREX_STATS_ENDPOINT`).
We kept the path exactly as specified — no repoint needed.

### 3.2 Request body

```json
{
  "source": "website",
  "site": "home-finders-coastal",
  "batch_id": "3f6c1d0e-9a4b-4a2e-9f77-1b2c3d4e5f60",
  "generated_at": "2026-08-26T12:00:00+02:00",
  "listings": [
    {
      "listing_id": "42",
      "reference": "HFC42",
      "days": [
        { "date": "2026-08-25", "metrics": { "impression": 140, "detail_view": 12, "unique_detail_view": 9 } },
        { "date": "2026-08-26", "metrics": { "detail_view": 4, "phone_click": 1 } }
      ],
      "delta":  { "detail_view": 16, "impression": 140, "phone_click": 1, "unique_detail_view": 9 },
      "totals": { "detail_view": 480, "impression": 5120, "phone_click": 23, "unique_detail_view": 310 }
    }
  ]
}
```

| Field | Meaning |
|-------|---------|
| `source` | Always `"website"`. |
| `site` | Which website sent this. An agency may run more than one site off one CoreX agency — stored stats are partitioned by it. |
| `batch_id` | UUID v4, unique per POST. Idempotency key. |
| `generated_at` | ISO-8601 with offset, when the batch was assembled. |
| `listings[]` | Up to **200** entries per request. A large agency arrives as several requests. |
| `listing_id` | The CoreX listing id, **as a string**. Cast it — never assume int. |
| `reference` | Agency reference (`properties.external_id`). May be null. **Fallback match only.** |
| `days[]` | Outstanding per-day deltas — the time series to append. `Y-m-d` in the agency's timezone (Africa/Johannesburg). |
| `delta` | Sum of every `days[].metrics` entry. |
| `totals` | The website's **lifetime** totals. For reconciliation, never for adding. |

**Which we consume:** `days[]` is authoritative (we store a daily series). `delta` is used **only**
as a fallback when a listing entry carries no `days[]` — then it is attributed to `generated_at`'s
date. `totals` is stored per (site, listing, metric) for drift detection and for the "all time"
figures in the UI.

### 3.3 Metric keys

The set is **open**. Unknown keys are stored, never rejected — the website can add a metric without
a CoreX deploy.

| Key | Meaning |
|-----|---------|
| `impression` | Appeared as a card on a results/search/home page ("hits"). |
| `detail_view` | Detail page rendered ("views"). |
| `unique_detail_view` | As above, deduplicated per visitor over a 6-hour window. |
| `gallery_open` | Photo lightbox opened. |
| `phone_click` | Agent phone number clicked. |
| `email_click` | Agent email clicked. |
| `share_click` | Share sheet opened. |
| `enquiry` | Enquiry form submitted for the listing. |

Counts exclude crawlers/bots — the website filters those before counting.

`enquiry` deliberately overlaps the leads CoreX already receives via `POST /leads`; it lives here so
conversion rate is computable from **one** source without joining the leads table.

A key is accepted for storage when it normalises (trim + lowercase) to `^[a-z0-9_]{1,40}$`. Anything
else is dropped **silently** for that metric only — the listing and the batch still succeed. This is
the storage-safety floor, not a whitelist: any new well-formed metric key lands automatically.

### 3.4 Behaviour — the parts that matter

1. **Idempotency.** Every accepted `batch_id` is recorded. A repeat returns **200 without applying
   it**, echoing the counts recorded the first time. The website retries any non-2xx, so a response
   lost in transit would otherwise double-count.
2. **Unknown listing ids never fail the batch.** A listing deleted in CoreX after the website counted
   a view still arrives. That entry is skipped, the rest is processed, the response is **200**, and
   the skipped ids are named in the body. A 4xx for one stale id would wedge the website's whole
   queue behind it — it retries the entire batch and never advances its watermark.
3. **Only 2xx means accepted.** The website advances its `pushed_count` watermark on 2xx only.
   So: never 2xx for a batch not durably stored. Ingest runs inside a **single transaction** —
   batch row + every increment commit together or not at all. Being briefly unavailable loses
   nothing; the next delta is just larger.
4. **Increments, not assignments.** `metric_count = metric_count + n`, scoped to
   (agency, site, listing, date, metric) — a single `INSERT … ON DUPLICATE KEY UPDATE` per chunk.

### 3.5 Response

```
200 OK
{ "batch_id": "3f6c...", "accepted": 37, "skipped": ["9182"] }
```

| Status | When |
|--------|------|
| **200** | Batch stored (or already stored — idempotent replay). |
| **401** | Missing/invalid API key. |
| **403** | Key lacks `stats:write`. |
| **422** | Structurally malformed body. **Not** unknown listing. **Not** unknown metric key. |

`accepted` = listing entries actually applied. `skipped` = listing ids (as strings, exactly as sent)
that resolved to no listing in this agency.

## 4. Data model / migrations

Deviation from the suggested column names, deliberate and reported: CoreX calls a listing a
**property** everywhere (`property_portal_metrics.property_id`, `properties.id`), and `count` is an
awkward column name in MySQL. Storage uses CoreX-native naming; **the wire contract is unchanged**.

### 4.1 `website_stat_batches`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | tenant |
| `agency_api_key_id` | FK → agency_api_keys, nullable | which credential pushed it (audit) |
| `site` | string(64) | the `site` field |
| `batch_id` | string(64) | the `batch_id` field |
| `source` | string(32) | the `source` field |
| `listing_count` | uint | `count(listings[])` as received |
| `accepted_count` / `skipped_count` | uint | what we applied / skipped |
| `skipped_listing_ids` | json, nullable | echoed back on replay |
| `metric_row_count` | uint | (listing × date × metric) rows touched |
| `generated_at` | timestamp, nullable | |
| `received_at` | timestamp | |
| timestamps + softDeletes | | non-negotiable #1 |

`unique (agency_id, site, batch_id)` — the idempotency guard, enforced by the database, not by a
read-then-write race.

### 4.2 `listing_website_stats`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | |
| `site` | string(64) | |
| `property_id` | FK → properties (cascade) | resolved CoreX listing |
| `stat_date` | date | `Y-m-d` as sent (Africa/Johannesburg) |
| `metric` | string(40) | open key set |
| `metric_count` | unsigned bigint, default 0 | running, incremented |
| timestamps | | no softDeletes — never user-deleted, and a tombstoned row would silently swallow later increments on the same natural key |

`unique (agency_id, site, property_id, stat_date, metric)` — the increment target.
`index (agency_id, stat_date, metric)`, `index (property_id, metric, stat_date)`.

Daily granularity keeps it small (one row per listing/metric/day) while supporting charts and any
date range.

### 4.3 `listing_website_stat_totals`

| Column | Type | Notes |
|--------|------|-------|
| `agency_id`, `site`, `property_id`, `metric` | | `unique` together |
| `reported_total` | unsigned bigint | the website's lifetime figure |
| `reported_at` | timestamp | when it last told us |

The reconciliation surface. `reported_total` vs `SUM(listing_website_stats.metric_count)` is the
drift check if a batch is ever lost, and it is what the UI shows as "all time" (authoritative even
across a gap).

## 5. UI placement

### 5.1 Listing detail — "Website Performance" panel
Intelligence tab, directly **below** "Portal Engagement Over Time" (the portal chart covers P24/PP;
this covers the agency's own site — same row of thinking, adjacent placement).

- Stat tiles over the last 30 days: **Views** (`detail_view`), **Unique Views**, **Impressions**,
  **Enquiries**, **Contact Clicks** (`phone_click + email_click + share_click`), and a
  **view→enquiry conversion rate**. All-time figures ride under each tile as subtext.
- A **30-day sparkline of `detail_view`** — inline SVG, no new chart dependency.
- **Last received** timestamp + site name, so a silent website reads as *silent*, not as zero traffic.
- Multiple sites for one agency: a per-site breakdown line under the header.
- Hidden entirely when the agency has never received website stats (no dead UI for agencies with no
  site on the API).

### 5.2 Listings index — "Views (30d)" column
A sortable column (`?sort=website_views`) in list view, so an agent can see at a glance which
mandates are getting attention. Rendered only when the agency has website stats.

### 5.3 Today dashboard — "Website Performance" card
Agent-scoped 30-day totals across the listings they own (views / enquiries / impressions), plus the
top listings by views. Snapshot urgency (`low`). Appears only when there is traffic to show.

## 6. Permissions

No new permission keys. The panel and column live on pages already gated by the existing
`properties` permissions; the API surface is gated by the API key's `stats:write` scope.
`stats:write` is added to `AgencyApiKey::SCOPES`, so it appears automatically in the per-key scope
checkbox list on the agency API panel (`resources/views/admin/agencies/create-edit.blade.php`
iterates `AgencyApiKey::SCOPES`). Existing keys do **not** get it implicitly — someone ticks it.

This is not an agency *setting*, so non-negotiable #10a (Setup Wizard) does not apply: it is a
per-credential grant on the API key, made where every other scope is made.

## 7. Acceptance criteria

1. `POST /api/v1/website/listings/stats` with a valid key + `stats:write` returns
   `200 {batch_id, accepted, skipped}` and stores one row per (site, listing, date, metric).
2. The same `batch_id` re-POSTed returns 200 and **does not** change any count.
3. A batch containing an unknown `listing_id` returns 200, applies every other listing, and names
   the unknown id in `skipped`.
4. An unknown metric key is stored, not rejected.
5. A key without `stats:write` gets 403; no key gets 401.
6. Counts increment across batches (two batches for the same day sum).
7. `delta` is used when a listing entry carries no `days[]`.
8. The Intelligence tab shows the panel with the right numbers, conversion rate, sparkline and
   last-received time.
9. The listings index sorts on "Views (30d)".
10. The Today card shows the agent's 30-day totals.

## 8. Deliberately NOT built (first pass)

`GET /api/v1/website/listings/{id}/stats?from=&to=` — the read-back so the website can show an owner
"your property has been viewed N times". Named as optional/second step in the brief. Not built; the
storage shape supports it directly when asked for.

## 9. Files

**New**
- `database/migrations/2026_08_29_000010_create_website_listing_stats_tables.php`
- `app/Models/WebsiteStatBatch.php`
- `app/Models/ListingWebsiteStat.php`
- `app/Models/ListingWebsiteStatTotal.php`
- `app/Services/Website/WebsiteListingStatsIngestService.php`
- `app/Services/Website/WebsiteListingStatsReportService.php`
- `app/Http/Controllers/Api/V1/Website/ListingStatsController.php`
- `resources/views/corex/properties/intelligence/_website-performance.blade.php`
- `tests/Feature/Website/WebsiteListingStatsIngestTest.php`

**Modified**
- `routes/api.php` — the route + scope group
- `app/Models/AgencyApiKey.php` — `stats:write`
- `app/Http/Controllers/CoreX/PropertyController.php` — index column data + sort
- `resources/views/corex/properties/index.blade.php` — the column
- `resources/views/corex/properties/show.blade.php` — the panel include
- `app/Services/CommandCenter/CommandCentreService.php` — the Today card
- `resources/views/command-center/today.blade.php` — the card's summary line
- `.ai/specs/agency-public-api.md` — cross-reference
