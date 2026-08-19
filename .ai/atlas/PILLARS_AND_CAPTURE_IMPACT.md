# Atlas — The Pillars & the Capture Impact Map

> **Status: DONE (facts-only impact map)** · Last verified: 2026-08-13
> Cross-cutting. Not a single feature — the **identity/data-flow map** for the two physical pillars
> (**Property**, **Contact**) and every capture path that creates or touches them today.
> Companions: `properties.md`, `contacts.md`, `prospecting-tracked-properties.md`, `market-intelligence.md`.
> **This doc states current reality and names collisions. It does NOT design fixes** — open decisions
> for Johan are listed at the end. Reverse-lookup entries added to `CROSS_REFERENCE.md`.

---

## 1. WHAT IT DOES

This entry maps the **identity rules and capture paths** for the Property and Contact pillars: what makes
two records "the same", which ingress paths create them, and where those paths **agree or diverge from the
governing principle**. It exists because property/contact data enters CoreX through many doors (manual
capture, MIC address-unlock, map T-pins, deeds capture, P24 imports, portal scrape) and those doors do not
share one identity spine today.

---

## 2. THE PILLARS — governing principle (Johan)

The intended law for the two physical pillars:

- **Property = ONE record.** Identity = **section + complex + street + erf + suburb**. One physical asset,
  one row. Everything CoreX learns about that asset compounds onto that single record.
- **Contact = ONE record.** Identity = **SA ID number**. One person, one row.
- **Resolve-and-refresh, never duplicate.** A new sighting of a known asset/person **updates** the existing
  record; it does not mint a second.
- **History compounds on the one record.** Deeds, valuations, outreach, sightings, sources all append to the
  single canonical row (audit-retained), never fork it.
- **Freshness ≠ identity.** Expiry / source / last-seen describe the *state* of a record; they are **not**
  part of what makes two records the same. Two sightings of the same asset with different freshness are still
  ONE asset.

The sections below record how close current code is to this law.

---

## 3. CURRENT REALITY vs THE PILLARS

### 3.1 Contact — pillar LARGELY ENFORCED (but agency-scoped)
- Model `App\Models\Contact`, table `contacts` (SoftDeletes, `ContactScope`/`BelongsToAgency`).
- Identity is enforced by **`App\Services\ContactDuplicateService`** — detection, not a hard `firstOrCreate`.
  Match fields are **configurable per agency** via `agency_contact_settings.duplicate_match_fields`, default
  **`[phone, email, id_number]`** (`ContactDuplicateService.php:38`), normalised before compare
  (`normalizeValue` ~:177): ZA phone → last-9 digits (`normalizePhone` ~:211), email → lowercased/trimmed,
  `id_number` → strip spaces/dashes. Matches both the mirror columns and the child tables
  `ContactPhone.phone_normalised` / `ContactEmail.email_normalised` (`findDuplicates` ~:71-76) and
  `findDuplicatesForIdentifiers(phones[], emails[], idNumber, agencyId)` (~:120-137).
- On a hit, `duplicate_mode` decides: `auto_link` / `soft_warn` (default) / `hard_block_override` /
  `hard_block_request` (`resolveMode` ~:140); attempts audited into `contact_duplicate_log` (`logAttempt` ~:158).
- **Gap vs pillar:** SA-ID is only ONE of three default keys (not the sole identity), and matching is
  **strictly agency-scoped — never cross-agency** (`findDuplicatesForIdentifiers` filters by `agencyId`).
  A person already held by another agency/agent is not seen as "the same" here.
- (`ContactMatch` is NOT contact identity — it is a buyer's saved-search/wishlist profile.)

### 3.2 Property (the `properties` stock table) — pillar NOT ENFORCED (core gap)
- Model `App\Models\Property`, table `properties`.
- **There is NO universal match-or-create for `properties`.** `external_id` is an auto-generated **surrogate
  UUID**, not a dedup key (self-healed `SoldPropertyImporter.php:215-218`). The **only** dedup on the stock
  table is the sold-listing importer, which match-or-creates on **`p24_listing_number` within the agency**
  (`SoldPropertyImporter.php:132-135`). Portal identity is tracked via `p24_ref` / `pp_ref`.
- **Outside those paths, `properties` rows are created freely** — manual capture (`Property::create`) and
  `promoteToStock()` enforce **no identity constraint** at the model level. `section + complex + street + erf
  + suburb` is **not** a key on `properties`. Deed number (`title_deed_number`) is stored but is **never** an
  identity key.

### 3.3 TrackedProperty — the DE-FACTO property-identity spine (identity lives HERE, not in `properties`)
- Model `App\Models\Prospecting\TrackedProperty`, table `tracked_properties`.
- **`App\Services\Prospecting\TrackedPropertyMatchOrCreateService::matchOrCreate()`** (`:83`) — first-match-wins,
  **spanning agencies** (`queryWithoutAgencyScope`), via `resolveMatch()` (`:~127`), a **6-strategy chain**:
  0. **Address-history** (`:~134`, `matchAddressHistory :~366`) — consults `tracked_property_addresses`: exact
     `street_number` + normalised `street_name` + `suburb_normalised` (`:~384-389`), or GPS ~5m on that history
     row (`:~405-411`).
  1. **Source-ref exact** (`:~147-152`) — `tracked_property_external_refs.(source, source_ref)`. Strongest.
  2. **GPS proximity ~5m** (`:~169-194`, tolerance const `:~51`) — `cma_gps_lat/lng` preferred, else `latitude/longitude`.
  3. **Erf + suburb** (`:~201-213`) — `erf_number` + `suburb_normalised`; treated as exact legal identity.
  4. **Normalised structured address** (`:~217-232`) — `street_number` + normalised `street_name` +
     `suburb_normalised`, vetoed by `numbersConflict()` (street/unit number as hard discriminator, `:~276`).
  5. **Token-overlap address** (`:~236-263`) — loose last resort; also number-conflict vetoed.
- **`title_deed_number` and `cadastral_extent` are stored/enriched but are NOT a match strategy**
  (`:~656-657, ~750-751`). Deed number does not drive TP identity — erf+suburb, source_ref, GPS, and
  normalised address do.
- **Consequence:** property identity **exists at the TrackedProperty layer, not at the `properties` layer.**
  `promoteToStock()` (TP → `properties`, `:~715-793`) can therefore **create a duplicate in `properties`**
  even though the TP was correctly deduped.

---

## 4. THE CAPTURE PATHS (how an address/contact enters today)

### 4.1 MIC address-unlock — TWO no-Property paths (the "landmine")
Capturing an address to unlock outreach **does not create a `properties` row**. Confirmed, two variants:
- **(a) Address-only pitch** — the pitched address is stored as **structured columns ON `contacts`**
  (`street_number/street_name/unit_number/complex_name/suburb/city/province/p24_*_id`, migration
  `2026_06_19_120000_add_structured_address_to_contacts_table.php`; these describe the *property being pitched*,
  distinct from the legacy free-text `contacts.address` = where the person lives). The composer picks the
  source with no Property: `SellerOutreachComposerService.php:57-59`
  (`$property ? OutreachAddress::fromProperty : OutreachAddress::fromContact`). The send row lands in
  **`seller_outreach_sends`** with **`property_id = NULL`** + `address_snapshot` / `suburb_snapshot`
  (`SellerOutreachSenderService.php:103,117-118`; migration `2026_06_19_090000_add_address_only_to_seller_outreach_sends.php`).
- **(b) Map T-pin pitch** — a Contact is captured and linked as owner on a **`tracked_properties`** row;
  **no Property is created** (comment `EntryPointController.php:565-571`). Only write is
  `tracked_properties.owner_contact_id` (`:657-659`; `storeFromTrackedProperty :572-673`; migration
  `2026_06_16_121000_add_tp_outreach_columns.php`). Address lives on `tracked_properties` + `tracked_property_addresses`.
- **Both paths are invisible to `properties` matching** — the captured address is on `contacts` or
  `tracked_properties`, never on `properties`.

### 4.2 What MIC / MI matching joins on
- **Prospect-collision** ("does HFC already hold this address"): `MapProspectStatusService` →
  `TrackedPropertyMatchOrCreateService::findExistingMatch()` (the 6-strategy chain), **then a `properties`
  GPS-proximity fallback** (`MapProspectStatusService.php:172-208`). No Contact join.
- **"IN STOCK" badging**: `OnMarketStockService` / `ProspectingStockMatchService::matchProspect()`
  (`:~33-46`) — joins `prospecting_listings.portal_ref` **OR** `normalized_address` → `properties`.
- **Buyer-demand** (the buyer side, e.g. `{matching_buyer_count}`): `MatchingService::propertiesForMatch()`
  (`:233`) / `matchesForProperty()` (`:193`) — scored per **(Property × `ContactMatch` wishlist)** pair;
  area counts by suburb/town geography (`SellerOutreachComposerService.php:288-299`).
- **Net:** MIC/outreach lead matching keys on **normalised address strings / GPS resolved to a
  `TrackedProperty`** (address as the entity), with a `properties` fallback — **not** on Contact records.

### 4.3 Deeds capture → promote
- **Deeds capture writes `tracked_properties`** via `matchOrCreate()/create()` (`:~525-549`): structured
  address, GPS (deeds-authoritative `cma_gps_lat/lng` kept separate from portal GPS), `erf_number`,
  `title_deed_number`, `cadastral_extent`, `capture_kind='deeds_capture'`, `deeds_office`, `scheme_name`,
  `scheme_number`, `section_number`, `bond_holder/amount`, `sale_type`, `deeds_registered_date`, municipal
  valuation, `owner_contact_id` (migration `2026_08_12_000001_add_deeds_capture_fields.php:24-38`). Source ref
  → **`tracked_property_external_refs`** (`writeExternalRef :~607-636`); append-only **`source_chain`** JSON
  audit (`buildSourceChainEntry :~638-646`); address-history row (`appendIngestedAddressToHistory :~436-523`).
  `promoted_to_property_id` stays NULL; unpromoted captures listed via `DeedsCaptureController.php:33`
  (`whereNull('promoted_to_property_id')`).
- **`promoteToStock()`** (`:~715-793`): creates a `properties` row (`draft`) copying address/GPS/erf/deed/
  valuation/type/beds/baths/garages/price (`:~735-766`); sets TP `promoted_to_property_id/promoted_at/
  status='promoted'` (`:~768-773`); fires `TrackedPropertyPromotedToStock` + `MandateConverted` (`:~775-789`).
  Deeds screen wrapper also links owner via the `contact_property` pivot (role=owner)
  (`DeedsCaptureController.php:41-67`, promote at `:53`). ⚠ The manual MIC entry path
  (`EntryPointController::storeFromProspecting`) does its **own inline** promotion instead of calling
  `promoteToStock()` (`EntryPointController.php:430-437`).

### 4.4 P24 stock — FOUR distinct stores (identity/freshness differ per store)
| Store | Table / model | What it is | Provenance | Freshness / staleness fields |
|-------|---------------|-----------|-----------|------------------------------|
| **A** | `properties` / `Property` (CSV importer → `ConfirmP24PropertyRowJob.php:119`) | Agency's **own onboarded stock** (go-live migration) | **No flat `source` column.** Only `compliance_snapshot_data` JSON `source='p24_go_live_migration'` + `p24_import_run_id` (`:133-134`) + `p24_listing_number` (`2026_04_14_000003:22`) / `p24_ref` (`2026_03_26_100001:17`). Staging: `p24_import_runs`, `p24_import_rows` (link `target_id :144`). | **WEAK** — only generic `status` (`2026_02_25_201319:36`), `published_at` (`:45`), `last_activity_at` (`2026_03_31_300011:12`), P24-sync stamps `p24_last_submitted_at`/`p24_activated_at`/`p24_listing_last_synced_at`/`p24_images_last_synced_at` (`2026_03_26_100001:18-22`). **No `off_market_at`, `expires_at`, `last_seen_at`, `imported_at`, `scraped_at`, or mandate-expiry column.** |
| **B** | `p24_listings` / `P24Listing` (`P24Listing.php:14`) | **IMAP alert / market archive** (the likely "~10 years" of *seen* history; not owned stock) | Whole table IS the P24 source; `p24_listing_number` (unique), `p24_url` (`2026_02_25_500001:13,23`) | `first_seen_date`, `last_seen_date`, `times_seen`, `listing_status='active'` (`2026_02_25_500001:24-27`). ⚠ **Invisible to scorers / never Match-or-Create** (per `prospecting-tracked-properties.md` AT-81 §1.4). |
| **C** | `prospecting_listings` / `ProspectingListing` (Chrome/MIC scrape) | Captured **competitor** stock | `portal_source` enum p24/pp (`2026_03_18_100000:15`), `portal_ref` (`:16`), `portal_url` (`:17`), `matched_property_id` → properties (`2026_05_12_105607`) | **RICHEST** — `first_seen_at`/`last_seen_at` (`:31-32`), `is_active` (`:34`), `portal_status`/`portal_status_changed_at`/`off_market_at` (`2026_08_21_000010:29-31`), `last_search_id` (`2026_08_21_000030:29`), `mandate_type` (`2026_08_21_000002`), `price_changed_at`. `FlagStaleProspectingListings` = 30-day window on `last_seen_at`. ⚠ nothing flips `is_active` back to false except that job → unseen rows read as live. |
| **D** | `tracked_properties` / `TrackedProperty` | Deduped address library | `source_chain` JSON, `last_enrichment_source` (`2026_05_14_170000:76,79`) | `first_seen_at`, `last_enriched_at` (`:77-78`), `status` active/archived/duplicate/promoted (`:86`) |

---

## 5. COLLISION POINTS (facts, not fixes)

1. **Property-pillar leak.** `properties` has **no identity constraint** while `tracked_properties` **does**
   (6-strategy match). Identity lives at the TP layer, not the stock layer → duplicates enter `properties` via
   **manual capture** and via **`promoteToStock()`** (which can mint a second `properties` row for an asset TP
   already knew). (§3.2, §3.3, §4.3)

2. **Address-unlock vs property.** The MIC address-unlock paths (§4.1) store the address on **`contacts`** or
   **`tracked_properties`**, never `properties`, and **never reconcile to a later deeds-created / promoted
   property**. Risk surface: double-booking the same asset, orphaned outreach (`seller_outreach_sends.property_id
   = NULL`), and anti-poaching / claim-lock keyed at a different layer than the eventual `properties` row.
   (Pitch-claim locks key on `property_id` per `mic-claim-property-key`; an unlock with no `properties` row sits
   outside that.)

3. **Contact ownership is agency-scoped only.** `ContactDuplicateService` never matches **cross-agency**
   (§3.1). The "this contact already belongs to another agent/agency" case is not detected by contact identity
   today.

4. **P24 freshness gap on owned stock.** Store **A (`properties`)** — the agency's own imported stock — has
   **no `last_seen`/`off_market`/`expiry` column** (§4.4). The proper freshness machinery (last-seen +
   off-market + 30-day stale job) exists only on **C (`prospecting_listings`)**, the *competitor* scrape.
   A stale imported P24 shell in `properties` is **not distinguishable from a live listing** by any queryable
   freshness field — only generic `status` / `last_activity_at`.

---

## 6. OPEN DECISIONS FOR JOHAN (list — not answered here)

1. **Property identity enforcement.** Enforce `section + complex + street + erf + suburb` identity **at the
   `properties` layer** (a real match-or-create / constraint on the stock table), **or** route **all** property
   creation (manual capture + promote + import) **through `TrackedProperty`** so the stock table can only ever
   be minted from an already-deduped TP? (Decides where the single-record law is enforced.)

2. **Address-unlock ↔ deeds/promoted-property reconciliation rule.** What is the rule that reconciles an
   MIC address-unlock capture (address on `contacts` / `tracked_properties`, `seller_outreach_sends.property_id
   = NULL`) to a `properties` row created **later** by deeds capture or promotion — so outreach, claims, and
   history land on the one asset record rather than fork?

3. **Freshness/expiry on owned `properties`.** Add a freshness/expiry signal (e.g. last-seen / off-market /
   source-stamp) to `properties` so a **stale imported P24 shell** can be told from a **live listing** — or keep
   freshness only on `prospecting_listings` and treat `properties` as manually curated?

4. **The `ingestOne` null-overwrite gap (cc5-flagged).** The enrichment/ingest path's handling of null incoming
   values overwriting existing populated fields — confirm the intended NEWER_WINS vs never-null-overwrite rule
   before it silently blanks compounded history.

---

## 7. KNOWN FRAGILITIES (for FRAGILITY_REGISTER.md)

- **F-PILLAR-1 (P0-class):** `properties` has no identity spine; duplicates enter via manual capture +
  `promoteToStock()` while `tracked_properties` is deduped. (§5.1)
- **F-PILLAR-2 (P1):** MIC address-unlock never reconciles to a later `properties` row; orphaned outreach +
  claim-lock at the wrong layer. (§5.2)
- **F-PILLAR-3 (P1):** Contact dedup is agency-scoped only; cross-agency same-person undetected. (§5.3)
- **F-PILLAR-4 (P1):** owned `properties` has no freshness/expiry column; stale imported P24 shell
  indistinguishable from a live listing. (§5.4)
- **F-PILLAR-5 (watch):** `EntryPointController::storeFromProspecting` inlines promotion instead of
  `promoteToStock()` — two promotion code paths to keep in sync. (§4.3)
</content>
