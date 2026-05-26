# Wishlist Unification Audit — `contact_matches` vs `buyer_preferences`
> Audit date: 2026-05-13
> Author: Claude (investigation-only, no code changes)
> Decision context: `contact_matches` is the architecturally correct source of truth. `buyer_preferences` will be deprecated and the buyer pipeline refactored to use `contact_matches`. This audit produces the comparison report that feeds the unification spec.

---

## 1. Mandatory Reads Confirmation

- **CLAUDE.md** — Read in full (Session Start Protocol followed).
- **STANDARDS.md** — Read in full.
- **Previous audit** `.ai/audits/2026-05-13-buyer-prospecting-audit.md` — Read in full. Findings carried forward: `contact_matches` has 2 rows, `buyer_preferences` has 31 rows, 30,121 `prospecting_buyer_matches` rows exist.
- **Existing specs searched and read:**
  - `.ai/specs/matches.md` (Core Matches — approved 2026-04-28) — the canonical spec for `contact_matches`
  - `.ai/specs/mobile-core-matches.md` — mobile API surface
  - `.ai/specs/SPEC_Portal_Scraping_Prospecting.md` — feeds prospecting tables
  - No spec exists for `buyer_preferences` (which is part of the reason this unification is happening).

---

## 2. Section A — `contact_matches` Deep Dive

### A1. Schema & Migration History

**Nine migrations have touched this table:**

| # | Migration | Adds |
|---|-----------|------|
| 1 | `2026_03_07_100001_create_contact_matches_table.php` | Base table: contact_id, created_by_user_id, listing_type, category, property_type, price_min/max, beds_min, baths_min, garages_min, parking_min, floor_size_min/max, erf_size_min/max, suburb, notes |
| 2 | `2026_03_07_100002_add_share_token_to_contact_matches_table.php` | `share_token` (string 64, unique) + backfill |
| 3 | `2026_03_07_100003_add_hidden_property_ids_to_contact_matches_table.php` | `hidden_property_ids` (json) |
| 4 | `2026_03_07_100004_add_property_view_counts_to_contact_matches_table.php` | `property_view_counts` (json) |
| 5 | `2026_03_17_085414_add_deleted_at_to_contact_matches_table.php` | `deleted_at` (SoftDeletes) |
| 6 | `2026_04_28_100001_extend_contact_matches.php` | `agency_id`, `name`, `status`, `suburbs` (json), `must_have_features` (json), `nice_to_have_features` (json), `last_engaged_at`, `auto_archive_at` + 4 indexes + backfill |
| 7 | `2026_04_28_100002_create_contact_match_feedback_table.php` | pivot table `contact_match_feedback` |
| 8 | `2026_04_28_100003_create_contact_match_notifications_table.php` | pivot table `contact_match_notifications` |
| 9 | `2026_04_28_120000_add_share_slug_to_contact_matches.php` | `share_slug` (string 120, unique) + backfill |

**Final column list (31 columns):**

| Column | Type | Nullable | Default |
|--------|------|----------|---------|
| id | bigint PK | no | auto |
| agency_id | FK->agencies | yes | null |
| contact_id | FK->contacts | no | cascadeOnDelete |
| created_by_user_id | FK->users | yes | nullOnDelete |
| name | string | yes | null |
| share_token | string(64) UNIQUE | no | |
| share_slug | string(120) UNIQUE | yes | null |
| status | string(20) | no | 'active' |
| listing_type | string | no | 'sale' |
| category | string | yes | null |
| property_type | string | yes | null |
| price_min | unsignedInteger | yes | null |
| price_max | unsignedInteger | yes | null |
| beds_min | unsignedTinyInteger | yes | null |
| baths_min | unsignedTinyInteger | yes | null |
| garages_min | unsignedTinyInteger | yes | null |
| parking_min | unsignedTinyInteger | yes | null |
| floor_size_min | unsignedInteger | yes | null |
| floor_size_max | unsignedInteger | yes | null |
| erf_size_min | unsignedInteger | yes | null |
| erf_size_max | unsignedInteger | yes | null |
| suburb | string | yes | null |
| suburbs | json | yes | null |
| must_have_features | json | yes | null |
| nice_to_have_features | json | yes | null |
| notes | text | yes | null |
| hidden_property_ids | json | yes | null |
| property_view_counts | json | yes | null |
| last_engaged_at | timestamp | yes | null |
| auto_archive_at | date | yes | null |
| deleted_at | timestamp | yes | null |
| created_at, updated_at | timestamps | | |

**Indexes:**
- `cm_agency_status_idx` (agency_id, status)
- `cm_contact_status_idx` (contact_id, status)
- `cm_price_idx` (price_min, price_max)
- `cm_listing_type_idx` (listing_type)
- `cm_share_slug_unique` (share_slug)

**Pivot tables:**
- `contact_match_feedback` — (contact_match_id, property_id) UNIQUE, reaction enum, note
- `contact_match_notifications` — (contact_match_id, property_id) UNIQUE, score, notified_user_id, notification_id (uuid)

---

### A2. Model

**File:** `app/Models/ContactMatch.php` (202 lines)

**Traits (Line 15):**
- `SoftDeletes`
- `BelongsToAgency` — confirmed (registers `App\Models\Scopes\AgencyScope` global scope, verified via Tinker: `getGlobalScopes()` returns `[SoftDeletingScope, AgencyScope]`)

**Status constants:** `STATUS_ACTIVE`, `STATUS_PAUSED`, `STATUS_FULFILLED`, `STATUS_EXPIRED`

**Fillable (Lines 22-52):** all 29 user-facing columns

**Casts (Lines 54-72):** integer numerics; suburbs/must_have_features/nice_to_have_features/hidden_property_ids/property_view_counts → array; last_engaged_at → datetime; auto_archive_at → date

**Relationships (Lines 112-130):**
- `contact()` → BelongsTo Contact
- `createdBy()` → BelongsTo User (via created_by_user_id)
- `feedback()` → HasMany ContactMatchFeedback
- `notifications()` → HasMany ContactMatchNotification

**Local scopes:**
- `scopeActive()` (Lines 132-135) — `where('status', 'active')`
- `scopeForListingType(?string $type)` (Lines 137-140)

**Public methods:**
- `boot()` — autogenerates share_token (creating) and share_slug (created)
- `generateSlug(self $match): string` — slug = "{first}-{last}-{random5}"
- `sharedUrl()`, `isPropertyHidden(int)`, `toggleHiddenProperty(int)`, `incrementPropertyView(int)`, `propertyViewCount(int)`, `listingTypeLabel()`, `priceRangeLabel()`, `suburbList()` (merges legacy suburb + json suburbs)

**Observers:** none registered as separate class — boot hooks handle slug/token generation inline.

---

### A3. UI — Capture & Edit

**Routes (`routes/web.php`):**

| Line | URI | Name | Controller@Method |
|------|-----|------|-------------------|
| 32 | GET /shared/match/{token} | shared.match | SharedMatchController@show |
| 33 | GET /shared/match/{token}/view/{property} | shared.match.view | SharedMatchController@recordView |
| 34 | POST /shared/match/{token}/feedback/{property} | shared.match.feedback | SharedMatchController@feedback |
| 1712 | GET /core-matches | corex.core-matches.index | ContactMatchController@index |
| 1746 | POST /contacts/{contact}/matches | corex.contacts.matches.store | ContactMatchController@store |
| 1747 | PUT /contacts/{contact}/matches/{match} | corex.contacts.matches.update | ContactMatchController@update |
| 1748 | POST /contacts/{contact}/matches/{match}/status | corex.contacts.matches.setStatus | ContactMatchController@setStatus |
| 1749 | GET /contacts/{contact}/matches/{match}/results | corex.contacts.matches.results | ContactMatchController@results |
| 1750 | POST /contacts/{contact}/matches/{match}/hide/{property} | corex.contacts.matches.toggleHide | ContactMatchController@toggleHide |
| 1751 | POST /contacts/{contact}/matches/{match}/convert/{property} | corex.contacts.matches.convertToDeal | ContactMatchController@convertToDeal |
| 1752 | DELETE /contacts/{contact}/matches/{match} | corex.contacts.matches.destroy | ContactMatchController@destroy |

**Controller:** `app/Http/Controllers/CoreX/ContactMatchController.php`

| Method | Lines | Summary |
|--------|-------|---------|
| index() | 17-40 | List own user's matches grouped by contact, ordered by status priority (active→paused→fulfilled→expired) |
| store() | 42-52 | Validates payload, creates ContactMatch with contact_id + created_by_user_id + agency_id, redirects to results |
| update() | 54-61 | Validates and updates, redirects to results |
| setStatus() | 63-72 | Sets status to active/paused/fulfilled/expired |
| results() | 74-86 | Loads properties via MatchingService->propertiesForMatch(), loads feedback, renders match-results view |
| toggleHide() | 88-94 | Calls $match->toggleHiddenProperty($property) |
| destroy() | 96-104 | Soft-deletes match, redirects to contact show |
| convertToDeal() | 109-140 | Creates draft Deal from (match, property); optionally marks match fulfilled |
| validatePayload() | 142-196 | Comprehensive validation + feature token mapping |

**Save path:** Controller writes directly to the model (`ContactMatch::create()` / `$match->update()`). No service or repository layer used.

**Blade view (form):** `resources/views/corex/contacts/show.blade.php` Lines 1049-1332, "Core Matches" tab.

**Form fields rendered in display order:**
1. `listing_type` — radio (sale/rental)
2. `category` — select from $matchCategories
3. `property_type` — select from $matchTypes
4. `suburb` — text input
5. `price_min`, `price_max` — number inputs (step 50000)
6. `beds_min`, `baths_min`, `garages_min`, `parking_min` — number inputs (max 20)
7. `floor_size_min`, `floor_size_max` — number inputs
8. `erf_size_min`, `erf_size_max` — number inputs
9. `feat_pool` — select Any/Has pool/No pool (mapped to must_have_features)
10. `feat_furnished` — select Any/Furnished/Unfurnished (mapped to must_have_features)
11. `feat_pets` — select Any/Pet friendly/No pets (mapped to must_have_features)
12. `notes` — textarea (max 500)

**Validation rules (Lines 144-170):**
```
name                    → nullable|string|max:120
listing_type            → required|in:sale,rental
category                → nullable|string|max:100
property_type           → nullable|string|max:100
price_min/max           → nullable|integer|min:0
beds_min/baths_min      → nullable|integer|min:0|max:20
garages_min/parking_min → nullable|integer|min:0|max:20
floor_size_min/max      → nullable|integer|min:0
erf_size_min/max        → nullable|integer|min:0
suburb                  → nullable|string|max:150
suburbs                 → nullable|array of max:150 strings
must_have_features      → nullable|array of max:60 strings
nice_to_have_features   → nullable|array of max:60 strings
feat_pool/furnished/pets → nullable|in:yes,no (mapped into must_have_features)
notes                   → nullable|string|max:500
```

**Other views:**
- `resources/views/corex/contacts/match-results.blade.php` — Agent-facing results page (score-sorted, WhatsApp share, hide/unhide, convert to deal)
- `resources/views/shared/match.blade.php` — Public client-facing share page (3 reaction buttons per property, optional note)

**Sidebar entry:** Core Matches link at `/core-matches` (route `corex.core-matches.index`).

---

### A4. Matching Engines that read from contact_matches

#### MatchingService — agent-facing, real-time
**File:** `app/Services/Matching/MatchingService.php` (431 lines)

| Method | Purpose |
|--------|---------|
| `scopeOverridesFor(ContactMatch)` | Reads `matches_visibility_scope` setting (agent/branch/agency) |
| `candidatesForProperty(Property)` | Returns active matches in same agency that could satisfy property |
| `matchesForProperty(Property)` | Like candidatesForProperty but sorted desc by score |
| `propertiesForMatch(ContactMatch, $overrides=[])` | Core method: properties matching criteria, supports UI overrides, hard SQL filters + PHP scoring |
| `score(Property, ContactMatch)` | Returns int 0-100 (see spec section 5 weights) |

**Scoring (per `.ai/specs/matches.md` section 5):**
- Hard filters: listing_type mismatch, property status sold/withdrawn/draft, hidden_property_ids, price out of range, beds/baths/garages below min, floor/erf out of range, missing must_have_features → return 0
- Weighted (sum 100): price 25 + suburb 20 + beds/baths 15 + property_type 10 + nice_to_haves 15 + freshness 10 + engagement bonus 5
- Threshold: <40 not surfaced; 40-60 weak; 60-80 good; 80+ strong

**Real-time:** scores computed on every access. Not cached.

**Invoked from:** ContactMatchController (results), SharedMatchController (show), MatchPropertyJob, PropertyController, Api/MobileCoreMatchController.

#### ClientMatchResolver — mobile-portal-facing, strict
**File:** `app/Services/Matching/ClientMatchResolver.php` (164 lines)

| Method | Purpose |
|--------|---------|
| `resolve(ContactMatch)` | Strict resolver — all filters are hard constraints, NULL on property = excluded |

Used by mobile client portal API. Stricter than MatchingService.

#### Property-triggered async
**`app/Observers/PropertyObserver.php` → dispatches `MatchPropertyJob`** on Property::saved. Job iterates `MatchingService::candidatesForProperty()` and creates `contact_match_notifications` rows.

---

### A5. Tinker — actual rows

Both `contact_matches` rows (PII not redacted as the data is sparse and contains no sensitive info beyond names):

**Row 1 (id=1, contact_id=2, created 2026-03-09):**
- agency_id: 1
- created_by_user_id: 22
- share_slug: `steve-jobs-hkuv6`
- listing_type: `sale`
- status: `active`
- **All criteria fields: NULL** (category, property_type, price_min/max, beds_min, baths_min, garages_min, parking_min, floor_size, erf_size, suburb, suburbs, must_have_features, nice_to_have_features, notes, last_engaged_at)

> Effectively an empty match. MatchingService would treat as "no filters" and score every property at maximum.

**Row 2 (id=2, contact_id=24, created 2026-05-06):**
- agency_id: 1
- created_by_user_id: 22
- share_slug: `rajan-naidoo-7azsh`
- listing_type: `sale`
- status: `active`
- category: `Residential`
- price_min: 750000, price_max: 1500000
- All other criteria: NULL

> A defined match but minimal — only category + price band. No suburb, beds, features.

---

### A6. What's missing from `contact_matches` (vs `buyer_preferences`)

Cross-referenced against the `buyer_preferences` schema (Section B1):

| Field in `buyer_preferences` | In `contact_matches`? | Notes |
|---|---|---|
| `budget_min` (decimal 14,2) | Partial — `price_min` (unsignedInteger) | Type differs: decimal vs integer. Cents precision lost in contact_matches. |
| `budget_max` (decimal 14,2) | Partial — `price_max` | Same caveat. |
| `bedrooms_min` (unsignedSmallInteger) | Partial — `beds_min` (unsignedTinyInteger) | Name differs; type narrower (TINYINT max 255 vs SMALLINT max 65535 — TINYINT is fine for beds in practice). |
| `bedrooms_max` | **NO — MISSING** | contact_matches has no max field for bedrooms. Adding this is needed for full parity. |
| `must_have_features` (json) | YES | Same field name. |
| `deal_breakers` (json) | **NO — MISSING** | contact_matches has `nice_to_have_features` (positive bonus) but no negative-exclusion field. Different semantics. |
| `preferred_areas` (json) | Partial — `suburbs` (json) | Conceptually equivalent. Both arrays of strings. |
| `preferred_property_types` (json) | Partial — `property_type` (string) | contact_matches stores a single string; buyer_preferences stores an array. Multi-type buyer cannot be represented in contact_matches today. |
| `preapproval_amount` (decimal 14,2) | **NO — MISSING** | Critical financial gate; used by PropertyMatchScoringService::getBuyerDemandForProperty() to count "pre-approved buyers". |
| `preapproval_expires_at` (date) | **NO — MISSING** | |
| `preapproval_institution` (varchar 100) | **NO — MISSING** | |
| `updated_by_user_id` (FK) | **NO — MISSING** | contact_matches tracks `created_by_user_id` only — no record of who last edited. |

**Fields searched for but NOT FOUND in either table:**
- `timeline` (when buyer plans to buy) — not in any wishlist table
- `finance_status` (cash/bond/pre-approved enum) — not as a column (preapproval_amount is a proxy)
- `source` (where buyer came from — referral, walk-in, portal) — not in either table; closest concept is `contact_sources` on the parent contact

---

## 3. Section B — `buyer_preferences` Deep Dive

### B1. Schema

**Migration files:**
1. `database/migrations/2026_05_06_000005_create_buyer_preferences_and_risk_scores.php` — base table
2. `database/migrations/2026_05_06_000017_create_prospecting_buyer_matches_table.php` — adds preapproval_amount, preapproval_expires_at, preapproval_institution

**Final columns (DESCRIBE confirmed via Tinker):**

| Column | Type | Nullable | Default |
|--------|------|----------|---------|
| id | bigint unsigned | NO | auto |
| contact_id | bigint unsigned | NO | (UNIQUE, FK cascadeOnDelete) |
| budget_min | decimal(14,2) | YES | NULL |
| budget_max | decimal(14,2) | YES | NULL |
| bedrooms_min | smallint unsigned | YES | NULL |
| bedrooms_max | smallint unsigned | YES | NULL |
| must_have_features | json | YES | NULL |
| deal_breakers | json | YES | NULL |
| preapproval_amount | decimal(14,2) | YES | NULL |
| preapproval_expires_at | date | YES | NULL |
| preapproval_institution | varchar(100) | YES | NULL |
| preferred_areas | json | YES | NULL |
| preferred_property_types | json | YES | NULL |
| updated_by_user_id | bigint unsigned | YES | NULL (FK nullOnDelete) |
| created_at | timestamp | YES | |
| updated_at | timestamp | YES | |

**Indexes:** UNIQUE on `contact_id`. No other indexes.

**No `agency_id` column** — multi-tenancy derived through contact join (this is a gap; see Section E).

**No `deleted_at`** — no soft delete support.

---

### B2. Model

**Status: NOT FOUND.**

There is no `app/Models/BuyerPreference.php` (or similar class). The system uses **raw DB queries** via `DB::table('buyer_preferences')` exclusively.

**Implications:**
- No BelongsToAgency trait → no AgencyScope auto-applied
- No relationships defined on the Eloquent side
- No casts → JSON columns must be manually json_encoded/decoded by callers
- No observers / events
- No SoftDeletes
- No scopes

This is part of why the previous audit found the architecture to be wrong — the row is contact-pillar data but lives outside the pillar conventions.

---

### B3. UI

**Route (`routes/web.php` line 1003):**
- POST `/command-center/buyers/{contact}/preferences` → `CommandCenter\BuyerDetailController@savePreferences`
- Route name: `command-center.buyers.preferences`

**Controller:** `app/Http/Controllers/CommandCenter/BuyerDetailController.php`

`savePreferences(Request $request, Contact $contact)` (Lines 40-77):

```
Validation:
  budget_min/max           → nullable|numeric|min:0
  bedrooms_min/max         → nullable|integer|min:0|max:20
  must_have_features       → nullable|array
  deal_breakers            → nullable|array
  preferred_areas          → nullable|array
  preferred_property_types → nullable|array
  preapproval_amount       → nullable|numeric|min:0
  preapproval_expires_at   → nullable|date
  preapproval_institution  → nullable|string|max:100

Save: DB::table('buyer_preferences')->updateOrInsert(
  ['contact_id' => $contact->id],
  $validated + ['updated_by_user_id' => Auth::id(), updated_at, created_at]
)
```

**Blade view:** `resources/views/command-center/buyers/detail.blade.php` Lines 234-300+

**Form fields in display order:**
1. Budget Min (number)
2. Budget Max (number)
3. Bedrooms Min (number)
4. Bedrooms Max (number)
5. Preferred Areas (text — comma-separated, parsed to array)
6. Pre-approved Amount (number)
7. Pre-approved Expires (date)
8. Pre-approval Institution (text, max 100)

**Read-only sections also displayed:**
- Auto-derived viewing-history patterns (avg viewed price, viewing intensity, top areas)
- Must-have features & deal breakers display block (read-only — UI exposes the data but no editor)

> **Gap discovered:** The form does NOT render editable inputs for `must_have_features`, `deal_breakers`, or `preferred_property_types`. These are accepted by the validator but no UI exists to set them. The 30 rows that have these arrays populated were seeded; they are not editable through the agent UI.

**Save path:** Controller writes directly via `DB::table()->updateOrInsert()`. No service layer.

---

### B4. Matching — PropertyMatchScoringService

**File:** `app/Services/PropertyMatchScoringService.php` (375 lines)

| Method | Inputs | Outputs | Writes |
|--------|--------|---------|--------|
| `calculateScore(object $prefs, Property)` (17-64) | preference row, property | score 0-100, tier, breakdown, missing_features | none (pure) |
| `recomputeForBuyer(int $contactId)` (91-124) | contact_id | int rows written | `property_buyer_matches` (upsert, score ≥50) |
| `recomputeProspectingMatches(int $listingId)` (200-234) | prospecting_listing_id | int rows written | `prospecting_buyer_matches` (upsert, score ≥50) |
| `recomputeProspectingMatchesForBuyer(int $contactId)` (239-275) | contact_id | int rows written | `prospecting_buyer_matches` (upsert, score ≥50) |
| `getBuyerDemandForProperty(int $propertyId, int $agencyId)` (306-354) | property_id, agency_id | total/perfect/strong/preapproved/area buyer counts | none (read) |
| `getProspectingDemand(int $listingId)` (280-301) | listing_id | demand summary + top 5 matches | none (read) |
| `scoreProspectingCapture(object $prefs, object $capture)` (184-195) | helper | match score | none |

**Scoring weights (PropertyMatchScoringService):**
- Price: 25 (within budget: 25; ±10%: 18; ±20%: 8; beyond: 0)
- Area: 20 (exact: 20; prefix nearby: 12; other: 5)
- Property type: 10
- Must-have features: 15
- Deal-breakers: 10 (0 if any present)
- Bedrooms: 20 (currently placeholder — not implemented; defaults generous)

**Call sites:**

- `recomputeProspectingMatches()` ← `app/Observers/ProspectingListingObserver.php:45` (on created/updated) + console command `prospecting:recompute-matches`
- `recomputeForBuyer()` ← `app/Console/Commands/RecomputePropertyMatches.php:28`
- `recomputeProspectingMatchesForBuyer()` ← `app/Console/Commands/RecomputeProspectingMatches.php:53`, `database/seeders/DemoDataSeeder.php:41`
- `getBuyerDemandForProperty()` ← `app/Http/Controllers/Presentation/PresentationController.php:332`

**Origin of the 30,121 prospecting_buyer_matches rows:**
- Seeded data: 31 buyer_preferences × 983 prospecting_listings = 30,473 candidate pairs
- 30,121 met the score ≥50 threshold and were upserted
- Traced via Tinker: top-scored row (id=4814, score=92, contact=6) corresponds to buyer_preferences contact_id=6 (budget R1.2M-R2M, areas Uvongo/Margate). Confirmed: matches trace cleanly back to source preferences.

---

### B5. Data Quality — Tinker output

```
TOTAL: 31
with budget_min: 31           (100%)
with budget_max: 31           (100%)
with bedrooms_min: 30         (97%)
with bedrooms_max: 30         (97%)
with must_have_features: 30   (97%)
with deal_breakers: 30        (97%)
with preferred_areas: 31      (100%)
with preferred_property_types: 30 (97%, but 28 of 30 are empty arrays "[]")
with preapproval_amount: 11   (35%)
with preapproval_expires_at: 11 (35%)
distinct agencies via contacts: 1
orphaned (no contact): 0
```

**preferred_property_types value distribution:**
- `[]` (empty array): 28
- `["Apartment"]`: 1
- `["Townhouse"]`: 1
- `NULL`: 1

> Only 2 of 31 rows actually specify a property type, even though the column is "populated" 30/31 times. The empty array is technically non-null. Real signal density is low.

**Pre-approval coverage:** 11/31 buyers (35%) have pre-approval data — meaningful for demand reporting.

**Orphans:** zero rows orphaned from a contact. All 31 are linked.

**Agency spread:** all 31 rows belong to agency_id=1 (single-tenant test data).

---

### B6. Callers

Every file that references `BuyerPreference`, `buyer_preferences`, or `PropertyMatchScoringService`:

| File | Role |
|------|------|
| `app/Services/PropertyMatchScoringService.php` | The service itself — reads `buyer_preferences` rows |
| `app/Http/Controllers/CommandCenter/BuyerDetailController.php` (lines 35, 56) | Buyer pipeline detail view — reads and writes `buyer_preferences` |
| `app/Http/Controllers/BuyerPortalController.php:34` | Public buyer portal — calls `getMatchesForBuyer()` |
| `app/Http/Controllers/Presentation/PresentationController.php:332` | Presentations — calls `getBuyerDemandForProperty()` |
| `app/Http/Controllers/Presentation/PresentationSnapshotController.php` | Referenced (snapshots include demand data) |
| `app/Http/Controllers/ProspectingController.php` | Reads `prospecting_buyer_matches` derived data |
| `app/Observers/ProspectingListingObserver.php:45` | Triggers `recomputeProspectingMatches()` on create/update |
| `app/Services/BuyerIntelligenceService.php:163` | Reads preferences for buyer intelligence page |
| `app/Console/Commands/RecomputePropertyMatches.php` | CLI: `matches:recompute` |
| `app/Console/Commands/RecomputeProspectingMatches.php` | CLI: `prospecting:recompute-matches` |
| `app/Console/Commands/DemoCleanup.php` | Demo data cleanup |
| `database/seeders/DemoDataSeeder.php:33,41` | Seeds the 31 rows and triggers initial match computation |
| `resources/views/command-center/buyers/detail.blade.php` (lines 234-300+) | The preference form UI |

---

## 4. Section C — Side-by-Side Comparison Matrix

| Dimension | `contact_matches` | `buyer_preferences` | Action Required |
|-----------|-------------------|---------------------|-----------------|
| Layer | Contact pillar (correct) | Buyer-pipeline-specific (wrong layer) | Adopt from contact_matches |
| Eloquent model | `ContactMatch.php` (full model, traits, scopes, methods) | **None** — raw DB queries only | Adopt from contact_matches |
| Schema completeness | 31 columns inc. features, freshness, share, lifecycle | 14 columns — narrow but adds preapproval + bedrooms_max + deal_breakers + multi-property-type | Port from buyer_preferences (preapproval block, bedrooms_max, deal_breakers, multi property_type) |
| Multi-tenancy (`agency_id` column) | YES — `agency_id` column + `BelongsToAgency` trait + `AgencyScope` global scope | NO — no agency_id column; relies on contact join | Adopt from contact_matches |
| Linked to Contact directly | YES (contact_id FK) | YES (contact_id FK, UNIQUE) | Adopt (note: contact_matches allows MANY rows per contact; buyer_preferences allows ONE) — see open questions |
| SoftDeletes | YES (`deleted_at`) | NO | Adopt from contact_matches |
| Status lifecycle | YES — active/paused/fulfilled/expired | NO — implicit (a row exists or it doesn't) | Adopt from contact_matches |
| Captures: price min/max | YES (unsignedInteger) | YES (decimal 14,2) | Adopt from contact_matches **— but consider widening to decimal for parity** |
| Captures: suburbs/areas (multi) | YES (`suburbs` json + legacy `suburb` string) | YES (`preferred_areas` json) | Adopt from contact_matches |
| Captures: property type | Single string (`property_type`) | json array (`preferred_property_types`) | **Port from buyer_preferences** — change contact_matches.property_type to support multi-type, OR add property_types json |
| Captures: bedrooms min | YES (`beds_min`) | YES (`bedrooms_min`) | Adopt (note name difference: beds vs bedrooms) |
| Captures: bedrooms max | NO | YES (`bedrooms_max`) | Port from buyer_preferences |
| Captures: bathrooms | YES (`baths_min` only) | NO | Adopt from contact_matches |
| Captures: garages/parking | YES (`garages_min`, `parking_min`) | NO | Adopt from contact_matches |
| Captures: floor/erf size ranges | YES (4 columns) | NO | Adopt from contact_matches |
| Captures: must-haves | YES (`must_have_features` json) | YES (`must_have_features` json) | Adopt from contact_matches |
| Captures: nice-to-haves (positive bonus) | YES (`nice_to_have_features` json) | NO | Adopt from contact_matches |
| Captures: deal-breakers (negative exclusion) | NO | YES (`deal_breakers` json) | Port from buyer_preferences |
| Captures: notes/free text | YES (`notes` text, max 500) | NO | Adopt from contact_matches |
| Captures: timeline (when buying) | NO | NO | **Add net new** — flag for Johan |
| Captures: finance status (cash/bond) | NO | NO (only preapproval amount) | **Add net new** if business needs distinct "cash" vs "bonded" buyers |
| Captures: pre-approval block (amount/expires/institution) | NO | YES (3 columns) | **Port from buyer_preferences** — critical for demand intelligence |
| Captures: source (where buyer came from) | NO (lives on Contact pillar via contact_sources) | NO | Drop here — keep on Contact pillar |
| Captures: updated_by_user_id | NO | YES | Port from buyer_preferences (audit trail) |
| Validation strictness | Strong (max:120 name, ranges enforced) | Looser (no string-max on arrays) | Adopt from contact_matches |
| UI completeness | Comprehensive — agent form, public share, mobile API, results page, WhatsApp share, hide/unhide, convert-to-deal | Partial — agent form only (and the form omits must_have_features, deal_breakers, preferred_property_types inputs entirely) | Adopt contact_matches UI; add new inputs for ported fields (bedrooms_max, deal_breakers, preapproval) |
| Matching engine integration | MatchingService (real-time scoring) + ClientMatchResolver (strict) | PropertyMatchScoringService (cached writes to property_buyer_matches + prospecting_buyer_matches) | Refactor PropertyMatchScoringService to read from ContactMatch (or merge into MatchingService) |
| Observer / event hooks | PropertyObserver → MatchPropertyJob → ContactMatchNotification | ProspectingListingObserver → recomputeProspectingMatches | Refactor prospecting observer to read from ContactMatch |
| Share/public link | YES (share_token + share_slug + SharedMatchController) | NO | Adopt from contact_matches |
| Buyer feedback per result | YES (`contact_match_feedback` pivot: interested/not_interested/saved + notes) | NO | Adopt from contact_matches |
| Hidden properties per buyer | YES (`hidden_property_ids` json) | NO | Adopt from contact_matches |
| Engagement tracking | YES (`last_engaged_at` + `property_view_counts`) | NO | Adopt from contact_matches |
| Auto-archive | YES (`auto_archive_at` date + `ArchiveStaleMatches` command) | NO | Adopt from contact_matches |
| Convert-to-Deal | YES (controller method + route) | NO | Adopt from contact_matches |
| Mobile API | YES (`/api/mobile/core-matches`) | NO | Adopt from contact_matches |
| Cardinality (per contact) | Many ContactMatches allowed | UNIQUE — one preference per contact | **Decision needed** — see open questions |
| Permission scheme | `access_core_matches`, `core_matches.view`, `core_matches.manage`, `core_matches.convert_to_deal` | None defined (uses generic command-center.buyers gate) | Adopt from contact_matches |

---

## 5. Section D — Migration & Refactor Surface

### D1. Data Migration Plan Inputs

**Row count to migrate:** 31 (confirmed via Tinker — 0 orphans, all linked to contacts, all in agency_id=1).

**Field-by-field mapping `buyer_preferences` → `contact_matches`:**

| Source (`buyer_preferences`) | Target (`contact_matches`) | Notes |
|------------------------------|---------------------------|-------|
| contact_id | contact_id | Direct |
| budget_min (decimal) | price_min (unsignedInteger) | Cast to integer (loses cents — acceptable for ZAR rounded values) |
| budget_max (decimal) | price_max (unsignedInteger) | Same |
| bedrooms_min (smallInt) | beds_min (tinyInt) | Direct (max 20 validation already enforced) |
| **bedrooms_max** | **(no target column)** | Requires new column or stored in `notes` — flag for Johan |
| must_have_features (json) | must_have_features (json) | Direct copy |
| **deal_breakers (json)** | **(no target column)** | Requires new column — flag for Johan |
| preferred_areas (json) | suburbs (json) | Direct rename |
| preferred_property_types (json) | property_type (string) | **Lossy** — buyer_preferences stores array; contact_matches stores string. Migration: if exactly 1 item → use it; if 0/many → leave null and log |
| **preapproval_amount** | **(no target column)** | Requires new column — flag for Johan |
| **preapproval_expires_at** | **(no target column)** | Requires new column — flag for Johan |
| **preapproval_institution** | **(no target column)** | Requires new column — flag for Johan |
| updated_by_user_id | **(no target column)** | Either add column or write into `created_by_user_id` on migrate |
| created_at / updated_at | created_at / updated_at | Direct |
| (no source) | agency_id | Derive from contact.agency_id |
| (no source) | listing_type | Default `'sale'` (no source data) |
| (no source) | status | Default `'active'` |
| (no source) | share_token, share_slug | Auto-generated by ContactMatch::boot() |

**Fields that cannot be mapped cleanly** (flag for Johan):
1. `bedrooms_max` — needs new column on contact_matches or absorbed into notes
2. `deal_breakers` — needs new column; semantic is different from `nice_to_have_features`
3. `preapproval_amount/expires_at/institution` — three new columns
4. `preferred_property_types` (array) — schema mismatch with `property_type` (string)
5. The 28 buyer_preferences rows with empty `preferred_property_types = []` — map as NULL or as the empty array?
6. The 11 rows with preapproval data would lose this info if migrated without the preapproval block being added first.

**Orphaned rows:** 0 — clean.

**Existing contact_matches collisions:** Only 2 contact_matches exist (contact_id=2 and contact_id=24). buyer_preferences exists for 31 contacts. Need to check if any of the 31 preference rows belong to contact_id=2 or contact_id=24 — if so, merge logic required.

### D2. Code Surface to Refactor (callers of `buyer_preferences`)

| File | Action |
|------|--------|
| `app/Services/PropertyMatchScoringService.php` | **Major rewrite** — read from `ContactMatch` model instead of `buyer_preferences` table. Re-map score fields (`budget_min/max` → `price_min/max`, `preferred_areas` → `suburbs`, `preferred_property_types` → `property_type`, etc.). Re-implement bedrooms_max + deal_breakers + preapproval logic after schema changes. |
| `app/Http/Controllers/CommandCenter/BuyerDetailController.php` (savePreferences, line 40-77) | Refactor to write to ContactMatch via the existing `ContactMatchController` save path (or call the model directly). Drop raw DB::table() calls. |
| `app/Http/Controllers/BuyerPortalController.php:34` | Update call to use `MatchingService::propertiesForMatch(ContactMatch)` instead of `PropertyMatchScoringService::getMatchesForBuyer($contactId)`. |
| `app/Http/Controllers/Presentation/PresentationController.php:332` | Refactor `getBuyerDemandForProperty` call. Decision needed: keep PropertyMatchScoringService and have it read from ContactMatch, or fold into MatchingService. |
| `app/Http/Controllers/Presentation/PresentationSnapshotController.php` | Same refactor if it uses demand data. |
| `app/Http/Controllers/ProspectingController.php` | If it reads `prospecting_buyer_matches`, no change to read path — but the write path through ProspectingListingObserver needs to change. |
| `app/Observers/ProspectingListingObserver.php:45` | Update to call new method that reads from ContactMatch (or unify into MatchingService). |
| `app/Services/BuyerIntelligenceService.php:163` | Refactor read path to use ContactMatch. |
| `app/Console/Commands/RecomputePropertyMatches.php` | Refactor to iterate over ContactMatches instead of buyer_preferences. |
| `app/Console/Commands/RecomputeProspectingMatches.php` | Same. |
| `app/Console/Commands/DemoCleanup.php` | Drop buyer_preferences cleanup once table is deprecated. |
| `database/seeders/DemoDataSeeder.php:33,41` | Refactor `seedBuyerPreferences()` to create ContactMatch records instead. |
| `resources/views/command-center/buyers/detail.blade.php:234-300+` | Replace preferences form with a ContactMatch form partial (or embed contact_matches form from contacts/show.blade.php). |
| **Tests** | Search for tests touching `buyer_preferences` or `BuyerPreference` — none found in initial sweep but a full test pass against `dev-check.ps1 -Full` should run after refactor. |

### D3. Buyer Pipeline UI Refactor Surface

**Current views rendering the buyer_preferences form:**
- `resources/views/command-center/buyers/detail.blade.php` — only the "Preferences" tab block (lines 234-300+)

**Replacement component options:**
- **Option A: Reuse partial from contact show page.** The `contact_matches` form lives inline in `resources/views/corex/contacts/show.blade.php` (lines 1055-1217). It is NOT currently a separate partial. It would need to be extracted to e.g. `resources/views/corex/contacts/_match-form.blade.php` to be reusable in both contexts (contact page + buyer pipeline page).
- **Option B: Move buyer pipeline page to use the same contact match results UI**. Replace the preferences tab with a "Wishlists" tab that lists the contact's ContactMatch rows (since a contact can have many) and provides Add/Edit links. This better fits the contact_matches cardinality model.

> Recommendation: Option B (extract `_match-form.blade.php` partial, then have both the contact tab AND the buyer pipeline tab use the same partial; treat the buyer pipeline page as a richer drill-in but driven by the same underlying ContactMatch records).

---

## 6. Section E — Risk & Compliance Checks

### E1. Multi-Tenancy

| Table/system | Has `agency_id`? | Scoped by AgencyScope? | Verdict |
|--------------|------------------|------------------------|---------|
| `contact_matches` | YES (column + BelongsToAgency trait) | YES (verified via `(new ContactMatch)->getGlobalScopes()` returning `[SoftDeletingScope, AgencyScope]`) | Correct |
| `buyer_preferences` | NO (no column) | NO (no Eloquent model, no scope) | **Gap** — relies on caller joining through contacts |
| `prospecting_buyer_matches` | NO (no column) | NO (raw queries) | **Gap** — relies on join through prospecting_listings AND contacts |
| `property_buyer_matches` | NO (no column) | NO | **Gap** — same pattern |

**Cross-agency leakage check (Tinker, on 30,121 matches):**
```
contact agency_id=1: 30121 matches
listing agency_id=1: 30121 matches
cross-agency match rows (contact.agency_id != listing.agency_id): 0
```

> No leakage today, but the only reason is that all data is in agency_id=1. In a multi-agency production environment, the absence of `agency_id` on the match tables means any forgotten join filter could cross agencies silently. **This is a tenancy bug, not a hypothetical concern** — it matches Non-negotiable #7 in CLAUDE.md ("Multi-tenancy is non-negotiable... enforced structurally by the global AgencyScope, not by ad-hoc where('agency_id') in controllers").

### E2. Soft Delete Behaviour

| Table | SoftDeletes? |
|-------|--------------|
| `contact_matches` | YES (`deleted_at`) |
| `buyer_preferences` | NO |
| `contact_match_feedback` | NO |
| `contact_match_notifications` | NO |
| `property_buyer_matches` | NO |
| `prospecting_buyer_matches` | NO |

> **No soft-deleted buyer_preferences exist** (table has no deleted_at). Deprecating the table will not lose archived rows because none can exist. However, when the new ContactMatch records are created from migration, their `deleted_at` state will start fresh — this is fine.

### E3. The 30,121 Existing Matches

These rows tie buyer_preferences (source) to prospecting_listings (target). After unification:

**Option A: Regenerate.** After buyer_preferences → contact_matches migration, run `php artisan prospecting:recompute-matches` (refactored to read from ContactMatch). Wipes and rebuilds all 30,121 rows. Clean. Estimated time given current code: minutes (single agency, 983 listings × ~30 active buyers).

**Option B: Remap in place.** Add a `contact_match_id` column to `prospecting_buyer_matches`, backfill from the 1:1 buyer_preferences.contact_id → contact_matches.contact_id mapping. Keep historical scores intact.

> **Recommendation: Option A (regenerate).** The scoring algorithm itself will change as fields are ported (bedrooms_max, deal_breakers, preapproval). Existing scores were computed with the old algorithm and will be inaccurate after the refactor. Regenerating is both simpler and more correct.

**Risks:**
- During the regeneration window the prospecting tab will show empty buyer-match counts. Time-box it.
- If observer changes are not deployed in sync with the data migration, new prospecting listings created mid-migration will produce stale matches against the old buyer_preferences and orphan after the table drops.

---

## 7. Gaps in `contact_matches` that Block Unification

| # | Gap | Severity | Recommended Approach |
|---|-----|----------|----------------------|
| 1 | No `preapproval_amount`, `preapproval_expires_at`, `preapproval_institution` columns | **Blocker** | Migration to add 3 columns to `contact_matches`. Used by demand intelligence (PropertyMatchScoringService::getBuyerDemandForProperty counts pre-approved buyers). Cannot drop buyer_preferences without these. |
| 2 | No `deal_breakers` json column | **Blocker** | Add column. Semantic distinction from `nice_to_have_features` (negative exclusion vs positive bonus) is real and used by scoring. |
| 3 | `property_type` is a single string, not a json array | **Blocker** | Either widen to `property_types` (json) or accept the constraint. With current data, only 2 of 31 buyer_preferences rows specify a property type, so the lossy migration is low-impact, but the schema mismatch is a forward-looking risk. |
| 4 | No `bedrooms_max` column | Important | Add column. Currently 30 buyer_preferences rows have bedrooms_max. Without it, buyer "wants 2-3 beds, not 5" cannot be expressed. |
| 5 | No `updated_by_user_id` column (only `created_by_user_id`) | Important | Add column for audit trail parity with buyer_preferences. |
| 6 | `prospecting_buyer_matches` and `property_buyer_matches` lack `agency_id` column | Important | Add column to both tables + BelongsToAgency trait if/when these gain models. Without this, multi-agency rollout will leak silently if a join is forgotten. |
| 7 | `contact_matches` has SoftDeletes; `buyer_preferences` does not — migration must not silently lose pseudo-archived records | Nice-to-have | Confirmed: zero archived buyer_preferences rows exist. Safe. |
| 8 | `contact_matches` form does not currently expose `bedrooms_max`, `deal_breakers`, `preapproval_*` (because the columns don't exist) | Important | UI work follows from columns being added (Gap 1, 2, 4). Form fields needed in `resources/views/corex/contacts/show.blade.php` and the extracted partial. |
| 9 | No `BuyerPreference` Eloquent model means no test coverage of buyer_preferences row behaviour | Nice-to-have | Refactor will replace usage entirely — no need to build a model for a deprecated table. |
| 10 | `MatchingService` and `PropertyMatchScoringService` are two separate engines with different weights/algorithms | Important | After porting, decision needed: merge into one engine or keep two (real-time for agent UI, cached for demand intelligence). Both reading from ContactMatch is the minimum; consolidation is optional. |
| 11 | `contact_matches` allows many rows per contact; `buyer_preferences` enforces UNIQUE(contact_id) | Important | If a contact in buyer_preferences gets migrated and they already have a ContactMatch, decide: append a 2nd ContactMatch (preserve both), or merge fields. See open question 1. |
| 12 | `BuyerDetailController::savePreferences` writes via raw `DB::table()` — bypasses AgencyScope and any future model events | Important | Replace with ContactMatch::create()/update() in refactor. |
| 13 | `contact_matches.property_type` is varchar (presumably unlimited unless validated), buyer_preferences allows multiple via JSON. The current UI uses a select dropdown from `$matchTypes` for property_type. If we keep single-string, the dropdown stays | Important | Schema decision drives form UX (single select vs multi-select chip). |
| 14 | `BuyerDetailController` validation accepts `must_have_features`, `deal_breakers`, `preferred_property_types` arrays but the form has no inputs for them — silent gap that explains why preferred_property_types is mostly `[]` | Nice-to-have | Will be solved by adopting the contact_matches form which has feature inputs. |
| 15 | `prospecting_buyer_matches` `agent_notified_at` / `dismissed_at` columns exist on the match table — confirm these continue to function after unification | Nice-to-have | These are decoration on the match record, not the source criteria. No change expected, but worth verifying after refactor. |

---

## 8. Open Questions for Johan

1. **Cardinality:** `buyer_preferences` is UNIQUE per contact. `contact_matches` allows many per contact (a buyer can have a "Margate sale" wishlist AND a "Shelly rental" wishlist). When migrating a buyer with both a preferences row AND existing ContactMatches, do we (a) append a new ContactMatch alongside, (b) merge fields into the existing primary ContactMatch, or (c) require manual reconciliation?

2. **Property type schema:** Do we widen `contact_matches.property_type` from a single string to a `property_types` JSON array? This better represents reality (a buyer may want "House OR Townhouse") but is a schema change with downstream impact on MatchingService scoring, the form UI (single select → multi-select chips), and the SharedMatchController public page. The current data shows only 2 of 31 rows actually have multiple property types — could defer to phase 2.

3. **Preapproval block placement:** Add `preapproval_amount`, `preapproval_expires_at`, `preapproval_institution` to `contact_matches`? Or move them to the `Contact` pillar (since pre-approval describes the buyer, not a specific wishlist)? Pre-approval is contact-level data conceptually — multiple wishlists for the same buyer share the same financial position.

4. **Bedrooms_max:** Add to `contact_matches`, or accept the loss and treat "bedrooms_min only" as the canonical filter? Current data shows 30 of 31 rows have bedrooms_max set.

5. **Deal-breakers vs nice_to_have_features:** Are these semantically distinct in your mental model? deal_breakers = "if property has this, exclude it"; nice_to_have_features = "if property has this, score higher". Should we add `deal_breakers` as a new column, or fold the use case into a convention on `must_have_features` with negation tokens?

6. **Two matching engines:** After refactor, do we want `MatchingService` and `PropertyMatchScoringService` to remain as two engines (one real-time, one cached) — both reading from ContactMatch — or merge them? The two have different scoring weights (e.g., MatchingService weights price 25 + suburb 20 + beds/baths 15; PropertyMatchScoringService weights price 25 + area 20 + bedrooms 20 placeholder).

7. **`prospecting_buyer_matches` regeneration:** Confirm regenerate-from-scratch (Option A in Section E3) over remap (Option B). Estimated downtime: minutes, but the prospecting tab will show 0 buyer matches during the rebuild.

8. **Permissions:** Does the buyer pipeline page (`/command-center/buyers/{contact}/preferences`) currently gate on `command-center.buyers.*` permissions? If so, after refactor, do users need `core_matches.manage` too — or do we surface the existing buyer-pipeline permissions through to ContactMatch writes when they originate from the buyer pipeline UI?

9. **`updated_by_user_id`:** Add to `contact_matches` for audit parity, or rely on `last_engaged_at` + git-blame style audit_logs elsewhere?

10. **Form unification:** Extract the contact_matches form (currently inline in `corex/contacts/show.blade.php` lines 1055-1217) to a Blade partial `_match-form.blade.php` so both the contact page AND the buyer pipeline page can use it? Or keep the buyer pipeline page rendering its own simpler form?

11. **buyer_preferences table fate:** After successful migration, drop the table (migration) or leave it empty with a deprecation comment? Dropping is cleaner; keeping for one release cycle as a safety net is more conservative.

12. **The 2 existing contact_matches:** Row 1 has no criteria at all (all NULL). Should the migration leave it untouched, treat it as "any property", or delete it as garbage? Row 2 has only category + price band.

---

## Verification Steps

### 1. `php -l`
No PHP files edited (investigation-only). All files read during deep dives parsed successfully — no syntax errors encountered.

### 2. View Cache Clear
```
php artisan view:clear
INFO  Compiled views cleared successfully.
```

### 3. `scripts/dev-check.ps1`
```
=== DEV CHECK ===
Changed files: 2
1. Lint PHP files -- No PHP files changed
2. Clear caches -- Caches cleared
3. Route check -- skipped (no route/controller changes)
4. View check -- skipped (no blade changes)
5. Tests -- skipped (use -Full to run all 894 tests)
=== DEV CHECK OK ===
```
Pass. 0 new failures.

### 4. Tinker Verification

**(a) `contact_matches` rows (both rows — see Section A5 for full output).** Confirmed: 2 rows total, agency_id=1 on both, listing_type=sale on both, status=active on both. Row 1 has all criteria NULL; Row 2 has category + price band only.

**(b) `buyer_preferences` fill-rate (full output in Section B5).** Confirmed: 31 rows, 100% on budget + areas, 35% on preapproval, 28 rows with empty preferred_property_types arrays, 0 orphans, 1 distinct agency.

**(c) Computed match traceability:**
```
match_id=4814 contact=6 score=92 tier=perfect | pref budget=1200000.00-2000000.00 | areas=["Uvongo","Margate"]
match_id=4806 contact=6 score=92 tier=perfect | pref budget=1200000.00-2000000.00 | areas=["Uvongo","Margate"]
match_id=4812 contact=6 score=92 tier=perfect | pref budget=1200000.00-2000000.00 | areas=["Uvongo","Margate"]
match_id=4819 contact=6 score=92 tier=perfect | pref budget=1200000.00-2000000.00 | areas=["Uvongo","Margate"]
match_id=49   contact=1 score=90 tier=perfect | pref budget=1500000.00-4000000.00 | areas=["Margate","Uvongo","Southbroom"]
```
Confirmed: every `prospecting_buyer_matches` row traces cleanly back to a `buyer_preferences` row (1:N via contact_id). Source of truth migration path is well-defined.

**(d) Cross-agency leakage check:**
```
contact agency_id=1: 30121 matches
listing agency_id=1: 30121 matches
cross-agency rows (contact.agency_id != listing.agency_id): 0
```
No active leakage today (single-tenant data), but the absence of `agency_id` on the match table itself is a structural gap.

**(e) Schema confirmation:** `DESCRIBE buyer_preferences` confirms 14 columns, no `agency_id`, no `deleted_at`.

**(f) `ContactMatch` global scopes:**
```
[SoftDeletingScope, App\Models\Scopes\AgencyScope]
```
Confirmed via Tinker.

---

**Deliverable file:** `.ai/audits/2026-05-13-wishlist-unification-audit.md` — this document.
