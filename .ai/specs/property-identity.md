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
| A5 | Shared advert / property rows across agencies (§14): tables, internal contribution record, `property-identity:share --dry-run` | landed (QA1); the share itself is not applied - Johan reviews A4 + A5 together |
| — | Possible-matches list, `--apply` merges | built 30 Oct (§16) after Johan's go; apply results in §16.4 |
| B1 | Lane B investigation | landed (QA1) `.ai/B1_AGENCY_WORK_LAYER_2026-10-29.md` |
| B2 | portal-capture + alert tables per agency; work record; owners scoped; backfill | landed (QA1) - section 12 |
| B3/B4 | two-agency privacy sweep; status panel + own-stock flag | landed (QA1) - section 12 |
| C1 | e-mail alerts tie to the property through the door; alerts on the property screen; Private Property alert import | landed (QA1) - section 15.1 |
| C2 | deeds / hand capture claims its Market Intelligence property; counts in Road to Sold | landed (QA1) - section 15.2 |
| C3 | one prospecting status per agency per property, both ways | landed (QA1) - section 15.3 |


## 14. A5 - one shared row per property / advert across agencies (built; the share step is NOT applied)

**Why.** Johan's ruling 1: one shared row per property / advert, public facts only, enriched by every agency, the contributor recorded and never visible to another agency. Lane B (§12) keeps each agency's own layer.

**Decision (lane A, with lane B's §12 in view):** the agency's `tracked_properties` / MIC rows are **not** moved or merged across agencies — lane B established that they are the agency's own layer and moving them would rewrite ~85 files of reads for no extra privacy. The shared rows are NEW tables that each agency's row **points at**:

| Table | Scope | Holds |
|---|---|---|
| `portal_advert_facts` (A2) | shared, no agency | one row per canonical advert: public advert facts (§3 allow-list) + `shared_property_id` |
| `shared_properties` (A5) | shared, no agency | one row per physical property across agencies: public property facts only (address parts, GPS + its precision, type, beds / baths / garages, floor and erf size) |
| `portal_advert_contributions` (A5) | **internal**, `agency_id`, `BelongsToAgency` | which agency saw which advert, first / last seen, how often, which public fields it supplied |
| `tracked_properties.shared_property_id`, `prospecting_listings.portal_advert_fact_id` | the agency's own row | the pointer to the shared row — **NULL until the share step is applied** |

**Privacy, by construction:** the shared tables have no `agency_id`; both directions of every read / write go through `SharedPortalPool`'s column allow-list; the contribution record is the only place an agency id meets an advert, it is agency-scoped at the query layer (an agency can only read its own rows), and no pool read returns it. Tests: `PropertyIdentityShareTest` (an agency reads only its own contributions; a pool read carries no agency; the report's privacy self-check finds no private column and no agency column on the shared side).

**What is live now:** every resolver call that carries a portal advert number already enriches the pool and — new in A5 — writes the contribution record (new scrapes only). Nothing else is written: no `shared_property_id` / `portal_advert_fact_id` is set, no `shared_properties` row is created.

**The share step (dry-run only):** `php artisan property-identity:share --dry-run` (QA / local / testing only; `--apply` does not exist). It reports the adverts that would be in the pool and how many are seen by several agencies; the shared properties that would exist (adverts and files grouped: an advert on two files joins them, an advert held by two agencies joins their files) split into one-agency-one-file, one-agency-several-files (needs the A4 merge decision first), spanning agencies, and **blocked because a file in the group is over-merged (split first)**; the contributions that would be written; where agencies disagree about one advert's suburb or price; how complete the public facts are; and the privacy self-check. It writes `storage/app/reports/property-identity-share-dry-run-*.md` / `.json` and nothing to the database.

**Applying it later (not built):** create the `shared_properties` and fill `shared_property_id` only for groups the report marks clean; groups needing a merge or split wait for the A4 decisions; the contribution record is backfilled from the existing MIC rows. Johan reviews A4 and A5 together first.

**Search / sort / filter / pagination, navigation, permissions:** no new screen and no new permission — the build adds tables, an internal model and a console report. Archive / restore: shared rows are never deleted by an agency (they are shared); an agency's own pointer is cleared with its own row.


## 16. Apply (30 Oct 2026, Johan's go - QA1 only; Staging is the safety copy)

> MIC Work tab build (31 Oct 2026): see [.ai/specs/mic-work-tab.md](mic-work-tab.md) and [.ai/research/mic-data-inventory-2026-10-31.md](../research/mic-data-inventory-2026-10-31.md).

**Command:** `php artisan property-identity:apply <step> [--dry-run|--apply] [--agency=] [--exclude-agency=20] [--journal-dir=]`. Steps run in this order, one at a time:
`own-stock-joins` -> `advert-clashes` -> `split-over-merged` -> `share`. Default is dry run (the diff is saved to `storage/app/reports/property-identity-apply-<step>-dry-run-*.md/.json`); `--apply` re-plans against the live data, writes only what the plan says and writes a row-level journal (`table, id, column, old, new`, JSON lines) so a step can be undone from the journal as well as from the DB dump. Refuses to run outside QA / local / testing. Services: `PropertyIdentityApplier` (plans + applies), `TrackedPropertyMerger` (the only code that moves rows between two files). The A4 reconciler now exposes `clashGroups()` and `groupFileAdverts()` so the report and the apply can never disagree about a group.

### 16.1 Johan's rulings (relayed 30 Oct, followed as written)
1. `property-refs:normalise --apply` first.
2. Own stock -> file: the high-confidence joins are applied; the "possible" ones are **not** merged - they go to the possible-matches list.
3. Same advert on two files: merge where the addresses agree; the "different houses" files stay separate and are flagged as possible; an advert on a file with **no address** attaches to the file that **has** one; two files are never merged on the advert number alone when their units differ.
4. Over-merged files are split by unit as the dry run proposed.
5. Then A5 share (public facts only, contributed-by kept). The Staging-copied test agency (20) is excluded from every step.
6. Then the possible-matches screen (§9), then every screen figure re-run (shown vs data).

### 16.2 Decisions taken while building (technical, recorded - not asked)
- **A join is a link, not a claim.** `properties.tracked_property_id` + `identity_confidence` + `identity_method` are set; `promoted_to_property_id` is never touched.
- **Agency 20 is left out of all four steps**, not only the share (it is a copy of HFC's stock, 3,393 identical Property24 numbers).
- **Which clash groups merge.** Only groups whose files **all agree on the address** (scorer tier exact). A4 found 0 of those, so the apply merges no two files on its own; the work is the attach and the flagging. Groups that "possibly agree" (943) or show different addresses (215) are recorded as pending pairs, one per extra file. Groups where **no** file has an address (and groups where more than one has one but cannot be compared) are left alone and counted - there is nothing to attach to.
- **Attach.** One advert moves (its external refs, MIC rows, portal-capture rows, e-mail alerts - every row carrying that canonical advert on the emptied file) to the single file that has a street. A file left with nothing at all (no adverts, owner, work record, deeds, promotion, comments, RTS rows) is flagged `status=duplicate`, `duplicate_of_tracked_property_id` = the receiving file. Never deleted. A file with anything else on it stays active.
- **Possible candidates for an own listing:** the probable ones, at most 3 per listing, best score first; a weak (GPS-only) candidate is listed only when it is the single one. This keeps the review list one line per *real* question instead of five GPS neighbours per unit.
- **Split.** The address group at the file's own address stays on the file; if none matches, the biggest group stays; the "no address" adverts stay. Every other group becomes a **new file** made only from the adverts' own public facts (address parts, suburb, type, rooms, erf size, last price) - never the old file's owner, deeds, erf or valuation - without GPS (the geocoder pins it later). Adverts move with their refs, MIC rows, captures and alerts. The old file's work record, comments, RTS holds and flags stay on the old file.
- **Share.** Components (agency files joined by a shared advert) that are clean are shared; a component with several files of one agency (not merged by §16.1) or with an over-merged file is skipped and counted. The public facts of an advert come from the advert's own MIC row (never from a file, which may hold deeds data); a pin is shared only from a non-deeds file with a street/exact/high pin. Status maps onto the pool's four states (`under_offer` = active). The contribution record keeps who contributed (internal, agency-scoped).
- **Merged files are hidden from Opportunities** (`status <> duplicate`) so a merge is not counted twice.

### 16.3 Possible-matches screen (built)
`/corex/prospecting/possible-matches` (route `corex.possible-matches.*`, a tab of Real Estate > Market intelligence, permission `prospecting_setup.manage` + `access_prospecting`, feature `prospecting`). One line per pending `identity_possible` decision of the viewer's agency; every query and every by-id action is agency-scoped (direct id outside the agency = 404). **Search:** street, number, suburb, complex, erf, advert number, record number (either side). **Sort:** how sure, found-by, address, date (default highest first, then newest). **Filter:** show (waiting/joined/not the same/everything), kind of pair, found-by, date range. **Pagination** 25. **Empty state** "Nothing to confirm - every match was certain." **Actions, one click each:** *Same property* (our listing: link it to the captured property, other candidates for that listing are closed without a veto; two captured properties: merge via `TrackedPropertyMerger`, the loser flagged duplicate, never deleted) - *Not the same* (a permanent veto the resolver respects) - *Undo* (a "not the same" back to waiting; a confirmed own-listing link unlinked; a confirmed merge cannot be undone from the screen - it can from the journal).

### 16.4 Apply log (QA1, 30 Oct 2026; code `d0af3e3ba`)
Rollback: git tag `pre-identity-apply-2026-10-30` (= QA1 `a56eee35c`, pushed) and DB dump `/mnt/HC_Volume_103099143/qa1-identity-apply-2026-10-30/corex_qa1-pre-apply-20261030-1256.sql.gz` (289 MB, gunzip-tested). Per step: the dry-run diff, the apply report and the row journal are in `.../diffs/`; before/after counts in `counts-*.txt`.

| Step | Before | After |
|---|---|---|
| 0 `property-refs:normalise --apply` | everything already canonical since 29 Oct | 0 rows written (idempotent re-run, 58,311 distinct adverts) |
| 1 own-stock joins | 0 properties linked; 0 pending pairs | **319 linked** (exact/portal_ref 103; high: address_unit 86, address_history 53, scheme_unit 32, fuzzy 31, gps 14); **1,326 candidate pairs** from 1,124 listings recorded as pending; 3,183 listings have no match; `promoted_to_property_id` untouched |
| 2 advert clashes | 4,714 adverts on 2+ files | **3,485 adverts attached** to the file with an address (3,519 refs, 3,414 captures, 1,472 alerts, 92 MIC rows moved); **3,427 emptied shells flagged duplicate**; **1,158 pairs flagged** (943 possible, 215 different houses); 71 left (no file with an address); 0 files merged (no group had files that all agree); 1,229 adverts remain on 2+ files = 943 + 215 + 71 |
| 3 split over-merged | 201 files with 6+ adverts | **159 files split into 1,146 new files**, 1,332 adverts moved (1,364 refs, 181 alerts, 32 captures); files with 6+ adverts 201 -> 83 |
| 4 share | pool, shared properties, contributions all 0 | **42,339 adverts in the pool; 39,219 shared properties; 42,339 contribution records (agency 1 only); 39,219 files and 40,790 MIC rows point at the shared row**; 1,033 components skipped (several files of one agency - the pairs awaiting review); agency 20: 0 rows; privacy self-check clean (no agency column, no private column on the shared side) |

**Note on the A4 numbers:** the split plan is 159 / 1,146 rather than A4's 153 / 1,139 because step 2 attached adverts to some files first; the apply plans against the data as it stands.
**Open:** 2,484 pairs wait on the possible-matches screen (1,326 own-listing + 1,158 advert pairs); MIC `matched_property_id` ("claimed") is unchanged at 1,072 - claims are written by the capture / claim paths (C2), not by the join; the suburb-centre pin repair is not part of this apply.

### 16.5 Own stock matched to Market Intelligence counts as claimed (Johan's 29 Oct ruling; built 30 Oct)
**Rule.** Once an own listing sits on its property file, the file's Market Intelligence adverts are (1) recognised as ours (`prospecting_listings.matched_property_id`, only where still empty - never overwritten), (2) claimed for the **listing's agent** exactly like a capture claim (`CaptureClaimService::claimFromJoin()` -> `claim()`: claim rows, the agency's work record `claimed_by_user_id`, a Road to Sold prospect for a sale listing), and (3) the work record's **own-stock flag** is on (`syncOwnStock()` has a third source, `linked`: a live own listing whose `tracked_property_id` is this file; statuses `prospecting`, `not_selling`, `draft`, `archived` are not stock - `Property::NOT_OWN_STOCK_STATUSES`). Every query is bound to the listing's own agency; another agency's rows are never touched.
**Where it runs.** (a) Going forward: `PropertyIdentityResolver::linkProperty()` when an *existing* listing is newly joined at exact/high confidence. A property that was only just created keeps lane C's rule (claimed for the person who added it; nothing for an import or a job). (b) The review screen's *Same property* on an own listing. (c) Back-fill: `php artisan property-identity:claim-joined --dry-run|--apply` (idempotent; agency 20 left out; journal of recognised adverts and claim ids).
**Safeguards.** Another agent's live claim or pitch lock on any advert of the file is never taken (the advert is still recognised as ours). A claim on an advert recognised as our own stock **never goes stale** (`ProspectingClaim::isOwnStockClaim()`): no warning to the agent, not on the move-or-keep list. A rental listing is claimed but not placed on the *sales* Road to Sold list. The agent must be active; an assistant claims for the agent they work for.

**Back-fill result (QA1, 30 Oct 2026, code `b7645f92f`; rollback tag `pre-mic-claim-join-2026-10-30` + dump `corex_qa1-pre-claims-20261030-1430.sql.gz`; diffs and journal beside the earlier ones):** 319 joined listings read; 15 are prospecting/draft rows (not stock) and skipped; 155 files have MIC adverts. Before -> after (agency 1): MIC adverts recognised as ours (`matched_property_id`) **1,072 -> 1,266**; active claims **104 -> 414** (310 new claim rows for 16 agents); the work record shows a claim on **84 -> 207** files and the own-stock flag on **467 -> 671** (207 now via `linked`); Road to Sold sales prospects **80 -> 200** (126 placed, 6 skipped); "In stock" (on-market own listings) unchanged at **186**. 20 files were left alone because another agent already held or was pitching one of their adverts (two own listings of different agents on one file: the first claim wins); 8 were already claimed. No other agency's rows were created or changed. The plan counted 234 adverts to recognise and the apply 194 because listings sharing a file recognise it once.

### 16.6 Where did we get this property? - three screens (31 Oct 2026, Johan: "how do I see that a property was received via email and from which portal?")

Screens only; matcher, resolver and merge code untouched. All reads go through `PropertySourceFacts` (named agency in every query - another agency's alert for the same advert never counts). Navigation unchanged.

- **S1 Property page** (`/corex/market-intelligence/opportunities/{id}`): one line under the header, "How we know this property": the doors (Email alert = this agency has an alert row for one of its adverts; Extension = a captured portal listing is on the file; Deeds / Manual / Import from the file's external-ref source types), the portal(s) (P24 / PP), the advert refs (first four, hover for the rest), first and last seen. The alert panel and linked-listings table below stay.
- **S2 MIC list** (`/corex/market-intelligence/work`): each row carries one source tag - **Email alert** if the advert has an alert row for the agency, else **Deeds** if the property file has a deeds capture, else **Extension** - and, when the advert has an alert, an "alert" marker with the last alert price change and its date. The filter rail has **By source** (Email alert / Deeds / Extension only; `?source=email|deeds|extension`); the filter matches the row tag exactly, so the three lists never overlap. Search fields, sort and scoping of the list are unchanged; the source filter is a query-layer `whereExists` bound to the agency.
- **S3 Portal alerts** (`/corex/market-intelligence/portal-alerts`): an alert linked to one of the agency's own property files has its reference linked to that file's page; unlinked alerts stay plain.
- Not built: a separate Email-alert marker for Private Property on the list beyond what the alert tables hold; "Extension" is every captured portal listing, so the filter says "Extension only" (captured, no alert, no deeds).

## 17. Same-address duplicates (31 Oct 2026, Johan's go - QA1 only)

**Why.** After the 30 Oct apply and a 1-hour MIC update (10:41-11:16 SAST) agency 1 had 523 exact-address groups (a) and 269 same-address/different-unit groups (b). Causes: 191 groups of files with **no suburb** (old captures; the matcher only compares addresses when a suburb is present), ~200 left by the 30 Oct split step (flats and houses), and flats in a block with no unit number (the portals do not give one).
**Rulings (Johan, 31 Oct).** Flats in a block with no unit number are NOT merged - they go to the Possible matches review list. The two address-match settings stay in Settings only (not the Setup Wizard).

**M1 - matcher.** `TrackedPropertyMatchOrCreateService::blankSuburbMatch()` runs after the scored address match: when the suburb is missing on ONE side (the incoming advert, or the file) but street number, street and unit agree, the suburb is borrowed from whichever side has it. Exactly one such file links at `high`, method `address_no_suburb`; several files, or a unit-type property with no unit named, are recorded as possible matches (review list), never merged. A different number, unit, erf or portion vetoes as before.
**M2 / M3 / M4 - one sweep.** `SameAddressSweep` (command `property-identity:same-address <no-suburb|exact|all> --dry-run|--apply`, QA/local/testing only, agency 20 left out, row journal with `--apply`). Files of one agency with the same number + street + unit (+ same suburb, or none) form a group. A group of at most 4 houses/stands merges through `TrackedPropertyMerger` (loser flagged `duplicate`, never deleted). A flat or scheme with no unit number, a group of more than 4 files, or a merge the merger refuses goes to the review list as pending pairs (`subject_key same_address:<first file>`). A pair already answered "not the same" is never touched. Deeds-capture files are left to the Deeds Capture screen.
**Toggle (M4).** NOT built yet: the after-import automatic run and the Settings switch (default on) are the next step. Until then the sweep is run by hand.

### 17.1 Apply log (QA1, 31 Oct 2026; code `404bdd216`)
Rollback: git tags `pre-same-address-M2-2026-10-31`, `pre-same-address-M3-2026-10-31` (both = `404bdd216`, pushed) and DB dumps `/mnt/HC_Volume_103099143/qa1-same-address-2026-10-31/corex_qa1-pre-M2-20261031-1221.sql.gz` and `...pre-M3-20261031-1231.sql.gz`; same folder: dry-run and apply reports, row journals (`same-address-<scope>-journal-*.jsonl`), `counts-before/after-M2|M3.txt`. Agency 20 excluded.

| Step | (a) exact groups | (b) different-unit groups | Review pairs | Files flagged duplicate |
|---|---|---|---|---|
| before (after the MIC run) | 523 | 269 | 2,961 | 3,427 |
| M2 `no-suburb` | 346 (no-suburb groups 190 -> 13) | 269 | 3,033 (+72) | 3,640 (+213 merged) |
| M3 `exact` | 229 | 269 | 3,128 (+95) | 3,760 (+120 merged) |

Left in (a) by design: 122 flat/scheme groups with no unit number (queued for review, never merged), 13 no-suburb blocks of more than 4 files (review), and about 95 groups that contain deeds-capture files (the Deeds Capture screen owns those). **Flag:** 14 no-suburb files all read "5 Selvey Avenue" (created 10 Jun 09:36-09:39) - this looks like a placeholder or wrongly captured address, not 14 houses; they are in the review list, not merged.
**Not caught by the sweep:** one file holding adverts of several units (property 230, 8 live adverts R875k-R1.795m). The sweep compares files, never the adverts inside one file; the 30 Oct split step only splits by differing advert address text. 80 files hold 5+ adverts with a price spread over 2x (report only, nothing split).

**One address rule for the MIC list (31 Oct 2026, Johan's go).** A row has an address when its own captured text is real, or the property file it sits on has a street name (a complex or estate name counts - "1 Entaba Hibberdene" is an address). The rule lives beside `ProspectingListing::scopeWithAddress()`: `scopeWithAddress()` / `hasAddressSql()` / `hasAddress()` filter on it and `ProspectingListing::resolveDisplayAddresses()` is what the row SHOWS - own text, else the matched own Property (same agency), else the file's street number + street name (a number of 0 is dropped; another agency's file is never used). `work()` batch-loads it once per page (one Property query, one file query) into `displayAddressByListingId`. Result: the "No address yet" note can never appear on a row the With-address-only filter lets through (it did for 226 QA1 rows whose listing text was blank but whose file had a street, e.g. PP-T4203603 on file 11487). Display only - nothing written; matcher, merge and sweep code untouched. A doubled word in a street name ("Newport Road Road") is a data issue and is not repaired by this.

## 18. Twin adverts, address visibility and history that survives (build source per Johan's GO 31 Oct 14:25, AT-520 / MIC lane; built step by step - see 17.2 for what has landed)

**Johan's direction (relayed 31 Oct, not verbatim).** (A) Matching essentially works only on properties with an address. (B) Same agency, cross-portal twins - e.g. RE/MAX on Private Property with an address and on P24 without one: when we can match confidently (same agent, same advert wording, same features, same rooms/sizes), link them and the no-address advert shows the address. Never across different agencies. (C) Whenever anyone in OUR agency has linked an address to the property (deed capture, Edit address, prospecting capture), show that address. (D) Claims and history are respected: a property one agent claimed, deed-captured, linked a contact to and marked "not on market", that later comes back on the market in MIC, stays in MIC with that history and the reason shown - never silently dropped or reset.

### 18.0 What exists today (investigation, QA1 `084f5f174`)
- **The only cross-portal join is by address.** `ProspectingApiController::assignPropertyGroup()` (line 497) gives two adverts one `property_group_id` when `normalized_address` (address + suburb) is equal on the other portal, same agency. Nothing else: no agent, wording, features or photo signal. The resolver (`PropertyIdentityResolver`, `TrackedPropertyMatchOrCreateService`) joins on advert number, erf/LPI/scheme, address, GPS, fuzzy street - all per agency. **Nothing joins different agencies**; the shared pool (`SharedPortalPool`) is keyed by one portal advert number and never links portals. The advertiser's agency name is used in exactly one place: hiding our own agency's adverts from "other agency stock" (`OnMarketStockService` line 386).
- **The signals do not exist in the data yet.** QA1 agency 1: `prospecting_listings.agent_name` is empty on **0 of 47,989** adverts; the advert wording is not stored at all (no description column; the extension payload carries none); `agency_name` is filled on about 95%. What we hold: agency name, suburb, type, beds, baths, garages, floor and erf size, price, first photo (thumbnail path only), dates.
- **Twins without an address (QA1, agency 1, active adverts, never linked).** 14,841 active adverts have agency name, price, beds and baths; 11,915 have no address. Same advertiser agency, same suburb spelling, type, beds, baths, other portal: **26 candidate pairs for 19 adverts**; STRONG (price within 1%, garages and sizes agree where both known) **6 adverts, each with exactly one twin**; WEAK (price within 10%) 19 adverts, 15 with exactly one twin. Examples (no-address P24 advert -> addressed PP advert): 46160 P24-117533153 -> 40611 PP-T5589119 (65 Outlook Road, Southbroom, R9,995,000, Home Finders Coastal, files 445/8996); 45404 -> 43918 (5263 Illovu, Lovu, R740,000, Ocebisa Properties); 45443 -> 43906 (136 Lucky Dube Drive); 45284 -> 43902; 45263 -> 43236. The count is small because the suburb must be spelled identically on both portals ("Uvongo" vs "Uvongo Beach" misses) and only 2,926 active adverts carry an address.
- **P24 groups one property advertised by several agencies - and the data is already in our stored pages, but nothing reads it.** A P24 search-results tile for a grouped property carries `<span class="js_groupedListingsLink" data-group-listing-id="<groupId>" data-listing-group-text="3 Estate Agencies" data-href="/Results/GroupedListings/<groupId>?...&listingNumbers=[116889663,116619470,...]">Listed by 3 Estate Agencies</span>`: the **group id** and **every member's listing number** are in the page, no click needed (the modal you opened loads that same `/Results/GroupedListings/<groupId>` URL, which returns each member's agency, price and size). Evidence on QA1: the 926 stored raw captures (`portal_captures.raw_html_path`, 825 search pages, 12 Jun) hold **1,551 distinct groups, 4,916 member listing numbers** (sizes: 790 pairs, 348 of 3, 162 of 4, up to 17). **Nothing uses it:** the MIC import API validates no group field (`ProspectingApiController` lines 33-55), the packaged extension (`storage/app/corex-extension.zip`, `content-p24-detail.js`, `content-cmainfo.js`, `background.js`) contains no `GroupedListings`/`data-group-listing-id` code, the presentation parser `Property24SearchParserV1` has no group handling, and no app code references the markers. The group is lost at capture.
- **How good is P24's grouping?** Of the 1,551 stored groups, 909 have two or more members already in MIC; **835 of those sit on 2+ different files** (unlinked twins); 485 mix an addressed advert with a no-address one, so **704 no-address adverts would gain an address from a group-mate**; 2,196 member numbers are adverts of other agencies we never captured; 35 groups include one of our own adverts. But the grouping is **not proof**: of 181 groups with two or more numbered addresses, **51 (28%) show different street numbers** (e.g. 111 vs 117 Marine Drive, 14 Gloucester Road vs 2 Kerry Avenue), 154 of the 909 differ in bedrooms, 24 in property type, 12 have a price spread over 1.5x. P24 evidently groups by the advertised development/stand as well as the exact home. So a P24 group is a **strong candidate set, not an identity**.
- **Private Property:** unknown. No PP raw page is stored on QA1 (every stored capture is www.property24.com), so I cannot say from data whether PP shows an equivalent badge. It needs a one-minute check in Chrome on a PP results page for a property listed by several agencies (look for "Listed by N agencies" / "also listed"); then the same capture applies.
- **Claims, status and reason.** Claim = `prospecting_claims` (per advert, 48 h freshness, closed when the property is marked Not selling); the agency's file-level status and reason = `tracked_property_agency_work.prospecting_status` / `prospecting_reason` / `claimed_by_user_id` (one row per agency per file, UNIQUE `(agency_id, tracked_property_id)`), plus `tracked_property_agency_work_events` (history). Statuses: interested, contacted, **not selling**, mandate elsewhere, do not contact. `ProspectingStatusSync` keeps the property's Not selling in step with the work record. Deeds data lives on the file (`title_deed_number`, `deeds_captured_at`, `erf_number`, ...), the contact on `owner_contact_id` / `tracked_property_owners`.
- **Merge keeps history - with one gap.** `TrackedPropertyMerger::merge()` moves 25 tables to the winner with `UPDATE IGNORE`. For the work record (unique key) a loser's row **stays on the loser** when the winner already has one: its claim, status and reason are then not visible on the surviving file. Deeds columns and `owner_contact_id` are copied only when the winner has none (contact) - deeds columns are not copied at all. **QA1 check of the 333 files merged by M2/M3: 0 had a claim, a status or reason, an own-stock flag, deeds data, an owner row or a comment; nothing is left on any loser (adverts, claims, owners, comments all moved).** So nothing was lost - the gap is latent and must be closed before any sweep merges a file that has history (18.4).
- **What MIC shows today.** The file-level status, reason, claimant and history show on the property page (`partials/_agency-work.blade.php`). The MIC **list** rows do not read the work record (`ProspectingListingStateEnricher` has no work-record input): they show the advert's own claim chip (active claims only; twins are badged by property id or normalised address), the durable "Prospected" badge for a worked-and-closed claim, and IN STOCK. A claimed + deeded + contact-linked + Not selling property that returns on the market as a **new advert on the same file** is therefore listed as an ordinary unclaimed advert (the old claim was closed by Not selling); the reason and history are only visible after opening the property. If the new advert instead lands on a different file (no address, no advert-number match) the history is not reached at all.

### 18.1 B - same-agency cross-portal twin link
**Scope guard.** Candidates are read only from the viewing agency's own adverts (`agency_id`), and the two adverts must carry the same **advertiser** agency name (normalised: case, spaces, "Pty", "(Pty) Ltd", punctuation). A twin is never looked for under another agency's rows, and an advert whose advertiser is another agency than the one it was captured under is still only matched to that same advertiser. Different advertisers are never twins on our own inference, whatever else agrees - the only cross-agency link is the portal's own group (18.1a).
**Candidate.** An active advert with **no address** (or only a suburb) and an advert on the **other portal** that has a street address (or sits on a file that has one), same agency, not already on the same file.
**Signals and gates (all agency-configurable, 18.5).** Required: advertiser agency name equal; suburb equal after alias normalisation (not literal spelling - `suburb_normalised` and the town/suburb alias table); property type equal; bedrooms and bathrooms equal; garages, floor size and erf size equal where both sides have them; price within `twin_price_tolerance_pct` (default 1). Corroboration, counted: listing agent name equal (needs capture); wording similarity >= `twin_wording_threshold` (needs capture); first-photo fingerprint equal (needs capture); a floor or erf size present and equal. A pair needs `twin_min_corroboration` corroborations (default 2).
**Result.**
| Evidence | Action | Recorded as |
|---|---|---|
| Exactly one candidate, gates met, corroboration >= minimum | link automatically (`TrackedPropertyMerger::moveAdvert` onto the file with the address; an emptied shell is flagged duplicate, never deleted) | confidence `high`, method `twin_portal`, reasons listed |
| Gates met, corroboration below the minimum, or two or more candidates | **review list** (Possible matches), one pair per candidate | `probable`, `twin_portal` |
| Any gate fails (agency, type, beds, baths, price) | nothing | - |
A pair an agent answered "not the same" is vetoed for good (existing mechanism). The advert numbers of both adverts stay in the audit chain. Until agent name, wording and photo fingerprint are captured, only sizes can corroborate, so almost every twin goes to the review list - that is deliberate: **a wrong merge is worse than a missed one** (the over-merged blocks of 30 Oct).
**Capture that must be built for this to work (extension + MIC ingest, not the matcher).** `agent_name` (already a column, never filled), an advert description stored as normalised text plus a fingerprint (new columns on `prospecting_listings`), and a first-photo fingerprint (perceptual hash of the thumbnail). All three are public portal facts and may go to the shared pool; they carry no agency data.

### 18.1a Cross-agency twins through P24's own group (the one allowed cross-agency link)
**Why it is allowed.** 18.1 never matches across agencies on our own inference. This rule does not infer anything: it uses the portal's own statement that these adverts are one property. The members' agencies, prices and sizes are public portal facts we already capture; nothing private crosses (claims, comments, owner, deeds stay in the viewing agency's layer, the shared pool keeps its allow-list).
**Capture (extension release, T8).** On a search-results tile read `data-group-listing-id`, `data-listing-group-text` and the `listingNumbers=[...]` of `js_groupedListingsLink`; send `portal_group_id` and `portal_group_members[]` (canonical `p24:<number>`) with the lead advert in `/prospecting/import`. Optionally fetch `/Results/GroupedListings/<groupId>` once per new group to read each member's agency, price and size (and to capture member adverts we never saw, as `no address` adverts, so the group is complete). New table `portal_advert_groups` (portal, group id, member canonical refs, first/last seen; public facts, no agency column - same discipline as the shared pool) and a nullable `portal_group_id` on `prospecting_listings`. Back-fill the 1,551 groups already sitting in the stored raw pages (`php artisan portal-groups:backfill-from-captures --dry-run`).
**Use.** The group is a **candidate set**. For an agency, the members present as adverts in its MIC become one candidate set for ONE property file:
| Evidence across the members | Action |
|---|---|
| No contradiction (street numbers equal or missing, unit equal or missing, beds/baths/type equal or one side missing, erf equal or missing) AND at least one member has an address (own file, deeds, Edit address, or advert) | **link all members to one file** (`moveAdvert`), confidence `high`, method `portal_group`; the no-address members show that address (18.2). |
| No contradiction, but no member has any address | link them to one file (no address yet); when an address arrives it shows on all. |
| Any contradiction (different street number, unit, erf, bedrooms, type; price spread beyond `portal_group_price_spread`) | **do not link**; subset links only where members agree with each other; the rest go to the review list as `portal_group` pairs with the contradiction named. (51 of 181 numbered groups on QA1 would split this way.) |
A member whose advertiser is our own agency and whose file already has history keeps that file as the winner (18.3). A member already on the review list as "not the same" stays out. Re-capturing a group never undoes an agent's unlink.
**Display.** Every member shows the address any member or our agency has (18.2), with the source "from P24 group" or the underlying source; the property page lists the group: "Also advertised by: eXp R1,390,000, Mandated Property Group R1,395,000, Just Property R1,390,000" (public facts), and the competing-adverts count feeds the existing cross-listed tile.
**Settings.** `portal_group_link_enabled` (default on), `portal_group_price_spread` (default 1.5, range 1.1-3), `portal_group_auto_link_min_confidence` (`high`/`exact`; `exact` sends every group to review).

### 18.2 C - show the agency's own address
**Rule.** A file shows the best address **this agency** has confirmed, in this order: (1) deeds capture, (2) an agent's "Edit address" (`tracked_property_addresses` verified / high), (3) a prospecting or portal capture with a street, (4) the advert's own address. Every advert on the file that has no address of its own displays the file's address, labelled with where it came from ("Address from deeds capture, 14 Oct"). Read-only display: the advert row is not rewritten. Never from another agency's file or from the shared pool's contributors. A GPS pin is not changed by this.
**Where.** MIC list row title and facts line, the property page header, the slide-over - one resolver (`FileAddress`, reading `tracked_property_addresses` + the file columns) so every screen agrees.

### 18.3 D - history is never dropped or reset
Invariants, each with a test:
1. **A new advert for a property the agency already knows attaches to its file** (advert number, address, twin link, or the review list) - it never creates a second file for a property that has history when the match is confident, and when it is not confident the pair is on the review list **with the history shown on the card**.
2. **Merge and attach never lose the work record.** When both files have one, the surviving record keeps the active claim, the **most recent** status with its reason and who/when, the deeds columns the winner lacks, the contact link, and moves the other's history events; the loser's row is archived, not left behind. (Closes the `UPDATE IGNORE` gap in 18.0.) A claim by one agent is never replaced by a claim from another during a merge; two live claims on different adverts stay (first claim wins display, both kept).
3. **A property marked not on market that returns on the market is flagged "Back on the market" and keeps everything**: the claim history, the deed-captured badge, the linked contact, and the reason ("Owner not selling - sold to a family member, set by A. Agent, 12 Aug"). The claim is **not** silently re-activated (it was closed by Not selling) and **not** erased; the claimant is told the property is back (in-app alert, existing alert channel) so they can re-claim with one click. Whether the status is automatically lifted on return is Johan's call (18.6).
4. Nothing is hard-deleted; every change leaves a dated history line.

### 18.4 What MIC displays
- **List row:** a status chip from the file's work record (the reason on hover) - "Owner not selling - back on the market", "Mandate elsewhere", "Do not contact"; a "Deed captured" tick; the address per 18.2 with its source; the existing source tag/alert marker (16.6) unchanged. The enricher gains one input: the work record per file (agency-scoped query, one query per page).
- **Property page:** unchanged panels, plus a "Back on the market" banner with the reason, who set it and when; the twin link shown as "Same advert on Property24 (P24-117533153)" with Unlink.
- **Review list:** a new kind "Same advert on both portals" with both adverts side by side (portal, price, rooms, sizes, agent, first photo, wording diff when captured) and the history of the file the advert would join.

### 18.5 Settings (Settings only, defaults sensible for any agency; not the Setup Wizard, consistent with the address-matching family - flagged for Johan)
`address_match_settings` new columns, page `/corex/settings/prospecting/address-matching`: `twin_match_enabled` (default on), `twin_price_tolerance_pct` (default 1, range 0-5), `twin_min_corroboration` (default 2, range 1-4), `twin_wording_threshold` (default 85, range 60-100), `twin_auto_link_min_confidence` (`high`/`exact`, default `high`; `exact` sends every twin to review). Saver guards each write with `$request->has()`. No agency id, brand or HFC wording anywhere (rule 9): the second agency gets the same defaults.

### 18.6 Decisions for Johan (business, not technical)
1. When a "not selling" property comes back on the market, should the status clear itself, or stay until an agent clears it (the banner shows either way)? Recommended: stay, with the banner and an alert to the claimant.
2. Is a wrong twin link (one house tied to another agency's lookalike inside our own agency) acceptable if it can be undone in one click, or must every twin go through the review list until the wording/agent capture exists? Recommended: review list only until capture exists, automatic after.
3. P24 itself says these adverts are one property across agencies. We would link all members of a P24 group to one file (and show any known address on all of them) only when nothing contradicts - 28% of groups mix different street numbers, so those would go to the review list. OK?
4. OK to ask the browser extension to start sending the agent name, advert wording and first photo? (Public facts; needs an extension release.)

### 18.7 Build list (sizes)
| # | Piece | Size |
|---|---|---|
| T1 | Work-record merge (invariant 2): fold two records, keep claim/status/reason/history, archive the loser's row; tests for claimed + not-selling + deeds + contact | M |
| T2 | `FileAddress` resolver + show on list, page, slide-over (18.2) | M |
| T3 | List-row status chip + "Back on the market" banner + alert to the claimant (18.3.3, 18.4) | M |
| T4 | Twin matcher + sweep step + review-list kind (18.1) using what exists today (agency, suburb alias, type, beds, baths, garages, sizes, price) - all to review, none automatic | M |
| T5 | Extension + ingest capture of agent name, wording, first-photo fingerprint (public facts, pool allow-list) | L |
| T6 | Corroboration scoring + auto-link and the five settings (18.5) | M |
| T8 | Extension release: read `data-group-listing-id` + member numbers (and the `/Results/GroupedListings/<id>` member list) from P24 search tiles; import API fields; `portal_advert_groups` table; back-fill command for the 1,551 stored groups; group link + review kind with contradiction rules (18.1a) | L |
| T9 | Check Private Property for an equivalent grouping (manual, then the same capture) | S |
| T7 | Tests: agency isolation (a twin never reads or links across agencies), unit/flat rule, veto, history survives merge and return-to-market | M |

## 19. Owner-advertised properties (build source per Johan's GO 31 Oct 14:25, AT-520 / MIC lane; built step by step - see 17.2 for what has landed)

**Johan:** private-seller adverts are gold. Some Private Property adverts say "Listed by owner" (e.g. `privateproperty.co.za/for-sale/advanced-search?si=1334,1338,1336,1340,1337`), and P24 has private-seller adverts too. They must be found, tagged, filterable, and prioritised - and contacted lawfully.

### 19.0 What exists today (investigation, QA1 `084f5f174`)
- **Nothing records the advertiser type.** No column, flag, API field or code path says "owner / private seller" (grep of `app/`: none). The MIC import validates `agent_name` and `agency_name` only (`ProspectingApiController` lines 45-46). A private seller leaves a **blank agency name** and nothing else.
- **P24 card.** The extension (`content-p24-detail.js` line 262) reads the agency from the logo block `.p24_branding img[alt]`; the listing page falls back to `.p24_agencyName` / `.p24_listingBranding img[alt]` (line 646). In the 8,775 distinct tiles in the stored raw search pages, **330 (3.8%) have no `p24_branding` block**, and the tile carries **no "Private Seller" text** (no such string in any of the 928 stored pages). That set also contains **developments** (tiles reading "Development in Uvongo From R...") and agencies without a logo, so "no logo" is not proof of a private seller. Of the 160 of those tiles that are in MIC, 87 have a blank agency name and 73 have one; of 1,873 branded tiles in MIC, 89 (4.8%) have a blank name. I found no stored P24 listing page for a known private seller (all 101 stored detail pages are agency adverts), so the P24 listing-page marker is **unconfirmed** and needs one real example.
- **PP card.** `content-cmainfo.js`/`background.js` take the agency as the last `img[alt]` on the card that is not a listing photo (extension lines 861-872): for a private advert this yields a blank or, worse, a wrong name. The text "Listed by owner" is **not read anywhere**. On a PP **listing page** the page's own data says it: `window.serverVariables.bundleParams.agencyInfo` is null for a private seller and `contactDetails` holds the contact (extension line 11785, used only by the other-agency-stock capture, which allows a blank agency but does not store a flag). No PP raw page is stored on QA1, so the card markup could not be checked from data.
- **How many look like private sellers on QA1 (agency 1).** Blank agency name: **3,402 of 47,989 adverts (7.1%)** - 2,247 PP and 1,155 P24. That is an upper bound, not a count: blank also means a failed read. The true number is **unknown until the capture is fixed** (19.2).
- **Opt-out handling that exists.** Per contact and channel: `contacts.opt_out_email/sms/whatsapp/call`, `messaging_opt_out_at/reason/source/kind`, `last_consent_check_at` (`Contact.php` lines 64-72); the Seller Outreach opt-out and its event (`OptOutRecorded`, per-send self-service link); the work-record status "Do not contact" (`TrackedPropertyAgencyWork`); specs `contact-consent.md`, `seller-outreach-spec.md`. All of it keys on a **contact record the agency already holds**. **I found no code that checks an external national opt-out / pre-emptive-block registry**, and an advert's owner is usually not yet a contact.

### 19.1 Data
- `prospecting_listings.advertiser_type` enum(`agency`,`owner`,`developer`,`unknown`) default `unknown` - a **public** fact (also on `portal_advert_facts`, allow-listed). `owner` is set only on positive evidence, **never inferred from a blank agency alone**.
- `prospecting_listings.owner_contact_name`, `owner_contact_phone`, `owner_contact_email` (nullable): **agency layer only**, never in the shared pool. Stored only when the advert **visibly shows** them on the page the agent is looking at; no "reveal number" click is automated and nothing is fetched behind a login. Each carries `owner_contact_seen_at` and the page source.
- File flag: `tracked_properties.owner_advertised` (boolean, derived): true while any active advert on the file is `owner`; set by the resolver on attach, cleared when the last owner advert goes inactive or an agency advert takes over, leaving a history line ("was advertised by the owner until 3 Nov"). Counts as history for 18.3: it is never silently reset.

### 19.2 Capture (extension release, with T5 and T8)
P24 card and listing page: `advertiser_type = owner` only when the page states it (the explicit private-seller label once confirmed on a real example), `developer` for development tiles, `agency` when a branding block / agency name is read, otherwise `unknown`. PP card: read the "Listed by owner" text; PP listing page: `agencyInfo == null` plus the contact block. Send `advertiser_type` (+ visible contact, if any) in `/prospecting/import`. Back-fill from the stored raw pages where they exist (P24: the 160 no-logo tiles already in MIC, classified by the markers above); everything else stays `unknown` and corrects itself on the next visit. **First step is a 10-minute check by a human of one real private advert on each portal (card and listing page) so the markers are not guessed.**

### 19.3 What MIC shows
- **Row tag** "Owner advertised" (its own colour token, hover: "Advertised by the owner on PP since 12 Oct"); on the property page a banner "Selling privately", the advert(s), and the contact details **if the advert shows them**, with the date seen.
- **MIC filter tick** "Owner advertised" in the filter rail (default off; shows a pill when on; counts shown) and a saved preset in the action presets.
- **Work-list priority signal.** Unclaimed owner-advertised adverts rank above agency adverts on the Work tab, longest on market first, with the suggested action "Call the owner" (agent-initiated, 19.4). Setting `owner_advert_priority` = off / normal / high (default high).
- Combines with 18: an owner advert that is a twin of an agency advert (18.1/18.1a) shows both; "owner" stays until the agency advert is the only active one.

### 19.4 Contact rules (compliance - applies before any contact)
1. **Owner adverts are not exempt from opt-out.** Before a call, SMS, WhatsApp or email to an advert's owner, the existing checks must pass: any matching contact's `opt_out_*` / `messaging_opt_out_at`, the file's "Do not contact" status, and the Seller Outreach opt-out. A blocked contact shows the reason on the button; it cannot be overridden by the row.
2. **National opt-out / pre-emptive block (NCC; CPA section 11 and POPIA direct-marketing rules, as Johan raised).** No registry check exists today. The spec requires a **pre-contact gate**: the "Call/Message the owner" action runs one function (`OwnerContactGate`) that (a) applies rule 1, (b) checks the agency's own do-not-contact list, and (c) checks the registry when the agency has one configured. **Which registry and whether a check is mandatory for a person who has just advertised their own home publicly is a compliance ruling for Johan before build**; until then the default is: no automated contact of any kind, first contact is agent-initiated and one-to-one, and the screen says so.
3. Contact details from an advert are personal information (POPIA): stored agency-only, shown to staff with access to that file, purged with the advert (existing retention), never exported in lists or reports without the same permission as owner contacts.

### 19.5 Settings (Settings only, not the wizard)
`owner_advert_priority` (off/normal/high, default high), `owner_advert_store_contact` (default on), `owner_advert_alert` (notify the agents who work that suburb when a new owner advert appears; default off). Agency-neutral wording (rule 9).

### 19.6 Build list
| # | Piece | Size |
|---|---|---|
| O0 | Human check: one private advert on P24 and on PP (card + listing page) to confirm the markers | S |
| O1 | Extension + import: `advertiser_type`, visible contact; columns; pool allow-list for the type only | M |
| O2 | Resolver: derive/clear `owner_advertised`, history line; back-fill from stored raw pages | M |
| O3 | Row tag, filter tick, preset, property-page banner (screens: coordinate with cc6) | M |
| O4 | Work-list priority + setting | S |
| O5 | `OwnerContactGate` (opt-out checks + do-not-contact + configurable registry hook) and tests (an opted-out contact is blocked; another agency's owner contact never visible) | M |

### 19.7 Decisions for Johan
1. Registry: which national opt-out list must we check before contacting an advertising owner, and is a check needed at all for someone who has publicly advertised their own home? (Compliance officer.)
2. OK that contact details are stored only when the advert shows them, with no click-to-reveal?
3. Priority default "high" for owner adverts above agency adverts on the Work tab?

### 17.2 AT-520 build log (MIC identity + crawl lane)
- **4.1 Merge safety (31 Oct).** `TrackedPropertyMerger` is a container singleton, so the row journal opened by a command is the one every merge writes to (M2/M3 journals were 0 bytes). `merge()` now folds two work records into one (`combineWorkRecords`): the claim is kept (the winner's when two agents claimed - the other claim is written to the history), the most recent status wins with its reason (the other status is written to the history), own-stock is OR-ed, the loser's history lines move to the survivor, and the loser's record is soft-deleted (never hard-deleted). Two different contacts on the two files: the winner's stays, the other is written to the history. Deed columns the survivor lacks are carried over, never overwritten. Every changed column is journalled. Tests: `TrackedPropertyMergerSafetyTest` (7). QA1 real-data check: 2,321 work records (207 claimed, 54 with a status); in today's same-address groups only 1 pair has a work record on both files and neither holds a claim or status.
- **4.2 After-import sweep (M4) + switch (31 Oct).** A listener (`QueueSameAddressSweep`) on `TrackedPropertyCreated`, `TrackedPropertyEnriched` and `TrackedPropertyAddressAdded` - which every ingress raises through the one door (extension import, deeds capture, CMA import, manual entry) - calls `RunSameAddressSweepJob::schedule($agencyId)`: one cache key per agency (15 min) and ONE job queued 3 minutes later, however many files an import touches; the job clears the key as it starts, so work arriving during the run schedules the next. The job runs `SameAddressSweep` scopes `no-suburb` then `exact` (apply), journals every row to `storage/app/reports/same-address-auto-<date>.jsonl` and logs the counts. Houses/stands merge; flats with no unit number and big blocks go to the Possible matches list; "not the same" is respected. **Switch:** `address_match_settings.same_address_sweep` (boolean, default ON for every agency, including an agency with no settings row), on `/corex/settings/prospecting/address-matching` ("Tidy up same-address properties after every import"); a post that does not carry the switch (`same_address_sweep_present`) never turns it off. Settings only, not the Setup Wizard (Johan's ruling for this family). Email-alert linking creates no files and changes no address, so it does not trigger a sweep. The test/demo agency (20 on QA1) is excluded by switching ITS setting off, not by an agency id in code. Tests: `SameAddressSweepAutoTest` (6).
- **4.3 Crawl record, Gone rule, reappearing (31 Oct).** Contract: `mic-work-tab.md` 11.3. `mic_crawls` (one per extension session: agency, user, portal, sale/rent, started/finished, pages read of total, adverts seen, complete, filtered, gone counts) + `mic_crawl_suburbs` (the suburbs the session's adverts are in, by our `p24_suburbs.id`), kept current by an observer on `prospecting_searches` (cc3's crawl columns; a legacy search is recorded as `legacy-<id>`). `SuburbReconcileService::reconcile` (called by the importer's final batch, unchanged) now finalises the crawl and applies `GoneRule`: in the covered suburbs, same portal and sale/rent type, an advert that is active or under offer and was not seen since the crawl began becomes `gone` (or `probably_sold` when it was under offer); `sold` is never touched; a partial, empty or FILTERED crawl (P24 `sp=` price/beds/type, a `/houses-for-sale/` path, PP `ps=`/price/beds) retires nothing. `ProspectingListingLifecycleObserver`: a re-sighted advert with `withdrawn`/`gone`/`probably_sold` and the active flag back on is set to `active` (the 4,286 PP adverts stuck as `withdrawn` while active). Helpers: `CrawlRecords::completeCrawlWithin()`, `latestCompleteCrawl()`, `lastCrawlBySuburb()`. Command `mic:fix-stuck-withdrawn --dry-run|--apply` (journalled). Tests: `CrawlRecordAndGoneRuleTest` (8). Reported, not changed: `ProspectingImportPricelessListingTest` fails at baseline (price null expected) after cc3's 3.4.
