# Buyer Wishlist & Prospecting Tab Investigation
> Audit Date: 2026-05-13
> Author: Claude (investigation-only, no code changes)
> Purpose: Feed into upcoming `.ai/specs/prospecting-intelligence-spec.md`

---

## 1. Mandatory Reads Confirmation

- **CLAUDE.md** -- Read in full. Session start protocol followed.
- **STANDARDS.md** -- Read in full. UX rules, execution rules, done criteria confirmed.
- **Relevant specs read:**
  - `.ai/specs/SPEC_Portal_Scraping_Prospecting.md` -- Chrome extension + prospecting module
  - `.ai/specs/matches.md` -- Core Matches module spec (approved 2026-04-28)
  - No spec named "prospecting-intelligence" exists yet -- this audit is the pre-spec investigation.

---

## 2. Section A -- Buyer Wishlist Data Model

### A1. Buyer Wishlist / Preference Tables

The system has **two parallel buyer criteria systems** plus extensive supporting tables:

#### PRIMARY: `contact_matches` (Agent-Created Wishlists)

**Migration files:**
- `database/migrations/2026_03_07_100001_create_contact_matches_table.php` (base)
- `database/migrations/2026_03_07_100002_add_share_token_to_contact_matches_table.php`
- `database/migrations/2026_03_07_100003_add_hidden_property_ids_to_contact_matches_table.php`
- `database/migrations/2026_03_07_100004_add_property_view_counts_to_contact_matches_table.php`
- `database/migrations/2026_03_17_085414_*.php` (adds SoftDeletes)
- `database/migrations/2026_04_28_100001_extend_contact_matches.php` (major extension)
- `database/migrations/2026_04_28_120000_*.php` (adds share_slug)

**Full column list:**

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | bigint PK | no | auto | |
| agency_id | bigint FK->agencies | yes | null | Added 2026-04-28, multi-tenancy |
| contact_id | bigint FK->contacts | no | | cascadeOnDelete |
| created_by_user_id | bigint FK->users | yes | | nullOnDelete |
| name | string | yes | null | Agent-facing label |
| share_token | string(48) unique | no | | Public share link |
| share_slug | string unique | no | | URL-safe slug |
| status | string(20) | no | 'active' | Values: active, paused, fulfilled, expired |
| listing_type | string | no | 'sale' | 'sale' or 'rental' |
| category | string | yes | null | |
| property_type | string | yes | null | |
| price_min | unsignedInteger | yes | null | |
| price_max | unsignedInteger | yes | null | |
| beds_min | unsignedTinyInteger | yes | null | |
| baths_min | unsignedTinyInteger | yes | null | |
| garages_min | unsignedTinyInteger | yes | null | |
| parking_min | unsignedTinyInteger | yes | null | |
| floor_size_min | unsignedInteger | yes | null | |
| floor_size_max | unsignedInteger | yes | null | |
| erf_size_min | unsignedInteger | yes | null | |
| erf_size_max | unsignedInteger | yes | null | |
| suburb | string | yes | null | Legacy single suburb |
| suburbs | json | yes | null | Multi-suburb array |
| must_have_features | json | yes | null | Hard filter |
| nice_to_have_features | json | yes | null | Bonus scoring |
| notes | text | yes | null | |
| hidden_property_ids | json | yes | null | Buyer exclusions |
| property_view_counts | json | yes | null | Per-property view tracking |
| last_engaged_at | timestamp | yes | null | Last shared page interaction |
| auto_archive_at | date | yes | null | Scheduled expiry |
| deleted_at | timestamp | yes | null | SoftDeletes |
| created_at, updated_at | timestamps | | | |

**Indexes:**
- `(agency_id, status)` -- cm_agency_status_idx
- `(contact_id, status)` -- cm_contact_status_idx
- `(price_min, price_max)` -- cm_price_idx
- `(listing_type)` -- cm_listing_type_idx

**Foreign keys:**
- `contact_id` -> `contacts.id` (cascadeOnDelete)
- `created_by_user_id` -> `users.id` (nullOnDelete)
- `agency_id` -> `agencies.id` (nullOnDelete)

#### SECONDARY: `buyer_preferences` (Buyer-Side Preferences)

**Migration:** `database/migrations/2026_05_06_000005_create_buyer_preferences_and_risk_scores.php`

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | bigint PK | no | |
| contact_id | bigint FK UNIQUE | no | One-to-one with contact |
| budget_min | decimal(14,2) | yes | |
| budget_max | decimal(14,2) | yes | |
| bedrooms_min | unsignedSmallInteger | yes | |
| bedrooms_max | unsignedSmallInteger | yes | |
| must_have_features | json | yes | |
| deal_breakers | json | yes | |
| preferred_areas | json | yes | |
| preferred_property_types | json | yes | |
| preapproval_amount | decimal(14,2) | yes | Added by 000017 |
| preapproval_expires_at | date | yes | Added by 000017 |
| preapproval_institution | string(100) | yes | Added by 000017 |
| updated_by_user_id | bigint FK | yes | |
| created_at, updated_at | timestamps | | |

**No explicit indexes** beyond the UNIQUE on contact_id.

#### Supporting Tables

| Table | Purpose | Migration |
|-------|---------|-----------|
| `contact_match_feedback` | Buyer reactions (interested/not_interested/saved) per property | 2026_04_28_100002 |
| `contact_match_notifications` | Agent notification records per match+property | 2026_04_28_100003 |
| `property_buyer_matches` | Cached scored matches (property->buyer, 0-100 score + tier) | 2026_05_06_000008 |
| `prospecting_buyer_matches` | Cached scored matches (prospecting listing->buyer) | 2026_05_06_000017 |
| `buyer_activity_log` | Timeline of buyer interactions | 2026_05_05_000020 |
| `buyer_state_transitions` | Lifecycle state changes (new->warm->cold->lost) | 2026_05_05_000020 |
| `buyer_property_views` | Denormalized viewing history per property | 2026_05_05_000020 |
| `buyer_lost_risk_scores` | Risk scoring (0-100) with factor breakdown | 2026_05_06_000005 |
| `buyer_lost_records` | Lost buyer tracking with recovery workflow | 2026_05_06_000010 |

---

### A2. Eloquent Models

#### `ContactMatch` -- Primary Wishlist Model
**File:** `app/Models/ContactMatch.php`

**Traits:** `SoftDeletes`, `BelongsToAgency`

**Status constants:**
- `STATUS_ACTIVE = 'active'`
- `STATUS_PAUSED = 'paused'`
- `STATUS_FULFILLED = 'fulfilled'`
- `STATUS_EXPIRED = 'expired'`

**Relationships:**
- `contact()` -- BelongsTo Contact
- `createdBy()` -- BelongsTo User (via created_by_user_id)
- `feedback()` -- HasMany ContactMatchFeedback
- `notifications()` -- HasMany ContactMatchNotification

**Local scopes:**
- `scopeActive(Builder $q)` -- `$q->where('status', 'active')`
- `scopeForListingType(Builder $q, ?string $type)` -- filters by listing_type if provided

**Global scopes:** AgencyScope via BelongsToAgency trait

**Key methods:**
- `suburbList()` -- merges legacy `suburb` + json `suburbs` array
- `priceRangeLabel()` -- formats price range for display
- `isPropertyHidden()` / `toggleHiddenProperty()` -- buyer property exclusion
- `incrementPropertyView()` -- tracks property views + updates last_engaged_at

#### Other Models

| Model | File | Traits | Key Relationships |
|-------|------|--------|-------------------|
| `ContactMatchFeedback` | `app/Models/ContactMatchFeedback.php` | none | match()->belongsTo, property()->belongsTo |
| `ContactMatchNotification` | `app/Models/ContactMatchNotification.php` | none (no timestamps) | match(), property(), notifiedUser() |
| `BuyerActivityLog` | `app/Models/BuyerActivityLog.php` | none (no timestamps) | contact(), property() |
| `BuyerPropertyView` | `app/Models/BuyerPropertyView.php` | none | contact(), property() |
| `BuyerStateTransition` | `app/Models/BuyerStateTransition.php` | none (no timestamps) | contact() |

---

### A3. Wishlist Data Quality Audit

#### `contact_matches` Table (Agent-Created Wishlists)

| Metric | Count |
|--------|-------|
| Total rows (incl soft-deleted) | 2 |
| Active (not soft-deleted) | 2 |
| Status = 'active' | 2 |
| With suburb (legacy field) | 0 |
| With suburbs (json array) | 0 |
| With price_min AND price_max | 1 |
| With property_type | 0 |
| With beds_min | 0 |
| Created last 30 days | 1 |
| Created last 90 days | 2 |
| Created last 180 days | 2 |
| Updated last 30 days | 2 |
| Updated last 90 days | 2 |
| Updated last 180 days | 2 |

**Detail of both rows:**
- ID 1: contact_id=2, sale, no price range, no suburb, no property type, no beds (created 2026-03-09)
- ID 2: contact_id=24, sale, R750k-R1.5M, no suburb, no property type, no beds (created 2026-05-06)

**Listing type breakdown:** All 2 rows are 'sale'

> **Assessment:** The `contact_matches` table is nearly empty with very sparse data. Not usable for aggregation intelligence without significant data entry by agents.

#### `buyer_preferences` Table (Buyer-Side Preferences)

| Metric | Count |
|--------|-------|
| Total rows | 31 |
| With budget_min AND budget_max | 31 (100%) |
| With preferred_areas | 31 (100%) |
| With preferred_property_types | 30 (97%) |

**Sample data:**
- Contact 1: R1.5M-R4M, areas: Margate/Uvongo/Southbroom
- Contact 11: R1.5M-R2.5M, areas: Margate/Uvongo
- Contact 2: R3M-R5M, areas: Southbroom/Shelly Beach
- Contact 3: R800k-R1.5M, areas: Margate, types: Apartment
- Contact 4: R5M-R8M, areas: Southbroom

> **Assessment:** `buyer_preferences` has better data quality (100% fill on budget and areas). This is the more reliable source for aggregation.

#### Contacts with `is_buyer = true`

| Metric | Count |
|--------|-------|
| Total buyers | 43 |
| buyer_state = warm | 22 |
| buyer_state = cold | 8 |
| buyer_state = lost | 9 |
| buyer_state = new | 4 |

#### `prospecting_buyer_matches` (Computed Matches)

| Metric | Value |
|--------|-------|
| Total matches | 30,121 |
| Tier: perfect | 1,148 (3.8%) |
| Tier: strong | 9,608 (31.9%) |
| Tier: approximate | 19,365 (64.3%) |
| Dismissed | 0 |
| Average score | 64.9 |

#### `property_buyer_matches`

| Metric | Value |
|--------|-------|
| Total matches | 3 |

> **Assessment:** Prospecting buyer matches are well-populated (30k+), driven by automated scoring from `PropertyMatchScoringService`. Internal property matches are nearly empty (3 rows).

#### Prospecting Listings

| Metric | Value |
|--------|-------|
| Total listings | 983 |
| Active | 983 (100%) |
| With matched_property_id (in-stock) | 0 |

**Top 10 suburbs by listing count:**

| Suburb | Count |
|--------|-------|
| Margate | 289 |
| Uvongo | 186 |
| Ramsgate | 128 |
| Shelly Beach | 101 |
| Margate Beach | 66 |
| Manaba Beach | 58 |
| Uvongo Beach | 51 |
| St Michaels On Sea | 45 |
| Lawrence Rocks | 32 |
| Beacon Rocks | 15 |

**Property type breakdown:**

| Type | Count |
|------|-------|
| Apartment | 553 |
| House | 282 |
| Vacant land | 88 |
| Commercial | 42 |
| Townhouse | 10 |
| Farm | 4 |
| Industrial | 2 |
| Land | 2 |

---

### A4. Active Buyer Definition

**On Contact model** (`app/Models/Contact.php`):
- `is_buyer` (boolean, default false) -- flag indicating contact is in buyer pipeline
- `buyer_state` (string, nullable) -- lifecycle values: `new`, `warm`, `cold`, `lost`
- `last_activity_at` (timestamp) -- last engagement
- `buyer_pipeline_entered_at` (timestamp) -- when entered pipeline
- Index: `(agency_id, is_buyer, buyer_state)` -- contacts_buyer_pipeline_idx
- Scope: `scopeBuyers($query)` -- `where('is_buyer', true)`

**On ContactMatch model:**
- `scopeActive()` -- `where('status', 'active')` -- refers to match status, not buyer activity

**On AgencyContactSettings** (`app/Models/AgencyContactSettings.php`):
- `buyer_warm_days` = 14 (default)
- `buyer_cold_days` = 30 (default)
- `buyer_lost_days` = 60 (default)

**State transition logic:** Managed by `BuyerStateService` (referenced but not yet examined in detail). States auto-transition based on inactivity thresholds defined in AgencyContactSettings.

> **Assessment:** "Active buyer" = `is_buyer = true AND buyer_state IN ('new', 'warm')`. There is NO single `scopeActiveBuyers()` scope on the Contact model -- queries must combine `is_buyer` + `buyer_state` manually. This is a gap.

---

## 3. Section B -- Matching Logic

### B1. Existing Buyer-to-Property Matching Systems

**Three distinct matching engines exist:**

#### Engine 1: `MatchingService` (Agent-Facing Core Matches)
**File:** `app/Services/Matching/MatchingService.php`

**Purpose:** Real-time scoring of properties against agent-created `ContactMatch` criteria.

**Key methods:**
- `candidatesForProperty($property)` -- finds all ContactMatches a property could satisfy
- `matchesForProperty($property)` -- returns sorted matches with scoring
- `propertiesForMatch($match)` -- reverse: all properties for a buyer's criteria
- `score($property, $match)` -- 0-100 scoring

**Scoring weights:**
- Price band: 25 points (linear decay +/-50% outside range)
- Suburb match: 20 points (exact then partial substring)
- Bedrooms: 8, Bathrooms: 7, Garages: 5
- Floor/erf size: 5 each
- Category/property_type: 5 each
- Nice-to-have features: 15 (prorated)
- Must-haves: 0 if ANY missing
- Min threshold: 40 points (configurable via PerformanceSetting)

**Query type:** Real-time SQL with hard filters, then PHP scoring. NOT cached.

**Called from:**
- `CoreX/ContactMatchController` (agent UI)
- `SharedMatchController` (public share links)
- `Api/MobileCoreMatchController` (mobile API)

#### Engine 2: `ClientMatchResolver` (Mobile Client Portal)
**File:** `app/Services/Matching/ClientMatchResolver.php`

**Purpose:** Strict deterministic filter for buyer-side mobile portal.

All criteria are hard constraints (no partial scoring). Excludes sold/withdrawn/draft/archived/pending/rented.

#### Engine 3: `PropertyMatchScoringService` (Buyer Preferences + Prospecting)
**File:** `app/Services/PropertyMatchScoringService.php`

**Purpose:** Matches properties against `buyer_preferences` table. Results cached in `property_buyer_matches` and `prospecting_buyer_matches`.

**Scoring weights:**
- Price: 25 points (within budget: full; +/-10%: 18; +/-20%: 8; beyond: 0)
- Area/suburb: 20 points (exact: 20; nearby prefix: 12; different: 5)
- Property type: 10 points
- Must-have features: 15 points
- Deal-breakers: 10 points (0 if present)
- Bedrooms: 20 points (placeholder, not yet implemented)

**Tier classification:** perfect (90-100), strong (70-89), approximate (50-69)

**Key methods:**
- `recomputeForBuyer($contactId)` -- cache all property matches for one buyer
- `recomputeProspectingMatches($listingId)` -- match prospecting listing vs all buyers
- `getBuyerDemandForProperty($propertyId, $agencyId)` -- demand summary
- `getProspectingDemand($listingId)` -- demand analysis

#### Engine 4: `ProspectingStockMatchService` (Address Matching)
**File:** `app/Services/Prospecting/ProspectingStockMatchService.php`

**Purpose:** Matches prospecting captures to existing agency properties by normalized address (exact + fuzzy two-pass).

### B2. Matcher Integration Summary

| Engine | Input Direction | Criteria Source | Storage | Real-time? |
|--------|----------------|-----------------|---------|------------|
| MatchingService | Property -> Buyers AND Buyer -> Properties | contact_matches | None (live query) | Yes |
| ClientMatchResolver | Buyer -> Properties | contact_matches | None (live query) | Yes |
| PropertyMatchScoringService | Property -> Buyers AND Prospecting -> Buyers | buyer_preferences | property_buyer_matches, prospecting_buyer_matches | No (cached) |
| ProspectingStockMatchService | Prospect -> Internal Property | Normalized address | prospecting_listings.matched_property_id | No (cached) |

**Triggers:**
- PropertyObserver: dispatches `MatchPropertyJob` on create/update
- ProspectingListingObserver: synchronous recompute on create/update
- CLI commands: `prospecting:recompute-matches`, `matches:recompute`, `prospecting:match-stock`

---

## 4. Section C -- Prospecting Tab Current State

### C1. Route & File Locations

**Routes** (in `routes/web.php`, lines 2353-2360):
| URI | Name | Method | Controller |
|-----|------|--------|------------|
| GET /prospecting | prospecting.index | index | ProspectingController |
| GET /prospecting/{listing} | prospecting.show | show | ProspectingController |
| POST /prospecting/{listing}/claim | prospecting.claim | claim | ProspectingController |
| POST /prospecting/{listing}/feedback | prospecting.feedback | feedback | ProspectingController |
| POST /prospecting/{listing}/release | prospecting.release | release | ProspectingController |
| GET /prospecting/thumbnail/{listing} | prospecting.thumbnail | thumbnail | ProspectingController |

**Middleware:** `auth`, `permission:access_prospecting`

**Controller:** `app/Http/Controllers/ProspectingController.php`
**View:** `resources/views/prospecting/index.blade.php`

**API routes** (`routes/api.php`, lines 134-135):
| Endpoint | Purpose |
|----------|---------|
| POST /api/prospecting/import | Chrome extension uploads listings |
| GET /api/prospecting/check-search | Duplicate prevention |

**Note:** `prospecting.show` attempts to render `prospecting.show` blade -- this view may not exist (show method primarily returns JSON for AJAX calls).

### C2. Prospecting Tab Query

**Data spine:** `prospecting_listings` table

**Base query:**
```php
$query = ProspectingListing::where('agency_id', $agencyId)
    ->with('activeClaim.user');
```

**Filters exposed in UI:**

| Filter | Parameter | Logic |
|--------|-----------|-------|
| Portal Source | portal_source | exact match (p24/pp/all) |
| Suburb | suburb | exact match |
| Property Type | property_type | exact match |
| Price Min/Max | price_min, price_max | range filter |
| Bedrooms Min | bedrooms_min | >= filter |
| Agent Name | agent_name | LIKE %search% |
| Agency Name | agency_name | LIKE %search% |
| Active/Removed | is_active | boolean |
| Captured By | captured_by | exact user_id |
| Date From/To | date_from, date_to | range on first_seen_at |
| Free Text Search | search | OR across address/suburb/agent/agency |
| Stock Match | stock_filter | in_stock / not_in_stock |
| Claim Filter | claim_filter | my_claims / unclaimed |

**Columns rendered per row:**

| Column | Source |
|--------|--------|
| Photo | thumbnail_path (50x38px) |
| Address | address (linked to portal URL, truncated 40 chars, "IN STOCK" badge if matched) |
| Suburb | suburb |
| Price | price (formatted R, with price change indicator if applicable) |
| Buyer Matches | buyer_match_count (color-coded badge: red >=5, amber >=2, green <2) |
| Bed/Bath/Gar | bedrooms/bathrooms/garages |
| Type | property_type |
| Agent | agent_name (truncated 20) |
| Agency | agency_name (truncated 20) |
| Portal | portal_source badge (P24/PP) |
| Ref | portal_ref (clickable) |
| Claim | Claim button or status + countdown |
| First Seen | first_seen_at |
| Status | is_active badge |

**Pagination:** 50 per page (manual LengthAwarePaginator after in-memory grouping by property_group_id)

**Summary stats displayed at top:**

| Stat | Query |
|------|-------|
| Total Active Listings | count where is_active=true |
| Average Asking Price | avg(price) on base query |
| New This Week | first_seen_at >= 7 days ago |
| Price Reductions | price_changed_at >= 1 week ago |
| Cross-Listed | property_group_id with >1 distinct portal_source |
| Buyer Matched | distinct prospecting_listing_id in prospecting_buyer_matches (score>=50, not dismissed) |
| In Our Stock | matched_property_id IS NOT NULL |
| My Claims | user's active claims count |
| Total Claimed | all active claims in agency |
| Expiring Soon | claims pending feedback > 24h |

### C3. User-Facing Actions Per Row

| Action | Trigger | Destination |
|--------|---------|-------------|
| View Portal | Click address/portal badge | External portal URL (new tab) |
| View In Stock Property | Click "IN STOCK" badge | `/corex/properties/{id}` |
| Claim Listing | "Claim" button (unclaimed) | POST creates ProspectingClaim |
| Update Claim | "Update" button (own claim) | Opens feedback modal |
| Release Claim | "Release Claim" in modal | POST releases claim |
| View Thumbnail | Hover/click image | GET thumbnail route |

**Feedback modal statuses:** contacted, meeting_set, listing, not_interested (releases), lost (releases)

### C4. Multi-Tenancy Check

**Mechanism:** Explicit `where('agency_id', $agencyId)` -- NOT via AgencyScope global scope.

```php
$user = $request->user();
$agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;
$query = ProspectingListing::where('agency_id', $agencyId);
```

All stats queries also scoped by `agency_id`. Filter dropdown values (suburbs, types, users) only drawn from listings in the user's agency.

**Note:** The `ProspectingListing` model does NOT use the `BelongsToAgency` trait -- scoping is controller-level, not model-level. This is inconsistent with the rest of the codebase where `BelongsToAgency` is the standard pattern.

---

## 5. Section D -- Reusable Aggregation Patterns

### D1. Existing Aggregation Patterns

| Pattern | File | Description |
|---------|------|-------------|
| KPI Card Component | `resources/views/components/corex-kpi-card.blade.php` | Reusable Blade component: title, value, trend%, trendUp direction |
| Dashboard Footer Strip | `resources/views/command-center/dashboard.blade.php` (lines 433-485) | KPI cards with progress bars |
| Branch Performance Cards | `resources/views/command-center/reporting/branch.blade.php` (lines 29-54) | Grid of metric cards (agents, buyers, listings, events, lost) |
| Branch Leaderboard | Same file (lines 56-83) | Sortable table with conditional color coding |
| ReportingService | `app/Services/ReportingService.php` | Agent/branch metrics, period comparison, conversion funnel |
| BuyerIntelligenceService | `app/Services/BuyerIntelligenceService.php` | Preference patterns (countBy suburbs/concerns), price ranges |
| PropertyIntelligenceService | `app/Services/PropertyIntelligenceService.php` | Feedback rollup, viewing counts, outcome distribution |
| P24MarketDataService | `app/Services/P24/P24MarketDataService.php` | Suburb stats, price distribution, market summary |

### D2. Reusable Helpers

#### Price Band Bucketing
**File:** `app/Services/P24/P24MarketDataService.php` (lines 107-127)
Pre-defined brackets: Under R1M, R1-1.5M, R1.5-2M, R2-3M, R3-5M, R5M+

**File:** `app/Services/Presentations/PriceBandService.php`
Optimal band finder with aggressive/balanced/defensive strategies.

#### Suburb Normalisation
**File:** `app/Services/MarketAnalytics/Helpers/SuburbNormalizer.php`
- `normalize()` -- lowercase, trim, collapse whitespace
- `slug()` -- URL-safe version

**File:** `app/Support/SuburbMapper.php`
- Maps suburbs to parent towns (config-driven from `south_coast_areas.php`)
- `townLabel()` -- "Greater {town} Area"
- `expandToTownArea()` -- groups suburb into sibling suburbs
- `suburbsInTown()` -- reverse lookup

#### Recency Calculations
Standard pattern across services:
```php
$since = now()->subDays($days); // 7/30/90
$query->where('date_column', '>=', $since)->count();
```
Period comparison: `$prior = now()->subDays($days * 2)` for trend calculation.

---

## 6. Gaps & Recommendations

### Blockers

| # | Gap | Severity | Recommendation |
|---|-----|----------|----------------|
| 1 | **`contact_matches` has only 2 rows** -- nearly empty. Cannot drive aggregation. | Blocker | Use `buyer_preferences` (31 rows, 100% budget/area fill) as primary data source for buyer demand intelligence. Or build unified query spanning both tables. |
| 2 | **No `scopeActiveBuyers()` on Contact model** -- buyer "activeness" requires combining `is_buyer + buyer_state` manually | Blocker | Add `scopeActiveBuyers()` that combines `is_buyer=true AND buyer_state IN ('new','warm')` |
| 3 | **`ProspectingListing` model lacks `BelongsToAgency` trait** -- inconsistent with codebase standard, agency scoping is controller-only | Blocker | Add `BelongsToAgency` trait to `ProspectingListing` model and remove manual `where('agency_id')` from controller |

### Important

| # | Gap | Severity | Recommendation |
|---|-----|----------|----------------|
| 4 | **Two parallel buyer criteria systems** (`contact_matches` vs `buyer_preferences`) with different schemas | Important | Spec must define which is authoritative for prospecting intelligence. `buyer_preferences` has better data quality; `contact_matches` has richer criteria fields but no data. |
| 5 | **`property_buyer_matches` has only 3 rows** -- internal property matching barely used | Important | Ensure `recomputeForBuyer()` is triggered on property create/update via observer |
| 6 | **No "bedrooms" scoring implemented** in PropertyMatchScoringService (20-point placeholder) | Important | Implement bedroom scoring -- 20 points is a significant weight that's currently always defaulted |
| 7 | **`prospecting.show` blade view may not exist** -- show() method returns JSON but also attempts blade render | Important | Verify blade view exists or make show() JSON-only |
| 8 | **No indexes on `buyer_preferences` beyond UNIQUE contact_id** -- missing indexes on budget_min/budget_max, preferred_areas for aggregation queries | Important | Add composite indexes on (budget_min, budget_max) and ensure JSON column queries are efficient |
| 9 | **`matched_property_id` is 0 for all 983 listings** -- stock matching not running or not finding matches | Important | Investigate why `ProspectingStockMatchService` isn't matching. May need address normalisation improvements. |
| 10 | **Claim security: no explicit agency_id re-check** in claim/feedback/release methods | Important | Add defensive `abort_unless($listing->agency_id === $agencyId, 403)` in claim actions |

### Nice-to-Have

| # | Gap | Severity | Recommendation |
|---|-----|----------|----------------|
| 11 | **No suburb normalisation on prospecting data** -- "Margate" vs "Margate Beach" are treated as separate suburbs | Nice-to-have | Use SuburbMapper::expandToTownArea() to group nearby suburbs |
| 12 | **No price trend tracking** on buyer preferences -- can't show "buyer demand is shifting upward" | Nice-to-have | Add periodic snapshots or use buyer_activity_log timestamps for trend inference |
| 13 | **Pagination is in-memory** (all results fetched, grouped, then paginated) -- will degrade at scale | Nice-to-have | Move grouping to SQL level for better performance with large datasets |

---

## 7. Open Questions for Johan

1. **Which buyer criteria table is authoritative for prospecting intelligence?**
   - `contact_matches` (agent-created, 2 rows, rich criteria) vs `buyer_preferences` (system-managed, 31 rows, better data quality)
   - Or should the spec query BOTH and merge results?

2. **Should the prospecting summary/segment block show demand based on ALL buyers (43) or only "active" buyers (new+warm = 26)?**

3. **What price bands should the summary block use?**
   - P24MarketDataService uses: Under R1M, R1-1.5M, R1.5-2M, R2-3M, R3-5M, R5M+
   - Are these appropriate for the KZN South Coast market?

4. **Should the WhatsApp prospecting modal pull from `buyer_preferences` or `contact_matches`?**
   - `buyer_preferences` has better data but lacks features like `nice_to_have_features` and `suburbs` (uses `preferred_areas` instead)
   - `contact_matches` has richer criteria but almost no data

5. **Should the "buyer demand" count in prospecting include dismissed matches?**
   - Currently 0 dismissed matches exist, but the field is available

6. **The `ProspectingListing` model doesn't use `BelongsToAgency` -- is this intentional?**
   - Every other tenant-owned model uses this trait. Should we add it during this feature build?

7. **Stock matching shows 0 matches (`matched_property_id` is NULL for all 983 listings). Is this expected?**
   - Should we investigate/fix stock matching as a prerequisite?

8. **For suburb-based aggregation, should "Margate" and "Margate Beach" be treated as the same area?**
   - SuburbMapper already supports town-level grouping via `south_coast_areas.php` config

---

## Verification Steps

### 1. PHP Lint
No PHP files were edited (investigation-only). All files read during investigation parsed without syntax errors.

### 2. View Cache Clear
```
php artisan view:clear
INFO  Compiled views cleared successfully.
```

### 3. dev-check.ps1
```
=== DEV CHECK ===
Changed files: 1
1. Lint PHP files -- No PHP files changed
2. Clear caches -- Caches cleared
3. Route check -- skipped (no route/controller changes)
4. View check -- skipped (no blade changes)
5. Tests -- skipped (use -Full to run all 894 tests)
=== DEV CHECK OK ===
```
Pass. 0 new failures.

### 4. Tinker Commands Run

**Command 1: ContactMatch data quality**
```php
ContactMatch::withTrashed()->count();  // 2
ContactMatch::where('status','active')->count();  // 2
ContactMatch::whereNotNull('suburb')->where('suburb','!=','')->count();  // 0
ContactMatch::whereNotNull('price_min')->whereNotNull('price_max')->count();  // 1
ContactMatch::whereNotNull('property_type')->where('property_type','!=','')->count();  // 0
ContactMatch::whereNotNull('beds_min')->count();  // 0
```

**Command 2: Buyer preferences quality**
```php
DB::table('buyer_preferences')->count();  // 31
DB::table('buyer_preferences')->whereNotNull('budget_min')->whereNotNull('budget_max')->count();  // 31
DB::table('buyer_preferences')->whereNotNull('preferred_areas')->count();  // 31
DB::table('buyer_preferences')->whereNotNull('preferred_property_types')->count();  // 30
```

**Command 3: Contact buyer states**
```php
Contact::where('is_buyer', true)->count();  // 43
// warm: 22, cold: 8, lost: 9, new: 4
```

**Command 4: Prospecting data**
```php
DB::table('prospecting_listings')->count();  // 983
DB::table('prospecting_buyer_matches')->count();  // 30,121
// Tiers: perfect=1148, strong=9608, approximate=19365, avg score=64.9
```

**Command 5: Suburb/type distributions** (see Section A3 for full output)
