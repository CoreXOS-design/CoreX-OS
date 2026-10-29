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
- The pool is read and written ONLY through `App\Services\Prospecting\SharedPortalPool` (an explicit column allow-list). No model, relation, query scope or API returns a pool row's contributors. In A2-A4 the pool records no contributor at all (no `agency_id`, no contributor id); A5 (§14) adds an INTERNAL contribution record that no agency-facing read can reach.
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
| 4 | **GPS** — precise pins only (a CMA property pin, or a pin the geocoder marked `exact`/`high`) | agency | within ~5 m **with the suburb agreeing and no number/unit conflict ⇒ `high` (links)**; within `gps_radius_m` otherwise ⇒ `probable` (suburb agrees) or `weak` (it does not) — recorded, never linked. A pin of `geo_confidence` `suburb`/`street`/unknown **never counts as GPS**; an incoming pin labelled `suburb`/`street` is not used at all |
| 5 | **Fuzzy** — loose word overlap on a street | agency | ONE street-agreeing candidate (number veto, street-agreement and unit rule kept; a number on one side only does not veto — the standing "Rule 4") ⇒ `high`/`fuzzy`, links. **Ties** (several houses fit) are never picked between: each is `weak`, recorded. Street-only scorer candidates with a score ≥ `fuzzy_threshold` are kept as `weak` possible matches |

Pool lookup (cross-agency) is by `canonical_ref` only and returns public columns for pre-fill — it never produces a link to another agency's tracked property.

### 5.2 The unit-number rule (fixes over-merged blocks going forward)

An address match **without matching unit numbers never auto-merges** where the property is a unit (one rule, `unitDoubt`, used by address history, the scored street rule and the fuzzy rule):
- unit/section on both sides and **equal** ⇒ agrees; **different** ⇒ VETO (no match, as today);
- a **unit-type** property (property_type apartment / flat / sectional / townhouse / duplex / simplex / cluster / studio / penthouse / unit, or one in a sectional scheme) where the two sides do not BOTH name a unit ⇒ at most `probable`, recorded as a possible match;
- anything else (a freehold house, with or without an estate / complex name; a legacy record with a stray unit lifted from a polluted line) ⇒ merges (`high`). A bare `complex_name` alone does NOT make a property unit-type: a freehold house in an estate has no unit.
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

All of them. **How** (A3): `TrackedPropertyMatchOrCreateService::matchOrCreate()` is now a thin delegate to `PropertyIdentityResolver::resolve()`, so every existing caller of the matcher — MIC extension, portal capture, deeds, CMA bulk import (`ParseMarketReportJob`), P24/PP leads, Road to Sold, rental take-on, map, seller outreach, presentation CMA propagation — goes through the door **without being edited**; a test fails if any app code other than the resolver calls the engine (`matchOrCreateEngine`). Rows created by Eloquent without calling the matcher are attached by `PropertyIdentityLinkObserver`, one hook per table (`config('property-identity.link_on_create')`, on by default, off in the test environment):

| Table | On | Does |
|---|---|---|
| `properties` (own stock — routes 1-3, 5-8, 10 and the portal pull) | created | **find or create** the property file; saves `tracked_property_id` + `identity_confidence` + `identity_method`. `promoteToStock()` suppresses it (the promote already decided) and stamps the link itself (`promoted`) |
| `market_report_comp_rows` (CMA bulk import) | created | **find only** — a comparable never mints a property file; address + scheme/section/flat from the row, never GPS |
| `presentation_sold_comps` | created | **find only**, from the address and erf in `raw_row_json` |
| `p24_listings` (email alerts) | saved | **attach by advert number only** (an alert has a suburb and a number, no address) |
| `deals` | saved | takes the property file of its `property_id` (deals with only a typed address stay unlinked — `DealPropertyLinkService`, run by hand, is the existing way to link them) |

A resolver failure is logged and never blocks the save. Not yet linked at all: `presentation_active_listings` (no address in the row) and deals with no `property_id` — see the A3 report.

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

## 12. Lane B - per-agency work layer (written by lane B / cc4; QA1 only)

**What the shared pool is (lane A) and what this section adds.** The shared pool is `portal_advert_facts` (public portal facts, no agency). `tracked_properties`
stays ONE ROW PER AGENCY PER PROPERTY, so the private columns on it (owner contact, deeds, bond, promotion, municipal value, last sold price/date, the archive /
duplicate decision, `source_chain`) are already that agency's own layer and **stay where they are**: moving them to a second table would copy ~85 files' worth of
reads for no extra privacy. Lane B therefore (1) closes the tables that were NOT scoped, (2) adds the agency's own work record for what is NEW per agency, (3) proves
the privacy with a two-agency sweep. Johan's ruling (29 Oct, 3rd answer) stands: **every deed-sourced field is private to the agency that paid, including last sold
price and date**; the pool carries portal-public facts only (lane A's allow-list already excludes them).

### 12.1 Tables

| Table | Scope | Holds |
|---|---|---|
| `portal_captures`, `portal_listings`, `portal_listing_observations` | `agency_id` NOT NULL + `BelongsToAgency` (step 1; backfill: capturing user's agency, else the first agency = HFC) | the extension's page captures. `portal_listings` is unique per `(agency_id, source_site, portal_listing_id)` |
| `p24_listings` (email alerts) | already `agency_id`; unique key is now `(agency_id, p24_listing_number)` (was the number alone); the importer looks up inside its own agency explicitly | alert rows |
| `tracked_property_owners` | `agency_id` NOT NULL + `BelongsToAgency`; the model stamps it from its tracked property on create | owner names + ID numbers from the paid deeds capture |
| `tracked_property_agency_work` | unique `(agency_id, tracked_property_id)`, `BelongsToAgency`, soft delete | `prospecting_status` + `prospecting_reason` + who/when, `is_own_stock` + `own_stock_property_id` + `own_stock_source` (`promoted` / `advert`), `claimed_by_user_id` + `claimed_at` |
| `tracked_property_agency_work_events` | `agency_id`, append-only | dated history of status / own-stock / claim changes (kind, from, to, reason, user) |

Comments (`tracked_property_comments`) and claims (`prospecting_claims`) were already per agency and keep their own tables: the work record **points at** them
(claim at a glance), it does not copy their history.

### 12.2 Rules

- **Only `App\Services\Prospecting\AgencyWorkService` reads or writes the work record.** Every method takes the agency explicitly (observers, jobs and commands have no
  signed-in user); a work record cannot be made or found through another agency's tracked property (`DomainException`).
- **Statuses** (fixed neutral list, plain words): Owner interested in selling / Owner contacted / Owner not selling / Mandate with another agency / Do not contact; or none.
  Setting the same value again writes no history line; clearing clears the reason.
- **Own stock** = the tracked property was promoted to one of the agency's live properties that is not `prospecting` or `not_selling`, OR one of its adverts is one of the
  agency's on-market properties (the same `OnMarketStockService` rule the "Show in-stock too" tick and the IN STOCK badge use). It is per agency: the same advert is
  own stock for the owner and other-agency stock for everyone else. The tick itself is unchanged and still decides whether own stock is listed.
- **Kept current by:** `ProspectingClaimObserver` (claim created / released), `TrackedPropertyObserver` (promoted link changed), `PropertyObserver::saved` (status / portal refs
  changed), `PropertyController::markNotSelling` (writes `not_selling` + the typed reason), and a re-read on the tracked property screen. `php artisan agency-work:backfill
  [--agency=N] [--dry-run]` fills it from existing data (open claims, own stock, properties already Not selling); idempotent.
- **Screen:** Market Intelligence > Opportunities > the property: panel "Your agency's notes on this property" (where it stands, our stock, claimed by, change form, history).
  Route `POST /corex/tracked-properties/{id}/prospecting-status` (`corex.tracked-properties.prospecting-status`), permission `access_prospecting` + `mic.comments.add`
  (no new permission key; Role Manager untouched). Not in the Setup Wizard: it is a work action, not a setting.
- **Search / sort / filter / pagination:** no new list screen was added. Archive/restore: the work record soft-deletes and `workFor()` restores it (BUILD_STANDARD 5a).
- **Agency 2's view never changes** when agency 1 claims, comments, sets a status or holds the stock: `tests/Feature/Prospecting/AgencyWorkLayerTest.php`,
  `AgencyWorkScreenTest.php`, and the sweep `MicAgencyScopeSweepTest.php` (an admin of agency B opens 16 MIC / tracked-property / deeds lists and 12 by-id screens of
  agency A's property; none shows A's street, owner, ID number, comment, status reason, alert number or bond holder, and by-id answers 404).
- **Sharing the property row itself is not done and not planned here**: lane A's pool is `portal_advert_facts`. If a future step ever shares `tracked_properties`
  rows, the work record is already keyed `(agency_id, tracked_property_id)` and `AgencyWorkService::assertOwn()` is the one line to relax.

### 12.3 Lane B decisions (taken, not asked)

1. Private columns stay on the per-agency `tracked_properties` row (see above) - nothing was moved or dropped.
2. Own stock is NOT flagged for a property that is only `prospecting` (deeds-promoted, no mandate): on QA1 2,101 tracked properties carry a promoted link or a matching advert, 467 are
   live own stock, the rest are prospecting / not-selling / stale.
3. The status list is a fixed constant (a configurable list would need a wizard step under non-negotiable 10a); wording is neutral, nothing HFC-specific.
4. `mic.comments.add` gates the status form: anyone who may add a comment may record where a property stands.
5. Lane A's backfill test inserted `portal_listings` rows with no agency; it now names one (test-only change).

## 13. Steps and status

| # | Step | Status |
|---|---|---|
| A1 | This spec | landed (QA1) |
| A2 | `PortalRef` normaliser + `canonical_ref` columns + model hooks + pool table (`portal_advert_facts`) + `property-refs:normalise` | landed (QA1) — dry-run report in the A2 build report; `--apply` not yet run |
| A3 | `PropertyIdentityResolver` + all routes through the door + settings fields + link columns + the one address-line reader | landed (QA1) |
| A4 | `property-identity:reconcile --dry-run` report for Johan (read-only; no `--apply` exists) | landed (QA1) |
| A5 | Make the property/advert row shared across agencies (§14) | next - after Johan has seen the A4 report; lane B steps 1-4 have landed |
| — | Possible-matches list, `--apply` merges | not in A2-A4; after Johan's review of A4 |
| B1 | Lane B investigation | landed (QA1) `.ai/B1_AGENCY_WORK_LAYER_2026-10-29.md` |
| B2 | portal-capture + alert tables per agency; work record; owners scoped; backfill | landed (QA1) - section 12 |
| B3/B4 | two-agency privacy sweep; status panel + own-stock flag | landed (QA1) - section 12 |


## 14. A5 - one shared row per property / advert across agencies (design; built after A4 and after lane B's steps 1-4)

**Why.** Today `tracked_properties`, MIC adverts (`prospecting_listings`) and external refs are per-agency copies; the pool of §3 only holds public advert facts keyed by advert. Johan's ruling 1 asks for one shared row per property / advert, public facts only, enriched by every agency, **with the contributor recorded and never visible to another agency**; lane B (cc4) holds each agency's own layer (claims, comments, prospecting status, owners, deed data, history) in a per-agency work record.

**Shape (to be confirmed against cc4's work record when it lands; nothing below is built):**
- The shared row is the pool row, extended: one row per canonical advert (already `portal_advert_facts`), plus a shared **property** key joining the adverts of one physical property (relists, both portals). Public columns only (§3 allow-list).
- Each agency's `tracked_properties` / MIC row becomes that agency's work record and points at the shared row (`shared_advert_id`, `shared_property_id`). **No private column ever moves to the shared side**; the agency's own columns stay where they are.
- Contribution record (internal): `portal_advert_contributions (shared_advert_id, agency_id, first_seen_at, last_seen_at, fields_contributed json)`. Written by `SharedPortalPool`; **no model, relation, scope, API or screen reads it for another agency** (only the contributing agency's own row, and platform audit). A test proves agency B cannot read agency A's contribution or claim state through any route.
- Agency 1 claiming, commenting or setting prospecting status writes only to agency 1's work record; agency 2's view of the shared row does not change.
- **Reconcile first** (same reporting as A4): `property-identity:share --dry-run` reports, per source, how many agency rows would join an existing shared row, how many would create one, clashes, and what would be written to the shared side — **no apply** until Johan reviews it.
- Multi-agency check (rule 9): no HFC id or wording anywhere. (The agency stamp on `p24_listings` and `portal_listings` — audit gap 11 — was done by lane B, step B2, §12.)
