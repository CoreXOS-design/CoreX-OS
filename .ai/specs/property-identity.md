# Property identity — one record per property, one door in

**Status:** A1 spec (this file). QA1 only. Nothing goes to Staging or live without Johan's order.
**Owner of this file:** lane A (foundation). Lane B (cc4, per-agency work layer on MIC) adds its own section at §12 and touches nothing else here.
**Pillars:** Property (`properties`), Tracked Properties (MIC), Deal (`deals`). Contact untouched; Agent untouched.
**Origin:** `.ai/AUDIT_PROPERTIES_MATCHING_2026-10-29.md` (+ `_APPENDIX.md`), approved by Johan 29 Oct with four rulings (§1).
**Related specs:** `structured-address-matching.md` (the address reader and scorer this builds on, unchanged), `deeds-capture.md`, `mic-complete-spec.md`, `road-to-sold.md`, `multi-tenancy.md`.

---

## 1. Johan's rulings (29 Oct, relayed by the conductor)

1. The shared pool holds **public portal facts only**: advert numbers, address, GPS, price, beds/baths, listing agent/agency name, status. Every agency's scrapes enrich it.
   Everything an agency captures itself — claims, comments, prospecting status, owner names/contacts, **deed data (private, paid for)**, history — is **that agency's own layer** and never visible to another agency. Agency 1 claiming never changes agency 2's view.
2. An agency's own listings are public: other agencies see them as other-agency stock. On the owning agency's screens they are flagged own stock and shown in MIC only when the existing "our stock" tick is on.
3. Existing data: **dry-run merge report first, Johan reviews, then apply.** Nothing in this build applies a merge.
4. HFC email alerts will be set up for testing.

## 2. The model in one paragraph

A **tracked property** (`tracked_properties`, one per agency per physical property) is the identity. Every source that talks about a property — own stock, MIC adverts, email alerts, portal captures, deeds, CMA comps, presentation comps, deals — is linked to it with a **confidence level that is saved on the link**.
Adverts are identified by ONE canonical reference (portal + listing id) stored in ONE place. A single door, `PropertyIdentityResolver`, finds or creates the tracked property; no ingest path decides "same property?" on its own.
Public advert facts also go to a **global shared pool** keyed by canonical reference; the agency layer stays per agency exactly as it is today.

## 3. Shared (public) vs private — explicit

| Layer | Where | Scope | Holds |
|---|---|---|---|
| **Shared pool** | NEW `portal_advert_facts` (no `agency_id`) | global, **public facts only** | `canonical_ref` (unique), `portal`, `listing_id`, `address_text`, `street_number`, `street_name`, `unit_number`, `complex_name`, `suburb`, `suburb_normalised`, `town`, `province`, `latitude`, `longitude`, `geo_confidence`, `price`, `bedrooms`, `bathrooms`, `garages`, `floor_size_m2`, `erf_size_m2`, `property_type`, `listing_agent_name`, `listing_agency_name`, `status` (active/sold/withdrawn/unknown), `first_seen_at`, `last_seen_at`, `price_history` (json), `seen_count` |
| **Agency layer** | `tracked_properties`, `tracked_property_external_refs`, `tracked_property_addresses`, `tracked_property_owners`, `property_match_decisions`, `rts_*`, MIC claim/status/comment tables (lane B) | `agency_id`, existing scope | everything else |

**Private columns of `tracked_properties` (never copied to the pool, never read across agencies):** `title_deed_number`, `deeds_office`, `deeds_registered_date`, `bond_holder`, `bond_amount`, `sale_type`, `deeds_captured_at`, `deeds_captured_by_user_id`, `ownership_parse_*`, `owner_contact_id`, `municipal_valuation*`, `erf_number`, `erf_portion`, `township`, `lpi_code`, `scheme_name`, `scheme_number`, `section_number`, `cadastral_extent`, `section_extent_m2`, `last_known_sold_price` / `last_known_sold_date` **when written by deeds**, `source_chain`, `status`, `promoted_*`, `capture_kind`, all of `tracked_property_owners`, all claim / comment / prospecting-status data.
(The erf and SG code come from deeds and CMA reports, which are paid-for agency data, so the pool never carries them.)

**Rules**
- The pool is read and written ONLY through `App\Services\Prospecting\SharedPortalPool` (an explicit column allow-list). No model, relation, query scope or API returns a pool row's contributors; the pool does not record which agency contributed (no `agency_id`, no contributor id).
- A pool row **never contains or reveals** another agency's tracked-property id, claim, status, comment or deed field.
- A new agency's first sight of an advert is pre-filled from the pool (address, GPS, price, beds/baths) into **its own** new tracked property. Nothing flows back except the public allow-list.
- Own listings: a published own listing (P24/PP syndication) writes its public facts to the pool like any scrape; the owning agency sees it flagged own stock (existing `matched_property_id` / promoted link), others see it as other-agency stock.
- Agency 1 claiming, commenting, or setting prospecting status writes only to agency 1's layer. A test proves agency 2's view is byte-identical before and after.

## 4. Canonical portal reference

**Format:** `<portal>:<listing id>` — lower-case portal, listing id as the portal prints it.
`p24:116123456` (digits) · `pp:T1014486` and `pp:RR4719094` (Private Property ids are letters + digits; the letters are kept, upper-cased). Max 40 chars.

**Storage — ONE place:** `tracked_property_external_refs` (the audit's answer: it already holds 52k refs against a tracked property; it is the only table every source can reach). New columns:

| Column | Meaning |
|---|---|
| `canonical_ref` varchar(40) NULL | the canonical form; NULL for non-advert refs (`cmainfo`, `deeds_capture`, `manual_prospect_entry`, `agency_stock`, `map_prospect_launch`) |
| `match_confidence` enum `exact,high,probable,weak` NULL | how the link was decided |
| `match_method` varchar(30) NULL | `portal_ref`, `lpi`, `erf_suburb`, `scheme_unit`, `deed_number`, `address_unit`, `gps`, `fuzzy`, `manual`, `address_history` |
| `matched_at` timestamp NULL | when the link was made |

Indexes `(canonical_ref)` (cross-agency pool lookups) and `(agency_id, canonical_ref)`. **Target: unique `(agency_id, canonical_ref)`** — one advert belongs to exactly one tracked property per agency — **added only after the reconcile report (A4) has been reviewed and applied**; until then it is a plain index, because the same advert sits under two property files of one agency in thousands of places today (the audit's 4,561 capture-vs-MIC splits). Existing `unq_tracked_external_ref (agency_id, source_type, source_ref)` stays — the raw value is kept for audit, never rewritten.
The backfill never fails or merges on such a clash: it stores `canonical_ref` on both rows and lists the pair in the report as a **clash** (the input of the A4 reconcile report).

**Other tables that carry an advert number** keep their raw column and gain a derived `canonical_ref` (nullable, indexed): `prospecting_listings` (MIC), `p24_listings` (alerts), `portal_listings` (captures), `property_external_sources` (OAS), and `properties` (own stock) as `p24_canonical_ref` + `pp_canonical_ref` (a property can be on both portals). Built in **A2**. Kept current by an Eloquent `saving` hook (`HasCanonicalPortalRef`) on each model; writers that skip events (`saveQuietly`, raw query-builder inserts such as the map's prospecting-listing insert) are caught by re-running `property-refs:normalise --apply`, which recomputes a derived column whose stored value is NULL or stale.
**Link columns** (`tracked_property_id` + `identity_confidence` + `identity_method`) for tables that have NO advert number — `deals`, `market_report_comp_rows`, `presentation_sold_comps`, `presentation_active_listings`, and `properties`/`p24_listings` where not yet present — are the "property-file id on stock, comps and deals" the audit asked for; they are added in **A3** together with the resolver that fills them.

### 4.1 Normaliser — `App\Support\Property\PortalRef::normalise(?string $raw, ?string $portalHint = null): ?string`

Pure, idempotent (`normalise(normalise(x)) === normalise(x)`), no DB.

1. Trim; strip surrounding quotes, NBSP and zero-width characters; strip a trailing `.0` (spreadsheet floats).
2. **URL:** if it contains `property24.com`, take the last path segment of 6–12 digits (before `?`/`#`) → `p24`; if it contains `privateproperty.co.za`, take the last segment matching `[A-Za-z]{1,3}\d{5,10}` → `pp`.
3. **Prefixed:** `P24-123`, `p24:123`, `P24 123` → `p24:123`; `PP-T123`, `pp:T123`, `PP T123` → `pp:T123`.
4. **Bare:** `[A-Za-z]{1,3}\d{5,10}` with no prefix = Private Property (`T5663557` → `pp:T5663557`). **Bare digits alone are never guessed from the digits:** they take the portal from `$portalHint` (the column or `source_type` they came from). No hint ⇒ NULL.
5. P24 id must be 6–12 digits; anything else (including test strings such as `CC5-LIVE-ISSUEA-TEST-…`) ⇒ NULL, counted as "unparseable" in the dry-run, never stored.
6. Leading zeros kept. Case: portal lower, PP letters upper.

**Every raw format found in the audit** (all must be test cases):

| Where | Raw | Hint | Canonical |
|---|---|---|---|
| `prospecting_listings.portal_ref` (p24) | `P24-100352744` | p24 | `p24:100352744` |
| `prospecting_listings.portal_ref` (pp) | `PP-T1014486` | pp | `pp:T1014486` |
| `tracked_property_external_refs` source_type `p24` | `P24-123` | p24 | `p24:123` |
| … `pp` | `PP-T123` | pp | `pp:T123` |
| … `chrome_capture` | `123` or `T123` | from the capture's portal | by hint |
| … `property24` | `123` | p24 | `p24:123` |
| … `private_property` | `T123` / `RR123` | pp | `pp:T123` |
| `p24_listings` (alerts) | `P24-117743851` | p24 | `p24:117743851` |
| `portal_listings` | bare digits | its `portal` column | by hint |
| `properties.p24_ref` / `p24_listing_number` | `103977859` | p24 | `p24:103977859` |
| `properties.pp_ref` | `T5535694`, `RR4719094` | pp | `pp:T5535694`, `pp:RR4719094` |
| URLs on the extension payload / OAS | `https://www.property24.com/…/116123456` | — | `p24:116123456` |

## 5. The door — `App\Services\Prospecting\PropertyIdentityResolver`

`resolve(IdentityFacts $facts, int $agencyId, IdentitySource $source, ?int $actorUserId = null): IdentityResult`

`IdentityFacts` carries whatever the source has (any subset): `portal_ref_raw` + `portal_hint`, `erf_number`, `erf_portion`, `lpi_code`, `title_deed_number` + `deeds_office`, `scheme_*`/`section_number`/`unit_number`/`complex_name`, address (raw or structured), `suburb`, `lat`/`lng` + `geo_confidence`, and the public advert facts for the pool.
`IdentityResult`: `tracked_property_id`, `created` (bool), `confidence` (`exact|high|probable|weak|none`), `method`, `candidates[]` (id, confidence, method, reasons), `possible_match_id` (when recorded), `canonical_ref`.

**Existing engine stays.** `TrackedPropertyMatchOrCreateService::matchOrCreate()` remains the engine behind the door (non-negotiable #10, address history, scorer, `numbersConflict`, decisions log). The resolver is the only public entry; `matchOrCreate()` is kept as a thin delegate to the resolver so every current caller keeps working unchanged until it is moved.

### 5.1 Keys in priority order and the confidence each returns

| # | Key | Layer | Result |
|---|---|---|---|
| 0 | Address history (an address an agent corrected) | agency | as today (`high`, method `address_history`) |
| 1 | **Canonical portal ref**, same agency | agency | `exact` / `portal_ref` — with `numbersConflict` self-heal as today |
| 2 | **LPI (SG code)**; else **erf + portion + same suburb**; else **scheme + section + same suburb**; **title deed number + deeds office** only as a corroborator of 2–4 (never a key alone; it changes at each transfer) | **private**, same agency only | LPI `exact`; erf/scheme `high`; deed number corroborating a `probable` lifts it to `high` |
| 3 | **Normalised address + suburb + unit** (`AddressMatcher` scorer, unchanged) | agency | EXACT tier + unit rule ok ⇒ `high`; POSSIBLE tier (neighbour suburb, type differs, unit on one side only) ⇒ `probable`; STREET-ONLY ⇒ `weak` |
| 4 | **GPS within `gps_radius_m`** | agency | `probable` only when a second signal agrees (street or suburb); GPS alone ⇒ `weak`. A pin with `geo_confidence` of `suburb`/`street`/NULL **never counts as GPS** |
| 5 | **Fuzzy** (token overlap ≥ `fuzzy_threshold`, number veto kept) | agency | `weak`; shown to the agent, never linked |

Pool lookup (cross-agency) is by `canonical_ref` only and returns public columns for pre-fill — it never produces a link to another agency's tracked property.

### 5.2 The unit-number rule (fixes over-merged blocks going forward)

An address match **without matching unit numbers never auto-merges**:
- unit/section on both sides and **equal** ⇒ agrees; **different** ⇒ VETO (no match, as today);
- unit on one side only ⇒ at most `probable`, recorded as a possible match;
- no unit on either side ⇒ merges (`high`) **only if neither side is a unit-type** (property_type apartment/flat/sectional/townhouse, or a `complex_name`/`scheme_*` present); otherwise `probable`.
The street-number-from-unit bug (`ProspectingApiController` first-number rule) is fixed by passing the full address to the one reader in A3.

### 5.3 How a result is used

| Confidence | Action |
|---|---|
| `exact`, `high` (≥ `auto_merge_min_confidence`) | link automatically; save `match_confidence` + `match_method` + `matched_at` on the ref/link; append `source_chain`; enrich public facts into the pool |
| `probable`, `weak` (below the threshold) | **never merged silently.** A `property_match_decisions` row is written (outcome `pending`, `strategy`, `confidence_score`, `candidates`, `incoming_facts`); the source creates its own new tracked property if none matched, and the pair appears on the possible-matches list (§9) |
| none | create a new tracked property (as today), save ref + confidence `exact` for a portal ref, else NULL |

"No" answers are remembered (`rejected_at`, as the decision log does for deeds) and the pair is never proposed again. The agent-facing decision log and veto, which today exist only for `deeds_capture`, extend to every source type in A3.

## 6. Settings (agency-configurable, defaults, in `address_match_settings`)

| Setting | Column | Default | Range |
|---|---|---|---|
| GPS radius (metres) | `gps_radius_m` (exists) | 25 | 5–100 |
| Fuzzy threshold (score) | `fuzzy_threshold` (new) | 85 | 60–100 |
| Auto-merge minimum confidence | `auto_merge_min_confidence` (new) | `high` | `exact` · `high` |

Page: existing `/corex/settings/prospecting/address-matching` (permission `prospecting_setup.manage`, agency admin) — two fields added. Sidebar entry exists. **Setup Wizard:** deliberately NOT in the wizard, consistent with Johan's recorded decision for this settings family (`agency-onboarding-setup.md` §5.1 "Deliberately NOT in the wizard"); the lane records the two new rows there. *Flag for Johan: if he wants the new two in the wizard it is a one-line change.* No row = defaults.

## 7. Which routes must use the door

All of them. Each is moved in A3 with its own test; the old private dedupe is removed or reduced to a call to the door.

| # | Route | File (audit) |
|---|---|---|
| 1 | Web "Add property" form | `PropertyController::store` |
| 2 | Add-Property wizard step 1 | `PropertyWizardController` |
| 3 | Mobile create `POST /api/mobile/properties` | `MobilePropertyController` |
| 4 | Pull from portal (extension) | `PropertyPullController` |
| 5 | P24 listings CSV import | `ConfirmP24PropertyRowJob` |
| 6 | Sold-properties spreadsheet | `SoldPropertyImporter` |
| 7 | Other-Agency-Stock import | `OtherAgencyStockImportService` |
| 8 | MIC listing → property | `ListingPropertyPromoter::promote` |
| 9 | `promoteToStock()` and its callers (deeds screen, tracked-properties promote, rental take-on CSV and REOS, Road to Sold prospect) | `TrackedPropertyMatchOrCreateService` |
| 10 | Duplicate listing (clone) | `PropertyController::duplicate` — intended copy: links to the same tracked property, never creates a second |
| Importers | MIC extension (`ProspectingApiController`), P24 email alerts, portal capture (`PortalCaptureController`), deeds capture API, CMA bulk import (`ParseMarketReportJob`), presentation comps (`MicSnapshotHydrator` / comp writers), deals (`DealPropertyLinkService` at save), rental take-on, manual create, map prospect launch | per audit appendix |

Demo seeders are exempt.

## 8. Data safety, backfill, merges

- **Normalise backfill** `property-refs:normalise --dry-run|--apply`: chunked, idempotent (re-run to resume), writes only the DERIVED canonical column (to its freshly computed value), never touches a raw value; refuses to run outside QA/local/testing (precedent: `address:backfill-structured`). The dry-run reports per source table: rows read, normalised, unparseable (with samples), already canonical, clashes (same canonical ref on two tracked properties of one agency), and **cross-source matches** (canonical refs present in two or more source tables).
- **Reconcile** `property-identity:reconcile --dry-run` (A4): own properties → tracked-property joins with confidence; duplicates by canonical ref; over-merged tracked properties (≥ 2 distinct unit numbers or ≥ 10 adverts on one file) with a proposed split; written to `storage/app/reports/`. **No `--apply` ships in this lane.** Merges are flagged (`duplicate_of_tracked_property_id`), never hard-deleted (non-negotiable #1).
- Every new column is nullable; no existing column is dropped or overwritten.

## 9. Possible-matches list (specified here; built after A4, not part of A2–A4)

Screen `/corex/prospecting/possible-matches` — agency admins (permission `prospecting_setup.manage`), scoped to the agency at the query layer, direct-URL access by id blocked. Rows from `property_match_decisions` where outcome is pending.
- **Search:** street, street number, suburb, complex, erf, canonical ref, record id. **Sort:** confidence, score, source, address, decided date (default: highest confidence then newest). **Filter:** confidence, source type, outcome (pending/confirmed/rejected), date range. Pagination 25. Empty state: "Nothing to confirm — every match was certain."
- **Actions:** Same property — link; Different property — remember "no"; Restore (undo a decision). No delete. Navigation entry in the same build (Admin, beside Address review).

## 10. Permissions and API

No new permission keys in A2–A4 (settings page reuses `prospecting_setup.manage`). The only HTTP surface A2–A4 adds are none; the extension's existing `/api/v1/...` responses stay backward compatible (additive `confidence`, `canonical_ref`).

## 11. Test matrix

Normaliser: every raw format in §4.1 + idempotence + junk + bare digits without hint + URL forms + spreadsheet float.
Resolver: each key and its confidence (`exact`, `high`, `probable`, `weak`); unit rule (equal / differ / one side / unit-type vs freehold); suburb-centre GPS never counts; deed number alone never matches; agency privacy (agency 2's resolve never returns, prefills from or alters agency 1's private columns; agency 1 claim leaves agency 2's view identical); below-threshold recorded not merged; "no" remembered.
Routes: one test per route in §7 (creates a tracked property link, no behaviour change on exact matches).
Fixtures: real shapes (Pebble Beach 303 vs 304; 1166 Lynne Avenue portions; Uvongo / Uvongo Beach; relisted advert with a new id).

## 12. Lane B — per-agency work layer (cc4 writes this section)

*(reserved for cc4: claim / status / comment tables, write-back to MIC, own-stock flag. Must read `claimed_by` / status only from the agency layer, and must treat the pool as read-only public facts.)*

## 13. Steps and status

| # | Step | Status |
|---|---|---|
| A1 | This spec | landed (QA1) |
| A2 | `PortalRef` normaliser + `canonical_ref` columns + model hooks + pool table (`portal_advert_facts`) + `property-refs:normalise` | landed (QA1) — dry-run report in the A2 build report; `--apply` not yet run |
| A3 | `PropertyIdentityResolver` + all routes through the door + settings fields | pending |
| A4 | `property-identity:reconcile --dry-run` report for Johan | pending |
| — | Possible-matches list, `--apply` merges | not in this lane; after Johan's review of A4 |
