# Unified Buyer Wishlist — Module Spec

> Status: Approved 2026-05-13
> Owner: Johan / Andre
> Pillars: Contact (primary) + Property + Agent (+ Deal via existing convert-to-deal bridge)
> Supersedes: `buyer_preferences` (Phase 1 deprecation), partially extends `.ai/specs/matches.md`
> Source audits:
> - `.ai/audits/2026-05-13-buyer-prospecting-audit.md`
> - `.ai/audits/2026-05-13-wishlist-unification-audit.md`

---

## Section 1 — Purpose & Context

**The architectural problem.** CoreX currently runs two parallel buyer-criteria systems. `contact_matches` (Andre's Core Matches engine — approved 2026-04-28 in `.ai/specs/matches.md`) is correctly anchored on the Contact pillar, uses `BelongsToAgency`, has SoftDeletes, status lifecycle, share links, mobile API, feedback pivots, and a convert-to-deal bridge — but is barely populated (2 rows). `buyer_preferences` (built later for the buyer pipeline) lives outside the pillar conventions: no Eloquent model (raw `DB::table()` everywhere), no `agency_id` column, no SoftDeletes, no observers, no scopes. It has 31 rows of richer data and drives 30,121 cached `prospecting_buyer_matches`, but the architecture is wrong (per unification audit Sections 2–3).

**The resolution.** Unify on `contact_matches` as the single source of truth. Migrate all `buyer_preferences` data into `contact_matches` (+ a preapproval block on the `Contact` pillar). Refactor every caller of `buyer_preferences` and `PropertyMatchScoringService` to read from `ContactMatch`. Deprecate the `buyer_preferences` table in two phases (this release: empty + deprecation listener; +30 days: drop migration). Close the multi-tenancy gap on the match tables (`prospecting_buyer_matches`, `property_buyer_matches`) at the same time.

**Downstream features this unblocks:**
- The prospecting intelligence summary block (top-of-tab demand breakdown by area / price band).
- The WhatsApp prospecting modal that injects live buyer-match intelligence into agent-sent messages.
- A consistent "Wishlists" tab on the buyer pipeline page that uses the same form as the Contact page.
- A clean foundation for ranking, notification, and conversion automation downstream.

---

## Section 2 — Source Material

The following files were read in full or relevant section before writing this spec. Confirm each remains current before any build prompt executes.

| File | Read |
|------|------|
| `CLAUDE.md` | Yes |
| `.ai/STANDARDS.md` | Yes |
| `.ai/audits/2026-05-13-buyer-prospecting-audit.md` | Yes |
| `.ai/audits/2026-05-13-wishlist-unification-audit.md` | Yes (primary input) |
| `.ai/specs/matches.md` | Yes |
| `.ai/specs/mobile-core-matches.md` | Yes |
| `.ai/specs/SPEC_Portal_Scraping_Prospecting.md` | Yes |
| `app/Models/ContactMatch.php` | Yes (read via audit deep-dive) |
| `app/Services/Matching/MatchingService.php` | Yes (audit) |
| `app/Services/Matching/ClientMatchResolver.php` | Yes (audit) |
| `app/Services/PropertyMatchScoringService.php` | Yes (audit) |
| `app/Http/Controllers/CoreX/ContactMatchController.php` | Yes (audit) |
| `app/Http/Controllers/CommandCenter/BuyerDetailController.php` | Yes (audit) |
| `app/Observers/ProspectingListingObserver.php` | Yes (audit) |
| `resources/views/corex/contacts/show.blade.php` (lines 1049–1332) | Yes (audit) |
| `resources/views/command-center/buyers/detail.blade.php` (lines 234–300+) | Yes (audit) |

---

## Section 3 — Decisions Locked

These are Johan's product decisions, locked. Do not re-debate or propose alternatives in any build prompt. Encode each as written.

### D1. Cardinality — many ContactMatches per contact, one flagged primary

- Many `ContactMatch` rows per contact are allowed.
- Add boolean column `is_primary` to `contact_matches`.
- Exactly one match per contact is flagged primary at any time — enforced in application logic, not via DB constraint:
  - When setting one row primary, all other rows for that contact are demoted to `is_primary = false`.
  - When deleting the primary, the next-most-recently-updated row for that contact becomes primary.
  - When creating the first match for a contact, it is primary by default.
- Aggregation queries for the prospecting intelligence layer default to `is_primary = true` only (one criteria set per buyer for demand counts).
- Buyer pipeline UI defaults to showing the primary wishlist. A "+ Add another wishlist" action allows non-primary wishlists.

*Rationale: a single UNIQUE(contact_id) constraint cannot express real-world buyer behaviour (a buyer may want to sale-hunt in Margate AND rent in Shelly Beach simultaneously). At the same time, demand-counting aggregations need a deterministic "this contact's main wishlist" answer.*

### D2. Property type schema — widen to JSON array, deprecate legacy column

- New column `property_types` (json, nullable) on `contact_matches`.
- Existing `property_type` (single string) column is **retained but deprecated** for one release cycle.
- Migration: for every existing `contact_matches` row with a non-null `property_type`, set `property_types = JSON_ARRAY(property_type)`.
- Matching engines read `property_types` first; fall back to `property_type` if `property_types` is null.
- New writes go to `property_types` only.
- UI changes from a single-select dropdown to a multi-select chip selector.
- After one release cycle, a follow-up ticket (out of scope of this spec) drops the `property_type` column.

*Rationale: real buyers say "House or Townhouse". The current single-string schema forces lossy compression.*

### D3. Preapproval block — on the Contact pillar, not on ContactMatch

- Preapproval lives on `contacts`, NOT on `contact_matches`.
- Three new columns on `contacts`:
  - `preapproval_amount` (decimal 14,2, nullable)
  - `preapproval_expires_at` (date, nullable)
  - `preapproval_institution` (varchar 100, nullable)
- Matcher reads via `$contactMatch->contact->preapproval_amount`.

*Rationale: a buyer's financial position is one fact about the person. Multiple wishlists for the same buyer share it. Duplicating it on every ContactMatch would create sync-rot.*

### D4. Bedrooms_max

- Add `bedrooms_max` (unsignedTinyInteger, nullable) to `contact_matches`.
- Hard filter in matcher: if `property.beds > bedrooms_max`, exclude.
- Form input added next to existing `beds_min`.

### D5. Deal-breakers — third feature bucket

- Add `deal_breakers` (json, nullable) to `contact_matches`.
- Three feature buckets total: must-have / nice-to-have / deal-breaker.
  - `must_have_features` — hard requirement (exclude if absent).
  - `nice_to_have_features` — positive bonus (presence increases score).
  - `deal_breakers` — hard exclusion (exclude if any present on property).
- Semantically distinct columns, not negation tokens on must_have_features.

### D6. Matcher merge — defer

- `MatchingService` and `PropertyMatchScoringService` remain as two separate engines in this release.
- Both refactor to read from `ContactMatch`.
- Add `// TODO(matcher-unification): see backlog ticket` comment at the top of both service files. Backlog ticket created separately.

### D7. Regeneration approach

- Option A: regenerate `prospecting_buyer_matches` and `property_buyer_matches` from scratch (rather than remap in place).
- Run as a queued job `RegenerateBuyerMatchesJob`, not request-time.
- Job writes start/finish entries to whichever audit mechanism `STANDARDS.md` prescribes (confirm during the Prompt 09 implementation — initial assumption: existing `audit_logs` pattern).
- The prospecting tab must display a graceful empty state (e.g. "Rebuilding buyer matches — refresh in a few minutes") during rebuild, not a 500.

### D8. Permissions — route owns the gate

- Buyer-pipeline route (`/command-center/buyers/{contact}/preferences` and successors) checks buyer-pipeline permissions only.
- Contact route (`/contacts/{contact}/matches/*`) checks `core_matches.manage`.
- Both routes write to the same `ContactMatch` model. The model itself does not enforce a permission gate.
- A user with buyer-pipeline access can edit wishlists from the buyer pipeline UI without also holding `core_matches.manage`; conversely, a user with only `core_matches.manage` cannot use the buyer-pipeline route.

### D9. updated_by_user_id

- Add `updated_by_user_id` (foreign key to `users`, nullable, `nullOnDelete`) to `contact_matches`.
- Set on every update via a model observer.
- `last_engaged_at` remains the buyer-side engagement timestamp (set when a client reacts on the public share page); `updated_by_user_id` is the agent-side audit signal (who last edited).

### D10. Form unification

- Extract the inline Core Matches form (currently inline in `resources/views/corex/contacts/show.blade.php` lines 1055–1217) to a Blade partial at `resources/views/corex/contacts/_match-form.blade.php`.
- Both the contact-page tab and the buyer-pipeline tab `@include` the same partial.
- Single source of truth for the wishlist form.

### D11. `buyer_preferences` deprecation — two-phase

**Phase 1 (this release, this spec):**
- Migrate all data to `contact_matches` and `contacts`.
- Refactor all callers per unification audit Section D2.
- Leave the `buyer_preferences` table in place but empty.
- Add a boot-time deprecation listener (`DB::listen()` or equivalent) that writes a `WARNING` to `storage/logs/laravel.log` whenever any query reads from or writes to `buyer_preferences`. This catches any missed caller.

**Phase 2 (≥30 days later, follow-up ticket, NOT in this spec's build sequence):**
- Drop the `buyer_preferences` table in a follow-up migration once Phase 1 listener has run 30+ days with zero warnings in production logs.
- This spec only notes the existence of the follow-up; the build sequence ends at Phase 1.

### D12. The 2 existing `contact_matches` rows

- **Row 1 (id=1, contact_id=2, all criteria NULL):** delete at the start of the data migration. It is test garbage that would otherwise score 100 against every property.
- **Row 2 (id=2, contact_id=24, has category=Residential + price band R750k–R1.5M):** keep.
- Migration must check whether contact_id=2 or contact_id=24 have rows in `buyer_preferences`:
  - If contact_id=2 has a `buyer_preferences` row → migrate it normally after deleting the empty contact_matches row (becomes the contact's primary).
  - If contact_id=24 has a `buyer_preferences` row → append a new ContactMatch (per D1 cardinality). The richer of the two rows is flagged `is_primary = true`. Migration logs the decision; Johan reviews after the dry-run before commit.

---

## Section 4 — Schema Changes

This section describes the SQL-shape of every schema change. Literal migration code is produced in Build Prompt 01.

### 4.1 `contacts` — add preapproval block (per D3)

| Column | Type | Nullable | Default | Index | FK | Backfill | Reason |
|--------|------|----------|---------|-------|----|---------| -------|
| `preapproval_amount` | decimal(14,2) | YES | NULL | — | — | none in schema migration; populated by data migration (Prompt 08) from `buyer_preferences.preapproval_amount` for the 11 contacts that have it | D3 |
| `preapproval_expires_at` | date | YES | NULL | — | — | data migration from `buyer_preferences.preapproval_expires_at` | D3 |
| `preapproval_institution` | varchar(100) | YES | NULL | — | — | data migration from `buyer_preferences.preapproval_institution` | D3 |

No index needed at this stage. If demand reporting becomes slow, a future migration can add `(preapproval_amount, preapproval_expires_at)` for the "pre-approved & not expired" filter.

### 4.2 `contact_matches` — add unification fields (per D1, D2, D4, D5, D9)

| Column | Type | Nullable | Default | Index | FK | Backfill | Reason |
|--------|------|----------|---------|-------|----|---------| -------|
| `is_primary` | boolean | NO | FALSE | `cm_contact_primary_idx` (contact_id, is_primary) | — | for every existing row, set `is_primary = TRUE` for the most-recently-updated row per `contact_id` (with 2 existing rows on 2 distinct contacts, both become primary) | D1 |
| `property_types` | json | YES | NULL | — | — | for every row with non-null `property_type`, set `property_types = JSON_ARRAY(property_type)`. With audit data this affects zero rows. | D2 |
| `bedrooms_max` | unsignedTinyInteger | YES | NULL | — | — | none in schema migration; populated by data migration from `buyer_preferences.bedrooms_max` (30 rows) | D4 |
| `deal_breakers` | json | YES | NULL | — | — | data migration from `buyer_preferences.deal_breakers` (30 rows) | D5 |
| `updated_by_user_id` | bigint unsigned | YES | NULL | — | `users(id)` `nullOnDelete` | data migration sets to source `buyer_preferences.updated_by_user_id` where present, else to designated system user (Section 8) | D9 |

`property_type` (the legacy single-string column) is **not dropped** in this release per D2.

### 4.3 `prospecting_buyer_matches` — close the multi-tenancy gap

| Column | Type | Nullable | Default | Index | FK | Backfill | Reason |
|--------|------|----------|---------|-------|----|---------| -------|
| `agency_id` | bigint unsigned | NO (after backfill) | — | `pbm_agency_contact_idx` (agency_id, contact_id), `pbm_agency_listing_idx` (agency_id, prospecting_listing_id) | `agencies(id)` `cascadeOnDelete` | UPDATE pbm INNER JOIN contacts c ON c.id=pbm.contact_id SET pbm.agency_id = c.agency_id (~30,121 rows). Verify zero NULLs before NOT NULL. | unification audit Gap 6 |

A new Eloquent model `ProspectingBuyerMatch` is created in Build Prompt 02, with the `BelongsToAgency` trait — this is what makes the tenancy fix structural rather than per-query.

### 4.4 `property_buyer_matches` — same fix

| Column | Type | Nullable | Default | Index | FK | Backfill | Reason |
|--------|------|----------|---------|-------|----|---------| -------|
| `agency_id` | bigint unsigned | NO (after backfill) | — | `pbm2_agency_contact_idx` (agency_id, contact_id), `pbm2_agency_property_idx` (agency_id, property_id) | `agencies(id)` `cascadeOnDelete` | same pattern, smaller row count (audit reported 3 rows) | unification audit Gap 6 |

New Eloquent model `PropertyBuyerMatch` with `BelongsToAgency` in Build Prompt 02.

### 4.5 New table — `wishlist_migration_log` (one-off)

Created in Build Prompt 07 (dry-run command) and used by Build Prompt 08 (data migration). Schema:

| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigint PK | |
| `source_buyer_preference_id` | bigint unsigned | source row |
| `target_contact_match_id` | bigint unsigned, nullable | created row (null if dry-run) |
| `contact_id` | bigint unsigned | for traceability |
| `agency_id` | bigint unsigned | derived from contact |
| `action` | enum('would_create', 'created', 'would_append', 'appended', 'would_merge', 'merged', 'skipped', 'failed') | dry-run uses `would_*` variants |
| `notes` | text, nullable | per-row decisions, mismatches, warnings |
| `created_at` | timestamp | |

This table is preserved through Phase 2 deprecation as an audit record. Not soft-deleted.

---

## Section 5 — Model Changes

### 5.1 `app/Models/Contact.php`

Per D3:

- Add to `$fillable`: `preapproval_amount`, `preapproval_expires_at`, `preapproval_institution`.
- Add to `$casts`: `preapproval_amount` → `decimal:2`, `preapproval_expires_at` → `date`.
- Add accessor `hasValidPreapproval()`: returns true iff `preapproval_amount` is set AND `preapproval_expires_at >= today`.
- No new relationships (preapproval lives on the row itself).
- No new global scopes (Contact already has `ContactScope` via existing setup).

### 5.2 `app/Models/ContactMatch.php`

Per D1, D2, D4, D5, D9:

- Add to `$fillable`: `is_primary`, `property_types`, `bedrooms_max`, `deal_breakers`, `updated_by_user_id`.
- Add to `$casts`: `is_primary` → `boolean`, `property_types` → `array`, `bedrooms_max` → `integer`, `deal_breakers` → `array`.
- Add relationship `updatedBy()` → BelongsTo User via `updated_by_user_id`.
- Add local scope `scopePrimary(Builder $q)` → `$q->where('is_primary', true)`.
- Add helper method `setAsPrimary()`:
  - Demotes all other non-soft-deleted ContactMatches for the same contact (`is_primary = false`).
  - Promotes this row (`is_primary = true`).
  - Wraps in DB transaction.
- Add helper method `propertyTypeList()`: returns `$this->property_types ?? ($this->property_type ? [$this->property_type] : [])` — the fallback read per D2.
- Add observer registration (see 5.3).
- Add `// TODO(matcher-unification): see backlog ticket` comment at top of file per D6.

### 5.3 New observer — `app/Observers/ContactMatchObserver.php`

Registered in `app/Providers/AppServiceProvider::boot()` (or `EventServiceProvider`). Handles:

- `creating(ContactMatch $m)`:
  - If this is the first ContactMatch for `$m->contact_id` (count of non-soft-deleted siblings = 0), set `is_primary = true`. Per D1.
  - Set `updated_by_user_id = Auth::id()` if not already set and a user is authenticated.
- `updating(ContactMatch $m)`:
  - Set `updated_by_user_id = Auth::id()` if a user is authenticated. Per D9.
- `saved(ContactMatch $m)`:
  - If `is_primary` was just set to `true`, demote all other non-soft-deleted siblings for the same contact. Per D1 uniqueness.
- `deleted(ContactMatch $m)` (fires on soft-delete):
  - If the deleted row was `is_primary`, find the next-most-recently-updated non-soft-deleted sibling for the contact and promote it. Per D1.
  - If no siblings remain, no action.

The observer wraps multi-row updates in transactions to avoid races.

### 5.4 New model — `app/Models/ProspectingBuyerMatch.php`

Created in Build Prompt 02. Per Section 4.3:

- Table: `prospecting_buyer_matches`.
- Traits: `BelongsToAgency` — registers `AgencyScope` globally and auto-fills `agency_id` on create from `Auth::user()->effectiveAgencyId()`.
- `$fillable`: all the columns audit found (`prospecting_listing_id`, `contact_id`, `agency_id`, `score`, `tier`, `matched_features`, `missing_features`, `matched_at`, `last_recompute_at`, `agent_notified_at`, `dismissed_at`, `dismissed_by_user_id`).
- `$casts`: `score` → `integer`, `matched_features` → `array`, `missing_features` → `array`, `matched_at` / `last_recompute_at` / `agent_notified_at` / `dismissed_at` → `datetime`.
- Relationships: `listing()` → BelongsTo ProspectingListing, `contact()` → BelongsTo Contact, `dismissedBy()` → BelongsTo User.
- No SoftDeletes (matches are computed artefacts; regeneration replaces them).

### 5.5 New model — `app/Models/PropertyBuyerMatch.php`

Created in Build Prompt 02. Same pattern as 5.4 but bound to `property_buyer_matches`:

- Table: `property_buyer_matches`.
- Traits: `BelongsToAgency`.
- `$fillable`: `property_id`, `contact_id`, `agency_id`, `score`, `tier`, `breakdown`, `missing_features`, `computed_at`.
- `$casts`: `score` → `integer`, `breakdown` → `array`, `missing_features` → `array`, `computed_at` → `datetime`.
- Relationships: `property()` → BelongsTo Property, `contact()` → BelongsTo Contact.

---

## Section 6 — UI Changes

### 6.1 `resources/views/corex/contacts/_match-form.blade.php` — new partial (per D10)

Extracted from `corex/contacts/show.blade.php` lines 1055–1217. Receives `$contact` and (optionally) `$match` (null for create).

**Form field list (in display order):**

| Field | Input type | New / existing | Server-side validation |
|-------|------------|----------------|------------------------|
| `listing_type` | radio (sale / rental) | existing | `required|in:sale,rental` |
| `name` | text | existing | `nullable|string|max:120` |
| `is_primary` | checkbox | **new** | `nullable|boolean` — UI only shows when there are siblings |
| `category` | select | existing | `nullable|string|max:100` |
| `property_types` | **multi-select chips** | **new (replaces single property_type select per D2)** | `nullable|array`, each item `string|max:100` |
| `suburbs` | multi-suburb chip selector | existing | `nullable|array` of `max:150` strings |
| `price_min`, `price_max` | number (step 50000) | existing | `nullable|integer|min:0` |
| `beds_min` | number (max 20) | existing | `nullable|integer|min:0|max:20` |
| `bedrooms_max` | **number (max 20)** | **new (per D4)** | `nullable|integer|min:0|max:20`, plus rule that `bedrooms_max >= beds_min` when both present |
| `baths_min`, `garages_min`, `parking_min` | number (max 20) | existing | same |
| `floor_size_min/max`, `erf_size_min/max` | number | existing | same |
| `must_have_features` | chip selector | existing (replaces feat_pool/feat_furnished/feat_pets selects) | `nullable|array` of `max:60` strings |
| `nice_to_have_features` | chip selector | existing | same |
| `deal_breakers` | **chip selector** | **new (per D5)** | `nullable|array` of `max:60` strings |
| `notes` | textarea (max 500) | existing | `nullable|string|max:500` |

Validation lives in `ContactMatchController::validatePayload()` (existing method), extended.

### 6.2 `resources/views/corex/contacts/show.blade.php`

Lines 1055–1217 replaced with `@include('corex.contacts._match-form', ['contact' => $contact, 'match' => null])`. Lines 1221–1332 (the list of existing matches) remain unchanged. The list block additionally renders an `is_primary` badge on whichever row is flagged primary, and an "Make primary" action on non-primary rows.

### 6.3 `resources/views/command-center/buyers/detail.blade.php`

Per D10 + D11:

- The current **Preferences tab** (lines 234–300+) is removed.
- Replaced by a **Wishlists tab** that:
  - Lists all `ContactMatch` rows for this contact (newest first; primary first within ties).
  - Shows `is_primary` badge, name, listing_type, price band, primary suburbs, status.
  - Each row has an Edit link that opens a drawer/modal containing `@include('corex.contacts._match-form', ['contact' => $contact, 'match' => $match])`.
  - "+ Add another wishlist" button opens the same drawer with `match = null`.
- The existing read-only auto-derived patterns block (avg viewed price, viewing intensity, top areas) is preserved as a sidebar widget on the same tab.

### 6.4 Preapproval inputs on the Contact detail page

Per D3, preapproval is contact-level data. The three new inputs (`preapproval_amount`, `preapproval_expires_at`, `preapproval_institution`) live in the **existing Buyer/Finance section** of `resources/views/command-center/buyers/detail.blade.php` (or `resources/views/corex/contacts/show.blade.php` — Build Prompt 04 confirms the exact placement after re-reading the current views and picking the section that most closely matches "financial position").

Validation: `nullable|numeric|min:0` for amount; `nullable|date` for expiry; `nullable|string|max:100` for institution.

### 6.5 Sidebar / navigation

No sidebar changes needed — Core Matches already has its sidebar entry (`/core-matches`) per `.ai/specs/matches.md` Section 11. The buyer pipeline page retains its existing entry point.

---

## Section 7 — Controller / Service Changes

For every file in the unification audit Section D2, this section spells out the required behaviour change. Build Prompts 05 (PropertyMatchScoringService) and 06 (all other callers) implement these.

| File | Current behaviour | Required behaviour | Change type |
|------|-------------------|--------------------|-------------|
| `app/Services/PropertyMatchScoringService.php` | Reads `buyer_preferences` rows directly via raw DB queries. Writes `prospecting_buyer_matches` and `property_buyer_matches`. | Read from `ContactMatch` model (where `is_primary=true` for aggregation methods; all active ContactMatches for buyer-scoped recompute methods). Map field names: `budget_min/max` → `price_min/max`, `preferred_areas` → `suburbs`, `preferred_property_types` → use `propertyTypeList()` helper (which handles `property_types` fallback to `property_type`). Implement `bedrooms_max` hard filter. Implement `deal_breakers` hard exclusion. Read preapproval from `contact_match->contact->preapproval_*`. Add `// TODO(matcher-unification)` comment at top per D6. | Major rewrite |
| `app/Http/Controllers/CommandCenter/BuyerDetailController.php` | `savePreferences()` writes to `buyer_preferences` via raw `DB::table()->updateOrInsert()`. | Method renamed to `saveWishlist`. Writes to a `ContactMatch` via the model (`$contact->matches()->create(...)` or `$match->update(...)`). Separately writes preapproval fields to the `Contact` model. Drops all raw `DB::table()` calls. Permission gate stays buyer-pipeline (per D8). | Rewrite of one method |
| `app/Http/Controllers/BuyerPortalController.php` line 34 | Calls `PropertyMatchScoringService::getMatchesForBuyer($contactId)`. | Calls `MatchingService::propertiesForMatch($contact->primaryMatch())` instead. The primary match is fetched via the new `scopePrimary` scope. | Targeted patch |
| `app/Http/Controllers/Presentation/PresentationController.php` line 332 | Calls `getBuyerDemandForProperty($propertyId, $agencyId)` which reads `buyer_preferences`. | Refactored service method reads from `ContactMatch::primary()` rows joined with `Contact` (for preapproval data). No call-site change required beyond ensuring the service is refactored first. | Verify after Prompt 05 |
| `app/Http/Controllers/Presentation/PresentationSnapshotController.php` | Same shape if it consumes demand data. | Same — verify after Prompt 05. | Verify |
| `app/Http/Controllers/ProspectingController.php` | Reads `prospecting_buyer_matches` derived data for buyer-match counts on the listing list page. | No change to read path (the match table is the same). Empty-state display added per D7 for the regeneration window. | Targeted patch (empty state only) |
| `app/Observers/ProspectingListingObserver.php` line 45 | Calls `PropertyMatchScoringService::recomputeProspectingMatches($listingId)` synchronously on create/update. | Same call — the service signature does not change, only its internal data source. Confirm after Prompt 05 that the observer still passes its tests. | Verify |
| `app/Services/BuyerIntelligenceService.php` line 163 | Reads `buyer_preferences` directly. | Reads `ContactMatch::primary()` for the contact. | Targeted patch |
| `app/Console/Commands/RecomputePropertyMatches.php` | Iterates `buyer_preferences` rows. | Iterates `ContactMatch::active()` rows (or `ContactMatch::primary()` if Johan prefers — flagged for Build Prompt 06). | Targeted patch |
| `app/Console/Commands/RecomputeProspectingMatches.php` | Same as above for prospecting. | Same refactor. | Targeted patch |
| `app/Console/Commands/DemoCleanup.php` | Cleans `buyer_preferences`. | Drop the buyer_preferences cleanup block once the table is empty (Phase 1). Phase 2 removes the table entirely. | Targeted patch |
| `database/seeders/DemoDataSeeder.php` lines 33, 41 | `seedBuyerPreferences()` creates 31 buyer_preferences rows; `Artisan::call('prospecting:recompute-matches')` populates 30,121 matches. | `seedBuyerPreferences()` renamed `seedContactMatchesWithPreapproval()`. Writes ContactMatch rows (one primary per contact) AND populates preapproval fields on Contact. Same downstream `Artisan::call('prospecting:recompute-matches')`. | Rewrite of one method |

**Other call sites discovered during Build Prompt 06 must be added to this list** rather than silently fixed. Update this spec before the build prompt is marked done.

---

## Section 8 — Data Migration

Implemented in **Build Prompt 07 (dry-run)** and **Build Prompt 08 (writes)**. This section is pseudocode only.

### 8.1 Pre-migration steps

1. **Snapshot.** Before any writes, snapshot `buyer_preferences`, `contact_matches`, `prospecting_buyer_matches`, `property_buyer_matches`, and `contacts` (the rows about to gain preapproval data). Snapshot via `mysqldump --tables` to a timestamped file in `storage/backups/wishlist-migration/`. Retained for at least 30 days per Section 12 rollback plan.
2. **Dry-run command.** `php artisan wishlist:migrate-dry-run`:
   - Reads all `buyer_preferences` rows.
   - Computes what would happen per row (the action enum from Section 4.5).
   - Writes findings to `wishlist_migration_log` with `would_*` actions.
   - Writes ZERO data to `contacts` or `contact_matches`.
   - Reports a summary: total rows, would-create count, would-append count, would-merge count, would-skip count, would-fail count.
   - Johan reviews the log before approving the live run.

### 8.2 Live migration order (Build Prompt 08)

```
BEGIN TRANSACTION (where feasible — preapproval writes, contact_matches writes can be transactional;
                   delete of empty row 1 separate)

Step 1 — Delete ContactMatch id=1 (per D12).
    Soft-delete via the model so it's recoverable.
    Log: { action: 'cleanup_empty_row1', contact_id: 2 }

Step 2 — For each buyer_preferences row:
    contact = Contact::find(row.contact_id)
    if contact is null:
        log('skipped', reason='orphan contact') and continue (audit reports zero orphans, but guard anyway)

    # Preapproval block → write to contacts
    if row has any of preapproval_amount / _expires_at / _institution:
        contact.preapproval_amount     = row.preapproval_amount
        contact.preapproval_expires_at = row.preapproval_expires_at
        contact.preapproval_institution = row.preapproval_institution
        contact.save()

    # Decide ContactMatch action per D1 + D12
    existing_matches = ContactMatch::where('contact_id', contact.id)->withTrashed()->get()

    if existing_matches.isEmpty():
        action = 'create_primary'
    elif contact.id == 24 and existing_matches has the kept row 2:
        # D12: append a non-primary alongside row 2.
        # Compare richness of row 2 vs the buyer_preferences row.
        # If preferences row is richer (more non-null criteria), it becomes primary
        # and row 2 is demoted. Otherwise preferences row is appended as non-primary.
        action = 'append_choose_primary'
    else:
        # Catch-all: append; first ContactMatch wins primary, or recently-updated wins per D1.
        # Existing-row-without-criteria already deleted in Step 1 if it's contact_id=2.
        action = 'append_demote_or_keep'

    # Map fields per the unification audit Section D1 mapping table
    new_match = ContactMatch::create({
        agency_id:            contact.agency_id,
        contact_id:           contact.id,
        created_by_user_id:   row.updated_by_user_id ?? SYSTEM_USER_ID,
        updated_by_user_id:   row.updated_by_user_id ?? SYSTEM_USER_ID,
        name:                 null,
        status:               'active',
        listing_type:         'sale',
        category:             null,
        property_type:        (property_types[0] if exactly one, else null),
        property_types:       row.preferred_property_types (json passthrough),
        price_min:            (int) row.budget_min,
        price_max:            (int) row.budget_max,
        beds_min:             row.bedrooms_min,
        bedrooms_max:         row.bedrooms_max,
        baths_min:            null,
        garages_min:          null, parking_min: null,
        floor_size_min/max:   null, erf_size_min/max: null,
        suburb:               null,
        suburbs:              row.preferred_areas,
        must_have_features:   row.must_have_features,
        nice_to_have_features: null,
        deal_breakers:        row.deal_breakers,
        notes:                null,
        is_primary:           (decided per action above)
    })

    # Observer fires — handles is_primary uniqueness automatically.

    Log every row to wishlist_migration_log with action='created'/'appended'/'merged' + the new_match.id.

Step 3 — After all rows processed:
    Verify counts: buyer_preferences row count == wishlist_migration_log non-failed rows.
    Verify every migrated contact has exactly one ContactMatch with is_primary=true.
    Verify the 11 contacts with preapproval data have their contacts.preapproval_amount populated.
    Verify zero buyer_preferences rows remain by TRUNCATEing them (or DELETEing) only after verification passes.

Step 4 — TRUNCATE buyer_preferences (Phase 1 keeps the empty table; the listener catches stragglers).

COMMIT.
```

### 8.3 System user for `created_by_user_id` / `updated_by_user_id`

A dedicated system user with email `system@corexos.co.za` is used as the audit signal for migration-created rows. If this user does not yet exist, Build Prompt 08 creates it first (one-off seed). It has role `system` (no login permission). This avoids attributing migrated rows to an arbitrary real agent.

### 8.4 Rollback (data migration only — schema rollback covered in Section 12)

If the live migration fails partway:
1. Restore `buyer_preferences` from snapshot.
2. Restore `contacts` preapproval columns to NULL where the snapshot had NULL.
3. Soft-delete every `ContactMatch` whose `wishlist_migration_log.action ∈ ('created', 'appended')` for the current run. The log is the audit trail.
4. Restore ContactMatch id=1 from snapshot if it was deleted in Step 1.
5. Investigate failure. Do not retry until root cause is fixed.

---

## Section 9 — Match Regeneration

Implemented in **Build Prompt 09**.

### 9.1 `RegenerateBuyerMatchesJob`

- Queued job (default queue), dispatched once at the end of Build Prompt 08's data migration AND available as a manual command (`php artisan wishlist:regenerate-matches`).
- Steps:
  1. Write audit log entry: `RegenerateBuyerMatchesJob::started` with timestamp + dispatcher.
  2. TRUNCATE `prospecting_buyer_matches` and `property_buyer_matches`.
  3. Loop all `ContactMatch::primary()->active()` rows:
     - Call `PropertyMatchScoringService::recomputeForBuyer($match->contact_id)` (writes property_buyer_matches).
     - Call `PropertyMatchScoringService::recomputeProspectingMatchesForBuyer($match->contact_id)` (writes prospecting_buyer_matches).
  4. Write audit log entry: `RegenerateBuyerMatchesJob::finished` with timestamp, row counts written, duration.
- **Idempotent.** Running twice in succession produces the same final state.
- **Estimated duration** based on audit data: 31 buyers × 983 prospecting listings = ~30k score computations, target ≤2 minutes on dev hardware. Production estimate documented during Prompt 09 implementation.

### 9.2 Empty-state UI during regeneration (per D7)

While the job is in-flight (detectable via cached `corex.matches.regenerating` flag set by the job's `started` handler and cleared by `finished`), `ProspectingController@index` and the buyer pipeline Wishlists tab display a banner: "Rebuilding buyer matches — refresh in a few minutes." No 500s.

### 9.3 Audit logging

Confirm during Build Prompt 09 that an `audit_logs` table or equivalent pattern exists per STANDARDS.md. If it does not, Build Prompt 09 adds it.

---

## Section 10 — Deprecation Mechanism for `buyer_preferences`

Implemented in **Build Prompt 10**.

### 10.1 Phase 1 — boot-time listener

In `app/Providers/AppServiceProvider::boot()` (or a dedicated `DeprecationServiceProvider` if STANDARDS.md prefers separation):

```php
DB::listen(function ($query) {
    if (str_contains($query->sql, 'buyer_preferences')) {
        Log::warning('DEPRECATED: query touched buyer_preferences table', [
            'sql'      => $query->sql,
            'bindings' => $query->bindings,
            'time_ms'  => $query->time,
            'caller'   => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8),
        ]);
    }
});
```

- Logs to `storage/logs/laravel.log` at WARNING level.
- Captures a short call stack so the missed caller is identifiable.
- Listener registration is conditional on `config('corex.deprecation.buyer_preferences_listener', true)` so it can be disabled in production if it generates noise (default: enabled).

### 10.2 Phase 2 (out of scope for this spec — follow-up ticket)

≥30 days after Phase 1 ships, if production logs show zero `WARNING: DEPRECATED: query touched buyer_preferences` entries:
- New migration drops `buyer_preferences` table.
- Removes the DB::listen() block.
- Tracked separately; not in this spec's build sequence.

---

## Section 11 — Build Prompt Sequence

Each prompt in the sequence MUST start with the standard CLAUDE.md + STANDARDS.md + this-spec read and end with `php -l`, `view:clear`, `dev-check.ps1`, Tinker verification. Each prompt is run only after the previous one is reviewed by Johan.

| # | Prompt | One-line summary | Success criteria |
|---|--------|------------------|------------------|
| 01 | **Schema migrations** | 4 migrations: preapproval on contacts; is_primary/property_types/bedrooms_max/deal_breakers/updated_by_user_id on contact_matches; agency_id on prospecting_buyer_matches and property_buyer_matches | All 10 post-migration Tinker checks pass; rollback proven; 0 NULL agency_ids; exactly 1 is_primary per contact |
| 02 | **Model updates** | Contact + ContactMatch fillable/casts/scopes; new ProspectingBuyerMatch + PropertyBuyerMatch models with BelongsToAgency; new ContactMatchObserver registered | Models read/write the new columns; observer enforces is_primary uniqueness in unit test; new match models scope by agency in Tinker |
| 03 | **`_match-form.blade.php` extraction (refactor only)** | Move lines 1055–1217 from corex/contacts/show.blade.php into the partial; show.blade.php @includes it; no behaviour change | Contact page renders identically before/after; existing match form submission still works |
| 04 | **Match form field additions** | Add bedrooms_max, deal_breakers chips, property_types multi-select chips to the partial; preapproval inputs on Contact detail page | Form posts successfully with new fields; validation rules enforced; existing matches editable without losing data |
| 05 | **`PropertyMatchScoringService` refactor** | Read from ContactMatch instead of buyer_preferences; implement property_types fallback, bedrooms_max hard filter, deal_breakers hard exclusion; read preapproval from contact relation; add TODO(matcher-unification) | Service unit-tested against ContactMatch input; existing recompute commands still produce non-empty results; no buyer_preferences references remain |
| 06 | **All caller refactors** | BuyerDetailController, BuyerPortalController, Presentation controllers, BuyerIntelligenceService, console commands, observer, seeder | Grep for "buyer_preferences" returns only the deprecation listener (Prompt 10) and the data-migration command (Prompts 07/08) |
| 07 | **Data migration dry-run command** | `php artisan wishlist:migrate-dry-run` — reads buyer_preferences, writes ZERO data, populates wishlist_migration_log with `would_*` actions; reports summary | Dry-run output reviewed by Johan; expected 31 rows logged, expected 11 preapproval blocks identified, decisions for contact_id=2 and contact_id=24 explicit |
| 08 | **Data migration command** | `php artisan wishlist:migrate` — executes the migration in transaction; verifies counts; TRUNCATEs buyer_preferences only on success | All 31 rows migrated; preapproval block populated on 11 contacts; ContactMatch id=1 soft-deleted; exactly one is_primary per migrated contact; wishlist_migration_log fully populated |
| 09 | **`RegenerateBuyerMatchesJob` + queue dispatch + audit logging** | Queued job that TRUNCATEs match tables and rebuilds from ContactMatch; audit-logged; empty-state banner wired up | After dispatch, both match tables repopulated; row counts match pre-migration baseline ±10% (the gap accounts for stricter scoring with bedrooms_max/deal_breakers); no 500s during run |
| 10 | **`buyer_preferences` deprecation listener** | DB::listen() block in AppServiceProvider; config flag; log-level WARNING | After deploy, deliberately running `DB::table('buyer_preferences')->count()` from Tinker triggers a WARNING in laravel.log |
| 11 | **Buyer pipeline Wishlists tab** | Replace Preferences tab in command-center/buyers/detail.blade.php; list ContactMatch rows; add/edit drawer using `_match-form.blade.php`; preserve auto-derived patterns sidebar | All 31 migrated contacts show their primary wishlist; add-another-wishlist creates non-primary; edit-from-drawer updates the row in place |
| 12 | **End-to-end smoke + validation (investigation only)** | Read-only audit: confirm all 31 contacts migrated, both match tables populated, no buyer_preferences WARNINGs over a 24h sample, both UIs functional, all 12 acceptance criteria checked | Final report saved to `.ai/audits/2026-MM-DD-wishlist-unification-postmigration-audit.md` |

---

## Section 12 — Rollback Plan

Each prompt has a rollback procedure documented in its own prompt-write. This section covers cross-prompt rollback strategy.

### 12.1 Per-prompt rollback

| Prompt | Rollback procedure |
|--------|-------------------|
| 01 | `php artisan migrate:rollback --step=4`. Schema returns to pre-migration state. Already proven in Prompt 01's own verification step. |
| 02 | `git revert` the commit. Models are pure code; no data state to unwind. |
| 03 | `git revert`. Behaviour-preserving refactor, no data impact. |
| 04 | `git revert`. UI-only changes; no data writes from this prompt. |
| 05 | `git revert`. Service refactor; the match tables still hold the most recent computed state. Re-run the old code path against the new schema is not guaranteed safe, so accompany the revert with a Prompt 09 regeneration. |
| 06 | `git revert` per file. Watch for downstream callers (run Prompt 12 smoke as the verification). |
| 07 | Dry-run writes only to `wishlist_migration_log`. Truncate that table to roll back. |
| 08 | Restore from snapshot (Section 8.1). Soft-delete every ContactMatch with a `wishlist_migration_log` entry from the failed run. Restore `buyer_preferences` from dump. Restore `contacts.preapproval_*` to snapshot values. |
| 09 | TRUNCATE match tables. The job is idempotent so re-running it after fixing the root cause is the forward path. Rollback per se = wait for the previous regeneration to finish, then accept the empty state until re-run. |
| 10 | `git revert` the listener registration. No data state. |
| 11 | `git revert`. UI is replaced; underlying data unaffected. |
| 12 | Read-only; nothing to roll back. |

### 12.2 Database backup retention

- `mysqldump` snapshots from Section 8.1 retained ≥30 days in `storage/backups/wishlist-migration/`.
- Once Phase 2 (table drop) ships and 7 days have passed with zero rollback requests, snapshots can be archived to cold storage.

### 12.3 Tinker rollback verification

After any rollback that touches data:

```
DB::table('buyer_preferences')->count();          // should match snapshot
DB::table('contact_matches')->withTrashed()->count();   // should match snapshot
DB::table('contacts')->whereNotNull('preapproval_amount')->count();  // should match snapshot
DB::table('prospecting_buyer_matches')->count();  // accept regeneration delta
DB::table('property_buyer_matches')->count();     // accept regeneration delta
```

### 12.4 Decision tree — rollback vs roll-forward

| Symptom | Action |
|---------|--------|
| Migration fails partway in Prompt 01 | Rollback (`migrate:rollback`). Fix the migration. Re-apply. |
| Data migration fails partway in Prompt 08 | Rollback per 12.1 #08. Fix the migration command. Re-run dry-run. Then re-run live. |
| Post-migration data quality check fails (e.g. an existing contact has zero primary wishlists) | Roll-forward via a targeted UPDATE if isolated; rollback if systemic. Johan decides. |
| New caller of `buyer_preferences` discovered after Phase 1 ships | Roll-forward: refactor the caller. The listener catches it; no data rollback needed. |
| `RegenerateBuyerMatchesJob` produces zero rows | Investigate the service; do NOT rollback the schema. The match tables were already truncated; either fix and re-run, or run the legacy seeder against the new schema as a temporary stop-gap. |
| Prompt 11 UI ships and the Wishlists tab is broken | `git revert` Prompt 11. The data model is unaffected. Ship the fix as a Prompt 11.1. |

---

## Section 13 — Acceptance Criteria

The spec is shipped (Phase 1 complete) when every item below is verifiable:

1. All 31 `buyer_preferences` rows successfully migrated to `contact_matches` + `contacts.preapproval_*`. Verified via `wishlist_migration_log` having 31 rows with `action IN ('created','appended','merged')`.
2. `buyer_preferences` table has 0 rows.
3. Every `contact_matches` row has non-null `agency_id` (already enforced by AgencyScope auto-fill).
4. Every `prospecting_buyer_matches` row has non-null `agency_id` matching the contact's `agency_id` (verified by the Prompt 01 backfill).
5. Every `property_buyer_matches` row has non-null `agency_id` matching the contact's `agency_id`.
6. Every contact with at least one ContactMatch has exactly one row with `is_primary = true`.
7. The 11 contacts that had preapproval data in `buyer_preferences` have their `preapproval_amount`, `preapproval_expires_at`, and `preapproval_institution` populated on the `contacts` table.
8. The buyer pipeline Preferences tab no longer exists; a Wishlists tab in its place renders the contact's ContactMatch rows.
9. `_match-form.blade.php` exists at `resources/views/corex/contacts/_match-form.blade.php` and is `@include`d by both:
   - `resources/views/corex/contacts/show.blade.php` (the Core Matches tab)
   - `resources/views/command-center/buyers/detail.blade.php` (the Wishlists tab edit drawer)
10. The boot-time `buyer_preferences` deprecation listener logs zero WARNINGs over a representative 7-day production traffic window post-deploy.
11. The `// TODO(matcher-unification)` comment exists at the top of both `MatchingService.php` and `PropertyMatchScoringService.php`.
12. All 12 of Johan's decisions (D1–D12) are encoded in code, with a checklist mapping each decision to the file/line where it is enforced. The Prompt 12 smoke audit produces this checklist.

---

## Section 14 — Open Questions

After encoding D1–D12 and walking the build sequence, the following items remain open. They are not blockers for any of Prompts 01–10 but must be answered before the prompts that depend on them.

1. **Auditing pattern for `RegenerateBuyerMatchesJob` (Prompt 09).** STANDARDS.md does not name a canonical audit-log table. If `audit_logs` does not exist in the codebase, Prompt 09 needs to choose: create a generic `audit_logs` table, or write to Laravel's built-in log channel. Recommend the latter for this spec (channel: `wishlist-migration`), with a follow-up to introduce a structured audit table if more modules need it. Confirm at Prompt 09 time.

2. **Preapproval input placement (Prompt 04).** The spec says the three preapproval inputs live in the "existing Buyer/Finance section." Build Prompt 04 must inspect both `corex/contacts/show.blade.php` and `command-center/buyers/detail.blade.php` and pick the most semantically correct existing section. If no existing section fits, create a "Financial Position" block on the contact detail page. Surface the chosen placement in the Prompt 04 build report so Johan can sanity-check.

3. **`is_primary` flagging during data migration for contact_id=24 (D12).** The decision is "the richer of the two rows is flagged primary, the migration logs the decision, Johan reviews." Build Prompt 07's dry-run output must include a clear table showing both rows side-by-side so the decision is informed. Operational concern, not a spec concern.

4. **Console command target — `ContactMatch::active()` or `ContactMatch::primary()` (Section 7, `RecomputePropertyMatches`)?** Iterating all active matches gives finer-grained results but may produce stale duplicates if a buyer has multiple wishlists. Iterating only primary matches is faster and matches the aggregation default but loses the secondary wishlists' signal. Recommend Build Prompt 06 default to `active()` (preserve signal), with a flag `--primary-only` for the faster path. Confirm with Johan at Prompt 06 time.

---

**End of spec.**
