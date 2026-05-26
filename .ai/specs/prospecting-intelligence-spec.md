# Prospecting Intelligence — Module Spec

> Status: Draft pending Johan approval — 2026-05-13
> Owner: Johan / Andre
> Pillars: Contact (buyers) + Property (prospecting listings, mandates, portal captures) + Agent (prospecting workflow)
> Depends on: `.ai/specs/unified-buyer-wishlist-spec.md` (must be fully shipped before this spec's data-dependent prompts execute)
> Source audits:
> - `.ai/audits/2026-05-13-buyer-prospecting-audit.md`
> - `.ai/audits/2026-05-13-wishlist-unification-audit.md`
> Sister spec (next):  `.ai/specs/whatsapp-prospecting-spec.md` (to be written) — consumes this spec's aggregation engine

---

## Section 1 — Purpose & Context

**The business problem.** Getting more stock into the agency. Current prospecting is high-effort, low-yield: agents pick up the phone with a list of property addresses and no intelligence about which sellers are most likely to convert. The result is cold calls, mostly "no thanks," and demoralised agents.

**The product insight.** Agents convert sellers when they can credibly say "we have X buyers actively looking for properties like yours, in your area, at your price band." That claim is true today — CoreX has the buyer data — but it isn't surfaced in the prospecting flow. Every prospecting call should be informed by live buyer-side demand.

**The resolution.** Add a **Prospecting Intelligence layer** on top of the prospecting tab:

1. **Active Buyers summary block** at the top of the prospecting tab. Shows demand segmentation (by town, price band, beds, property type, pre-approval status). Each segment is clickable — tapping it filters the property list below to match. Powered by ContactMatch data (unified per the wishlist spec).
2. **Time-based Buyer Funnel panel** alongside the summary block. Shows new buyers entering the agency over selectable windows (7 / 30 / 60 / 90 / 180 days), broken down by pipeline status (new / warm / cold / lost). For branch managers and recruitment decisions.
3. **Source-aware prospecting list** — extend the current prospecting list to include not just captured-address listings but also P24-email-sourced, Private Property-email-sourced, and Portal-Capture-scraped listings. Each row carries a source badge and source-appropriate actions ("Open ad" for email-sourced, "WhatsApp" once we ship the WhatsApp spec for address-captured).

**Downstream features this unblocks:**
- WhatsApp prospecting modal (next spec) — consumes this spec's aggregation API to inject live buyer counts into agent-composed messages.
- Future: buyer-direction matching (the "0 stock matches" bug from the audit — separate spec, after this one).
- Future: branch-manager dashboards built on the same aggregation engine.

**Explicit out of scope** for this spec:
- The WhatsApp modal itself (next spec).
- Fixing the stock matcher (`property_buyer_matches` showing 0 rows — separate spec).
- Mobile API endpoints for prospecting intelligence (web-first ship; mobile follows).
- Cross-agency / industry-wide demand data.
- Any change to how P24/PP emails or Portal Capture data is collected — only how it's displayed.

---

## Section 2 — Source Material

| File / Spec | Read |
|------|------|
| `CLAUDE.md` | Yes |
| `.ai/STANDARDS.md` | Yes |
| `.ai/specs/unified-buyer-wishlist-spec.md` | Yes (primary dependency) |
| `.ai/audits/2026-05-13-buyer-prospecting-audit.md` | Yes (primary input — Sections C1–C4 cover current prospecting tab state) |
| `.ai/audits/2026-05-13-wishlist-unification-audit.md` | Yes (matcher engines reference) |
| `.ai/specs/SPEC_Portal_Scraping_Prospecting.md` | Yes (source for portal-captured listings) |
| `.ai/specs/matches.md` | Yes (matching engine reference) |
| `app/Http/Controllers/ProspectingController.php` | To be re-read during pre-flight of each build prompt |
| `resources/views/corex/prospecting/index.blade.php` (path confirmed in audit) | To be re-read during pre-flight |
| `app/Models/ProspectingListing.php` | To be re-read during pre-flight |
| `app/Services/PropertyMatchScoringService.php` (post-Prompt-05 state) | Must be re-read after wishlist spec Prompt 05 ships |

---

## Section 3 — Decisions Locked

These are Johan's product decisions, locked. Do not re-debate in any build prompt. Encode as written.

### P1. Active buyer definition

An **active buyer** is a `Contact` that satisfies ALL of:

- `buyer_status IN ('new', 'warm')` (the pipeline statuses confirming active agency engagement).
- At least one `ContactMatch` row with `status = 'active'` AND `deleted_at IS NULL`.

**Cold buyers are excluded by default**, but the summary block exposes a checkbox "Include cold buyers" that adds `'cold'` to the status filter. Default OFF.

**Lost buyers are always excluded** from active-buyer aggregation.

**Rationale:** active buyers are who you can sell to right now if the right stock surfaces. Pipeline status + having a wishlist is the operational definition of "match-ready."

### P2. Time-based buyer view — first-class secondary panel

Alongside the active-buyers summary, a **Buyer Funnel** panel shows new buyers entering the agency over selectable windows. Not for prospecting decisions — for funnel health.

- Toggle windows: 7 / 30 / 60 / 90 / 180 days. Default: 30.
- Counts contacts whose `became_buyer_at` (or equivalent canonical timestamp — confirm during pre-flight) falls within the window.
- Includes ALL pipeline statuses (new + warm + cold + lost). Loss-in-30-days is a real signal.
- Breakdown displayed: "32 new buyers in last 30 days: 18 new, 9 warm, 3 cold, 2 lost."
- Click a status segment → filters the property list below to properties matching buyers IN THAT STATUS within the window.

### P3. Town-level default, suburb drill-down

Demand segmentation in the summary block aggregates at **town level by default** for impact ("we have 23 buyers in Margate"). When an agent clicks a town segment, the drill-down view exposes the suburb breakdown ("23 in Margate area = 14 Margate proper + 6 Uvongo + 3 Manaba") so prospecting can be precise.

- A `towns` reference is required (Section 4.4).
- Suburbs without a known town parent surface as their own "town" in the summary (don't lose them — just label clearly).

### P4. DISTINCT-contact aggregation across all active wishlists

When counting "buyers in segment X," the aggregation uses `SELECT COUNT(DISTINCT contact_id)` across ALL active `ContactMatch` rows for that contact — NOT only the primary wishlist.

**Refinement of unified-wishlist spec D1.** The primary flag from the wishlist spec governs:
- Which wishlist is shown in the buyer pipeline UI by default.
- Which criteria appear in default WhatsApp messages.

But the demand summary uses every active wishlist a buyer has, then de-duplicates at the contact level. A buyer with three wishlists across three towns counts once per town they're interested in, never thrice in one town.

**Rationale:** an investor with a primary "Margate house" wishlist and a secondary "Uvongo apartment" wishlist generates real demand in both towns. Primary-only counting would hide their Uvongo interest from agents prospecting Uvongo sellers. The DISTINCT clause prevents the inverse problem (double-counting if two wishlists happen to overlap).

### P5. Click-to-filter is server-rendered, not client-side filtering

The summary block segments are rendered with their counts on page load. When an agent clicks a segment, the page reloads with query parameters that re-render both the summary block (now showing the active filter) and the property list below (now filtered).

**Rationale:** the property list is paginated and could exceed 1000 rows. Client-side filtering can't reliably handle that scale, and a true server query also lets us pre-cache aggregations. Server-rendered is simpler, faster for the common case, and keeps URL-shareable filter state ("send me a link to all Margate 3-bed prospecting opportunities").

Alpine.js is still used for non-filter interactivity (collapsibles, inline edits).

### P6. Source-aware property list — four sources, one list

The prospecting list unifies four data sources. Each row displays a **source badge** and exposes **source-appropriate actions**:

| Source | Badge | Primary action | Secondary actions |
|---|---|---|---|
| Captured (full address known) | `Captured` (green) | Prospect (once WhatsApp spec ships) | View on map, edit, archive |
| P24 email alert | `P24 Alert` (blue) | Open P24 ad | Capture address, archive |
| Private Property email alert | `PP Alert` (blue) | Open PP ad | Capture address, archive |
| Portal Capture (Chrome extension) | `Portal Scrape` (purple) | View captured details | Open original URL, capture address, archive |

**No data is merged across sources** — each source row remains its own record. The list is a unified *view*, not a unified table. The data model for each source is unchanged by this spec.

### P7. Empty state during match regeneration

When `RegenerateBuyerMatchesJob` is running (per wishlist spec D7), both the summary block and the time-based panel display a banner: "Rebuilding buyer matches — refresh in a few minutes." The property list remains usable but match counts per row may show as zero or stale. No 500s.

Detected via the same cached `corex.matches.regenerating` flag the wishlist spec defines.

### P8. Permissions — same gate as the prospecting tab today

This spec adds no new permissions. Whoever can see the prospecting tab today can see the intelligence layer. Per CLAUDE.md route-owns-the-gate convention.

### P9. Aggregation caching strategy

The summary block aggregations run on every page load by default. If performance proves an issue:

- Phase 1 (this spec): direct queries on `ContactMatch` joined to `contacts`. With ~30 active buyers in test data, ~100s in production at scale, queries are sub-second.
- Phase 2 (follow-up ticket): materialised view or cached aggregation refreshed after `RegenerateBuyerMatchesJob`. Out of scope of this spec — flagged for later if needed.

### P10. Mobile API

Out of scope for this spec. Web-first. Mobile API endpoints (`/api/mobile/prospecting-intelligence`) tracked as a follow-up ticket.

### P11. Buyer-direction matching

This spec deals only with seller-prospecting (buyer demand → property targets). The buyer-direction view ("here are properties matching buyer X's wishlist") and the fix for the 0-row `property_buyer_matches` bug are out of scope. Separate spec.

---

## Section 4 — Schema Changes

### 4.1 `contacts` — confirm pipeline status column

No schema change required if `buyer_status` already exists on `contacts` with values `new` / `warm` / `cold` / `lost`. The buyer-prospecting audit (Section A4) reported 43 buyer contacts with status breakdown "22 warm, 8 cold, 9 lost, 4 new" — so the column exists.

**Pre-flight of Prompt 01 (this spec)** confirms:
- Exact column name (`buyer_status` or `status` or another name).
- Exact enum / string values.
- Whether the column has an index.

If no index exists and the column is filtered on every summary query, add a single-column index in Prompt 01.

### 4.2 `contacts` — `became_buyer_at` timestamp

If a canonical "when did this contact become a buyer" timestamp does not exist, add it:

| Column | Type | Nullable | Default | Index | Backfill |
|---|---|---|---|---|---|
| `became_buyer_at` | timestamp | YES | NULL | yes (for time-window queries) | `became_buyer_at = created_at` for all contacts currently `buyer_status IS NOT NULL` |

Pre-flight confirms whether an existing column serves this purpose (e.g. `buyer_since`, `pipeline_entered_at`). Reuse if so.

### 4.3 `prospecting_buyer_matches` and `property_buyer_matches`

No schema changes. These tables now have `agency_id` from wishlist spec Prompt 01. This spec consumes them read-only.

### 4.4 New table — `towns`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `name` | varchar(100) | NO | "Margate", "Shelly Beach", etc. |
| `agency_id` | bigint unsigned | YES | Multi-tenancy: NULL = system-shared; non-null = agency-specific. AgencyScope-aware reads (system rows + own agency). |
| `region` | varchar(100) | YES | "KZN South Coast", "Cape Peninsula" |
| `slug` | varchar(120) UNIQUE | NO | URL-safe |
| `created_at`, `updated_at` | timestamps | | |

| Index | Columns |
|---|---|
| `towns_slug_unique` | (slug) UNIQUE |
| `towns_agency_idx` | (agency_id) |
| `towns_region_idx` | (region) |

**`town_id` foreign key added to `suburbs`** if a `suburbs` table exists.
If suburbs are stored as strings on `contact_matches.suburbs` (JSON) and on `properties.suburb` (string) — likely per the audit — then a lookup table is needed:

### 4.5 New table — `suburb_town_mapping`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `suburb_name` | varchar(150) | normalised lowercase |
| `town_id` | FK -> towns(id) | |
| `agency_id` | bigint unsigned, nullable | system + agency overrides |
| Index on `(suburb_name, agency_id)` for fast lookup | | |

This is a pragmatic choice: rather than refactor existing string suburb storage everywhere, we add a lookup. When the summary block aggregates, it joins on this mapping. When no mapping exists, the suburb surfaces as its own town in the summary (Section 5.1 falls back gracefully).

**Seed data:** initial seeder populates KZN South Coast town/suburb mapping (Margate town: Margate, Uvongo, Manaba, Ramsgate; Shelly Beach town: Shelly Beach, Southbroom — confirm exact mapping with Johan before seeding). Future agencies seed their own.

**Pre-flight of Prompt 01 confirms whether any existing table already serves as a suburb-town reference.** If yes (e.g. from TVA API integration), reuse. If no, create per above.

### 4.6 Prospecting list source unification

The audit (Section C2) reports the prospecting tab currently spines on a specific table. To unify across four sources, the recommended approach is a **database VIEW or a query-time UNION**, NOT a new physical table. This preserves source-data integrity.

Options:
- **Option A: SQL VIEW** `vw_prospecting_unified` that UNIONs the four sources with a `source_type` column.
- **Option B: Eloquent query-time UNION** in `ProspectingController` or a new `ProspectingListingResolver` service.

**Recommendation: Option B (query-time UNION via a resolver service)** because (a) it keeps multi-tenancy clean (each source already AgencyScopes through its own model), (b) it's easier to test, (c) VIEWs in Laravel + MySQL across mixed schemas get awkward fast.

Build Prompt 03 of this spec confirms during pre-flight which source tables actually exist, what their schemas look like, and finalises the resolver design.

---

## Section 5 — UI Changes

### 5.1 Prospecting tab — top summary block

Above the existing property list, a new **Active Buyers Summary** block. Server-rendered Blade.

**Headline row:**
> **23 active buyers** | with criteria across **6 towns** | **9 pre-approved** | total budget pool **R 58.5M**

**Segment grid (5 segment types, click-to-filter):**

1. **By Town** (default expanded): "Margate · 14 buyers", "Shelly Beach · 5", "Port Shepstone · 2", etc.
2. **By Price Band**: "Under R1m · 3", "R1m–R2m · 8", "R2m–R3m · 7", "R3m–R5m · 4", "R5m+ · 1".
3. **By Bedrooms**: "1-bed · 2", "2-bed · 5", "3-bed · 11", "4-bed · 4", "5+ bed · 1".
4. **By Property Type**: "House · 14", "Townhouse · 6", "Apartment · 3", "Vacant Land · 0".
5. **By Pipeline Status**: "New · 4", "Warm · 22", "Cold · 6 (toggle on)".

**Toggle controls (above the grid):**
- "Include cold buyers" checkbox (per P1).
- "Pre-approved only" checkbox.
- "Has primary wishlist only" — for users who want the simpler view; defaults OFF.

**Filter state in URL.** Clicking a segment encodes filter as query parameters: `?filter[town]=margate&filter[price_band]=1m-2m`. Multiple filters combine (AND). A "Clear filters" link appears when any filter is active.

**Drill-down behaviour.** Clicking a town segment shows:
- The suburb breakdown for that town.
- The list of buyers in that town (collapsed, expandable per-buyer to see all their wishlists).
- The property list below reshapes to properties in that town.

### 5.2 Buyer Funnel panel (time-based, per P2)

Position: right of or below the summary block (responsive — confirm layout pattern during pre-flight).

**Headline:**
> Last [30] days: **32 new buyers** — 18 new, 9 warm, 3 cold, 2 lost

Window toggle: pill buttons for 7 / 30 / 60 / 90 / 180.

Each status badge is clickable: filters the property list to "properties matching buyers in this status who entered the agency in this window."

### 5.3 Source-aware prospecting list (per P6)

Each existing row in the prospecting list gains:
- A coloured **source badge** at the top-left of the row.
- A **per-row buyer-match count** ("3 active buyers matching" — pulled from `prospecting_buyer_matches` filtered by current page filters, DISTINCT contact_id, agency-scoped).
- Source-appropriate primary action button.

Rows from email/scrape sources (P24, PP, Portal Capture) that have not yet had their address captured display a "Capture address" inline action that opens a quick-capture modal (out of scope of this spec — placeholder action stub only).

### 5.4 Sidebar / navigation

No sidebar change. Prospecting tab already exists.

### 5.5 Empty state during regeneration (per P7)

Banner at the top of the page when the regeneration flag is set:
> ⚠ Rebuilding buyer matches — counts may be stale. Refresh in a few minutes.

Summary block + funnel panel + per-row match counts continue to render with whatever data is currently in the match tables.

---

## Section 6 — Aggregation Engine

A new service: `app/Services/Prospecting/ProspectingIntelligenceService.php`.

### 6.1 Public API

```php
class ProspectingIntelligenceService
{
    // Headline numbers for the summary block.
    public function activeBuyerSummary(array $filters = []): SummaryDTO;

    // Per-segment counts for the grid (towns, price bands, beds, types, statuses).
    public function segmentBreakdown(string $segment, array $filters = []): array;

    // Time-based panel.
    public function buyerFunnel(int $windowDays = 30): FunnelDTO;

    // Per-listing match count (used by the prospecting list).
    public function buyersMatchingListing(int $listingId): int;

    // Used by the WhatsApp spec (next).
    public function buyersForPropertyProfile(PropertyProfile $profile): Collection;
}
```

### 6.2 Query patterns

Every aggregation:
- Joins `contact_matches` to `contacts` to access `buyer_status` and `became_buyer_at`.
- Applies `ContactMatch::active()` scope.
- Applies `Contact::where('buyer_status', IN, $statusFilter)`.
- Uses `COUNT(DISTINCT contact_id)` for buyer counts (per P4).
- AgencyScope auto-applies at the model level. NEVER bypass with raw `DB::table()`.

Town aggregation uses `suburb_town_mapping`:
```
SELECT t.name AS town, COUNT(DISTINCT cm.contact_id) AS buyers
FROM contact_matches cm
JOIN contacts c ON c.id = cm.contact_id
JOIN JSON_TABLE(cm.suburbs, '$[*]' COLUMNS (suburb VARCHAR(150) PATH '$')) sub
LEFT JOIN suburb_town_mapping stm ON stm.suburb_name = LOWER(sub.suburb)
LEFT JOIN towns t ON t.id = stm.town_id
WHERE cm.status = 'active'
  AND cm.deleted_at IS NULL
  AND c.buyer_status IN ('new', 'warm')
GROUP BY t.name
```

Suburbs without a town mapping appear under `t.name IS NULL` — the service treats this as a synthetic "(unmapped) {suburb_name}" town and surfaces them at the bottom of the segment grid.

### 6.3 Per-listing match count

Reads `prospecting_buyer_matches` directly (scope by `agency_id` + `prospecting_listing_id`), then DISTINCTs by `contact_id`. Single query per listing — if performance becomes an issue, cache per listing_id (Phase 2).

### 6.4 Caching

Section 6 ships uncached. Aggregations on test data (31 buyers) measure sub-100ms. Production scale (~100s of buyers, 1000s of listings) should remain sub-second. Phase 2 caching deferred.

---

## Section 7 — Controller & Service Changes

### 7.1 `app/Http/Controllers/ProspectingController.php`

- Accept new query parameters: `filter[town]`, `filter[price_band]`, `filter[bedrooms]`, `filter[property_type]`, `filter[status]`, `include_cold`, `preapproved_only`, `funnel_window`.
- Inject `ProspectingIntelligenceService`.
- Pass to view: `$summary`, `$segments`, `$funnel`, `$filteredListings`, `$activeFilters`.
- Existing prospecting list query becomes filter-aware (joins `prospecting_buyer_matches`, applies filters).

### 7.2 New service files

- `app/Services/Prospecting/ProspectingIntelligenceService.php`
- `app/Services/Prospecting/ProspectingListingResolver.php` (unifies the four sources per P6)
- `app/Services/Prospecting/PriceBandClassifier.php` (utility: maps price → band token; standardised across summary + filters + future WhatsApp messages)

### 7.3 New models

- `app/Models/Town.php` (BelongsToAgency-aware via the agency_id column)
- `app/Models/SuburbTownMapping.php` (BelongsToAgency-aware)

---

## Section 8 — Build Prompt Sequence

Each prompt: standard reads, pre-flight, changes, post-change verification, end-of-prompt verification. Run only after the previous reports clean.

| # | Prompt | One-line summary | Success criteria |
|---|---|---|---|
| 01 | **Schema migrations + seed** | Create `towns`, `suburb_town_mapping`. Confirm or add `became_buyer_at` on contacts. Seed KZN South Coast town/suburb mapping after Johan confirms list. | All 10 post-migration checks pass; rollback proven; seed produces ≥1 town with ≥3 mapped suburbs |
| 02 | **Models** | `Town`, `SuburbTownMapping` with BelongsToAgency; relationships; scopes | Models read/write; agency scope verified |
| 03 | **`ProspectingListingResolver`** | Service that unions the four sources into a paginated query | Returns rows from each source with correct source_type tag; pagination works; AgencyScope respected |
| 04 | **`PriceBandClassifier`** | Pure utility; price → band token; reverse | 100% unit test coverage on the boundaries |
| 05 | **`ProspectingIntelligenceService`** | Core aggregation engine; all 5 segment types; funnel; per-listing count | Tinker outputs match expected counts on test data; sub-second on agency_id=1 dataset |
| 06 | **`ProspectingController` refactor** | Wire the service into the controller; query parameter parsing; pass DTOs to view | Existing prospecting tab still renders; filter query parameters round-trip correctly |
| 07 | **Summary block UI** | Top-of-page block with headline row + 5 segment grids + toggle controls | Renders; click-to-filter reloads with correct URL state; visual matches Johan's design intent |
| 08 | **Buyer Funnel panel UI** | Time-based panel; window pills; status click-through | Renders; window toggles update counts; status click filters property list |
| 09 | **Source-aware list UI** | Source badges per row; source-appropriate actions; per-row match counts | All four source types render distinct badges; "Open P24 ad" works for P24-source rows |
| 10 | **Empty-state banner** | Banner shown when regeneration flag set | Setting the flag in Tinker triggers the banner; clearing it removes it |
| 11 | **End-to-end smoke + validation** | Investigation prompt: confirm all features work together, AgencyScope holds, performance acceptable, no behaviour regression on existing prospecting | Final report saved to `.ai/audits/2026-MM-DD-prospecting-intelligence-postship-audit.md` |

---

## Section 9 — Rollback Plan

Per-prompt rollback table (similar shape to wishlist spec Section 12). Schema migrations rolled back via `migrate:rollback`. UI prompts via `git revert`. Service prompts via `git revert` — these read from match tables only, write nothing destructive.

**No data destruction risk in this spec.** All writes go to new tables (`towns`, `suburb_town_mapping`); all reads from existing tables. Rollback at any prompt is safe and clean.

---

## Section 10 — Acceptance Criteria

1. Active buyers summary block renders at the top of the prospecting tab with five segment grids and three toggle controls.
2. Clicking any segment filters the property list below; URL state encodes the filter.
3. Buyer funnel panel displays 30-day count with pipeline-status breakdown; window-pill toggles work.
4. Source badges render correctly for all four source types.
5. Per-listing buyer-match count appears on every prospecting list row.
6. `COUNT(DISTINCT contact_id)` invariant holds: a buyer with three wishlists in Margate counts as 1 in the Margate segment.
7. Cold buyers excluded by default; included when toggle is on.
8. Lost buyers never appear in active-buyer aggregations.
9. AgencyScope enforces tenancy on every aggregation (verified via Tinker on a multi-agency test setup, even if only one real agency exists).
10. Empty-state banner appears when regeneration flag is set; disappears when cleared.
11. No new permission errors introduced — anyone who can see the prospecting tab today can see the new layer.
12. Performance: every page load under 1 second on agency_id=1 test dataset (~30 active buyers, ~1000 prospecting listings).

---

## Section 11 — Open Questions

1. **Town/suburb seed list for KZN South Coast.** Johan to confirm initial town groupings before Prompt 01 seeds them. Suggested starting point:
   - Margate town: Margate, Uvongo, Manaba Beach, Ramsgate
   - Shelly Beach town: Shelly Beach, St Michaels-on-Sea, Southbroom
   - Port Shepstone town: Port Shepstone, Oslo Beach, Umtentweni
   - Margate's standalone or Margate-area treatment of Hibberdene / Pumula / Munster / Trafalgar (further south) — Johan to advise
2. **Existing `became_buyer_at` field.** If no canonical timestamp exists, default to `contacts.created_at` for backfill — but confirm before Prompt 01.
3. **Price band thresholds.** Default proposed: <R1m, R1–2m, R2–3m, R3–5m, R5m+. Johan to confirm or adjust for KZN South Coast market reality.
4. **Buyer Funnel panel layout.** Right of summary block (desktop) vs below (mobile)? Defer to Prompt 08 pre-flight.

---

**End of spec.**
