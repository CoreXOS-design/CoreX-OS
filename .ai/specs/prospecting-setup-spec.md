# Prospecting Setup — Module Spec

> Status: Draft pending Johan approval — 2026-05-13
> Owner: Johan / Andre
> Pillars: Agency (settings) + Property + Contact
> Depends on: `.ai/specs/unified-buyer-wishlist-spec.md` (Phase 1 shipped — provides the ContactMatch data layer this spec configures)
> Sister spec (next):
> - `.ai/specs/prospecting-intelligence-spec.md` (already drafted, will be updated to consume this spec's configuration tables instead of hardcoded constants)
> Source decisions (Johan, 2026-05-13):
> - Four segment dimensions, no more: town/area, property type, bedrooms, price band.
> - Every dimension is user-configurable. No hardcoded constants in the prospecting summary block.
> - No pre-approval stats in summary (defensibility — see Section 1).
> - No cash-buyer flag (same reason).
> - Preapproval block on contacts (already shipped in unified wishlist spec) stays for operational use, doesn't aggregate into prospecting claims.

---

## Section 1 — Purpose & Context

**The problem.** Hardcoded segmentation constants don't survive contact with reality. The KZN South Coast's "Margate town" rolls up Margate / Uvongo / Manaba / Ramsgate. A Cape Town agency's "Atlantic Seaboard" rolls up Sea Point / Bantry Bay / Clifton / Camps Bay. A Gauteng agency's "Sandton" rolls up entirely different suburbs. Price bands that make sense in KZN don't make sense in Constantia. There is no national correct answer — every agency knows their market.

**The principle.** The prospecting summary block segments buyers across four dimensions: town/area, property type, bedrooms, price band. Every one of those dimensions is configured by the agency, not by Anthropic engineers picking defaults.

**The honest pitch principle.** When an agent prospects a seller via WhatsApp / email / phone, every claim is factually defensible. "We have 14 active warm buyers in Margate" — defensible from the data. "Of which 9 are pre-approved" — NOT defensible (pre-approvals are unreliable). "Of which 7 are looking for 3-bed properties like yours" — defensible. The summary block surfaces only data that holds up in a PPRA dispute.

This means: **no pre-approval counts in any prospecting summary. No cash-buyer stats. No "qualified" labels.** A buyer is a buyer. The operational data on each contact (preapproval status, sale-pending status, financial notes) is for the agent's awareness when working that buyer — never aggregated for seller pitches.

**Downstream features this unblocks:**
- Prospecting intelligence layer (summary block + segment filters + buyer funnel) reads its configuration from this spec's tables.
- Seller-outreach WhatsApp composer (the next spec) injects "X buyers in [town] looking for [property type, beds, price band]" — all four values driven by this spec's configuration.
- Stock-gap analysis ("we have 7 buyers wanting 2-bed apartments in Shelly Beach, only 1 active mandate matches") — same configuration.

**Explicit out of scope:**
- User-configurable buyer pipeline statuses (new / warm / cold / lost remains a hardcoded enum — affects too much else in the system).
- Bathrooms / parking / garages as segment dimensions (data exists on the wishlist, available as drill-down filters, not primary segments).
- Feature-based segmentation (must-have / nice-to-have / deal-breaker — these are buyer attributes, available in drill-down, not segments).
- Region-level rollups above town (e.g. "KZN South Coast" containing multiple towns). Add if requested in v2.
- Multi-currency / international price bands.

---

## Section 2 — Source Material

| File / Spec | Read |
|---|---|
| `CLAUDE.md` | Yes |
| `.ai/STANDARDS.md` | Yes |
| `.ai/specs/unified-buyer-wishlist-spec.md` | Yes (provides ContactMatch data layer) |
| `.ai/specs/prospecting-intelligence-spec.md` | Yes (will be updated post-this-spec to consume configuration) |
| `.ai/specs/corex-domain-events-spec.md` | Yes (this spec adds 4 new event classes to the catalogue) |
| `config/corex.php` | Re-read during pre-flight |

---

## Section 3 — Decisions Locked

### S1. Four configurable dimensions, no more

The agency manages exactly four prospecting setup areas in v1:

1. **Towns + suburb mappings** — town is the headline aggregation level; suburbs roll up to towns.
2. **Property types** — replaces hardcoded list; agencies can add "Smallholding" or "Farm" or "Game Farm" if relevant.
3. **Bedroom segments** — default 1 / 2 / 3 / 4 / 5+, agency can rename or split.
4. **Price bands** — separate sets for sale and rental; agency-named (e.g. "Entry: R0-R1.2m", "Mid: R1.2m-R2.5m", "Upper: R2.5m-R5m", "Premium: R5m+").

Every dimension is agency-scoped via `BelongsToAgency`. Default seed data ships for new agencies; each agency edits independently.

### S2. No pre-approval or cash-buyer aggregation in prospecting

The summary block shows buyer counts. Period. No "of which X pre-approved" stat. No "X cash buyers" stat. The preapproval block on contacts (already shipped in unified wishlist Phase 1) remains for operational use when an agent qualifies a specific buyer — but never propagates to aggregate seller-facing claims.

Rationale: pre-approval is a marketing word with weak factual grounding. Sale-pending, cash, bonded, "ready to buy" — all degrees of reliability that vary buyer-by-buyer. The only defensible claim is "this person is a buyer in our system actively looking" — and that's what the summary surfaces.

### S3. Setup screen reachable from two places

Canonical location: **Settings → Prospecting Setup**. Single entry point for the agency administrator.

Shortcut: **⚙ icon top-right of the Prospecting tab**, opens the same setup screen as a slide-in drawer. Lets agents tweak configuration from where they use it without navigating away.

Both entry points render the same component / form. No duplication of UI logic.

### S4. Seed data for new agencies

When an agency is created (or when this spec ships for an existing agency), default seed data populates:

- **Towns:** KZN South Coast seed for HFC; other agencies start blank with a "Build from web" helper that web-searches the agency's region and suggests town/suburb mappings.
- **Property types:** House, Townhouse, Apartment, Vacant Land, Farm, Smallholding, Commercial — agency edits.
- **Bedroom segments:** 1, 2, 3, 4, 5+ — agency edits if relevant.
- **Price bands (sale):** Entry (0 – 1.2m), Mid (1.2m – 2.5m), Upper-mid (2.5m – 5m), Premium (5m+) — agency renames/resizes.
- **Price bands (rental):** Budget (0 – 8k), Standard (8k – 15k), Upper (15k – 30k), Luxury (30k+) — same.

Agencies can completely replace the defaults. Soft-deleted defaults are archived, not lost (CoreX no-hard-deletes rule).

### S5. Edits are versioned, not destructive

Towns / price bands / property types / bedroom segments — when an agency edits any of these, the change is forward-applied to new aggregations. Existing buyer records continue to reference whatever string/value they were captured with (e.g. a wishlist's `suburbs` json contains "Margate" — that string doesn't change if the agency renames the town).

This means historical claims remain defensible. "On 2026-05-13 we said 14 buyers in Margate" is based on the configuration at that point, recorded in the `domain_event_log` per the events spec. Even if Margate gets renamed to "Margate Greater Area" in 2026-07, the historical claim still resolves.

### S6. Multi-tenancy non-negotiable

Every configuration table has `agency_id NOT NULL`, scoped by `BelongsToAgency` at the model layer. An agency editing its setup never touches another agency's data. Even the "default seed" pattern creates per-agency rows, not shared rows — so an agency can rename "Premium" to "Luxury" without affecting any other agency.

### S7. Events emitted on configuration change

Every edit to towns / property types / bedrooms / price bands emits a domain event from the catalogue:
- `Prospecting\TownConfigured`
- `Prospecting\SuburbMappingChanged`
- `Prospecting\PropertyTypeConfigured`
- `Prospecting\BedroomSegmentConfigured`
- `Prospecting\PriceBandConfigured`

The wildcard audit listener writes each to `domain_event_log` — full forensic trail of "who changed what configuration when" with payload snapshots. Per the events spec.

### S8. Read API for the prospecting intelligence layer

A single service `ProspectingConfigurationService` exposes the configuration to consumers:

```php
class ProspectingConfigurationService
{
    public function towns(int $agencyId): Collection;
    public function suburbToTown(int $agencyId, string $suburb): ?Town;
    public function propertyTypes(int $agencyId): Collection;
    public function bedroomSegments(int $agencyId): Collection;
    public function priceBandsFor(int $agencyId, string $listingType): Collection; // 'sale' or 'rental'
    public function classifyPrice(int $agencyId, string $listingType, int $price): ?PriceBand;
    public function bedroomBucketFor(int $agencyId, int $beds): ?BedroomSegment;
}
```

The prospecting intelligence layer (next spec) becomes a consumer of this service — never queries the configuration tables directly.

---

## Section 4 — Schema Changes

### 4.1 New table — `towns`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | FK → agencies, AgencyScope |
| `name` | varchar(100) | NO | "Margate" |
| `slug` | varchar(120) | NO | URL-safe |
| `region` | varchar(100) | YES | "KZN South Coast" — optional grouping for future region rollup |
| `display_order` | unsignedSmallInteger | NO | default 0 — agencies can reorder |
| `deleted_at` | timestamp | YES | SoftDeletes per no-hard-deletes rule |
| `created_at`, `updated_at` | timestamps | | |

Indexes: `(agency_id, slug)` UNIQUE within agency, `(agency_id, display_order)`, soft-delete index.

### 4.2 New table — `town_suburbs`

The suburb-to-town mapping. Per agency.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | FK → agencies |
| `town_id` | bigint unsigned | NO | FK → towns |
| `suburb_name` | varchar(150) | NO | "Uvongo", "Manaba Beach" |
| `suburb_normalised` | varchar(150) | NO | lowercase, trimmed — for fast lookup against wishlist json |
| `deleted_at` | timestamp | YES | SoftDeletes |
| `created_at`, `updated_at` | timestamps | | |

Indexes: `(agency_id, suburb_normalised)` for fast wishlist resolution, `(agency_id, town_id)` for "all suburbs in this town", soft-delete index.

When a wishlist has `suburbs: ["uvongo", "Margate"]`, the configuration service looks up each suburb's town via `town_suburbs.suburb_normalised`. Suburbs without a town mapping appear in summary under a synthetic "(Unmapped) Suburb Name" town until the agency adds them.

### 4.3 New table — `property_type_options`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | |
| `name` | varchar(100) | NO | "House", "Townhouse", "Apartment", "Smallholding", etc. |
| `slug` | varchar(120) | NO | |
| `display_order` | unsignedSmallInteger | NO | 0 |
| `is_active` | boolean | NO | default true — soft toggle for "deprecated but not removed" |
| `deleted_at` | timestamp | YES | SoftDeletes |
| timestamps | | | |

Indexes: `(agency_id, slug)` UNIQUE, `(agency_id, display_order, is_active)`.

The wishlist `property_types` json continues to store free strings. Configuration service maps each string back to a property_type_option via slug normalisation.

### 4.4 New table — `bedroom_segments`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | |
| `name` | varchar(50) | NO | "1 bed", "2 bed", "Open plan / studio" |
| `beds_min` | unsignedTinyInteger | NO | Inclusive lower bound |
| `beds_max` | unsignedTinyInteger | YES | NULL = no upper bound (the 5+ case) |
| `display_order` | unsignedSmallInteger | NO | 0 |
| `deleted_at` | timestamp | YES | |
| timestamps | | | |

Indexes: `(agency_id, display_order)`, `(agency_id, beds_min, beds_max)` for fast bucket-finding.

A buyer with `beds_min=3` falls into whichever segment covers beds 3 (or the next-most-applicable segment if the agency's segments don't include 3 exactly).

### 4.5 New table — `price_bands`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint PK | NO | |
| `agency_id` | bigint unsigned | NO | |
| `listing_type` | enum('sale','rental') | NO | Separate bands per listing type |
| `name` | varchar(100) | NO | "Entry", "Mid", "Upper-mid", "Premium" |
| `price_min` | unsignedBigInteger | NO | Cents, inclusive — 0 for the entry band |
| `price_max` | unsignedBigInteger | YES | Cents, exclusive — NULL = no upper bound (Premium) |
| `display_order` | unsignedSmallInteger | NO | 0 |
| `deleted_at` | timestamp | YES | |
| timestamps | | | |

Indexes: `(agency_id, listing_type, display_order)`, `(agency_id, listing_type, price_min)` for fast bucket lookup.

A buyer wishlist with `price_min=1500000, price_max=2200000` and `listing_type='sale'` matches whichever band(s) overlap the range — typically one. Aggregation uses `(price_max - price_min) / 2` as the buyer's "implied target" for assignment to a single band.

### 4.6 No changes to existing tables

`contacts`, `contact_matches`, `properties`, `prospecting_listings`, `prospecting_buyer_matches`, `property_buyer_matches` — all unchanged.

---

## Section 5 — Model Changes

Five new Eloquent models in `app/Models/Prospecting/`:

| Model | Notes |
|---|---|
| `Town` | BelongsToAgency, SoftDeletes, hasMany Suburbs |
| `TownSuburb` | BelongsToAgency, SoftDeletes, belongsTo Town |
| `PropertyTypeOption` | BelongsToAgency, SoftDeletes |
| `BedroomSegment` | BelongsToAgency, SoftDeletes |
| `PriceBand` | BelongsToAgency, SoftDeletes |

Each model has scopes for `active()`, `forListingType($type)` (where relevant), and `ordered()` (by display_order).

---

## Section 6 — UI

### 6.1 Settings → Prospecting Setup page

A single page with four tabbed sections (or a vertical sidebar — match existing settings-page convention; confirm during pre-flight).

**Tab 1 — Towns & Suburbs**

- List of towns with display order, drag-to-reorder.
- Each town expands to show its suburbs.
- Per town: edit name, add suburb (inline), remove suburb (soft-delete).
- "+ Add Town" button.
- "Build from web" helper button — opens a search-based modal that takes an area name ("KZN South Coast"), runs a curated web search, and suggests town/suburb mappings. Agency reviews and ticks-to-accept. (V1 implementation: a simple manual-paste-from-web fallback if real web integration is complex; the helper text can be "Suggested towns for KZN South Coast: Margate, Shelly Beach, Port Shepstone...").
- Save-on-blur for inline edits.

**Tab 2 — Property Types**

- Drag-to-reorder list.
- Per row: name, active toggle (deprecate without delete), edit, archive.
- "+ Add Property Type".

**Tab 3 — Bedroom Segments**

- Drag-to-reorder list.
- Per row: name, beds_min, beds_max (blank = no upper), edit, archive.
- "+ Add Segment".
- Validation: segments must not overlap. Each bed count maps to exactly one segment (or zero — show warning if uncovered).

**Tab 4 — Price Bands**

- Two sub-sections: Sale and Rental.
- Each is a drag-to-reorder list of bands.
- Per band: name, price_min, price_max (blank = no upper), edit, archive.
- Validation: bands within a listing_type must not overlap.
- Currency formatting (ZAR) on display, raw rand on edit.

### 6.2 Drawer entry from Prospecting tab

⚙ icon top-right of `/corex/prospecting` opens the same component as a slide-in drawer.

### 6.3 Navigation

- Settings sidebar gets a new entry: "Prospecting Setup".
- Permission: `prospecting_setup.manage` — default granted to agency admins. Agents read-only.

### 6.4 Read-only access from elsewhere

Agents see the configuration in read-only form when they're using prospecting (e.g. the segment grid labels come from the configuration). They cannot edit unless they have the manage permission.

---

## Section 7 — Configuration Service

`app/Services/Prospecting/ProspectingConfigurationService.php`

Public API per Section S8. Implementation:
- All reads cached per agency for the request lifecycle (no per-query DB hits).
- Cache invalidation on any configuration write — fired via domain events.
- `classifyPrice($agencyId, $listingType, $price)` finds the band containing $price within the agency's bands for the listing type. Returns null if no band covers it (data integrity issue — log a warning to flag).
- `bedroomBucketFor($agencyId, $beds)` finds the segment. Same null-fallback.
- `suburbToTown($agencyId, $suburb)` does a normalised lookup; returns null for unmapped. Caller handles synthetic "(Unmapped)" display.

This service becomes the SINGLE source of truth for "what segments exist" across the entire prospecting intelligence layer, WhatsApp composer, and any future feature that needs to bucket buyer or property data.

---

## Section 8 — Events Emitted

Per spec E2 conventions in `corex-domain-events-spec.md`:

- `Prospecting\TownConfigured(town, action: 'created'|'updated'|'archived', actorUserId, agencyId)`
- `Prospecting\SuburbMappingChanged(suburb, town, action, actorUserId, agencyId)`
- `Prospecting\PropertyTypeConfigured(propertyType, action, actorUserId, agencyId)`
- `Prospecting\BedroomSegmentConfigured(segment, action, actorUserId, agencyId)`
- `Prospecting\PriceBandConfigured(band, action, actorUserId, agencyId)`

Each extends `AbstractDomainEvent`. Each fires from the relevant controller after the database write. Wildcard audit listener writes to `domain_event_log` automatically.

Also subscribes to these events:
- `Prospecting\InvalidateConfigurationCache` — clears the per-agency configuration cache so the next read picks up the change.

---

## Section 9 — Build Prompt Sequence

| # | Prompt | Summary | Success criteria |
|---|---|---|---|
| 01 | **Schema + seeders** | 5 migrations: towns, town_suburbs, property_type_options, bedroom_segments, price_bands. Seeder for HFC's KZN South Coast towns + default property types + bedroom segments + price bands. | All 5 migrations + rollback proven; HFC seed produces ≥3 towns with ≥3 suburbs each, 7 property types, 5 bedroom segments, 4+4 price bands |
| 02 | **Models + service** | 5 Eloquent models with BelongsToAgency + SoftDeletes. `ProspectingConfigurationService` with full public API. Per-request cache layer. | Tinker tests every public method; agency-scoped data isolation verified |
| 03 | **Event classes + listeners** | 5 event classes extending AbstractDomainEvent. Cache-invalidation listener. | Firing each event writes to domain_event_log; cache clears on write |
| 04 | **Settings → Prospecting Setup UI** | Tabbed page with all 4 sections, drag-to-reorder, inline edit, soft-delete archive. Permission gate. | Browser smoke: HFC admin creates a custom town, renames a price band, archives a property type — all persist + emit events |
| 05 | **Prospecting tab ⚙ drawer** | Same component as a slide-in drawer accessible from the prospecting tab. | Drawer opens, edits propagate, prospecting summary block re-renders with new config (placeholder OK — full reactivity is Prompt 06 of the prospecting intelligence build) |
| 06 | **End-to-end smoke** | Investigation-only audit: every event fires correctly, cache invalidates, multi-tenancy holds across two test agencies. | Single audit doc in `.ai/audits/`; PASS verdict; recommendations for the prospecting intelligence layer integration |

Each prompt: standard reads + pre-flight + changes + verification + dev-check.

---

## Section 10 — Rollback Plan

Per-prompt rollback via `migrate:rollback` and `git revert`. No data destruction risk — all writes go to new tables, all reads from existing tables. Existing prospecting / wishlist / contact flows are unaffected throughout.

---

## Section 11 — Acceptance Criteria

1. Five new tables created with full schema per Section 4. All AgencyScope-enforced.
2. HFC seeded with KZN South Coast towns (Margate town: Margate + Uvongo + Manaba + Ramsgate; Shelly Beach town: Shelly Beach + St Michaels + Southbroom; Port Shepstone town: Port Shepstone + Oslo Beach + Umtentweni; plus standalone seeded towns for Hibberdene, Pumula, Munster, Trafalgar — agency edits the rest).
3. HFC seeded with 7 property types, 5 bedroom segments, 4 sale price bands, 4 rental price bands.
4. `ProspectingConfigurationService` exposes all 7 public methods; per-request cache works; invalidation on writes works.
5. Settings → Prospecting Setup page renders, allows full CRUD on all four dimensions.
6. ⚙ drawer on Prospecting tab works.
7. All 5 events emit on writes; audit log captures them with payload snapshots.
8. Multi-tenancy proven: two test agencies each manage their own config; neither sees the other's data.
9. Soft-delete restores work (archived items recoverable from admin).
10. Edit-history defensibility: changing "Premium" to "Luxury" doesn't break a domain_event_log row that previously stated "we have 4 Premium-band buyers".
11. Permission gate enforced: only `prospecting_setup.manage` users can write; agents read-only.
12. dev-check.ps1 PASS, all migrations cleanly rollback-and-reapply.

---

## Section 12 — Open Questions

1. **Web-search "Build from web" helper for towns.** V1 implementation: simple curated suggestion based on agency region OR manual paste-from-web. Full automation is a follow-up.
2. **Bedroom segment overlap handling.** If an agency creates overlapping segments (1-3 beds, 2-4 beds), how does the service classify a buyer wanting 3 beds? Recommendation: pick the segment with the smallest range (most specific); flag overlapping configurations to the agency at edit time.
3. **Price band for "any price" buyers.** If a buyer's wishlist has `price_min=null, price_max=null`, which band do they fall into? Recommendation: aggregate under a synthetic "Unspecified" band — visible in the summary block but called out as such.
4. **Region rollup.** Beyond towns. Out of v1 scope but flagged for v2.

---

**End of spec.**
