# Market Intelligence — comps leak / recommended-price / stats-graph investigation

**Date:** 2026-07-11
**Env:** qatesting1 (`/corex-qa1`, branch `QA1`, HEAD `e696151a`) — carrying tonight's fresh live-snapshot data (AT-230)
**Investigator:** m3 (CC3 lane), read-only first. Fixes on Johan's approval.
**Subject property:** **ID 6060** — Shelly Beach, R1,900,000 asking, 7-bed House, `listing_type=sale`, agency 1, created 2026-07-04 (~7 days on market). Portal metrics: P24 226/308 views (09–10 Jul), enquiries present.

Three defects reported on this property's page. All three reproduced exactly. Doctrine verdict (code-bug vs data-artifact) called per item.

---

## Defect 1 — a RENTAL appears under "Comparable Listings" → **CODE DEFECT**

**Surface:** Property page → Intelligence tab → "Comparable Listings" section (`resources/views/corex/properties/show.blade.php:5080–5100`), fed by `$comparables = $intel->getComparableListings($property->id)` (line 4648).

**Source:** `App\Services\PropertyIntelligenceService::getComparableListings()` — `PropertyIntelligenceService.php:326`.

```php
Property::withoutGlobalScopes()
    ->where('id', '!=', $propertyId)
    ->where('agency_id', $property->agency_id)
    ->whereNull('deleted_at')
    ->when($property->suburb, fn($q) => $q->where('suburb', $property->suburb))
    ->orderByDesc('published_at')
    ->limit($limit)   // 5
```

**Root cause:** the query gates on agency + suburb + soft-delete only. **No `listing_type` predicate, no price band, no property-kind gate.** Any rental or commercial letting in the same suburb is a "comparable listing" for a residential sale.

**Evidence (qa1, live data):** `getComparableListings(6060)` returns —
| id | title | shown price | listing_type |
|----|-------|-------------|--------------|
| 6073 | **Restaurant to let in Shelly Beach** | R23,265 | **rental** |
| 4886 | **Office Rented Out in Shelly Beach** | R0 | **rental** |
| 2993 | Stunning Beach Front Property | R1,630,000 | sale |
| 4926 | Apartment For Sale | R1,299,000 | sale |
| 1667 | Family home R2 649 000 | R2,649,000 | sale |

Two of five "comparables" are commercial lettings — a restaurant's monthly rent (R23,265) rendered as if a sale price against a R1.9m 7-bed house.

**Verdict:** Code defect. Present on live too (data path is identical; live simply has different suburb inventory). Not a qa1 artifact.

**Twin (latent, same class):** `App\Services\MarketDataSnapshotService::getComparableListings()` (`MarketDataSnapshotService.php:82`) has the identical omission and feeds `comparable_listings` into `PropertyPresentationSnapshot`. Same fix must land here.

---

## Defect 2 — Recommended Price R804,000 on a R1.9m property → **CODE DEFECT** (rental-poisoning hypothesis DISPROVEN)

**Surface:** Property page → Intelligence → "Presentations & Market Positioning" card (`show.blade.php:4994–5008`): Recommended Price R804,500 (line 4997), Area Average R1,481,250 (5001), Recent Comps 12 (5005). All three reproduced by `getLatestMarketPosition(6060)`.

**Recommended source:** `MarketDataSnapshotService::calculateRecommendedPrice()` (line 134) = plain **median** of `getComparableSales()` (line 51) = `property_sold_records` where `suburb = 'Shelly Beach'` AND `sold_date >= now-6mo`, `limit 10`. **No gating whatsoever.**

**The 10 records feeding it (all `source=manual` genuine sales):**
`R1,300,000 (Apartment) · 799,000 (Townhouse) · 720,000 (Townhouse) · 865,000 (Apartment) · 810,000 (Apartment) · 770,000 (Apartment) · 1,200,000 (Apartment) · 765,000 (Apartment) · 250,000 (Business/commercial) · 1,195,000 (House)`

Sorted → median = (799,000 + 810,000) / 2 = **R804,500**. Confirmed exactly.

**Prime-suspect check (per doctrine — verify, don't assume):** Johan's hypothesis was that a rental leaked into the pool and its monthly amount was treated as a sale price, crushing the anchor. **Disproven.** `property_sold_records` has **zero** rental rows for Shelly Beach (no `listing_type` column; no `REGEXP 'let|rent'` matches; every row is a genuine sold sale). The recommended-price pool is a *different* table from the Comparable-Listings pool where the rental leaked (Defect 1). They do not intersect.

**Actual root cause — no profile gating:** a 7-bed R1.9m **House** is being valued off a pool dominated by 1–2-bed apartments/townhouses (R720k–R1.3m) plus a **R250k commercial "Business"**. The engine applies:
- ❌ no title-type gate (a commercial shop is in the pool)
- ❌ no property-kind gate (apartments vs the subject's house)
- ❌ no price band
- ❌ no beds / erf-size gate
- ❌ the subject's own asking (R1.9m) is never used as an anchor

This is precisely the pre-AT-22 **"R1.1M trap"** that `App\Services\Presentations\CompPoolBuilder` (the canonical gate-then-rank + subject-anchored-band engine) was built to fix. **`MarketDataSnapshotService` never adopted `CompPoolBuilder`** — it is the legacy Phase-1 "median of raw suburb sales" path, still live on the property page.

**Secondary defect on the same card:** Area Average (R1,481,250) is the **MEAN** of `presentation_sold_comps` (12-month window, a **different table**), while Recommended is the **MEDIAN** of `property_sold_records` (6-month window). Two sources, two statistics, two windows, presented side-by-side as if reconcilable — which is why Recommended (R804k) sits absurdly below Area Average (R1.48m) on an above-average-size property.

**Verdict:** Code defect. Present on live too.

---

## Defect 3 — stats graph renders empty while counts show → **DATA / ENVIRONMENT ARTIFACT (stale qa1 build), NOT a code defect**

**Surface:** Property page → Intelligence → "Portal Engagement Over Time" (`resources/views/corex/properties/intelligence/_portal-engagement-chart.blade.php`).

**Backend is healthy on qa1:**
- `property_portal_metrics` for 6060 has 5 rows, 2026-07-07 … 2026-07-10 (within the 180-day window). The nightly sync **did** carry the table.
- `getPortalEngagementSeries(6060)` returns `has_data=true`, 180 points, 2 non-zero (07-09 views 226 / 07-10 views 308). Data is present and correct.
- Layout `layouts/corex.blade.php:150` yields `@stack('scripts')`, so the inline chart JS + Alpine store DO load.

**Root cause — stale compiled asset bundle on qa1:**
- qa1 serves built assets (no `public/hot`); manifest maps `resources/js/app.js → assets/app-DCI-I60D.js`.
- That bundle was built **2026-07-03 10:12**. `grep` of the bundle: `NexusCharts` ×1, `transactionVolume` ×1, **`portalEngagement` ×0**.
- `window.NexusCharts.portalEngagement()` was added by commit **`b92188bf`** ("portal engagement chart + historical stats backfill"), which is **in QA1's source** (`nexus-charts.js`, mtime 2026-07-06) but **postdates the 2026-07-03 build**. The bundle was never rebuilt after that commit landed.

**Runtime consequence** (matches the symptom precisely):
```js
if (!window.NexusCharts || !this.$refs.canvas) return;   // NexusCharts truthy → guard passes
this.chart = window.NexusCharts.portalEngagement(...)     // undefined → TypeError → chart never builds
```
The chart never renders → **empty graph**. The count tiles/legend numbers come from the **inline** Alpine store (`totalViews()/totalLeads()`, server-rendered in the blade `@push` — always current) → **counts still show**. Exactly "graph empty yet views/enquiries show as counts."

**This is the answer to Johan's one-look test** ("does the graph render on live?"): live has a current build containing `portalEngagement` → the graph renders there. qa1's stale build does not. Code-vs-env is settled: **env**.

**Process gap that caused it:** `scripts/qa-deploy.sh` has **no `npm build` step** (only fetch → ff → migrate → clears → chown). JS/CSS source changes therefore never reach qa1's served bundle. The 06–07 Jul frontend work (dual-axis chart fix `b9c36bb6`, chart partial) is invisible on qa1 for the same reason.

---

## Bug-class sweep — every comps consumer

| Consumer | Source | Rental/type gate? | Status |
|---|---|---|---|
| `PropertyIntelligenceService::getComparableListings` (property page) | `properties` table | ❌ none | **LEAK — confirmed (Defect 1)** |
| `MarketDataSnapshotService::getComparableListings` (snapshots) | `properties` table | ❌ none | **LEAK — latent, same class** |
| `MarketDataSnapshotService::getComparableSales` / `calculateRecommendedPrice` | `property_sold_records` | ❌ no type/profile gate | **Defect 2** (no rentals present, but ungated) |
| `MarketDataSnapshotService::calculateAreaAverages` | `presentation_sold_comps` | ❌ no gate | inconsistent-stat contributor (Defect 2 secondary) |
| `CmaCoverageService::scoreForProperty` comp_count ("Recent Comps") | union: `deals` + `market_report_comp_rows` + `presentation_sold_comps` | sold-based, not active listings | **PROTECTED** (no active rentals in the union) |
| `CompetitorStockMatchService::loadCandidates` (competitor stock) | `prospecting_listings` | price-band + `property_type` family gate + commercial/industrial exclusion | **INCIDENTALLY protected** — a rental's monthly "price" falls below a sale price-band → excluded. No *explicit* `listing_type` gate; recommend adding one for robustness. |
| CMA/presentation "Comparable Sales" (MicSnapshotHydrator / CompPoolBuilder) | `presentation_sold_comps` + deals (sold) | type-gated via CompPoolBuilder | **PROTECTED** |

---

## Fix proposals (on approval — no code changed yet)

**Defect 1 (property page + snapshot comparable listings):** add a subject-matched `listing_type` gate to *both* `getComparableListings` methods —
`->where('listing_type', $property->listing_type ?? 'sale')` — plus a property-kind sanity filter so a residential sale never lists commercial lettings or wildly off-profile stock. Minimal correct fix = the `listing_type` gate; best-in-class = route this surface through the same `CompPoolBuilder` profile gate used by the CMA engine so "comparable" actually means comparable.

**Defect 2 (recommended price + area average):** retire the ungated median. Route `getComparableSales` / `calculateRecommendedPrice` through **`CompPoolBuilder`** (title-type gate → subject-anchored price band → radius → rank) with the subject's asking as the band anchor, so the recommendation is a defensible profile-matched figure rather than a raw suburb median. Unify the "Area Average" onto the **same gated pool and the same statistic** (or relabel it explicitly as "suburb mean, all types" so the two figures stop contradicting each other on one card). This is the AT-22 doctrine applied to the property-page surface that never received it.

**Defect 3 (stale qa1 graph):** **no application code change.** Rebuild qa1 assets now —
`cd /corex-qa1 && npm ci && npm run build && chown -R www-data:www-data public/build` — then hard-reload. **And** add an `npm ci && npm run build` step to `scripts/qa-deploy.sh` so JS/CSS source changes always reach the served bundle (this is the durable fix; the rebuild is the immediate one).

---

## Evidence commands (reproducible on `/corex-qa1`)
- Property: `Property::withoutGlobalScopes()->find(6060)` → Shelly Beach R1.9m 7-bed sale.
- Defect 1: `app(PropertyIntelligenceService::class)->getComparableListings(6060)` → returns rows with `listing_type=rental`.
- Defect 2: `app(MarketDataSnapshotService::class)->calculateRecommendedPrice($p, ...getComparableSales(6060))` → R804,500; pool = 10 ungated suburb sold records incl. a R250k "Business".
- Defect 3: bundle grep `grep -c portalEngagement /corex-qa1/public/build/assets/app-DCI-I60D.js` → **0**; `getPortalEngagementSeries(6060)` → `has_data=true` with real points.
