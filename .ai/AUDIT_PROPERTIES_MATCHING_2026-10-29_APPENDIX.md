# Audit leg 1 — Technical appendix (QA1 `8dfee589f`, 2026-10-29)

Companion to `.ai/AUDIT_PROPERTIES_MATCHING_2026-10-29.md` (plain-language report). Read-only: all database access used SELECT/SHOW/DESCRIBE
through a guard script that refused any write keyword; nothing in `/corex-qa1` was changed. Paths are relative to the repo root.
"UNVERIFIED" = could not be proven from code or data. Counts are `deleted_at IS NULL` unless said otherwise. Only agency 1 (HFC) has MIC data.

Contents: Part 0 central matcher and cross-source evidence (written by the lead auditor); Part 1 own stock, deals, deeds, map;
Part 2 MIC, email alerts, own portal imports, portal side-tables; Part 3 presentations, CMA imports, address normalisation, suburb reference data.
Parts 1 to 3 are the three source audits as written, with their own SQL.

---

# Part 0 — Central matcher and cross-source evidence

## 0.1 The matcher: `app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php`

Entry `matchOrCreate(agencyId, facts, source, actorUserId)` (:145). Resolution order in `resolveMatch` (:655):

| Step | What | Where | Notes |
|---|---|---|---|
| 0 | Address history (`tracked_property_addresses`) | `resolveByAddressHistory` :1482 | an address an agent corrected once always re-resolves to the same file |
| 1 | Source reference exact | :689-709 | key = `(agency_id, source_type, source_ref)`; the type is compared **as written**, so `p24`/`P24-123` ≠ `property24`/`123` ≠ `chrome_capture`/`123`. Guarded by `numbersConflict()` (self-heal on a wrong link) |
| 2 | GPS within `GPS_TOLERANCE_DEGREES = 0.00005` (~5 m) | :712-736 | tries `cma_gps_*` then `latitude/longitude`; **no check of `geo_confidence`**; first row wins (no ordering) |
| 3, 3b, 4 | Erf(+portion/LPI)+suburb, scheme+section, street number+street+suburb | `scoredTrackedMatch` :856 → `AddressMatcher` + `AddressMatchScorer` | scored; labels `3_erf_suburb`, `3b_scheme_section`, `4_normalised_address` (RULE_STRATEGY :79) |
| 5 | Token overlap ≥ 2 words, same suburb spelling set, `limit(50)`, number veto, street agreement | :750-846 | ties broken by nearest GPS, else oldest id (`5_token_overlap_untiebroken`) |

Scorer (`app/Services/Address/AddressMatchScorer.php`): tiers `exact | possible | street_only | none`; vetoes on street number, unit/section, erf,
portion, LPI, scheme number, and an unrelated suburb; weights lpi/erf 40, scheme 25, unit 15, suburb 15 (neighbour 7), street 15, number 15, type 3, gps 5; the score ranks, the tier decides.
`DECISION_TRACKED_SOURCE_TYPES = ['deeds_capture']` (:92): the agent-facing decision log and veto exist only for deeds captures; extending them is a one-line change per the docblock.
Other places that decide "same property" separately: `ProspectingStockMatchService` (Pass 1 string equality on `normalized_address`), `OnMarketStockService` (advert number then normalised address), `DealPropertyLinkService` (own 0-100 score), `MapPinService` (read-time fold), `SuburbMatcher` (presentations).

Road to Sold keys on the same spine: `app/Services/RoadToSold/Support/TrackedPropertyResolver.php` (a listing or own property with no property file gets one via `matchOrCreate`; own stock uses `source_type='agency_stock'`, `source_ref='property:{id}'`, 5 rows on QA1); tables `rts_*` hold `tracked_property_id` FKs (`database/migrations/2026_10_29_300200_create_rts_holds_and_flags_tables.php:29,48`).

## 0.2 Reference vocabularies (`tracked_property_external_refs`, unique `(agency_id, source_type, source_ref)`)

```
SELECT source_type, COUNT(*) refs, COUNT(DISTINCT tracked_property_id) tps, SUM(deleted_at IS NOT NULL) del FROM tracked_property_external_refs GROUP BY 1 ORDER BY refs DESC
```
| source_type | refs | property files | written by |
|---|---|---|---|
| p24 (`P24-123`) | 23,385 | 20,646 | MIC extension (`ProspectingApiController`) |
| pp (`PP-T123`) | 19,555 | 18,414 | MIC extension |
| chrome_capture (`123`) | 6,043 | 6,043 | `PortalCaptureController::linkPortalItemsToTrackedProperties` |
| deeds_capture (`cmainfo:<scheme/section>`) | 2,220 | 2,205 | deeds capture API |
| property24 (`123`) | 389 | 262 | `Property24SyndicationService::writeP24ExternalRef` (own listings) and `P24LeadService` |
| cmainfo (`report:N`, `pres_N`) | 302 | 232 | CMA parsers / presentation field extraction |
| manual_prospect_entry | 50 | 50 | manual |
| private_property | 10 | 10 | `PpLeadService` (10 empty files with no address) |
| map_prospect_launch | 9 | 8 | map pin → prospecting |
| agency_stock | 5 | 5 | Road to Sold |

## 0.3 Cross-source advert-number overlap

```
-- x1.sql (abridged): joins on the P24 number with the 'P24-' prefix applied/removed
alerts (p24_listings) rows                                            14,092 (distinct 14,092)
alert refs also in MIC (prospecting_listings, same 'P24-<n>' string)  8,226
alert refs NOT in MIC                                                  5,866
portal_listings rows                                                   8,767
portal_listings that equal a MIC P24 row (CONCAT('P24-',id))           5,433   -> point at DIFFERENT property file: 4,561; same: 0; one side null: 872
own properties with any P24 ref                                        8,243
  of which found in MIC by 'P24-'+ref                                    247
  of which found in alerts                                               381
MIC duplicates by (portal_source, portal_ref)                          0 (unique index)
```
Formats seen: MIC `P24-115909286` / `PP-T1014486`; alerts `P24-117743851`; `portal_listings` and own `p24_ref` bare digits; own `pp_ref` `T5663557`.

## 0.4 Property file quality (`tracked_properties`, 46,356 live)

```
SELECT status, capture_kind, COUNT(*) n, SUM(erf_number<>'') erf, SUM(title_deed_number<>'') deed, SUM(lpi_code<>'') lpi,
 SUM(street_name<>'' AND street_number<>'') struct, SUM(suburb<>'') sub, SUM(latitude IS NOT NULL AND latitude<>0) gps ... GROUP BY 1,2
```
| status | capture_kind | n | erf | deed | lpi | street no.+name | suburb | GPS |
|---|---|---|---|---|---|---|---|---|
| active | NULL (MIC / CMA / capture-born) | 44,434 | 366 | 53 | 1 | 7,866 | 38,316 | 35,185 |
| promoted | deeds_capture | 1,553 | 704 | 1,549 | 3 | 1,324 | 1,553 | 1,550 |
| active | deeds_capture | 257 | 145 | 257 | 2 | 252 | 257 | 257 |
| promoted | NULL | 112 | 53 | 70 | NULL | 110 | 112 | 88 |

(Counts taken before agent audits; Parts 1 to 3 quote the later totals 46,356 / 37,080 with GPS.)

## 0.5 GPS precision (affects matcher step 2)

```
SELECT geo_confidence, COUNT(*) n, COUNT(DISTINCT CONCAT(latitude,',',longitude)) distinct_points FROM tracked_properties WHERE deleted_at IS NULL AND latitude IS NOT NULL GROUP BY 1
NULL 2,048 / 1,201 points | exact 4,086 / 1,670 | high 43 / 20 | street 2,601 / 91 | suburb 28,302 / 1,332
```
528 suburb-level points are shared by more than one property file; the largest is shared by 1,928. Matcher step 2 does not read `geo_confidence` (:712-736). Whether this has produced false merges at ingest is UNVERIFIED (ingest paths that carry GPS are few: MIC rows carry none at capture), but the geocoder writes these pins afterwards and later facts that carry the same pin would match them.

## 0.6 Over-merged property files (several different units / adverts on one file)

```
property files by number of P24+PP refs:  6-9: 133 files (932 refs) | 10-19: 46 (586) | 20+: 22 (772)
MIC rows on files with ≥10 refs: 1,358
top: TP 1480 "303 / Pebble Beach, 10 Heleza Boulevard" 75 refs | 7599 "1603 / Pearls Of Umhlanga, 6 Lagoon Drive" 69 | 1056 "1 / Ocean Club Drive" 62 | 1070 405 / Avanti Residences 46 | 1012 304 / Albizia Drive 41 | 217 124 Marine Drive 40
```
Cause for TP 1480 (Part 2, A "Address handling"): `ProspectingApiController.php:553-557` takes the first number of the address string as the street number (here a unit), and the matcher's street rules then collapse units of one block. Whether each 6-to-9-ref file is a legitimate relist history is UNVERIFIED; 10+ refs on one file is not plausible as a relist history.

## 0.7 Duplicates by address (live)

| Where | Rule | Groups | Rows |
|---|---|---|---|
| tracked_properties | same number + street + suburb_normalised + unit | 97 | 773 |
| tracked_properties | same erf + suburb + unit + section | 7 | 14 |
| properties (same agency; Part 1 query) | number+street+suburb+unit, rows with all parts | 234 | 503 |
| properties (all agencies; lead auditor query) | number+street+suburb+unit | 676 | 1,521 |
| MIC (same portal, same `normalized_address`) | p24 1,090 groups / 2,844 rows; pp 368 / 870 | 1,458 | 3,714 |
| MIC (both portals share `normalized_address`) | cross-portal grouping | 2,840 | 7,067 (1,971 groups span both portals) |
| MIC rows whose property file holds both a P24 and a PP advert | cross-portal works at file level | 1,376 files | — |

The difference between 234 and 676 groups on `properties` is that the second query ignores the agency (agency 20 holds 3,410 imported rows); it is not reconciled further. UNVERIFIED which groups are genuine second units.

## 0.8 Own stock vs the property-file spine

```
properties live 9,920 | referenced by a property file (promoted_to_property_id) 1,869 | not linked 8,051
  of the 8,051, with number+street+suburb that equals some property file's address (an unlinked twin): 506
MIC rows whose file is promoted (ours): 616 | MIC rows with matched_property_id: 1,072 | with possible_property_id: 0 | with linked_deed_tracked_property_id: 11
```
`possible_property_id` is NULL on every MIC row although 6,529 have GPS: whether `ComputePossibleStockMatchJob` (queue `matching`) runs on QA1 is UNVERIFIED.

---

# Part 1 — Own stock, deals, deeds capture, map


Code: /corex-qa1 @ QA1 8dfee589f (read-only). DB: corex_qa1 via q.sh (read-only). Date of counts: 2026-10-29.
Conventions: "live" = `deleted_at IS NULL`. `TPMC` = `App\Services\Prospecting\TrackedPropertyMatchOrCreateService`. "Facts" = the `$facts` array passed to `matchOrCreate()`.
Nothing here was written to. Anything not proven by a cited line or SQL is marked UNVERIFIED.

## 0. One-page summary (the five things that matter)

1. **The agency-stock table (`properties`) is NOT behind the central Match-or-Create rule.** Of 14 code paths that create a `properties` row, only the two that go through `TPMC::promoteToStock()` (deeds promote, Tracked-Properties promote, rental take-on, REOS take-on, Road-to-Sold) match before creating. The web create form, the wizard, mobile create, "Pull from portal", P24 CSV import, Sold import, Other-Agency-Stock import and "Duplicate listing" never call `matchOrCreate()` and never look at erf / address / GPS. Each dedupes (if at all) on one private key: P24 listing number, or `external_id`, or `(portal, listing_ref)`.
2. **There is no `properties.tracked_property_id`.** The only Property-to-TrackedProperty link is the one-way `tracked_properties.promoted_to_property_id`. In QA1, 8,051 of 9,920 live properties (81%) have no tracked property pointing at them; 4,631 of agency 1's (HFC) 6,500; 88 of HFC's 185 currently-on-market properties. A property that was typed in or imported is invisible to the tracked-property universe (map T-pin, MIC, deeds pre-check) until a Road-to-Sold list happens to mint one lazily (`TrackedPropertyResolver::forProperty`).
3. **Portal reference is stored three different ways and the portal URL is mostly not stored at all.** CSV import writes `p24_listing_number` + `p24_ref` + `external_id`; "Pull from portal" writes the portal ref into `external_id` only (no `p24_ref`, no URL column — the URL goes into a free-text note); Other-Agency-Stock writes `property_external_sources(listing_ref, listing_url)` and no `p24_ref`. So the same P24 advert imported by two routes is not recognised as one property by either route. There is no portal-URL column on `properties` at all.
4. **Structured address/erf/GPS are only as good as the source.** Of 9,920 live properties: street no + street name + suburb = 3,890 (39%); erf_number 740 (7.5%); title_deed_number 1,596; `lpi_code` (the SG21 code) 0; `street_core`/`address_parse_status` populated on only ~124/116 rows (the structured layer fills on save only; no backfill has run on QA1); GPS 8,983 (90.6%) but 6,179 have `geo_source` NULL (supplied by the P24 CSV, never geocoded) and 1,105 are suburb-centroid precision. On `tracked_properties` 28,302 of 37,080 GPS rows are `geo_confidence='suburb'` (a suburb-centre pin, not the building).
5. **Deals never create or match a property, and 83% of deals have no property at all.** 147 of 177 live deals carry only a free-text `property_address` (141 start with a street number). `DealPropertyLinkService` (scored address matcher, 0-100) exists but is a manual artisan command (`deals:link-properties`), not scheduled, and its review queue `deal_link_review_queue` has 0 rows. Those 147 deals never appear as sold pins on the map (map joins `deals.property_id` → `properties.latitude`).

---

## A. Properties — own agency stock

### A.1 Arrives via (file:line)
Every writer of a `properties` row (grep `Property::create|new Property|insertGetId` over app/ database/seeders):

| # | Path | File:line | Calls `matchOrCreate`/`AddressMatcher`? | Dedupe key actually used |
|---|---|---|---|---|
| 1 | Web "Add property" classic form `PropertyController::store` | app/Http/Controllers/CoreX/PropertyController.php:1329, `Property::create($data)` at :1551 | No | None. Only a CONTACT-phone/email duplicate guard (`guardPendingContactDuplicates` :1200, called :1490). Address guard exists only on the GET `create()` when opened from a contact (`ContactAddressPropertyGuard::findLinkableProperty/findHeldForContact`, :1137-1155) |
| 2 | Add-Property wizard step 1 `PropertyWizardController` | app/Http/Controllers/CoreX/PropertyWizardController.php:303 | No | Idempotency on the agent's OWN draft only: `wizard_token` / `property_id` (:248-262). Not address/erf |
| 3 | Mobile API create `POST /api/mobile/properties` | app/Http/Controllers/Api/MobilePropertyController.php:162 (route routes/api.php:824) | No | None. Rules at :281-283 take `street_number`, `street_name`, `address`; no erf/stand/lat/lng/title-deed in `propertyRules` (grep) |
| 4 | "Pull from portal" Chrome extension `POST /api/v1/properties/pull-from-portal` | app/Http/Controllers/Api/PropertyPullController.php:209 create / :121 lookup | No | `(agency_id, external_id = portal_ref)` incl. soft-deleted (:118-122) — restores an archived row (:179-190) |
| 5 | P24 listings CSV import (agency on-boarding) | app/Jobs/ConfirmP24PropertyRowJob.php:249 `new Property($attrs)`; lookup :76-80 | No | `(agency_id, p24_listing_number)` only |
| 6 | Sold-properties spreadsheet import | app/Services/Properties/SoldPropertyImporter.php:149 create; lookup :132-136 | No (docblock at :129 claims "non-negotiable #10" but the code is only a P24-code lookup) | `(agency_id, p24_listing_number = sheet "Code")`; rows with no code always create |
| 7 | Other-Agency-Stock (OAS) import from P24/PP page | app/Services/Properties/OtherAgencyStockImportService.php:30-41 (`new Property()`), save ~:177-214 | No | `property_external_sources (agency_id, portal, listing_ref)` unique; re-import updates in place, restores archived (:49-64) |
| 8 | MIC prospecting listing → property (Pitch Now / seller outreach / RTS pitch path) `ListingPropertyPromoter::promote` | app/Services/SellerOutreach/ListingPropertyPromoter.php:193 create | Yes, read-only: `MicPropertyReconciliationService::resolveExistingProperty` (:136-148) then falls through to create | 1) `external_id='prospecting:{listing.id}'` (:54-58); 2) raw `address`/`LOWER(TRIM(address))` + suburb (:64-75); 3) TPMC `findExistingMatch` → promoted sibling (MicPropertyReconciliationService.php:37-79) |
| 9 | `TPMC::promoteToStock()` (the "promote" gateway) | app/Services/Prospecting/TrackedPropertyMatchOrCreateService.php:2176 (create at :2303, refresh branch :2236-2285) | Yes — `resolvePropertyMatch()` :2025 | See A.4 |
| 9a | …called by deeds-capture screen | app/Http/Controllers/CoreX/DeedsCaptureController.php:1063 | | |
| 9b | …by generic Tracked Properties promote | app/Http/Controllers/CoreX/TrackedPropertyController.php:173 | | |
| 9c | …by rentals take-on (CSV) | app/Services/Rentals/TakeOnImport/RentalTakeOnConfirmService.php:180-212 (`matchOrCreate` then `promoteToStock`) | | |
| 9d | …by REOS tenancy take-on | app/Services/Rentals/TakeOnImport/Reos/ReosTenancyConfirmService.php:233,249 | | |
| 9e | …by Road-to-Sold prospect | app/Services/RoadToSold/ProspectPropertyService.php:36 | | |
| 10 | "Duplicate listing" (clone / switch type) `PropertyController::duplicate` | app/Http/Controllers/CoreX/PropertyController.php:2192 (`makeClone`, `$clone->save()`) | No | None (intended copy) |
| 11 | Demo seeders / `SeedDemoAuctions` | database/seeders/Demo/*.php, app/Console/Commands/Auctions/SeedDemoAuctions.php:56 | n/a | `firstOrCreate($matchKey)` in demo seeders |

Not creators (checked): CMA/presentation propagation `PropertyCmaPropagationService` only updates a bound property (no create/new in file, grep); the CMA listener `PropagateCmaToProperty` writes to `tracked_properties` via `matchOrCreate` (Listeners/Presentation/PropagateCmaToProperty.php:125). Deals do not create properties (Section B).

Model-level hooks on every creation (all paths): `Property::boot` app/Models/Property.php:1304 (`creating`: assistant refused, `external_id` = UUID if empty) and :1332 (`saving`: `AddressStructurer::apply` then `suburb_normalised` / `street_name_normalised`). `PropertyObserver::created` app/Observers/PropertyObserver.php:393-460: audit row, `PropertyCaptured` event, and **geocode-on-create** (`PropertyGeoBackfillService::backfillProperty`, :453) when an address exists and GPS is empty.

### A.2 Saved identifiers (table.column)
`properties` (SHOW COLUMNS via information_schema; counts in A.6):

| Identifier | Column(s) | Who writes it |
|---|---|---|
| P24 reference | `p24_ref` (push key), `p24_listing_number` (import key), `source_reference` (agency's own P24 ref), `external_id` (UUID by default; = portal ref on CSV import and Pull; = `prospecting:{id}` on MIC promote) | CSV import writes all of `p24_listing_number`, `p24_ref` (ConfirmP24PropertyRowJob.php:203), `external_id`, `source_reference`. Pull writes `external_id` only (PropertyPullController.php:200). Sold import writes `p24_listing_number` only (SoldPropertyImporter.php:298). Web/mobile/wizard: none |
| Private Property ref | `pp_ref`, `pp_listing_feed_ref` | Written after a syndication push (grep `'pp_ref'` finds only reads outside Models); no import path writes it |
| Portal URL | **no column on `properties`**. Only `property_external_sources.listing_url` (OAS, 1 row) and `prospecting_listings.portal_url` (MIC). Pull puts "Source: <url>" into a `property_notes` row (PropertyPullController.php:225-226) | — |
| Other-agency source (agency/agent name, phone) | `property_external_sources.source_agency_name/source_agent_name/source_agent_phone/source_agent_email` | OAS import only (OtherAgencyStockImportService.php:~229) |
| Erf / stand | `erf_number` (validated on store :1411 / update :1845), `stand_number` (:1406), `erf_portion`, `erf_size_m2` | Manual form; P24 CSV maps `StandNumber` → `stand_number` (P24ListingsCsvParser.php:138) but **not** erf; deeds/TP promote writes `erf_number` (TPMC.php:2314) |
| Title deed | `title_deed_number` (in `$fillable` Property.php:1172) | Only TPMC promote (TPMC.php:2315). Not in web store/update validation |
| SG / LPI | `lpi_code`, `township`, `sg_province`, `sg_rural_urban`, `sg_farm_name`, `sg_last_searched_at`; SG PDFs in `property_sg_documents` | `lpi_code` is written only by `AddressStructurer` from other facts (not by any property path; 0 rows). `promoteToStock` does not copy `lpi_code`/`erf_portion`/`township` from the TP (TPMC.php:2303-2370 field list) |
| Street address (structured) | `street_number`, `street_name`, `unit_number`, `complex_name`, `unit_section_block`, `suburb`, `city`, `town`, `province`, `district`, `region`, `p24_suburb_id/p24_city_id/p24_province_id`; postal code: **no column** on `properties` (`tracked_properties.postal_code` only) | |
| Street address (raw) | `address` (recomputed from parts by `PropertyObserver::saving`, see comment in DeedsCaptureController.php:~711), `address_raw` (set once by AddressStructurer) | |
| Derived address cache | `suburb_normalised`, `street_name_normalised`, `street_core`, `street_type`, `address_parse_status/note` | Property.php:1332-1345, `AddressStructurer` |
| GPS | `latitude`, `longitude`, `geo_source`, `geo_confidence`, `geo_resolved_at`, `cma_gps_lat/lng` | form map pin / P24 CSV `Latitude` (P24ListingsCsvParser.php:160) / OAS payload / geocoder (observer) / promote copies TP lat/lng |
| Owner | not on the row. `contact_property` (role seller/landlord/owner), `property_seller_links`; web create refuses a property with no linked contact (PropertyController.php:~1479) | |
| Agent / agency / branch | `agent_id`, `pp_second_agent_id`, `agency_id`, `branch_id` | |
| Tracked-property link | **none on `properties`**. Reverse only: `tracked_properties.promoted_to_property_id` + `promoted_at` + `promoted_by_user_id`; `tracked_property_external_refs(source_type='agency_stock', source_ref='property:{id}')` (5 rows) from RTS (`TrackedPropertyResolver::forProperty`, RoadToSold/Support/TrackedPropertyResolver.php:76-104 — facts: address, street_number, street_name, suburb, erf_number, property_type, erf_size_m2, price; **no GPS, no deed no.**; and it does not set `promoted_to_property_id`) | |
| Other IDs | `lightstone_id` (CSV, 513 rows), `development_id` (0) | ConfirmP24PropertyRowJob.php:107 |

### A.3 Address handling
- Single normalisation code (shared by Property / TrackedProperty / TrackedPropertyAddress): `App\Models\Prospecting\TrackedPropertyAddress::normaliseSuburb()` (app/Models/Prospecting/TrackedPropertyAddress.php:170) and `::normaliseStreet()` (:321) → `properties.suburb_normalised` / `street_name_normalised`, set on every save (Property.php:1339-1344).
- Structured layer: `App\Services\Address\AddressStructurer::apply($property)` (Property.php:1337), parser `AddressParser`, suburb resolver `SuburbResolver`, LPI parser `LpiCode`. Writes derived `street_core/street_type/township/lpi_code/address_parse_status` when an address source column is dirty; fills EMPTY `street_number/unit_number/complex_name/scheme_number/erf_portion/erf_number` only; never overwrites (AddressStructurer.php header). Runs on save only, so legacy rows stay unstructured until re-saved or backfilled.
- Raw-string only normaliser (older): `ProspectingListing::normalizeAddress()` (app/Models/ProspectingListing.php:462: lowercase, strip non-alphanumerics, append suburb). Still used by `ProspectingStockMatchService` Pass 1 and `OnMarketStockService::resolveForClaim` strategy 2.
- Other normalisers: `App\Support\Geocoding\AddressNormaliser` (geocode target / sectional "Ss" handling), `PropertyAddressKey` (app/Support/Geocoding/PropertyAddressKey.php: `street_number|street_name_norm|suburb_norm|unit_number`, used by map grouping; the docblock says the Property save hook uses it — the actual hook at Property.php:1332 only writes the two `*_normalised` columns).
- Per-path parsing: OAS `OtherAgencyStockFieldMapper::parseStreetAddress` (Properties/OtherAgencyStockFieldMapper.php:251: comma segments, "unit/flat/apt" → unit_number, leading `\d+[A-Za-z]?` → street_number); MIC promote `ListingPropertyPromoter::parseStreet` (first comma segment); Sold import `parseRowData` (SoldPropertyImporter.php:~255-275, 587); Pull passes the portal's single `address` string straight through with no street parsing (PropertyPullController.php:~150) — AddressStructurer fills street_number/name on save.
- Postal code: not stored on `properties` at all.

### A.4 Matching today (what exists, who calls it, thresholds)
1. **`TPMC::resolvePropertyMatch(TrackedProperty)`** (TrackedPropertyMatchOrCreateService.php:2025) — Tracked → Stock, used only by `promoteToStock`. Uses `AddressMatcher::properties()` (app/Services/Address/AddressMatcher.php:50) scoring via `AddressMatchScorer` (tiers exact / possible / street_only). Exact rules in priority order `RULE_ORDER = ['lpi','erf','scheme','street']` (AddressMatcher.php:~38). Candidate retrieval (AddressMatcher.php:146-200): `lpi_code` equality, `erf_number` (leading zeros trimmed), scheme/complex, and street (street_name LIKE core, or `street_core`) within `suburb_normalised` ∪ P24 neighbour suburbs. One exact hit auto-links; >1 is logged `AMBIGUOUS` and falls through (:2125-2140). GPS ≤25 m (bbox 0.00025°, haversine ≤25.0) is **log-only, never auto-linked** (:2053-2075). Possible matches are surfaced to the agent via `possiblePropertyMatches()` (:2101) and `PossiblePropertyMatchException`.
2. **Deeds duplicate-take rule** around that: `PropertyDuplicateMatchEvidence` (builds side-by-side evidence; `strategyFor` = sectional / freehold_erf / address_fallback, Services/Prospecting/PropertyDuplicateMatchEvidence.php:30-42), `PropertyDuplicateAgeResolver` (bands), `PropertyDuplicateTakeService::reassign` (records who took what in `property_audit_log`; sets status Prospecting + agent), `PropertyDuplicateBlockGuard::authorizeOverride()` which **always returns false** (Services/Prospecting/PropertyDuplicateBlockGuard.php:22). `property_match_decisions` (463 rows; top groups by `GROUP BY subject_type, matched_type, strategy, outcome`: `deeds_capture`→tracked_property `0_address_history_exact` 229, `1_source_ref` 92, `0_address_history_gps` 42, `5_token_overlap` 19, `5_token_overlap_gps_tiebreak` 10, `4_normalised_address` 7, `2_gps_latlng` 6, `3_erf_suburb` 3; `deeds_capture_property`→property sectional/freehold_erf with outcomes took_existing 28+4 / created_new 7 / blocked 4 / blocked_acknowledged 3+2; `mic_claim`→property `normalized_address` 2; remaining small groups not listed) is the audit + veto store (`PropertyMatchDecisionService::record/isRejected/reject`, Services/Prospecting/PropertyMatchDecisionService.php).
3. **Prospecting listing ↔ Stock:** `ProspectingStockMatchService::matchProspect` (Services/Prospecting/ProspectingStockMatchService.php:19): on-market properties only; Pass 1 exact `normalizeAddress(address,suburb)` equality; Pass 2 same suburb + structured street number + street word (scored); `findPossibleMatch` GPS ≤25 m possible match (:13-14, :161). `OnMarketStockService::resolveForClaim` (OnMarketStockService.php:~205): strategy order `portal_ref` (`p24_ref`/`pp_ref`) → `normalized_address` → `promoted_link`; vetoed by `property_match_decisions`. Wired by `ProspectingListingObserver` (Observers/ProspectingListingObserver.php:137) and `Property`-save jobs (`MatchPropertyProspectingJob`).
4. **Contact → Stock:** `ContactAddressPropertyGuard` (Services/Contact/ContactAddressPropertyGuard.php:41) calls `TPMC::findExistingMatch` + stock lookup; agency setting `address_match_mode` (off / aggressiveness). Only surfaced when the create form is opened from a contact (PropertyController.php:1137).
5. **Private keys per ingress** (not shared): `p24_listing_number` (#5, #6), `external_id` = portal_ref (#4), `(portal, listing_ref)` (#7), `external_id='prospecting:{id}'` (#8), `wizard_token` (#2).
6. **P24 listing-number reconciler** (Services/Importer/P24ListingNumberReconciler.php): one-shot repair; matches CSV rows to properties by listing number / GPS box ±0.0002° (~22 m) and only writes when exactly ONE property resolves.
7. Domain-event audit trail for "took existing / created new" only on the deeds route.

### A.5 Missing (stock)
- No match-before-create on paths #1, #2, #3, #4, #5, #6, #7, #10: an agent can type, mobile-create, Pull, CSV-import or OAS-import the same physical house twice and nothing compares erf / address / GPS across `properties` or against `tracked_properties`.
- The same advert arriving by different routes is not recognised: CSV import keys on `p24_listing_number`; Pull on `external_id`; OAS on `property_external_sources`; none checks the other's column.
- Pull-from-portal does not set `p24_ref`/`p24_listing_number`/`pp_ref`, so a pulled-then-syndicated listing has no portal ref for `OnMarketStockService::resolveForClaim` strategy 1 and the P24 push keys on `p24_ref` (see ConfirmP24PropertyRowJob.php:195-203 comment) → duplicate-on-P24 risk (same class as the reconciler's docblock).
- No `properties.tracked_property_id`; typed/imported properties never get a tracked property unless RTS runs for them; `TrackedPropertyResolver::forProperty` carries no GPS or deed number and does not stamp `promoted_to_property_id`.
- `promoteToStock` drops `lpi_code`, `erf_portion`, `township`, `scheme_number`, `deeds_*` from the TP onto the new Property (not in field lists at TPMC.php:2236-2370) so the strongest identity key (LPI) never lives on the stock row (0 rows).
- No portal-URL column; no postal-code column on `properties`.
- `ConfirmP24PropertyRowJob` fills `stand_number` but never `erf_number` (CSV has no erf field) so 4,862 properties have stand_number and only 740 erf_number → erf-based matching (`AddressMatcher` erf rule, `idx_tp_addr_erf`) has nothing to match for imported stock.
- `PropertyDuplicateBlockGuard::authorizeOverride()` hard-coded false (pending Johan's decision).
- Structured layer not backfilled on QA1 stock: `street_core` 124 / 9,920.

### A.6 Row evidence (QA1) — SQL run
Totals: `SELECT COUNT(*) total, SUM(deleted_at IS NULL) live FROM properties` → 10,254 total, 9,920 live. By agency (live): agency 1 = 6,500; agency 20 = 3,410 (all with `p24_ref`, 761 structured addr, 0 erf); 21/22/23 = 10 rows. Status (live): withdrawn 5,073; expired 1,917; prospecting 1,553; sold 814; active 236; let_out 77; draft 67; others small.

Main count (live rows):
```sql
SELECT COUNT(*) total, SUM(p24_ref<>'') p24ref, SUM(p24_listing_number<>'') p24num, SUM(pp_ref<>'') ppref,
 SUM(source_reference<>'') srcref, SUM(external_id<>'') ext, SUM(erf_number<>'') erf, SUM(stand_number<>'') stand,
 SUM(title_deed_number<>'') deed, SUM(lpi_code<>'') lpi, SUM(sg_province<>'') sgprov, SUM(address<>'') addr,
 SUM(address_raw<>'') addrraw, SUM(street_number<>'' AND street_name<>'' AND suburb<>'') structured,
 SUM(street_core<>'') score, SUM(latitude IS NOT NULL AND longitude IS NOT NULL AND latitude<>0 AND longitude<>0) gps,
 SUM(cma_gps_lat IS NOT NULL AND cma_gps_lat<>0) cmagps FROM properties WHERE deleted_at IS NULL
```
| Measure | Count (of 9,920) |
|---|---|
| portal ref `p24_ref` / `p24_listing_number` / `pp_ref` / `source_reference` | 8,239 / 8,150 / 207 / 1,708 |
| `external_id` non-UUID (a portal ref or `prospecting:`) / UUID | 8,190 / 1,730 (`external_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-'`) |
| no p24_ref, no p24_listing_number, no pp_ref | 1,677 |
| `erf_number` / `stand_number` / `title_deed_number` / `lpi_code` / `sg_province` | 740 / 4,862 / 1,596 / 0 / 7 |
| `address` / `address_raw` | 9,908 / 126 |
| structured (street_number+street_name+suburb) | 3,890 (agency 1: 3,120 of 6,500; agency 20: 761 of 3,410) |
| `street_name` / `suburb` / `city` / `town` | 9,590 / 9,905 / 9,624 / 6,376 |
| `suburb_normalised` / `street_name_normalised` / `street_core` | 9,904 / 9,590 / 124 |
| `address_parse_status` | NULL 10,127 (of 10,254 incl. archived); parsed 116; review 10; unparseable 1 |
| no street_number AND no erf AND no stand | 1,832 |
| GPS lat+lng non-null/non-zero / `cma_gps_lat` | 8,983 / 0 |
| `geo_source` (live): google 2,791; market_report 749; market_report_subject 10; unresolved 191; NULL 6,179 | |
| `geo_confidence` (live): NULL 6,179; exact 2,173; suburb 1,105; street 262; failed 191; high 10 | |
| P24-imported (`p24_imported_at`) / MIC-promoted (`external_id` LIKE `prospecting:`) / wizard drafts | 8,145 / 46 / 5 |
| Linked from a tracked property (`tp.promoted_to_property_id = p.id`, live TP) | 1,869 properties (1,882 TPs) |
| NOT linked from any tracked property | 8,051 (agency 1: 4,631; agency 1 on-market statuses: 88 of 185) |
| `property_external_sources` rows / with URL | 1 / 1 |
| `property_notes` "Property pulled from portal via CoreX extension" | 2 |

Duplicates (live, same agency):
```sql
-- p24_ref / pp_ref: GROUP BY agency_id,p24_ref HAVING c>1            -> 0 groups ; pp_ref -> 0 groups
-- p24_listing_number: GROUP BY agency_id,p24_listing_number HAVING c>1 -> 4 groups / 8 rows
-- number+street+suburb: GROUP BY agency_id, LOWER(TRIM(street_number)),LOWER(TRIM(street_name)),LOWER(TRIM(suburb)) HAVING c>1
                                                                        -> 442 groups / 1,699 rows (includes sectional units sharing a street address)
-- same + COALESCE(LOWER(TRIM(unit_number)),'') (only rows with all 3 parts) -> 234 groups / 503 rows
-- LOWER(TRIM(address)) + LOWER(TRIM(suburb))                           -> 603 groups / 1,341 rows
-- erf_number + suburb (erf<>'')                                        -> 1 group / 2 rows
```
Reading: portal refs are clean (P24 CSV key held), but address-level repeats are large (~500-1,700 rows) and cannot be separated into "real second unit" vs "duplicate" without unit/complex review. UNVERIFIED which of the 234 are genuine duplicates.

`property_match_decisions`: `SELECT COUNT(*)` → 463; `SELECT subject_type, matched_type, strategy, outcome, COUNT(*) …GROUP BY 1,2,3,4 ORDER BY 5 DESC LIMIT 15` (see A.4 item 2).

---

## B. Deals

### B.1 Arrives via (file:line)
- DR2 capture/edit `Dr2\DealRegisterController` (validation app/Http/Controllers/Dr2/DealRegisterController.php:726-727: `property_id` nullable `exists:properties,id`, `property_address` nullable string max 255; fill :945-946). Picker feed `searchProperties` (:1118) uses `Property::searchAddress` and by default shows on-market only (`?all=1` reveals off-market).
- Multi-property: `DealProperty::create` at :631 (create-time rows), :1790 (`addProperty`); `Deal::syncPrimaryPropertyPivot` mirrors `deals.property_id` ↔ `deal_properties` (app/Models/Deal.php:~100-125).
- Link service `App\Services\Deals\DealPropertyLinkService` (manual `deals:link-properties` command, app/Console/Commands/Deals/LinkPropertiesCommand.php:21; no schedule entry found — grep routes/console.php shows none).
- `deals_v2` twins (DealV2 model; `AlignV2TwinsToDeals`, `DealParityCheck`) — `property_id` column exists.

### B.2 Saved identifiers
- `deals.property_id` (FK, nullable), `deals.property_address` (varchar 255, free text), `deals.seller_name`, `deals.buyer_name`, `deals.property_value`, `sale_price`; `deal_properties(deal_id, property_id, is_primary, allocated_price, allocated_commission)`.
- **No** erf, stand, title deed, SG/LPI, structured street/suburb, GPS, portal ref, tracked_property_id columns on `deals` or `deal_properties` (information_schema query over deals/deals_v2/deal_properties, regex on prop|erf|stand|deed|address|street|suburb|town|city|gps|lat|lng|unit|complex|tracked|sg_|lpi|seller|buyer|owner returned only the columns above).
- Owner/party: `seller_name`/`buyer_name` text + optional contact-id CSV (`seller_contact_ids`, `buyer_contact_ids`, :736-737) which create the property↔contact link at save. A linked property must have a resolvable seller contact (`DealPropertyOwnerGate::assertHasKnownOwner`, :906-913; "there cannot be a deal without an owner").
- Agent/agency: `deal_user`/`deal_v2_agents`, `agency_id` stamped explicitly (:~950).

### B.3 Address handling
None on the deal. `property_address` is typed or copied display text; `DealPropertyLinkService::linkDeal` normalises it with `App\Support\Geocoding\AddressNormaliser` (Services/Deals/DealPropertyLinkService.php:5-11) only when run.

### B.4 Matching today
- Manual link: agent picks a property in the picker; `DealPropertyOwnerGate` (same-owner/known-owner), `DealPropertyStatusService::committedDealOnProperty` (one Granted/Registered deal per property).
- `DealPropertyLinkService::linkDeal` (same agency): normalise → candidates scored 100 exact / 70-85 token-subset or substring / 50-65 Levenshtein ≤3 / 30-45 suburb+street fragment, ±20 date-proximity boost (registration_date within 730 days of property created_at/last_activity). Resolution: one candidate ≥70 (`MIN_SCORE_AUTO_LINK`) links; medium links + would queue; low or >1 → no link; `MIN_SCORE_REVIEW`=30. Since 2026-08-24 "queued_for_review" is always false (the review screen was removed; docblock lines 38-47). Not auto-run on deal save.
- Deals never call `matchOrCreate`, never create a `properties` or `tracked_properties` row (grep for TrackedProperty in Models/Deal.php, DealRegisterController.php, app/Services/Deal*: nothing; Listeners mention TrackedProperty only for activity credit / RTS).

### B.5 Missing
- 147/177 deals have no property link; their address string is never matched at save time.
- Deal registration/sale does not feed a tracked property or `last_known_sold_price` (no listener found).
- No erf / title-deed / structured address captured on a deal, so a deal cannot be matched to a deeds capture except through a property.
- `deals_v2.property_id` is 0/177 populated although the twin has the column (UNVERIFIED whether by design; `AlignV2TwinsToDeals` not read).

### B.6 Row evidence
```sql
SELECT COUNT(*) total, SUM(property_id IS NOT NULL) with_prop, SUM(property_address<>'') with_addr,
       SUM(property_id IS NULL AND property_address<>'') addr_only FROM deals WHERE deleted_at IS NULL;
-- 177 / 30 / 177 / 147
SELECT COUNT(*) FROM deals WHERE deleted_at IS NULL AND property_id IS NULL AND property_address REGEXP '^[0-9]'; -- 141
SELECT COUNT(*) total, SUM(property_id IS NOT NULL) FROM deals_v2 WHERE deleted_at IS NULL;                    -- 177 / 0
SELECT COUNT(*) dp, COUNT(DISTINCT deal_id), SUM(is_primary) FROM deal_properties WHERE deleted_at IS NULL;      -- 31 / 31 / 31 (one pivot row belongs to a soft-deleted deal)
SELECT COUNT(DISTINCT d.property_id) FROM deals d JOIN tracked_properties tp ON tp.promoted_to_property_id=d.property_id
  AND tp.deleted_at IS NULL WHERE d.deleted_at IS NULL;                                                          -- 16 of 30 linked properties are tracked-linked
SELECT COUNT(*) FROM deal_link_review_queue;                                                                     -- 0
```
Property referenced by >1 deal: property 3522 (2 deals). Repeated typed addresses: `LOWER(TRIM(property_address))` e.g. "3 crescent road" ×4, "1 millpark" ×3, "2 chatham crescent" ×3 — not linked to a property.

---

## C. Deeds capture / import

### C.1 Arrives via (file:line)
- Chrome extension (CMA Info page) → `POST /api/v1/deeds-capture` → `Api\DeedsCaptureController::store` (app/Http/Controllers/Api/DeedsCaptureController.php:42; per-row `ingestOne` :519; validation :88-135). Spec: .ai/specs/deeds-capture.md §2-3.
- Pre-check before the paid ID reveal `POST /api/v1/deeds-capture/check-duplicate` → `checkDuplicate` (:184; spec §9). Read-only: no row written (an `agent_activity_events` row only).
- Screen + promote: `CoreX\DeedsCaptureController` (app/Http/Controllers/CoreX/DeedsCaptureController.php; promote → `promoteToStock` :1063).
- Link/read services: `DeedsCaptureLinkService` (owner lookup for MIC compose), `DeedsCapturePreCheckRanker`, `OwnershipHistoryParser`, `OwnerContactResolver`.
- Title Deed Register (app/Services/TitleDeeds/*) is **not** an ingest path for deeds data — see C.4.

### C.2 Saved identifiers (table.column)
On capture, `ingestOne` builds the Facts (Api/DeedsCaptureController.php:626-661) and calls `TPMC::matchOrCreate(source type 'deeds_capture', ref = payload `source_ref`)` (:668). Stored on `tracked_properties`:
- Reference: `tracked_property_external_refs(source_type='deeds_capture', source_ref='cmainfo:<id>')` unique `(agency_id, source_type, source_ref)`. Real refs look like `cmainfo:344/1985-16` (a scheme/section style number), **not** the 21-char LPI.
- Erf `erf_number`; deed number `title_deed_number`; SG/LPI `lpi_code` (+ derived `township`, `erf_portion`) — from `property.lpi_code` or parsed from `source_ref` via `LpiCode::parse` (:226, :651); scheme `scheme_name/scheme_number/section_number/unit_number/complex_name`; deeds `deeds_office`, `deeds_registered_date`; extent split into `erf_size_m2` (freehold Extent), `cadastral_extent`, `section_extent_m2`; `municipal_valuation(+_year)` (from CMA report path, not this payload); sale `last_known_sold_price/date`, `bond_holder`, `bond_amount`, `sale_type`.
- Address: `address`/`street_number`/`street_name`/`suburb`/`town`(=municipality)/`province`; `address_raw` + derived `street_core/street_type/p24_suburb_id/p24_city_id/address_parse_status`; postal_code column exists on `tracked_properties` (not populated by deeds payload — not in the validated property keys).
- GPS: `latitude`/`longitude` (from `property.latitude/longitude`), `geo_source/geo_confidence`.
- Capture markers: `capture_kind='deeds_capture'` (only when the capture created the TP), `deeds_captured_at`, `deeds_captured_by_user_id`.
- Owners: `tracked_property_owners(tracked_property_id, contact_id, name, id_number varchar(20), id_type, ownership_share_pct, deed_reference, ownership_status current/past, is_primary, role)`; first owner also `tracked_properties.owner_contact_id`; owner Contacts created/deduped on `id_number` (entities on `entity_reg_no`, `OwnerEntityClassifier`); ownership history parsed by `OwnershipHistoryParser` from `ownership_history_raw`. Phone/email normally empty.
- Agent: `deeds_captured_by_user_id` (most recent scraper), agency from token.
- Title Deed Register (`title_deeds`, `title_deed_owners`): `property_id`, `property_address` snapshot, `deal_id`, `attorney_firm`, owners by `contact_id` + `name_snapshot`; **no** erf, deed number, SG code (SHOW columns: id, agency_id, reference_no, reference, branch_id, agent_id, listing_agent_id, property_id, property_address, deal_id, attorney_firm, received_at, storage_location, status, …). It is a custody log for physical deeds; `property_id` is picked by the user.

### C.3 Address handling
Whole structured pipeline of A.3 applies on `TrackedProperty` save (AddressStructurer sets `address_raw`, `street_core`, etc.) plus: extension 3.8.1/3.9.0 sends `cma_street_number`, `situated_at`, `estate`, `lpi_code` (validation :95-100); spec §6.2 "Situated at is NOT the unit's erf"; §9.10 second-source street-number correctness; `factsHaveStreetNumber` / `streetNumberConfirmed` guard rejects a loose token-overlap hit that fails to confirm the street number (`checkDuplicate()` in the Api controller).

### C.4 Matching today
Capture (write path), `TPMC::matchOrCreate` — strategies recorded in `property_match_decisions.strategy` and `describeLastMatch`: `0_address_history_exact`, `0_address_history_gps`, `1_source_ref`, `2_gps_latlng`, `3_erf_suburb`, `4_normalised_address`, `5_token_overlap`, `5_token_overlap_gps_tiebreak` (QA1 counts: 229/42/92/6/3/7/19/10). Internals are covered by another auditor.
Pre-check (read path, `checkDuplicate`): `findExistingMatch` (structural TP), `findExistingStock` (properties), `findPossibleTrackedMatches`, `findPossibleOwnerMatches` (owner name + address), `findSameStreetOthers`; then `DeedsCapturePreCheckRanker::rank` (Services/Prospecting/DeedsCapturePreCheckRanker.php) orders exact → surname → possible → no-unit using `OwnerSurnameEvidence` (surname only, never first names or ID digits). Status `exists` / `possible_match` / `not_found`.
Deed → Stock: `promoteToStock` → `resolvePropertyMatch` (A.4 #1). Duplicate-take bands/blocks (active_blocked, no_go, approval, auto-take): CoreX\DeedsCaptureController ~:850-1030 + `PropertyDuplicateAgeResolver`, `PropertyDuplicateTakeService`, `PropertyDuplicateBlockGuard` (A.4 #2).
Deed → MIC listing: `DeedsCaptureLinkService::ownersForListing` (Services/Prospecting/DeedsCaptureLinkService.php:48): remembered manual link `prospecting_listings.linked_deed_tracked_property_id` → else identity keys erf+suburb, street+suburb, GPS box 0.00005° (~5 m) (:36-37, :272+), with duplicate-TP sibling handling; `availableDeeds()` :433 for the "Link a deed" modal.
Deed → Contact: owners deduped on `id_number` via `ContactIdentifierService` / `resolveOwnerContact` (Api controller :972).
Title Deed Register → Property/Deal: user-picked `property_id`/`deal_id` (`TitleDeedDealLink`), no matching.

### C.5 Missing
- **LPI/SG code almost never arrives.** Only 5 of 1,912 deeds-captured tracked properties have `lpi_code`; QA1's real `source_ref`s are `cmainfo:<scheme/section number>`, not the 21-char LPI, so `LpiCode::parse(source_ref)` returns null and the strongest freehold key (spec structured-address §4.1) is unused. Needs extension 3.9.0+ sending `property.lpi_code` (UNVERIFIED which build is in the field).
- Erf present on only 922 of 1,912 deeds captures (sectional units have no own erf by design — 1,003 have a section number).
- `title_deed_number` present on 1,908/1,912 but **never used as a match key**: not in `AddressMatcher` retrieval (lpi / erf / scheme / street only) and `PropertyDuplicateMatchEvidence` only displays it.
- 272 deeds captures are active-unpromoted and 1,640 are promoted; on promote the TP's `lpi_code`, `erf_portion`, `township`, `scheme_number`, `deeds_office` are not copied to the Property.
- Title Deed Register is empty (0 rows) and has no identity fields, so it cannot be matched to deeds data.
- `tracked_property_owners.id_number` is varchar(20): joint-owner rows with long IDs rely on the owners[] array path (spec §7).
- Municipal valuation is only on 19 of the 1,912 deeds captures (the valuation arrives via the CMA report path, not deeds capture).

### C.6 Row evidence
```sql
SELECT COUNT(*) n, SUM(erf_number<>'') erf, SUM(title_deed_number<>'') deed, SUM(lpi_code<>'') lpi,
 SUM(street_number<>'' AND street_name<>'' AND suburb<>'') structured, SUM(latitude IS NOT NULL AND latitude<>0 AND longitude<>0) gps,
 SUM(scheme_name<>'') scheme, SUM(section_number<>'') section, SUM(municipal_valuation IS NOT NULL) munval,
 SUM(promoted_to_property_id IS NOT NULL) promoted, SUM(owner_contact_id IS NOT NULL) owner_c, SUM(status='duplicate') dup_status, SUM(address_raw<>'') araw
 FROM tracked_properties WHERE deleted_at IS NULL AND deeds_captured_at IS NOT NULL;
-- n 1,912 | erf 922 | deed 1,908 | lpi 5 | structured 1,675 | gps 1,909 | scheme 998 | section 1,003 | munval 19 | promoted 1,640 | owner_c 1,911 | dup_status 0 | araw 138
```
- All live tracked_properties: 46,356; `capture_kind='deeds_capture'` 1,810; `lpi_code` 6; erf 1,268; `title_deed_number` 1,929; `street_core` 284; `address_parse_status` 285.
- `tracked_property_owners`: 3,424 rows total; 3,052 on live TPs across 1,933 TPs; 3,038 with `id_number`; 3,052 with `contact_id`; 2,971 `ownership_status='current'`.
- Duplicate deeds TPs: `GROUP BY agency_id, erf_number, LOWER(suburb), COALESCE(section_number,'') HAVING c>1` over deeds-captured → 0 groups.
- `tracked_property_external_refs` by `source_type`: p24 23,385; pp 19,555; chrome_capture 6,043; deeds_capture 2,220; property24 389; cmainfo 302; manual_prospect_entry 50; private_property 10; map_prospect_launch 9; agency_stock 5.
- `title_deeds` 0; `title_deed_owners` 0; `property_sg_documents` 22 rows over 6 properties.

---

## D. Map

### D.1 Arrives via (file:line) — what a map pin is
Pins are read-only views over eight layers fed by `MapPinService::getPinsInBounds` (app/Services/Map/MapPinService.php:143-165) through `MapController::pins` (app/Http/Controllers/Map/MapController.php:104). Layer → table:

| Layer key | Source table(s) | Filter / coordinates | File:line |
|---|---|---|---|
| `hfc_listings` / `hfc_sold` / `hfc_off_market` | `properties` (+ `property_sold_records`) | `properties.latitude/longitude`; on-market via `Property::constrainOnMarket`; excludes OAS status | MapPinService.php:375-560 |
| `tracked_properties` | `tracked_properties` | `status='active' AND promoted_to_property_id IS NULL`, lat/lng non-null, `geocode_needs_review=0`; then folded away when an agency `properties` row has the same number+street core+suburb (cached `agencyAddressIndex`, :81-140, :640-700) | :574-770 |
| `sold_comps` | `market_report_comp_rows` (mrcr lat/lng or scheme-report subject GPS), `presentation_sold_comps` (via `raw_row_json.mic_comp_row_id`), `deals` JOIN `properties` (needs `deals.property_id` and the property's GPS) | | :826-1030 |
| `active_listings` | `prospecting_listings` (`is_active`, p24/pp, own lat/lng) minus stock via `OnMarketStockService::applyNotStock` | | :1100-1290 |
| `mic_subjects` | `market_reports` (subject GPS) | | :1296 |
| `scheme_owners` | `scheme_owners` JOIN `market_reports` | | :1354 |
So a pin is **all of properties, tracked_properties and prospecting_listings** (plus report/comp tables). `LocationGrouper` (app/Services/Map/LocationGrouper.php) collapses records to one composite pin per location by `AddressNormaliser::parse()['geocode_target']`, else lat/lng rounded to 5 dp (~1.1 m).

Map interactions that WRITE:
- `MapActivityController::log` → `prospectLaunched` (app/Http/Controllers/Map/MapActivityController.php:338-370): when the clicked pin has no `tracked_property_id`, calls `TPMC::matchOrCreate` with **facts = address (raw string), latitude, longitude, suburb only**, source `{type:'map_prospect_launch', ref: record_id|location_key}` (:353-370). Then `resolveProspectingListingId` (:524-571) finds or **inserts a `prospecting_listings` row** for layer-prefixed record ids `mrcr:N|pal:N|deal:N` (`portal_source='p24'`, `portal_ref='mrcr:N'`, `portal_url=''`, `price=0`, address/suburb copied, `tracked_property_id` set).
- `MapController::pitchSchemeOwner` (app/Http/Controllers/Map/MapController.php:629-700): `matchOrCreate` with facts address ("Unit N, Scheme"), complex_name, scheme_name, scheme_number, section_number, unit_number, property_type, floor_size_m2, latitude, longitude; source `{type:'scheme_owner_report', ref:'scheme_owner:{id}'}`; also `TrackedPropertyOwner::firstOrCreate` by `(tracked_property_id, name)`.
- Other map handlers (`propertyCard`, `soldCard`, `activeCard`, `micSubjectCard`, `schemeOwnerCard`, `presentationPins`) are read-only. No map code creates a `properties` row.

### D.2 Saved identifiers
- Map-launch TP: `address` (raw), `suburb`, `latitude/longitude` only → no street_number/street_name (AddressStructurer parses on save), no erf, no deed, no SG/LPI, no portal ref. Source ref `map_prospect_launch` is the pin's record id (e.g. `pal:123`) — 9 rows in QA1.
- Scheme-owner TP: scheme name/number/section/unit + owner name (no ID number — "a genuinely weaker identity than a deed", MapController.php:~678).
- Synthetic `prospecting_listings` row for mrcr/pal/deal pins: `portal_ref='mrcr:N'` etc, empty `portal_url`.

### D.3 Address handling
Map-side: `LocationGrouper` + `AddressNormaliser` + `PropertyAddressKey` (display grouping only). T-pin ↔ stock fold (MapPinService.php:640-700) uses `AddressParser::numberAndCore` + `TrackedPropertyAddress::normaliseSuburb`, key `number|core|suburb`, unit conflict veto, street-type veto; a pin with no street_name or suburb is never folded. `agencyAddressIndex` is cached per agency (`forgetAgencyAddressIndex`).
Geocoding (how GPS gets on records): `App\Services\Geocoding\AddressResolverService::resolve` (app/Services/Geocoding/AddressResolverService.php:53): 1) `geocoding_cache` by `AddressNormaliser::normalise`; 2) `market_reports.subject_address` (MIC) ; 3) `portal_captures`; 4) Google Geocoding (daily cap via `GeocodeRateLimiter`, bounded to `GeocodingArea::forAgency`); 5) Nominatim if enabled; else failure not cached. Callers: `PropertyObserver::created` (:453) and `::updated` (:485) → `PropertyGeoBackfillService::backfillProperty` (failure recorded as `geo_source='unresolved'`, `recordFailure` PropertyGeoBackfillService.php:44); `GeocodeTrackedPropertyAddressesJob` (Jobs/Prospecting, :104) and `GeoBackfillTrackedPropertiesCommand` → `backfillTrackedProperty`; property page "geocode" button (PropertyController.php:3973); `PresentationGeneratorService.php:232`. Manual pin: form `latitude/longitude` (PropertyController.php:1376). Source specs: .ai/specs/geocoding-spec.md (written 2026-05-25), .ai/specs/map-pin-taxonomy-investigation.md.

### D.4 Matching today
- Stock vs tracked: presentation-only fold described above (never writes `promoted_to_property_id`).
- Map-launch → TPMC `matchOrCreate` with raw address + GPS (so matches fall to `2_gps_latlng`, `4_normalised_address` (needs number+street, which the raw string only gets after parsing), `5_token_overlap`; UNVERIFIED which strategy fires for a bare address — internals covered by another auditor).
- Cross-layer: the grouper merges by geocode_target or 5-dp GPS only; there is no cross-layer ID match (spec map-pin-taxonomy-investigation Finding #4/#6).
- Active listings vs stock: `OnMarketStockService::applyNotStock` (portal ref + normalised address).

### D.5 Missing
- Properties without a pin: 937 live properties have no usable GPS (9,920 - 8,983); 6,179 have `geo_source` NULL, i.e. GPS came from the source CSV or was never geocoded; 191 `unresolved`.
- Tracked properties pinned at suburb centre: 28,302 of 37,080 GPS rows are `geo_confidence='suburb'` → many pins stack at the suburb centroid; 9,058 TPs have no geo at all (`geo_source` NULL), 2,266 `unresolved/failed`.
- Prospecting listings: only 6,529 of 45,440 live rows carry their own `latitude/longitude` (14%); the `active_listings` layer requires pl lat/lng (MapPinService.php:1109-1112), so 85% of MIC listings never reach the map via that layer (they may appear as T-pins through their tracked property, 42,943 linked).
- Deals: 147 of 177 deals have no property → no pin; the sold-comps `deals` branch needs `deals.property_id` AND `properties.latitude`.
- Properties not backed by a tracked property (8,051) never appear on the T layer; they appear on H layers only while on-market (off-market via `hfc_off_market`).
- Map-launched TPs are matched with raw address only (no erf/street parts) → higher duplicate risk than deeds-sourced TPs; 9 `map_prospect_launch` refs exist.
- Market reports: 196 of 436 live reports have subject GPS; comp rows 4,002 (GPS count UNVERIFIED — only table size queried); `presentation_sold_comps` GPS is borrowed via `mic_comp_row_id` and silently skipped when absent (:938-960).
- Geocoding cost control: Google daily cap and a 5 s network timeout; transient failures are not cached so large backfills retry.

### D.6 Row evidence
```sql
SELECT COUNT(*) n, SUM(latitude IS NOT NULL AND latitude<>0) gps,
  SUM(status='active' AND promoted_to_property_id IS NULL) active_unpromoted,
  SUM(status='active' AND promoted_to_property_id IS NULL AND latitude IS NOT NULL AND latitude<>0
      AND (geocode_needs_review=0 OR geocode_needs_review IS NULL)) mappable_T,
  SUM(geocode_needs_review=1) needs_review FROM tracked_properties WHERE deleted_at IS NULL;
-- 46,356 / 37,080 / 44,441 / 35,192 / 0
SELECT geo_source, geo_confidence, COUNT(*) FROM tracked_properties WHERE deleted_at IS NULL GROUP BY 1,2;
-- google/suburb 28,302 ; NULL/NULL 9,058 ; google/exact 2,744 ; google/street 2,601 ; unresolved/failed 2,266 ; market_report/exact 1,342 ; market_report_subject/high 43
SELECT COUNT(*) n, SUM(latitude IS NOT NULL AND latitude<>0) gps, SUM(tracked_property_id IS NOT NULL) tp_link,
  SUM(matched_property_id IS NOT NULL) stock_link FROM prospecting_listings WHERE deleted_at IS NULL;
-- 45,440 / 6,529 / 42,943 / 1,072
SELECT COUNT(*) n, SUM(subject_latitude IS NOT NULL AND subject_latitude<>0) gps FROM market_reports WHERE deleted_at IS NULL; -- 436 / 196
SELECT COUNT(*) FROM market_report_comp_rows;                                                                                  -- 4,002
SELECT COUNT(*) FROM geocoding_cache;   -- NOT RUN (UNVERIFIED size)
```
Properties GPS/geo as in A.6. Pins therefore: stock 8,983 with GPS (but H layers show on-market ≈236 active + off-market/sold), tracked 35,192 mappable T, listings 6,529 (with own GPS), plus comps/reports.

---

## Appendix — files read (all under /corex-qa1)
app/Models/Property.php (boot :1304-1347); app/Observers/PropertyObserver.php (:393-490); app/Http/Controllers/CoreX/{PropertyController,PropertyWizardController,DeedsCaptureController,TrackedPropertyController}.php; app/Http/Controllers/Api/{MobilePropertyController,PropertyPullController,DeedsCaptureController}.php; app/Http/Controllers/Dr2/DealRegisterController.php; app/Http/Controllers/Map/{MapController,MapActivityController}.php; app/Jobs/ConfirmP24PropertyRowJob.php; app/Services/Properties/{OtherAgencyStockImportService,SoldPropertyImporter}.php; app/Services/SellerOutreach/ListingPropertyPromoter.php; app/Services/Prospecting/{TrackedPropertyMatchOrCreateService (:1-2420 selected), MicPropertyReconciliationService, ProspectingStockMatchService, OnMarketStockService, PropertyDuplicate*, PropertyMatchDecisionService, DeedsCaptureLinkService, DeedsCapturePreCheckRanker}.php; app/Services/Address/*; app/Services/Deals/DealPropertyLinkService.php; app/Services/Map/MapPinService.php; app/Services/Geocoding/*; app/Services/Rentals/TakeOnImport/*; app/Services/RoadToSold/Support/TrackedPropertyResolver.php; app/Listeners/Presentation/PropagateCmaToProperty.php; .ai/specs/deeds-capture.md, title-deed-register.md, geocoding-spec.md, map-pin-taxonomy-investigation.md (headings only).
Not read in full (UNVERIFIED beyond what is cited): `OwnershipHistoryParser`, `OwnerContactResolver`, `AddressMatchScorer` thresholds, `TPMC::resolveMatch` internals (other auditor), `Property24SyndicationService` import side, `AlignV2TwinsToDeals`.

---

# Part 2 — MIC, email alerts, own portal imports, portal side-tables


Code: /corex-qa1 @ QA1 (8dfee589f). DB: corex_qa1 via read-only helper. Counts taken 2026-10-29. Only agency_id=1 (HFC) exists in prospecting_listings (SQL A-0), so every count is single-agency.

Naming note. The LIVE extension source is `public/chrome-extension/portal-capture/` (manifest.json:4 version 3.12.0, name "CoreX"). The folder `chrome-extension/portal-capture/` (manifest 3.3.6) is an OLD CMA-only build that posts only to /portal-captures/ingest (chrome-extension/portal-capture/popup.js:254). Everything below about the extension refers to the public/ copy.

---------------------------------------------------------------------------------------------------
## A. MIC scrapes via the web extension (other-agency stock on P24 / PP)

### Arrives via (file:line)
- Extension -> `POST /api/prospecting/import` (background.js:1050-1051 `handleSendToCorex`; batches built in `sendBatchToApi` background.js:596-650; durable offline queue background.js:417-480). Route: routes/api.php:681 (`v1.prospecting.import`) and legacy duplicate routes/api.php:1083. Controller: app/Http/Controllers/Api/ProspectingApiController.php:20 `import()`.
- P24 search-tile scraper: public/chrome-extension/portal-capture/content-p24-detail.js:178-290 (`extractListing`), :293-318 (`extractAllListings`; drops tiles without a 6+ digit ref AND a URL, dedups by resolved ref in-page).
- PP search-tile scraper: content-pp.js:206-345 (ref from href `/T\d{5,}`, :215); fallback HTML parser background.js:887-973.
- The same extension also has separate flows that are NOT this API: "Pull Property" -> /api/properties/pull-from-portal (background.js:1084) and Other Agency Stock import -> /api/v1/other-agency-stock/import (background.js:1144). These create `properties`, not prospecting_listings (see Missing M-A6).

### Fields the extension scrapes and posts (per listing)
Posted keys (baseListing background.js:975-983 / content-p24-detail.js:179-185): portal_ref, portal_url, address, suburb, price, bedrooms, bathrooms, garages, property_size_m2, erf_size_m2, property_type, agent_name, agency_name, thumbnail_url, source, plus portal_status for P24 only.
- portal_ref: P24 = `'P24-' + 6+digit id from URL path` (content-p24-detail.js:203-220); PP = `'PP-' + T-ref` (content-pp.js:331, e.g. PP-T5539380).
- portal_url: absolute listing URL (content-p24-detail.js:187-201; content-pp.js:332).
- address: P24 `.p24_address` text, left null if tile has none (:239-242). PP: derived from the URL slug by `extractAddressFromUrl` (content-pp.js:221, UNVERIFIED function body not read), "PP often omits it" (:220).
- suburb: P24 `.p24_location`, fallback = 2nd URL path segment title-cased (:238, :244-248). PP: from the same URL parse.
- erf_size_m2 is erf AREA in m2 (`.p24_size span`, :257-259). The extension does NOT scrape erf NUMBER, title deed, SG code, owner, GPS or street-number/street-name parts.
- agent_name: P24 never set (initialised null :182, never assigned); PP hard-coded `null` (content-pp.js:342). agency_name: P24 from `.p24_branding img[alt]` (:269); PP from img alt (content-pp.js:296-305).
- portal_status (active / under_offer / sold / withdrawn): P24 only, from ribbons/badges (content-p24-detail.js:161-176, :288). PP never sends it.
- mandate_type: accepted by the API (ProspectingApiController.php:~45 `in:sole,exclusive,open,joint`) but NO extension code sets it (grep of public/chrome-extension/portal-capture/content-*.js: no "mandate").
- Server validation block: ProspectingApiController.php:22-50. Only `portal_ref` truthy is required per row; rows without it are skipped (:122-125).

### Saved identifiers (table.column)
prospecting_listings (SHOW CREATE TABLE, SQL A-1):
- portal_source enum('p24','pp'), portal_ref varchar(50) NOT NULL, portal_url varchar(500) NULL.
- address varchar(255) NULL (raw string), normalized_address varchar(255), suburb varchar(100) NOT NULL, district varchar(100). NO street_number, street_name, town, postal_code, erf_number, title_deed, sg_code columns exist on this table.
- latitude / longitude decimal(10,7) NULL: NOT written by the import; backfilled later by app/Console/Commands/GeocodingBackfillCommand.php:395-428.
- erf_size_m2 (area), property_size_m2, bedrooms, bathrooms, garages, property_type, price, agent_name, agency_name, thumbnail_source_url/thumbnail_path, mandate_type, portal_status (+ portal_status_changed_at, off_market_at), is_active, first_seen_at, last_seen_at, last_search_id, listed_date (+ listed_date_source; only set by ListedDateRecorder.php:28-49 from a captured detail page).
- Links: tracked_property_id (FK tracked_properties), matched_property_id (FK properties, confident stock match), possible_property_id (+ possible_match_verdict, possible_match_candidate_ids JSON; advisory), linked_deed_tracked_property_id (+ _by_user_id, _at; the "linked deed" column), property_group_id (cross-portal grouping).
- first_seen_email_date exists (migration 2026_03_18_140001) but NO code writes it (migration comment database/migrations/2026_10_29_100000_add_listed_date_to_prospecting_listings.php:12; SQL A-2 shows 0 rows).
- ProspectingSearch row per capture session: prospecting_searches(agency_id, user_id, portal_source, search_url, search_description, total_results, pages_captured) ProspectingApiController.php:62-95.
- Side tables written on this path: prospecting_price_history, prospecting_price_anomalies (price guard, :407-480), tracked_properties + tracked_property_external_refs via the matcher.

### Address handling
- NO structured split is stored on prospecting_listings. Raw `address` is truncated to 255 (:117-121); the literal "Address not available" and blank become NULL.
- Normaliser = `ProspectingListing::normalizeAddress($address, $suburb)` app/Models/ProspectingListing.php:462-479: lower-case, strip non `[a-z0-9\s]`, collapse whitespace, then APPEND the stripped suburb. Returns NULL if address empty. No street-type, unit, or complex handling. Written by `assignPropertyGroup` ProspectingApiController.php:499-530.
- Controller-side "street parse" for the matcher is a naive regex `^(\d+\w*)\s+(.+)$` (ProspectingApiController.php:553-557). Takes the FIRST number as street_number even when it is a unit/complex number. QA1 proof (SQL A-7): TP 1480 has street_number=303, street_name="Pebble Beach, 10 Heleza Boulevard" and carries 75 listings with 62 distinct normalized addresses (units 229/234/F03/F41 plus Heleza Blvd numbers 1, 2, 10).
- A better reader exists and is used on the MATCH side only: app/Services/Address/AddressParser.php (header :12-29, "THE one address reader"), `ProspectingListing::parseStreetNumber()` (:494, last comma-segment rule) and `AddressMatcher`/`AddressMatchScorer` (tiers exact / possible / street_only / none, AddressMatchScorer.php:29-32). The ingest path does not use it to store columns.
- On update the address is overwritten unconditionally: `$existing->address = $data['address'];` ProspectingApiController.php:202 (no `?? $existing->address`), so a later tile without a street address NULLs a previously known one. Also portal_url overwritten (:211). Compare suburb/district/etc., which keep the old value on null.
- Structured readiness derived from DB (SQL A-3): of 45,441 rows only 8,004 (p24) + 6,212 (pp) have any address; of those 7,042 (p24) / 5,930 (pp) start with a house number.

### Matching today
1. Row identity (dedupe at ingest): `(agency_id, portal_source, portal_ref)`, DB unique index `prospecting_listings_agency_id_portal_source_portal_ref_unique` (SQL A-1) and lookup ProspectingApiController.php:143-146. A re-listed advert with a NEW portal_ref becomes a NEW row ("rotating refs").
2. Cross-portal grouping: `assignPropertyGroup` ProspectingApiController.php:499-530: if `normalized_address` equals a row from the OTHER portal (`portal_source != this`), copy its `property_group_id`; otherwise group id = own row id. Exact string equality only, address-less rows never group. Migration 2026_03_18_120000 added normalized_address + property_group_id (+ indexes). Same-portal rotating-ref duplicates are NOT grouped; they are collapsed only at count time by `OnMarketStockService::distinctPropertyCountSql()` app/Services/Prospecting/OnMarketStockService.php:501-505 (key = portal_source|normalized_address, else CONCAT('id:',id)). The "dedup_identity" generated column (2026_08_22_140100) was added then dropped by 2026_08_22_150000_revert_dedup_identity_option2; SQL A-1 confirms the column does not exist on QA1.
3. Tracked-property link (Match-or-Create, CLAUDE.md #10): ProspectingApiController.php:543-590 calls `TrackedPropertyMatchOrCreateService::matchOrCreate(agencyId, facts, source, actorUserId)` per row, after every create AND update.
   - facts (null/empty removed): address, street_number, street_name (naive regex above), suburb, property_type, bedrooms, bathrooms, garages, floor_size_m2 (=property_size_m2), erf_size_m2, last_known_asking_price. NO lat/lng, NO erf_number, NO portal_url, NO agent/agency.
   - source: `['type' => 'p24'|'pp', 'ref' => 'P24-<id>'|'PP-T<id>', 'payload' => ['prospecting_listing_id' => id]]`.
   - Result stored in prospecting_listings.tracked_property_id (:582-585). Any exception is swallowed and logged (:587-592), leaving the row unlinked.
   - Strategy 1 (source-ref) in the matcher keys on `(agency_id, source_type, source_ref)` TrackedPropertyMatchOrCreateService.php:689-694; the extension's source_type is 'p24'/'pp' (refs `P24-123`), which differs from the types used by every other source (see C and D). Matcher internals are covered by another auditor.
4. Own-stock match (is this competitor advert actually OUR property?): `ProspectingListingObserver` (registered AppServiceProvider.php:465) -> on created and on dirty normalized_address/suburb -> `ProspectingStockMatchService::matchProspect()` app/Services/Prospecting/ProspectingStockMatchService.php:19-103: only on-market agency properties; Pass 1 = `normalizeAddress(property)` string-equal to the listing's normalized_address; Pass 2 = shared AddressMatcher EXACT tier, requires a readable street number (:75-91). Writes matched_property_id + matched_at (setMatch :456). Confident only; no numeric score. Because `create()` runs before `assignPropertyGroup` sets normalized_address, the useful match fires on the following save (updated hook), not on created.
5. Possible (advisory) match: `findPossibleMatch` ProspectingStockMatchService.php:~165-250: GPS within 0.00025 deg (~25 m, haversine <= 25 m), properties with geo_confidence exact/street, complex-coherence filter, sectional unit-number gate. Queued via ComputePossibleStockMatchJob, and only dispatched for rows that already have latitude/longitude (ProspectingListingObserver.php:60-65). Writes possible_property_id / possible_match_verdict. Never confident on GPS alone.
6. Reverse match when a Property is saved: `matchAllForProperty` ProspectingStockMatchService.php:~330-365 (same suburb, runs matchProspect on each unmatched row).
7. Display-time "is this ours" (not stored): `OnMarketStockService::identitySets()/stockMapForListings()/resolveForClaim()` OnMarketStockService.php:~100-300: portal_ref exact (`P24-`+properties.p24_ref, `PP-`+properties.pp_ref), then normalized_address exact, then tracked-property promoted_to_property_id link ('promoted_link'); resolveForClaim also honours agent-rejected matches (PropertyMatchDecisionService 'mic_claim').
8. Reconciling MIC->property create: `MicPropertyReconciliationService::resolveExistingProperty` app/Services/Prospecting/MicPropertyReconciliationService.php:35-83 uses `matcher->findExistingMatch` then promoted sibling via AddressMatcher EXACT tier.
9. Claims: `ProspectingClaimService` app/Services/Prospecting/ProspectingClaimService.php: temp lock per prospecting_listing_id (:39-85), tracked-property lock (:96-135), permanent claim keyed on prospecting_listing_id AND property_id = listings.matched_property_id (:143-231, `claimOnPitchNow` :405-451) so a claim survives a rotating ref only when a property link exists. No portal_ref/address logic of its own.
10. Linked deed: `linked_deed_tracked_property_id` is set by ComposeSellerService.php:485 (cleared :546, EntryPointController.php:1141) when an agent attaches a deeds-captured TrackedProperty (DeedsCaptureLinkService.php:52 reads it). Not set at ingest.
11. Lifecycle: portal_status drives is_active/off_market_at on update (ProspectingApiController.php:152-175); `SuburbReconcileService` retires unseen rows when capture_complete is sent (:350-367).

### Missing
- M-A1 Portal ref IS captured (P24-/PP- prefixed, required per row), but the matcher is not told it can equal OUR own stock refs in the matcher's own source-ref keying: our syndication writes `source_type='property24'` + BARE number (C), the MIC import writes `source_type='p24'` + `P24-<number>`. Strategy 1 can never link them (SQL C-2: 163 of 173 shared P24 listings sit on DIFFERENT tracked properties).
- M-A2 No GPS, erf number, title deed, SG code or owner at ingest (extension does not read them; facts omit them). GPS arrives later via backfill (6,529 of 45,441 listings, SQL A-1b), which means the 25 m possible-match job can run on only ~14% of rows.
- M-A3 Naive street-number regex (see Address handling) mis-assigns unit/complex numbers; fact quality into the matcher is the weak link.
- M-A4 Address overwritten with NULL on update (ProspectingApiController.php:202).
- M-A5 agent_name never populated (0 of 45,441, SQL A-1) and mandate_type never populated (0); mandate/portal status on MIC is therefore only active/under_offer/sold/withdrawn from P24 ribbons; PP has no status (943 PP rows `NULL` + is_active=1, SQL A-4).
- M-A6 "Pull Property" (PropertyPullController.php:118-123, 200) stores portal_ref in `properties.external_id` and the URL only in a note text (:225-227); it does not call matchOrCreate in this controller (grep: no match). Related ListedDateRecorder call at :214-219.
- M-A7 Same-portal rotating-ref duplicates are not merged at ingest; 2,844 rows sit in 1,090 duplicated-normalized-address groups for p24 and 870 rows in 368 groups for pp (SQL A-5).
- M-A8 2,495 address-bearing listings have NO tracked_property_id (946 p24 + 1,549 pp, SQL A-6). Cause UNVERIFIED (matcher exception swallowed at ProspectingApiController.php:587-592, or a possible-match exception; not tested).
- M-A9 `possible_property_id` is NULL on every row (0 of 45,441, SQL A-1) although 6,529 rows have GPS; whether ComputePossibleStockMatchJob ('matching' queue) is running on QA1 is UNVERIFIED.

### Row evidence (SQL I ran)
A-0 `SELECT agency_id, COUNT(*) FROM prospecting_listings GROUP BY agency_id` -> agency 1 = 45,441.
A-1 per portal (non-deleted flag shown separately; total includes 1 soft-deleted p24 row):
`SELECT portal_source, COUNT(*) total, SUM(portal_ref<>'') ref, SUM(portal_url IS NOT NULL AND portal_url<>'') url, SUM(address IS NOT NULL AND address<>'') addr, SUM(normalized_address IS NOT NULL AND normalized_address<>'') norm, SUM(latitude IS NOT NULL AND longitude IS NOT NULL AND latitude<>0 AND longitude<>0) gps, SUM(erf_size_m2 IS NOT NULL) erf_size, SUM(tracked_property_id IS NOT NULL) tp, SUM(matched_property_id IS NOT NULL) matched, SUM(possible_property_id IS NOT NULL) possible, SUM(linked_deed_tracked_property_id IS NOT NULL) deedlink, SUM(property_group_id IS NOT NULL) grp, SUM(agency_name<>'') agcy, SUM(agent_name<>'') agent, SUM(mandate_type IS NOT NULL) mandate, SUM(first_seen_email_date IS NOT NULL) emaildate, SUM(listed_date IS NOT NULL) listed FROM prospecting_listings GROUP BY portal_source`

| portal | total | ref | url | addr | normalized | GPS | erf_size_m2 | tracked_property_id | matched_property_id | possible | linked deed | group id | agency_name | agent_name | mandate | email date | listed_date |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| p24 | 24,333 | 24,333 | 24,333 | 8,004 | 8,004 | 3,121 | 23,058 | 23,386 | 721 | 0 | 7 | 24,332 | 23,255 | 0 | 0 | 0 | 0 |
| pp | 21,108 | 21,108 | 21,108 | 6,212 | 6,212 | 3,408 | 17,924 | 19,558 | 352 | 0 | 4 | 21,108 | 19,198 | 0 | 0 | 0 | 0 |

(GPS total 6,529. No erf-number, title-deed or street-number-column exists on the table; column list proves it.)
A-1b ref prefixes: `SELECT portal_source, LEFT(portal_ref,4), COUNT(*) FROM prospecting_listings GROUP BY 1,2` -> p24 'P24-' 24,332 (+1 'CC5-' soft-deleted test row); pp 'PP-T' 21,108.
A-3 `SELECT portal_source, SUM(address REGEXP '^[0-9]+[A-Za-z]?[ ]'), SUM(address LIKE '%,%'), SUM(suburb='') FROM prospecting_listings WHERE deleted_at IS NULL AND address IS NOT NULL GROUP BY 1` -> p24 7,042 / 2,213 / 0; pp 5,930 / 3,197 / 0.
A-4 `SELECT portal_source, COALESCE(portal_status,'NULL'), is_active, COUNT(*) FROM prospecting_listings WHERE deleted_at IS NULL GROUP BY 1,2,3` -> p24: active/1 9,426; NULL/0 2,249; sold/0 213; under_offer/0 949; withdrawn/0 11,495. pp: NULL/0 1,755; NULL/1 943; withdrawn/0 18,410.
A-5 duplicates:
- Same portal ref more than once: impossible by unique index (SQL A-1 SHOW CREATE TABLE); no 'P24-' vs 'PP-' overlap.
- Same (portal_source, normalized_address) >1 (non-deleted): `SELECT portal_source, COUNT(*), SUM(c), SUM(c-1) FROM (SELECT portal_source, normalized_address, COUNT(*) c FROM prospecting_listings WHERE deleted_at IS NULL AND normalized_address<>'' GROUP BY 1,2 HAVING c>1) t GROUP BY 1` -> p24: 1,090 groups, 2,844 rows, 1,754 excess; pp: 368 groups, 870 rows, 502 excess. Active-only: p24 162 groups / 255 excess; pp 0.
- Across portals: 2,840 normalized-address groups with >1 row, 1,971 span both portals; 9,989 distinct normalized addresses overall.
- Simple LOWER(TRIM(address)),LOWER(TRIM(suburb)) groups with a leading house number and >1 rows: 2,558.
- property_group_id groups with >1 row: 1,991 groups (4,648 rows); 1,986 groups contain both portals.
A-6 `SELECT portal_source, address IS NOT NULL, COUNT(*) FROM prospecting_listings WHERE deleted_at IS NULL AND tracked_property_id IS NULL GROUP BY 1,2` -> p24 946 (+1 no address); pp 1,549 (+1). Also 2,500 listings have no matching tracked_property_external_refs row on (agency, source_type=portal_source, source_ref=portal_ref).
A-7 Tracked-property fan-in: `SELECT COUNT(*), SUM(c), SUM(c>1), MAX(c) FROM (SELECT tracked_property_id, COUNT(*) c FROM prospecting_listings WHERE deleted_at IS NULL AND tracked_property_id IS NOT NULL GROUP BY 1) t` -> 37,688 TPs hold 42,943 listings, 2,259 TPs hold >1, max 75. 772 TPs hold listings with >1 distinct normalized address. 1,376 TPs hold both p24 and pp listings (the cross-portal link works at TP level).
A-8 Linked TPs' own identity data (join prospecting_listings -> tracked_properties, non-deleted): 42,943 linked; TP erf_number present 421; TP title_deed_number 310; TP street_number+street_name+suburb present 11,733; TP lat/lng 39,906 (TP geo_source google 33,647, unresolved 2,266, NULL 9,058). So only ~1% of MIC-linked TPs carry an erf number.
Total TPs (non-deleted) 46,356.

---------------------------------------------------------------------------------------------------
## B. P24 / PP EMAIL alerts and portal leads

### Arrives via (file:line)
- P24 alert emails: scheduler `routes/console.php:12` `Schedule::command('p24:import')->hourly()` -> app/Console/Commands/ImportP24Alerts.php -> app/Services/P24/P24ImapImportService.php:`import()` (IMAP filter `from('no-reply@property24.com')`, :88-93; config/services.php:56-64 `p24_imap`, off unless P24_IMPORT_ENABLED). Manual trigger: Admin/P24Controller.php:189 -> ImportP24AlertsJob.
- Parser: app/Services/P24/P24EmailParserService.php:19-107 `parse()`.
- There is NO Private Property alert-email parser (grep for `no-reply@property24` / privateproperty mail parsing in app/: only P24ImapImportService; PP strings elsewhere are unrelated: Property.php, LinkExtractionService, WhistleblowComplaintService, OAS controller).
- P24 leads: app/Services/Syndication/Property24/P24LeadService.php:134 `processLead`. PP leads: app/Services/PrivateProperty/PpLeadService.php:156 `processLead`. Both write `portal_leads`.

### Saved identifiers (table.column)
Alert email extracts (P24EmailParserService.php:19-103): listing number via `listingNumber=(P24-\d+)` in links (fallback: subject), asking price (`R ...` regex; rows with no price are skipped, P24ImapImportService.php:129), property_type + bedrooms (`N Bedroom Type`), suburb (from subject `X for sale in <suburb>` or text line under the bedroom line), bathrooms/garages (icon_bath/icon_garage), is_mandated (the word "Mandated" in the block), p24_url (the property24.com link containing the listingNumber).
Saved to `p24_listings` (SHOW CREATE TABLE, SQL B-1): agency_id, p24_listing_number varchar(255) UNIQUE (global unique, not per agency), asking_price, original_price, property_type, suburb, area (never written by the importer), bedrooms, bathrooms, garages, is_mandated, listing_status (default 'active'; nothing sets it to anything else), p24_url, first_seen_date, last_seen_date, times_seen; child `p24_price_changes`; dedupe/audit table `p24_import_log` (email_uid). No address, street, erf, GPS, agent, agency or tracked_property_id/matched_property_id column.
Leads: `portal_leads` (SQL B-3) carries listing_id (property id), listing_portal_ref (P24: bare number, PP: T-number), contact_id, name/email/phone/message, lead_source_raw JSON. Raw payload keys (SQL B-4): P24 = date, name, message, emailAddress, contactNumber, listingNumber; PP = Date, PPRef, LeadId, Message, BranchId, FromName, LeadType, FromEmail, UniqueListingId, FromContactNumber. No address or suburb in either payload.

### Address handling
- Emails: suburb text only, never a street address (P24 hides it); no normalisation. Spec confirmation: .ai/specs/market-intelligence-discovery.md Section 3.2.
- Leads: none (no address field in payload; `facts` for matching is built from `$raw['listingAddress'|'address'|'suburb'...]` which are absent).

### Matching today
- Email -> anything: NO matching at ingest. The importer only upserts p24_listings by `p24_listing_number` (P24ImapImportService.php:132, NOT scoped by agency, relying on the global unique index) and never calls matchOrCreate, never touches prospecting_listings, never writes prospecting_listings.first_seen_email_date. Spec section 3.1 says "and/or prospecting_listings"; the code writes only p24_listings (spec/code discrepancy).
- The one link that exists is a READ-TIME join on the string: `MarketIntelligenceController::applyP24EmailCrossReference` app/Http/Controllers/CoreX/MarketIntelligenceController.php:1304-1330 matches `prospecting_listings.portal_ref` ('P24-123456789') to `p24_listings.p24_listing_number` ('P24-123456789', same format) with `whereIn`, and copies first_seen_date, original_price, times_seen, listing_status onto the MIC row for display only (nothing persisted). Alerts without a prospecting row are listed separately as source "P24 email alert" (MIC controller :1837-1850).
- So: an email alert CAN be matched to a P24 extension import, by exact portal ref string, at display time, and only for P24 (PP has no alerts). It cannot be matched to anything by address, and nothing links it to a tracked property.
- P24 lead -> property: P24LeadService.php:262-310 `resolveListingId`: (1) `properties.p24_listing_number = ref OR properties.p24_ref = ref` (agency-scoped, bare number); (2) `matchOrCreate(facts = address/suburb/lat/lng/property_type from payload, source = ['type'=>'property24','ref'=>listingNumber,'payload'=>raw])`, returns `promoted_to_property_id` if the TP is promoted. Dedupe of the lead: agency+ref+email|phone+received_at (:247-260).
- PP lead -> property: PpLeadService.php:286-345: (1) `UniqueListingId` = our property id; (2) `PPRef` = properties.pp_ref or pp_listing_feed_ref; (3) `matchOrCreate(facts = ListingAddress/Address/Suburb/PropertyType, source = ['type'=>'private_property','ref'=>listingRef|ppRef|unique,'payload'=>raw])`. Dedupe by PP LeadId (:176-180).

### Missing
- M-B1 Alerts are not matched to a tracked property or to prospecting_listings in storage; the P24 ref is stored in a format that DOES equal the extension's, but only the MIC read path uses it (SQL B-2: 8,226 of 14,092 alert rows have a prospecting_listings row; 5,866 have none and are alert-only).
- M-B2 Alert importer ignores PP entirely (no PP alert source).
- M-B3 Alert ref is uniquely keyed globally, not per agency (`p24_listings_p24_listing_number_unique`, SQL B-1), and `resolveAgencyId` picks the lowest-id agency having p24_agency_id (P24ImapImportService.php:239-246): single-agency assumption (CLAUDE.md rule #9).
- M-B4 Lead sources use different `source_type` strings and ref formats from the MIC import: 'property24' + bare number, 'private_property' + bare T-number, versus the extension's 'p24' + 'P24-<n>', 'pp' + 'PP-T<n>'. Strategy 1 in the matcher keys on (source_type, source_ref) exactly, so a lead can never source-ref-match a MIC-created tracked property.
- M-B5 Lead payloads carry no address, so the matchOrCreate fallback has no facts: SQL B-5 shows 10 'private_property' refs exist, each on a tracked property with no street and no suburb (empty shells, none promoted). The 5 unresolved P24 leads and 17 unresolved PP leads (SQL B-3) are the cases that fall through.
- M-B6 Alert `area`, address and `listing_status` updates are never populated (listing_status stays 'active' for all 14,092 rows, SQL B-2), so MIC "email_listing_status" is always 'active'.

### Row evidence
B-1 `SHOW CREATE TABLE p24_listings` -> UNIQUE (p24_listing_number); no address/tracked columns. 14,092 rows total (SQL B-2).
B-2 `SELECT agency_id, listing_status, COUNT(*), SUM(p24_url<>''), SUM(suburb<>''), SUM(area<>''), SUM(is_mandated), MIN(first_seen_date), MAX(last_seen_date), SUM(p24_listing_number LIKE 'P24-%') FROM p24_listings WHERE deleted_at IS NULL GROUP BY 1,2` -> agency 1, active, 14,092 rows, URL 14,092, suburb 12,435, area 0, mandated 462, 2026-03-02..2026-10-22, all 14,092 'P24-' prefixed.
- Alert rows that equal a prospecting_listings p24 row by ref: 8,226; with no such row: 5,866; alert rows whose ref has a tracked_property_external_refs row (type 'p24'): 8,138; alert rows equal to an OWN property (`CONCAT('P24-',properties.p24_ref)`): 270. Duplicate p24_listing_number: 0.
- `p24_import_log` (email_uid not 'run_%'): success 15,529 emails (2026-02-02..2026-10-22, 17,735 listings found, 12,817 new), error 72.
B-3 `SELECT portal, COUNT(*), SUM(listing_portal_ref<>''), SUM(listing_id IS NOT NULL), SUM(contact_id IS NOT NULL) FROM portal_leads WHERE deleted_at IS NULL GROUP BY portal` -> p24 626 / 626 / 621 / 609; pp 243 / 243 / 226 / 243; website 63. Unresolved with ref: p24 5, pp 17.
B-4 `SELECT portal, JSON_KEYS(lead_source_raw) FROM portal_leads ...` -> keys listed above.
B-5 `SELECT r.source_type, COUNT(*), SUM(tp.street_name IS NULL AND tp.suburb IS NULL) FROM tracked_property_external_refs r JOIN tracked_properties tp ON tp.id=r.tracked_property_id WHERE r.source_type IN ('property24','private_property') GROUP BY 1` -> private_property 10 refs / 10 TPs without any address / 0 promoted; property24 389 refs / 262 TPs / 1 without address / 374 promoted.

---------------------------------------------------------------------------------------------------
## C. P24 / PP imports of OUR OWN listings

### Arrives via (file:line)
- Syndication push: app/Services/Syndication/Property24/Property24SyndicationService.php (p24_ref written ~:440-474, `$property->update($updateData)`; audit-chain hook `writeP24ExternalRef` :114-147 called :474). PP equivalent: app/Services/PrivateProperty/PrivatePropertySyndicationService.php (pp_ref / pp_listing_feed_ref; pp_listing_status :629). UNVERIFIED: PP external-ref hook (not read).
- P24 CSV import of existing stock: app/Services/Importer/P24ListingsCsvParser.php (listing number, street number/name, ErfSize+ErfAreaUnit, :58-144), Jobs ParseP24ListingsImportJob / ConfirmP24PropertyRowJob; one-shot repair app/Services/Importer/P24ListingNumberReconciler.php (matches CSV rows back to properties by listing number / GPS box +-0.0002 deg ~22 m, writes p24_listing_number AND p24_ref).
- `p24_listings` (email alert table, section B) is NOT the table for own imports despite the name.

### Saved identifiers (table.column), `properties` (SHOW COLUMNS, SQL C-1)
- Portal refs: p24_ref (P24 listing number, bare digits, e.g. 100314556), p24_listing_number (from CSV import), pp_ref (e.g. T2870187), pp_listing_feed_ref (our feed ref, e.g. 17), source_reference, p24_suburb_id / p24_city_id / p24_province_id, pp_suburb_id. NO portal URL column and NO "web reference" column (only virtual_tour_url; PP/P24 URL not stored).
- Address: address (raw, 255), address_raw (text), street_number, street_name, street_name_normalised, street_core, street_type, suburb, suburb_normalised, city, town, township, address_parse_status/_note, lpi_code. No postal_code on properties (UNVERIFIED: not in the filtered column list; postal_code exists on tracked_properties).
- Cadastral: erf_number, erf_portion, erf_size_m2, title_deed_number, sg_province/sg_rural_urban/sg_farm_name/sg_last_searched_at (no SG 21-char code column in the filtered list; UNVERIFIED).
- GPS: latitude, longitude, geo_source, geo_confidence, geo_resolved_at.
- Link to pillar: there is NO tracked_property_id on properties; the link lives on tracked_properties.promoted_to_property_id (+ promoted_at) and tracked_property_external_refs.

### Address handling
Structured columns exist and are parsed by the structured-address machinery (app/Services/Address/*, CSV parser builds `address = street_number + ' ' + street_name` P24ListingsCsvParser.php:69). Normalisation for MIC matching: `ProspectingListing::normalizeAddress($p->address, $p->suburb)` (OnMarketStockService.php:165, ProspectingStockMatchService.php:52).

### Matching today
- `writeP24ExternalRef` (Property24SyndicationService.php:114-147): on EVERY submit with a p24_ref, `matchOrCreate(facts = address, suburb, latitude, longitude, property_type, bedrooms, bathrooms, garages (no street split, no erf), source = ['type'=>'property24','ref'=>$listingNumber (bare digits),'payload'=>['property_id'=>id]])`; sets tracked.promoted_to_property_id ONLY when the TP was freshly created in that call (:132-145; poisoning fix, MIC CRISIS ITEM 3).
- Stock identity used by MIC: `OnMarketStockService::identitySets` builds refs `P24-<p24_ref>` / `PP-<pp_ref>` plus normalized addresses from properties, excluding stale and status prospecting/not_selling (OnMarketStockService.php:~125-165). This is what makes extension rows "IN STOCK".
- Lead resolution uses properties.p24_listing_number / p24_ref / pp_ref directly (section B).

### Missing
- M-C1 Syndication ref type 'property24' + bare number vs extension 'p24' + 'P24-number' (SQL C-2): 389 'property24' refs, 173 overlap a 'p24' ref in the same agency, 163 of those 173 point at DIFFERENT tracked properties (10 same). So own-stock tracked properties and MIC tracked properties for the same P24 listing are mostly separate rows (duplicates).
- M-C2 No portal URL is stored on properties; no P24 "web reference" distinct from listing number.
- M-C3 writeP24ExternalRef passes only a raw address, no erf/street split (comment :80-86), so the loose strategies fire; the file's own comment records a live mismatch incident.
- M-C4 `p24_ref` and `p24_listing_number` can disagree (3 properties, SQL C-1b).

### Row evidence
C-1 `SELECT COUNT(*), SUM(p24_ref<>''), SUM(p24_listing_number<>''), SUM(pp_ref<>''), SUM(erf_number<>''), SUM(title_deed_number<>''), SUM(lpi_code<>''), SUM(street_number<>'' AND street_name<>'' AND suburb<>''), SUM(latitude IS NOT NULL AND longitude IS NOT NULL AND latitude<>0 AND longitude<>0) FROM properties WHERE deleted_at IS NULL` -> total 9,920; p24_ref 8,239; p24_listing_number 8,150; pp_ref 207; erf_number 740; title_deed_number 1,596; lpi_code 0; structured number+street+suburb 3,890; GPS 8,983.
C-1b `... p24_ref<>p24_listing_number (both set)` -> 3.
C-2 `SELECT SUM(a.tracked_property_id<>b.tracked_property_id), SUM(a.tracked_property_id=b.tracked_property_id) FROM tracked_property_external_refs a JOIN tracked_property_external_refs b ON b.source_type='p24' AND b.agency_id=a.agency_id AND (b.source_ref=a.source_ref OR b.source_ref=CONCAT('P24-',a.source_ref)) WHERE a.source_type='property24'` -> differ 163, same 10 (173 pairs).
C-3 `SELECT source_type, COUNT(*) FROM tracked_property_external_refs GROUP BY 1` -> agency_stock 5, chrome_capture 6,043, cmainfo 302, deeds_capture 2,220, manual_prospect_entry 50, map_prospect_launch 9, p24 23,385, pp 19,555, private_property 10, property24 389. (Ten different source_type vocabularies; the matcher's strategy 1 only matches identical type+ref.)

---------------------------------------------------------------------------------------------------
## D. Portal side-tables: portal_captures, portal_listings, portal_listing_observations

### Arrives via (file:line)
- Presentation/CMA capture: the OLD extension build posts raw HTML of the current P24 page to `POST /portal-captures/ingest` (routes/web.php:8465-8466, middleware auth.portal_capture + permission:access_presentations) -> app/Http/Controllers/Presentation/PortalCaptureController.php:22-231 `ingest`. Live extension (public/) still has presentation flows (UNVERIFIED which popup code posts /portal-captures/ingest; popup.js not read in full). Server re-classifies page type (:76-80), then ONLY for Property24:
  - search page: `Property24SearchExtractorV1` (app/Services/Presentations/Evidence/Extractors/Property24SearchExtractorV1.php:195-212) -> items -> `PortalListingTrackingService::processItems` -> `linkPortalItemsToTrackedProperties`.
  - listing page: `extractProperty24ListingFields` (PortalCaptureController.php:849-985) -> same two calls + `recordListedDate` (:1253-1259).
  - Private Property pages are stored as portal_captures only (no extractor, no portal_listings, no TP link).

### Saved identifiers (table.column)
- portal_captures (SQL D-1): user_id, presentation_id, source_site, page_type, source_url, final_url, page_title, captured_at, extractor_version, dom_hash_sha256, raw_html_path (storage `portal_captures/<id>.html`), screenshot_path, parse_status, extracted_fields_json, jsonld_json, found_image_urls_json. No agency_id (agency is resolved via users.agency_id).
- portal_listings (SQL D-1): source_site, portal_listing_id varchar(50) = BARE digits (non-digits stripped, PortalListingTrackingService.php:~52), canonical_url, first_seen_at/last_seen_at, last_capture_id, current_fields_json (price, beds, baths, parking, size_m2, erf_m2, title), primary_image_url, tracked_property_id (FK). UNIQUE (source_site, portal_listing_id). NO agency_id (global across agencies), NO address/suburb columns, no GPS.
- portal_listing_observations: portal_listing_id, capture_id, observed_at, observed_fields_json, changed_fields_json (field deltas for price/beds/baths/parking/size/erf/title; PortalListingTrackingService.php:15 TRACKED_FIELDS) - an audit trail of what each capture saw.
- Items carry `url`, `address` (search tile `.p24_address`), `location` (search tile `.p24_location`), `image`, price etc. (Property24SearchExtractorV1.php:201-211) but only the fields above are persisted on portal_listings; address stays only in portal_captures.extracted_fields_json.

### Address handling
`linkPortalItemsToTrackedProperties` PortalCaptureController.php:1283-1346: same naive `^(\d+\w*)\s+(.+)$` split (:1299-1305), same facts keys as section A plus none for GPS/erf number; matches with source = `['type'=>'chrome_capture','ref'=>bare portal_listing_id,'payload'=>[capture_id, source_site, portal_listing_id]]`. Two wiring gaps (code-evident, runtime UNVERIFIED): (1) search items expose the suburb under `location`, but the facts read `$item['suburb']` (:1311), so suburb is null for every search-sourced item; (2) the listing-page path passes `$listingFields['address']` (:166) but `extractProperty24ListingFields` never assigns `$fields['address']` (only `$fields['suburb']` from the `p24_address` node, ~:880-890), so listing-page captures also reach the matcher with no street address.

### Matching today
- portal_listings dedupe: (source_site, portal_listing_id) unique; `normalizeSourceSite` forces 'www.property24.com' (:1262-1273).
- TP link: matchOrCreate with type 'chrome_capture' and a BARE ref, then `portal_listings.tracked_property_id` filled where null (:1335-1340).
- No link to prospecting_listings at all (no FK, no code). The only bridge is the string `prospecting_listings.portal_ref = 'P24-'+portal_listing_id`.
- `recordListedDate` (:1253) updates prospecting_listings.listed_date for an existing MIC row by (agency, 'p24', ref); `ListedDateRecorder::portalRef` normalises the prefix (ListedDateRecorder.php:32).

### Missing
- M-D1 Same P24 advert is held under three identities: prospecting_listings 'P24-<id>', portal_listings '<id>', TP external refs 'p24'/'P24-<id>' vs 'chrome_capture'/'<id>'. SQL D-3: of 5,433 portal_listings that equal a prospecting_listings P24 row by ref, 4,561 point at a DIFFERENT tracked_property than the MIC row, 0 at the same one (872 have a null on one side).
- M-D2 portal_listings has no agency_id: one global row per P24 id, shared by every agency; TP linking is by whichever user captured first (`whereNull('tracked_property_id')`, :1335-1340) - a multi-agency hazard (CLAUDE.md rule #9).
- M-D3 Captures stopped: latest P24 capture 2026-06-12 (SQL D-2). The CMA/presentation capture path is no longer feeding data, while MIC (section A) is.
- M-D4 Wiring gaps above (suburb, address) degrade the facts passed to the matcher.
- M-D5 PP captures never produce portal_listings or TPs.

### Row evidence
D-1 SHOW CREATE TABLE for the three tables (columns above; indexes: portal_listings_site_id_unique, idx_portal_listings_tracked, portal_obs_listing_observed_idx).
D-2 `SELECT source_site, page_type, parse_status, COUNT(*), MIN(captured_at), MAX(captured_at) FROM portal_captures WHERE deleted_at IS NULL GROUP BY 1,2,3` -> www.property24.com search/parsed 821 (2026-03-03..2026-06-12); property/parsed 95 (2026-03-03..2026-06-10). Nothing else.
D-2b `SELECT source_site, COUNT(*), SUM(tracked_property_id IS NOT NULL), SUM(canonical_url IS NOT NULL), SUM(primary_image_url IS NOT NULL) FROM portal_listings GROUP BY 1` -> www.property24.com 8,767 listings; 6,043 with TP; 8,767 with URL; 46 with image. portal_listing_observations: 17,013 rows.
D-3 `SELECT COUNT(*) FROM portal_listings pt JOIN prospecting_listings pl ON pl.portal_source='p24' AND pl.portal_ref=CONCAT('P24-',pt.portal_listing_id)` -> 5,433; of these `WHERE pt.tracked_property_id=pl.tracked_property_id` -> 0; `pt.tracked_property_id<>pl.tracked_property_id (both not null)` -> 4,561.
D-4 tracked_property_external_refs source_type 'chrome_capture' = 6,043 rows (matches portal_listings with TP).

---------------------------------------------------------------------------------------------------
## Cross-source answers to the business questions

1. Portal reference on web-extension imports: YES, always, as `P24-<digits>` / `PP-T<digits>` (required per row, ProspectingApiController.php:122-125; 100% of 45,441 rows, SQL A-1). On Pull Property it goes to `properties.external_id` (PropertyPullController.php:200). Portal URL is saved on prospecting_listings.portal_url (100%) but NOT on properties.
2. Portal reference on email imports: YES for P24 (`P24-<digits>` in p24_listings.p24_listing_number, plus p24_url); PP has no email import.
3. Can an email alert be matched to a P24 import? Yes, by exact string equality of `p24_listing_number` = `prospecting_listings.portal_ref`, but ONLY at MIC display time (MarketIntelligenceController.php:1304-1330). 8,226 of 14,092 alert rows currently have a counterpart. No persisted link, no address match, no tracked-property link.
4. Where matching is absent: email alerts (no matchOrCreate), PP alerts (no source), lead payloads (no address), portal_captures vs prospecting_listings (no shared key in matcher), own-stock vs MIC (type/ref-format mismatch), GPS/erf/deed/owner never captured at ingest by the extension.
5. Erf number / title deed / SG code / owner at ingest: none of the four sources captures them; they appear only on tracked_properties/properties via deeds capture, CMA info or imports.

---

# Part 3 — Presentations, CMA imports, address normalisation, suburb data


Code: /corex-qa1 @ 8dfee589f (branch QA1). DB: corex_qa1 (read-only via q.sh). Date of audit: 2026-10-29.
Convention: `file:line` is relative to /corex-qa1. "UNVERIFIED" = could not prove from code/DB. All counts are `deleted_at IS NULL` unless stated.
Note: the SQL helper refuses statements containing CREATE, so schema came from DESCRIBE / information_schema, not SHOW CREATE TABLE.

## Headline findings (read these first)

1. **Presentation comps are an island.** `presentation_sold_comps` and `presentation_active_listings` have NO column for property_id, tracked_property_id, prospecting_listing_id, erf, street, portal ref, URL or GPS. The `mic_comp_row_id` column on both tables is **never written** (0 of 4,277 and 0 of 6,988 rows); the id only lives inside `raw_row_json`. No presentation comp is ever passed to `matchOrCreate`. Presentation to comp matching is by suburb text, address-fragment LIKE on the report subject, and Haversine only (A, "Matching today").
2. **CMA report comps are mostly NOT fed to the Tracked Property universe.** `ParseMarketReportJob` only calls `matchOrCreate` for the parser's `extractedAddresses` list (ParseMarketReportJob.php:304-318). Comp rows are stored with no link column to any pillar. QA1: 3,557 comp rows + 194 subject rows, but only 702 tracked_properties carry a `report:N` ref in `source_chain`, and only 133 of 436 live reports ever fed a tracked_property address. The erf number is in `raw_row_json` for ~740 comp rows but there is **no erf column** on `market_report_comp_rows`.
3. **Chrome portal capture creates tracked properties with no suburb.** `PortalCaptureController::linkPortalItemsToTrackedProperties` reads `$item['suburb']` (PortalCaptureController.php:1311) but the P24 search extractor returns the suburb under the key `location` (Property24SearchExtractorV1.php:212). Evidence: 6,043 tracked_properties linked from `portal_listings`; only 3 have a suburb, 16 have GPS, 1,279 have a street name. 6,043 portal listings map to 6,043 DISTINCT tracked properties (1:1), i.e. no merge occurred.
4. **There is a single new shared address reader, but it is not the only normaliser.** `App\Services\Address\AddressParser` (+ AddressStructurer / AddressMatcher / AddressMatchScorer / SuburbResolver) is the designed "one reader" (.ai/specs/structured-address-matching.md). At least 12 other independent normalisers/keys remain in live use (section C), several with different rules (e.g. `CompFingerprint` drops everything after the first comma; `ProspectingListing::normalizeAddress` strips punctuation and appends suburb; `SuburbMatcher` strips "beach/bay/north..." so Uvongo == Uvongo Beach while the scorer treats them as neighbours). On QA1 the structured columns are barely populated: `street_core` is set on 284 of 46,356 tracked_properties although 10,177 have a street_name (see C, evidence).
5. **Dedupe on presentation data is weak or switched off.** `listing_dedupe_v1` defaults OFF (config/features.php:12); 0 presentation_active_listings have an `external_key`; all 106 server-side URL snapshots on QA1 were blocked (`blocked_reason` NOT NULL), and the legacy persist path drops the P24 listing id (UrlIngestionService.php:169-196). Sold comps duplicate within a presentation (130 groups, 137 extra rows; 106 groups are the same sale arriving from two different sources). CMA comp rows duplicate across reports (252 address groups / 325 extra rows; 314 scheme+section groups / 537 extra rows).

---

# A. Presentation / CMA data

Tables: `presentation_sold_comps`, `presentation_active_listings`, `presentation_listing_price_history`, plus `portal_captures` / `portal_listings` (portal capture feed), `presentation_url_snapshots`, `presentation_fields`, `presentation_uploads`.

## Arrives via (file:line)

| Path | Writer | Target |
|---|---|---|
| MIC snapshot at presentation generate (the main path) | `MicSnapshotHydrator::hydrateForPresentation` app/Services/Presentations/MicSnapshotHydrator.php:80 -> `PresentationSoldComp::create` :186, `PresentationActiveListing::create` :239 | copies `market_report_comp_rows` into the per-presentation tables; wipes and re-inserts `mic_snapshot_v1` rows each regeneration (:86-92) |
| Deal register comps (HFC own deals) | `MicSnapshotHydrator::collectAndInsertDealComps` :499 (`deal_register_v1`) | 0 rows on QA1 |
| Agent "browse market" picker | PresentationReviewController.php:792 (`agent_browse`, carries `mic_comp_row_id` in raw JSON) | 39 rows |
| Uploaded CMA/vicinity PDFs | UploadExtractionService.php:468-530 `persistSoldComps`/`persistActiveListings` (`doc_extract_v1:*`); suburb derived as "text after the last comma" of the address (:476-479, :506-509) | 4 comp parser_versions + `doc_extract_v1:cma_active` |
| Uploaded sales report / suburb stock docs | SalesReportParserV1.php:53 (`sales_report_v1`), SuburbStockParserV1.php:51 (`suburb_stock_v1`) | 5 and 5,153 rows |
| Server-side URL fetch of P24/PP search+listing pages | UrlIngestionService.php:100 `persistWithDedupe` (flag ON) / :169 `persistLegacy` (flag OFF, the default) | 0 rows on QA1 (all 106 snapshots blocked) |
| Chrome extension portal capture | PortalCaptureController::ingest PortalCaptureController.php:22 -> `portal_captures` row, `PortalListingTrackingService::processItems` -> `portal_listings`; extractor `extractProperty24ListingFields` :849 for listing pages | `portal_captures` 926 rows (825 search, 101 property), `portal_listings` 8,767 |
| Presentation field extraction (CMA subject block) | UploadExtractionService.php:384 fires `PresentationFieldsExtracted`; listener `App\Listeners\Presentation\PropagateCmaToProperty` (registered AppServiceProvider.php:917) | calls `PropertyCmaPropagationService` and `matchOrCreate` |

Comp-to-subject *selection* (what ends up in a presentation): MicSnapshotHydrator.php:638-760 `collectMatchedRows`.

## Saved identifiers (table.column)

`presentation_sold_comps` (DESCRIBE): id, mic_comp_row_id (never written), presentation_id, agency_id, source_upload_id, sold_date, sold_price_inc, suburb (free text), property_type, beds, baths, size_m2, listed_date, raw_row_json (TEXT), parser_version, is_demo. **No** address / erf / deed / SG / GPS / portal-ref / URL / owner / agent columns.

`presentation_active_listings`: as above plus listing_date, list_price_inc, status, source_snapshot_id, extraction_method, `external_key` varchar(255), `fingerprint` char(64), first_seen_at, last_seen_at, is_active, source_rank, merge_confidence, data_quality_score, conflict_flags_json. No address / erf / GPS / URL columns.

`presentation_listing_price_history`: active_listing_id, price_inc, captured_at (written only if `listing_lifecycle_v1`, default OFF; config/features.php:13). 0 rows.

Identifiers that DO exist only inside `raw_row_json` (JSON, not queryable by index):
- MIC-sourced rows (MicSnapshotHydrator::encodeRaw :906-): `address`, `scheme_name`, `section_number`, `mic_comp_row_id`, `source_report_id`, `latitude`, `longitude`, `extent_m2`, `sale_date`, `sale_price`, `distance_m`. No erf, no title deed, no SG code, no owner.
- Doc-extract rows: `address` (e.g. "4 CORDINER STREET, SCOTTBURGH"), `erf_no` key exists but null (sample active-listing row), no GPS.
- Deal-register rows (:480-495): `deal_id`, `source_property_id` (properties.id), `address`, `latitude`, `longitude`. Only comp type that carries a pillar id, 0 rows on QA1.
- P24/PP search row via `ListingNormalizationService`: `external_id` -> `external_key = "p24:<id>"` / `"pp:<id>"` (ListingNormalizationService.php:52-65). URL and address are NOT stored (raw_data = `['source'=>'dom']`, Property24SearchParserV1.php:407-414).

`portal_captures`: user_id, presentation_id, source_site, page_type, `source_url`, `final_url`, page_title, dom_hash_sha256, `extracted_fields_json` (listing pages: `listing_id`, `price`, `title`, `suburb` (really the address text, PortalCaptureController.php:887), `property_type`, bedrooms, bathrooms, floor_m2, `erf_m2` (SIZE only), `agent_name`, `agent_phone` (:926-935), `url`), `jsonld_json`. No agency_id column (scoped through user).

`portal_listings`: `source_site` + `portal_listing_id` (UNIQUE `portal_listings_site_id_unique`), `canonical_url`, `current_fields_json` limited to price/beds/baths/parking/size_m2/erf_m2/title (PortalListingTrackingService.php:18), `tracked_property_id`. No agency_id, no address, no suburb, no GPS.

`presentations` (the subject): `property_address` varchar(255), `suburb` varchar(100), `property_id` (136 of 222 set), `listing_id` (0), `tracked_property_id` (**0 of 222 set; no writer found in app/Http/Controllers/Presentation or app/Services/Presentations**, only the fillable at Presentation.php:21 and relation :160), `erf_size_m2`. Presentation fields (`presentation_fields`): `subject.address`, `subject.suburb`, `subject.erf`, `subject.title_deed`, `subject.gps`, `subject.extent_m2`, `municipal.total_value`... (PropagateCmaToProperty.php:34-45).

## Address handling

- No structured address on any comp row: one free-text `address` inside raw JSON, one free-text `suburb` column.
- Suburb text is not canonicalised on the doc-extract path: "text after the last comma" (UploadExtractionService.php:476-479). Pollution evidence: 112 of 113 `doc_extract_v1:vicinity_sales_sectional` rows have a suburb like "UVONGO BEACH (Unit 43)" (`SELECT ... suburb LIKE '%(%'`); 101 distinct suburb strings in those 113 rows. 73 of 299 `street_sales` and 20 of 250 `vicinity_sales` rows have a blank suburb.
- MIC-sourced rows take `market_report_comp_rows.suburb_normalised` (or the presentation suburb as fallback, MicSnapshotHydrator.php:~900 `resolveSuburb`).
- Suburb comparison for pool membership uses `App\Support\Presentations\SuburbMatcher` (SuburbMatcher.php:76,114) which strips trailing locality words (beach, bay, park, heights, estate, extension, ext, north, south, east, west, central, village). Under it "Uvongo" == "Uvongo Beach".
- Subject address fragments for finding same-subject reports: `SubjectReportResolver::extractAddressNeedles` (SubjectReportResolver.php:46): split on commas, lower-case, keep pieces >= 8 chars, also the piece with the leading number stripped.
- Geocoding of comps: `CompRowGeocoder` (backfill before radius filter) and `encodeRaw` eager geocode via `AddressResolverService::resolve(address, suburb, ...)`; result written back to `market_report_comp_rows.latitude/longitude`, not to the presentation tables.
- Chrome capture -> TP facts: street number split with `/^(\d+\w*)\s+(.+)$/` (PortalCaptureController.php:1302-1305). The same regex pair is copied in PropagateCmaToProperty.php::fieldsToFacts.

## Matching today

| Step | Code | Keys / thresholds |
|---|---|---|
| Which CMA rows go into a presentation | MicSnapshotHydrator::collectMatchedRows :638-760 | (1) same-subject reports via `SubjectReportResolver::resolveReportIds` (street needle LIKE on `market_reports.subject_address`, suburb only confirms); (2) `SuburbMatcher::matches(row.suburb_normalised, presentation.suburb)`; (3) Haversine <= radius if both have GPS (scope `radius_all`). Then agency + is_demo filter, title-type gate, `CompPoolBuilder` price/radius ladder. |
| Dedupe inside a hydration | MicSnapshotHydrator::deduplicate :772 / fingerprint :886 | `S|SCHEME|section|date|price` else `A|lower(address)|date|price`; keeps higher market_report_id |
| Cross-source dedupe deal vs CMA | `CompFingerprint::sourceAgnosticKey` (CompFingerprint.php:49) used by MicSnapshotHydrator.php:438 and `CmaCoverageService::fingerprint*` | scheme|section|date|price else `normaliseAddress` (text before first comma, lower-cased)|date|price. CMA wins on tie. |
| Subject self-exclusion | CompPoolBuilder (normaliseAddr :525, address + distance guard) and deal comps excluded by `property_id` (MicSnapshotHydrator.php:~290 `$subjectPropertyId`) | |
| Portal listing rows dedupe | `ListingNormalizationService::upsertNormalizedRow` ListingNormalizationService.php:108 | (1) `presentation_id + external_key` ("p24:<id>"); (2) `presentation_id + fingerprint` = sha256(suburb, type, beds, baths, size, price bucket R5k) (:73). **Flag OFF by default**. 0 external_key rows on QA1. |
| Portal listing identity | `PortalListingTrackingService::upsertListing` + unique (`source_site`, `portal_listing_id`) | exact id; leading non-digits stripped (PortalListingTrackingService.php:42-45) |
| Capture -> Tracked Property | `PortalCaptureController::linkPortalItemsToTrackedProperties` :1283-1349 calls `TrackedPropertyMatchOrCreateService::matchOrCreate` (:1320) | facts: address, street_number, street_name (regex split), suburb (see finding 3, never populated for search items), property_type, bedrooms, bathrooms, floor_size_m2, erf_size_m2 (this is erf AREA, not the erf number), last_known_asking_price. source: `['type'=>'chrome_capture','ref'=><portal listing id>,'payload'=>[capture_id, source_site, portal_listing_id]]`. On success writes `portal_listings.tracked_property_id` (:1338). |
| CMA field extraction -> Tracked Property | `PropagateCmaToProperty::propagateToTrackedProperty` PropagateCmaToProperty.php:84-135, `matchOrCreate` :125 | facts: address, street_number, street_name (regex), suburb, erf_number (prefix "Erf"/"Stand" stripped), title_deed_number, erf_size_m2, `cma_gps_lat/cma_gps_lng`, municipal_valuation(+year), last_known_sold_price/date. Guard: bails when no suburb, street_name, erf_number and no GPS (:118). source: `['type'=>'cmainfo','ref'=>"pres_<id>",'payload'=>[presentation_id, extracted_at]]`. 16 tracked_properties carry a `pres_` ref in source_chain. |
| CMA field extraction -> Property | `PropertyCmaPropagationService::resolveTargetProperty` PropertyCmaPropagationService.php:206-245 | order: presentations.listing_id (0 rows) -> `property_presentation_snapshots` pivot (0 rows) -> `findByAddress` :278 (scorer EXACT, single POSSIBLE, else 2-token overlap with scorer veto) -> `findByErf` :339 (erf_number/property_number/stand_number + `suburb_normalised IN suburbSpellingKeys`). Stale guard on `last_cma_at`. Writes erf_number, municipal_valuation(+year), cma_gps_lat/lng, title_deed_number (buildUpdates :398-440). |
| Map pitch from a comp | MapActivityController.php:339-420 `prospectLaunched` -> `matchOrCreate` with `['type'=>'map_prospect_launch', 'ref'=>record_id]` and facts address/lat/lng/suburb only; then `resolveProspectingListingId` :517-570 inserts a **synthetic** `prospecting_listings` row (`portal_source='p24'`, `portal_ref='mrcr:N'|'pal:N'|'deal:N'`, `portal_url=''`, price 0). 0 such rows on QA1 (query on portal_ref LIKE 'mrcr:%'/'pal:%'/'deal:%' returned none). |
| Active competition for a subject | `CompetitorStockMatchService::findCompetitors` (CompetitorStockMatchService.php:95) reads `prospecting_listings` (MIC portal stock); `deduplicateSamePhysicalProperty` :231 groups by equal price + size within 10% + beds agree. Attribute-based, no address identity. HFC stock cross-check by `portal_ref` -> `listing_stocks.external_ref` (:1303-1326). | |
| Active competition display dedupe | AnalysisDataService.php:1005-1075 `compileActiveCompetition`: dedupe uploaded rows vs captured P24 pages by `external_key` / capture `listing_id`; `erfsMatch` (10% size tolerance) at :1224 | |

## Missing

- **Presentation comps (sold + active) vs `properties`: no match.** No property_id/tracked_property_id column; the deal-register comp stores `source_property_id` in JSON only.
- **Presentation comps vs `tracked_properties`: no match** (never passed to `matchOrCreate`; the comp is a snapshot copy of a `market_report_comp_rows` row which itself is un-linked, see B).
- **Presentation comps vs MIC listings (`prospecting_listings`): no identity link.** Active listings in a presentation (`mic_snapshot_v1` 793 rows) come from `market_report_comp_rows` (CMA report listings), not from `prospecting_listings`; the "Active Competition" section is a separate attribute-scored read of `prospecting_listings` (price/beds/size), so the same advert can appear once from a CMA PDF and once from MIC with no link. `presentation_active_listings` has no `portal_ref`, URL or `prospecting_listing_id`.
- `presentations.tracked_property_id` is never set (0/222), so even the subject of a presentation is not bound to the tracked-property pillar; only `property_id` (136/222) is.
- `mic_comp_row_id` columns (both tables) are dead; MapPinService works around it by joining on `raw_row_json.mic_comp_row_id` (MapPinService.php:22-24, 935-951).
- URL ingestion legacy path drops the portal listing id and the URL (UrlIngestionService.php:178-196 `persistLegacy`; `buildExternalKey` only runs in the dedupe path).
- `SuburbStockParserV1` rows (4,600 live active listings, 82% of the 5,635 live active listings) carry no suburb, no address, no price per area: `suburb` NULL in all 4,600 and raw JSON has `suburb: null`. Unmatchable by construction.
- Doc-extract comps have no GPS (0 of 815 rows) and no erf; the erf key exists in the extractor but is not populated in QA1 rows.
- `listing_dedupe_v1`, `listing_lifecycle_v1`, `listing_data_quality_v1` are all default OFF (config/features.php:12-14).
- Chrome capture search items: suburb key mismatch `location` vs `suburb` (finding 3); `agent_name`/`agent_phone` are extracted for listing pages (PortalCaptureController.php:926-935) but never passed to `matchOrCreate`; the portal URL is never passed as a fact (only `ref`).

## Row evidence (with the SQL I ran)

```
SELECT (SELECT COUNT(*) FROM presentation_sold_comps) sc_total,(SELECT COUNT(*) FROM presentation_sold_comps WHERE deleted_at IS NULL) sc_live,(SELECT COUNT(*) FROM presentation_sold_comps WHERE mic_comp_row_id IS NOT NULL) sc_micid, (same three for presentation_active_listings), (SELECT COUNT(*) FROM presentation_listing_price_history), (SELECT COUNT(*) FROM presentations)
=> sold: 4277 total / 2180 live / 0 mic_comp_row_id; active: 6988 total / 5635 live / 0 mic_comp_row_id / 0 external_key; price_history 0; presentations 222
```
Live sold comps by parser_version (`GROUP BY parser_version`): mic_snapshot_v1 1321, doc_extract_v1:street_sales 299, vicinity_sales 250, cma_comps 153, vicinity_sales_sectional 113, agent_browse 39, sales_report_v1 5.
Live active listings: suburb_stock_v1 4600 (extraction_method NULL, external_key 0), mic_snapshot_v1 793, doc_extract_v1:cma_active 242. No `deterministic_v1`/`ai_v1` (URL ingestion) rows; `source_snapshot_id NOT NULL` = 0.

Identifier coverage inside raw_row_json of live sold comps (`JSON_EXTRACT(raw_row_json,'$.address'|'$.latitude'|'$.mic_comp_row_id'|'$.erf_number'|'$.erf'|'$.deal_id')`, `JSON_VALID` all true):

| parser_version | n | has address | has GPS | has mic_comp_row_id | has erf | has deal_id |
|---|---|---|---|---|---|---|
| mic_snapshot_v1 | 1321 | 1301 | 790 | 1321 | 0 | 0 |
| agent_browse | 39 | 39 | 39 | 39 | 0 | 0 |
| doc_extract (4 variants) | 815 | 815 | 0 | 0 | 0 | 0 |
| sales_report_v1 | 5 | 0 | 0 | 0 | 0 | 0 |

Active listings (mic_snapshot_v1: 793 with 792 address, 788 real GPS via `JSON_TYPE(...)<>'NULL'`; cma_active: 242/242 address, 0 GPS; suburb_stock_v1: 4600 with no address/GPS/ref/URL).

Duplicates:
- Same sale twice inside one presentation, key = presentation_id + LOWER(TRIM(raw address)) + sold_date + sold_price_inc: 130 groups, 137 extra rows; of these 106 groups mix more than one parser_version (same sale ingested from MIC and from an uploaded doc), 24 are same-source. Across all presentations: 2,175 live rows with an address = 1,585 distinct (address,date,price) sales (expected: each presentation snapshots its own copy).
- Active listings with the same external_key: n/a (no external keys).

Pillar links: `presentations.property_id` set 136/222; `tracked_property_id` 0/222; `listing_id` 0/222; `property_presentation_snapshots` 0 rows.

Portal captures: `portal_captures` 926 (925 attached to a presentation); `portal_listings` 8,767 rows = 8,767 distinct (source_site='www.property24.com', portal_listing_id), all with canonical_url; 6,043 with `tracked_property_id` (69%), pointing at 6,043 distinct tracked_properties. Of those 6,043 TPs: street_name 1,279, suburb 3, latitude 16, erf_size_m2 3,228:
`SELECT COUNT(*) n, SUM(tp.street_name<>'') street, SUM(tp.suburb<>'') suburb, SUM(tp.latitude IS NOT NULL) gps, SUM(tp.erf_size_m2 IS NOT NULL) erfsize FROM tracked_properties tp JOIN portal_listings pl ON pl.tracked_property_id=tp.id` => 6043 / 1279 / 3 / 16 / 3228.
`tracked_property_addresses WHERE source_type='chrome_capture'`: 80 rows, 0 with suburb, 18 with street, 0 with GPS.
`presentation_url_snapshots`: 106 (p24_listing 63, p24_search 39, private_property_search 4), all 106 have `blocked_reason` NOT NULL.

---

# B. CMA report imports (bulk importer) and the older importers

## Arrives via (file:line)

1. Upload: `MarketReportController::store` (single) and `bulkImportStore` (bulk) (routes/web.php:2861-2876; .ai/specs/cma-import-freeland-investigation.md). Both go through `MarketReportIngestService::ingest` (app/Services/MarketReports/MarketReportIngestService.php:42-125): sha256 of the file; **dedupe key = UNIQUE(agency_id, file_hash)** (`uq_market_reports_agency_hash`, SHOW INDEX), including soft-deleted rows (restore + re-parse, :53-72); then `ParseMarketReportJob::dispatchSync(..., deferAi: true)`.
2. Parse: `ParseMarketReportJob::handle` (app/Jobs/MarketReports/ParseMarketReportJob.php): parser resolved by `MarketReportParserRegistry`; order known parser -> `LearnedLayoutParser` -> AI read (`AiReportReadService` + `CanonicalReadMapper::toParseResult`) -> unread. Parsers (app/Services/MarketReports/Parsers): CmaInfoPropertyValuation, SectionalTitleSales, VicinitySale, MedianSalesAnalysis, MarketAnalysis, SchemeOwnersList, Learned, GenericFallback.
3. Persistence inside one transaction (ParseMarketReportJob.php:~170-300): subject metadata back to `market_reports` (:185-205), `MarketDataPoint::create`, `MarketReportCompRow::create` (:245), `SchemeOwner::updateOrCreate` (:278). After the transaction: for each `$result->extractedAddresses` -> `TrackedPropertyMatchOrCreateService::matchOrCreate` (:304-318), source `['type'=>'cmainfo','ref'=>'report:<id>']`, facts = the address array with `sale_price/sale_date` renamed to `last_known_sold_price/last_known_sold_date` (`matcherFacts` :513-523). Then `BackfillPropertyGpsFromReportJob` when the report has subject GPS.
4. Re-parse: `MarketReportController::resetReportForReparse` (CoreX/MarketReportController.php:443) and `MarketReportReprocessor` (:49-51) soft-delete the report's comp rows and data points, then re-run the job, so comp rows are not duplicated within a report on re-parse. They ARE duplicated across different reports (below).
5. `MarketReportParseResult` (app/Services/MarketReports/DTOs/MarketReportParseResult.php) is a pure DTO; it does NOT call `matchOrCreate` itself (the class comment says the job does).

## Saved identifiers (table.column)

`market_reports`: agency_id, `file_hash` (sha256), file_name, `source_suburb`, `source_town`, `subject_address` (single varchar, street only e.g. "FOREST ROAD"), `subject_scheme_name`, `subject_section_number`, `subject_latitude/longitude`, `subject_extent_m2`, `radius_metres`, report_date, parse_status, read_method, raw_extracted_json. No erf, deed, SG, owner on the report row.

`market_report_comp_rows` (one row per sold comp / listing / subject / owner): agency_id, market_report_id, row_index, `row_type` ENUM(subject, comp, listing, owner), `scheme_name`, `section_number`, `flat_number`, `ss_number`, `ss_year`, `address` (one free-text string), `suburb_normalised`, property_type, `extent_m2`, `sale_date`, `sale_price`, `estimated_value`, `r_per_m2`, `list_price`, `days_on_market`, `municipal_valuation(+_year)`, condition, `distance_to_subject_m`, `latitude`, `longitude`, `raw_row_json`. **No erf_number, no title deed, no SG/LPI, no owner name, no street_number/street_name/suburb split, no tracked_property_id/property_id.** Indexes: (market_report_id,row_type), (suburb_normalised,sale_date), (latitude,longitude), (scheme_name).

Where the missing identifiers hide: `raw_row_json` carries `erf_number` and `erf_usage` for the vicinity-sale parser (key set seen in 629 + 111 distinct-shape rows; `JSON_CONTAINS_PATH` for erf_number/erf/stand_number/erf_no = 740 of 3,923 live rows). Title deed, SG code, LPI code and bond holder: 0 rows (`JSON_CONTAINS_PATH` over title_deed/title_deed_number/deed_number/bond_holder/sg_code/lpi_code = 0). Owner name only on the 13 `owner` rows (`scheme_owners`: owner_name, scheme_name, section_number, flat_number, scheme_ss_number, extent_m2; no GPS columns). The valuation parser builds comp rows with `buildCompRow` (CmaInfoPropertyValuationParser.php:740-768), which has no erf field.

`market_data_points.tracked_property_id` exists but 0 of 5,944 rows set (parsers never set it; ParseMarketReportJob.php just passes `$dp['tracked_property_id'] ?? null`).

`market_report_types`: key, display_name, parser_class, expected_fields_json, auto_approve (config table, no identity data).

## Address handling

- Comp rows keep the printed address as ONE string (e.g. "ANO, 6 BANK STREET, MARGATE", "FOREST ROAD") and `suburb_normalised`. `suburb_normalised` is the REPORT's suburb (via `resolveReportSuburb` AbstractCmaInfoParser.php:120-133 -> `TrackedProperty::normaliseSuburb` -> `TrackedPropertyAddress::normaliseSuburb` TrackedPropertyAddress.php:170), applied to every row unless the row carries its own (`$row['suburb_normalised'] ?? $suburbNorm`, CmaInfoPropertyValuationParser.php:751). So a 300 m vicinity report taken in Uvongo labels neighbouring-suburb comps "uvongo". A post-transaction belt-and-braces update fills blank `suburb_normalised` from `source_suburb` (ParseMarketReportJob.php:~255-268).
- The *tracked-property side* (extractedAddresses) goes through `AbstractCmaInfoParser::makeAddress` (AbstractCmaInfoParser.php:529-565): one `AddressParser::parse` call splits the printed line into street number / clean street name, then `matchOrCreate` canonicalises further (`canonicalFactsForWrite` TPMC :1851). The AI path does the same in `CanonicalReadMapper::address` (CanonicalReadMapper.php:~290).
- Note `makeAddress` is given the WHOLE address as `street_name` (e.g. `'street_name' => $row['address']` in CmaInfoVicinitySaleParser.php:~163; for sectional rows it passes `scheme_name` as the street name, CmaInfoSectionalTitleSalesParser.php:~149). The parser tries to split it; if it cannot (two numbers/streets) the raw string passes through and the save-time writer flags `review`.

## Matching today

- Report-level dedupe: exact sha256 per agency (UNIQUE index). A re-scan or re-export of the same CMA (different bytes) is a new report. No (subject address + report_date) or content-level dedupe: 437 reports, 437 distinct hashes, 196 with subject GPS.
- Row-level dedupe within the parser: valuation parser dedupes its two comp tables by `(sale_date|sale_price)` only (CmaInfoPropertyValuationParser.php:~163-171). No other parser dedupes rows.
- Row-level to pillar: only for the subset in `extractedAddresses`: the valuation parser's CMA comp table + subject address; the vicinity parser's every comp row with an address; the sectional parser's every row with address or scheme (scheme name used as the street); the AI mapper every `comp` (not `listing`) row with an address. NOT included: valuation-parser "sold with distance" rows and "active listings with distance" rows (CmaInfoPropertyValuationParser.php:~190-197), scheme-owner rows, and listings. Matching itself is `matchOrCreate` (other auditor): this source supplies `source.type='cmainfo'`, `ref='report:<id>'`, facts = street_number, street_name, suburb, town, latitude, longitude, erf_number (vicinity parser only), last_known_sold_price, last_known_sold_date.
- Report to presentation: `SubjectReportResolver` (street-needle LIKE on `subject_address`) and suburb/Haversine in the hydrator (see A). No report-to-property id.
- Map T-pin vs report comps: MapPinService folds T-pins into agency Stock by key number|street_core|suburb at read time (MapPinService.php:654-687); report comp rows are a separate layer with their own GPS. Whether comp rows are folded into TP pins: UNVERIFIED.

## Missing

- `market_report_comp_rows` has no link to any pillar (no tracked_property_id/property_id) and no erf column; the erf is only in JSON and only for the vicinity parser.
- Sold/listing rows from the valuation report's "with distance" tables and all `listing` rows never reach `matchOrCreate` (code: only `extractedAddresses` do). Sectional comps without a street address (1,596 of 3,557 comps have empty address, 45%) cannot form a TP address; scheme name is passed as the street name instead.
- Sale price/date flow into TP facts only for rows that were in `extractedAddresses`; the 3,557 comp rows are otherwise "evidence" with no pillar write-back.
- Owner (buyer/seller) names are not captured for sales rows at all; only scheme owner rolls (13 rows).
- Cross-report dedupe of the same sale is absent (see evidence).
- Older `listing_import_runs` / `listing_import_rows` (propcon xlsx): 6 runs (all `applied`, source `propcon`, Feb to Mar 2026) and **0 rows** in QA1; no code in app/ writes either table (grep `listing_import_rows|listing_import_runs`: only models, migrations and `MarketAnalytics/Adapters/ImportedListingsAdapter.php` reading). Row columns: external_id, external_ref, `property` (single string), status, price_cents, file_agent, matched_listing_stock_id (matches `listing_stocks`, not properties). Treat as legacy/dead. No erf, GPS, SG, structured address.
- The current P24 CSV importer (`p24_import_runs`/`p24_import_rows`, `P24ListingsCsvParser`, `ConfirmP24PropertyRowJob`) is a different source and likely another auditor's; for the record: it writes `properties` directly (dedupe `agency_id + p24_listing_number`, ConfirmP24PropertyRowJob.php:77-80), does NOT call `matchOrCreate`, and its mapped payload carries address, street_number, street_name, stand_number, unit_number, p24_suburb_id (P24 SuburbId), latitude, longitude, lightstone_id, source_reference (P24ListingsCsvParser.php:123-160). QA1: 39,036 listing rows (32,926 confirmed rows only 5,259 distinct ListingNumber, i.e. the same ListingNumber re-staged across runs; 4,471 pending; 1,639 error); in mapped_json 38,664 have street_name, 23,352 stand_number, 26,224 GPS, 28,518 p24_suburb_id.

## Row evidence (with the SQL I ran)

```
market_reports: SELECT COUNT(*) total, SUM(deleted_at IS NULL) live, SUM(subject_address<>'') subj_addr, SUM(subject_latitude IS NOT NULL) subj_gps, SUM(source_suburb<>'') src_suburb, SUM(source_town<>'') src_town, SUM(subject_scheme_name IS NOT NULL) scheme, COUNT(DISTINCT file_hash) FROM market_reports
=> 437 total, 436 live, subject_address 197, subject GPS 196, source_suburb 366, source_town 256, scheme 109, distinct hashes 437
```
By type (live): cma_info_property_valuation 194, sectional_title_sales 94, vicinity_sale 52, other 68 (51 parser-read as "other", 16 unread, 1 AI), median_sales_analysis 19, market_analysis 7, scheme_owners_list 1, learned_layout 1. All `parse_status=parsed`.

Comp rows (live; `GROUP BY row_type`):

| row_type | rows | has address | address starts with a number | has suburb | number + street + suburb (proxy) | GPS | scheme | section | price | date | extent |
|---|---|---|---|---|---|---|---|---|---|---|---|
| comp | 3,557 | 1,961 | 1,668 | 3,278 | 1,569 | 1,775 | 1,670 | 2,756 | 3,545 | 3,557 | 3,103 |
| subject | 194 | 194 | 155 | 192 | 155 | 194 | 107 | 113 | 152 | 151 | 113 |
| listing | 159 | 158 | 95 | 159 | 95 | 152 | 98 | 159 | 0 | 0 | 159 |
| owner | 13 | 0 | - | 13 | - | 0 | 13 | 13 | 0 | 0 | 13 |

(address-starts-with-number = `address REGEXP '^[0-9]+[A-Za-z]?[[:space:]]'`; "structured" is a proxy because there is no split column.) Total rows incl. soft-deleted 4,002 (79 deleted).

Duplicates (comp rows, live):
- By address: `GROUP BY agency_id, LOWER(TRIM(address)), suburb_normalised, sale_date, sale_price HAVING COUNT(*)>1` (address non-empty): 252 groups, 577 rows, 325 extra rows; 251 of the groups span more than one report. 1,961 addressed comps collapse to 1,629 distinct sales.
- By scheme+section: `GROUP BY agency_id, UPPER(TRIM(scheme_name)), section_number, sale_date, sale_price HAVING COUNT(*)>1`: 314 groups, 537 extra rows.

Link to pillars:
- `tracked_property_addresses WHERE source_type='cmainfo'`: 214 rows / 191 TPs; `source_ref LIKE 'report:%'`: 200 rows from 133 distinct reports; 303 parsed live reports have never fed any address row.
- `tracked_properties WHERE source_chain LIKE '%"ref": "report:%'`: 702 TPs (of 46,356 live). `... '%"ref": "pres\_%'`: 16.
- `market_data_points.tracked_property_id NOT NULL`: 0 of 5,944.
- `scheme_owners`: 13 rows, 0 GPS columns populated (no lat/lng columns are set).

Legacy importer: `SELECT COUNT(*) FROM listing_import_runs` = 6; `listing_import_rows` = 0; `p24_import_runs` = 5; `p24_import_rows` = 39,150.

---

# C. Address normalisation: inventory

Verdict: **one designed shared reader exists (`AddressParser`), plus a shared suburb normaliser (`TrackedPropertyAddress::normaliseSuburb`) and street normaliser (`TrackedPropertyAddress::normaliseStreet`), but at least 12 other divergent normalisers/keys are still live.** The spec itself admits (.ai/specs/structured-address-matching.md §14.2, "Not moved (deliberate)") that SuburbMatcher, DealPropertyLinkService and PortalInventoryGuard were left for a later pass, and `OnMarketStockService`/`ProspectingListing::normalizeAddress` keep their own semantics.

## C.1 Normaliser inventory

| # | Normaliser (file:line) | What it does | Called by |
|---|---|---|---|
| 1 | `App\Services\Address\AddressParser::parse` (app/Services/Address/AddressParser.php:48), `numberAndCore` :165 | THE reader. Pure text to street_number, street_core, street_type, street_name_clean/typed, unit, section, complex, scheme, erf, erf_portion, township, lpi_code, status (parsed/review/unparseable). Cuts "Cadastral Extent ... M2" tails, ordinals, abbreviations, Afrikaans types (StreetTypes app/Support/Address/StreetTypes.php). Two numbers/streets = `review`, never a pick. | AddressStructurer, AddressFacts, `makeAddress` (CMA parsers AbstractCmaInfoParser.php:539), CanonicalReadMapper.php:296, MapPinService.php:97,657, Reos rent-roll take-on, BackfillStructuredAddresses |
| 2 | `AddressStructurer::structure/apply` (AddressStructurer.php:48,169) | Writes derived columns: street_core, street_type, p24_suburb_id/p24_city_id, scheme_number, erf_portion, township, lpi_code, address_raw, address_parse_status/_note; fills only EMPTY existing columns | `Property::saving` (Property.php:1337), `TrackedProperty::creating/updating` (TrackedProperty.php:113,120), `TrackedPropertyAddress::creating` (TrackedPropertyAddress.php:89), `TPMC::canonicalFactsForWrite` (:1851), AddressReviewController, backfill command |
| 3 | `AddressMatcher::properties/trackedProperties` (AddressMatcher.php:50,59) + `AddressMatchScorer::score` (AddressMatchScorer.php:41) + `AddressFacts::fromPayload` (AddressFacts.php:103) | Scored comparison: tiers EXACT / POSSIBLE / STREET_ONLY / none with vetoes (number, unit, erf, portion, LPI, non-neighbour suburb). Weights lpi/erf 40, scheme 25, unit 15, suburb 15/7 neighbour, street 15, number 15, type 3, gps 5 (spec §6.4) | TPMC (:859,884,1369,1420,2034,2089,2103), PropertyCmaPropagationService.php:286, MicPropertyReconciliationService.php:64, ProspectingStockMatchService.php:95, PropertyDuplicateMatchEvidence.php:120, DeedsCaptureLinkService.php:292, ContactAddressPropertyGuard.php:213 |
| 4 | `TrackedPropertyAddress::normaliseSuburb` (TrackedPropertyAddress.php:170) | lower, apostrophes deleted, punctuation to space, whitespace collapsed, then `canonicaliseSuburbAlias` (:274: config/property-suburb-aliases.php groups + `suburb_aliases` table). Does NOT merge Uvongo/Uvongo Beach. | `TrackedProperty::normaliseSuburb` (delegates, TrackedProperty.php:131), CMA parsers (AbstractCmaInfoParser.php:82-84), ParseMarketReportJob.php:~257, CanonicalReadMapper, SuburbResolver, suburbSpellingKeys, PropertyAddressKey, MapPinService, TPMC |
| 5 | `TrackedPropertyAddress::normaliseStreet` (:321) | older street normaliser: strips leading Unit/Flat prefix, apostrophes, ordinals, expands St/Rd/Ave/Dr/Close/Cres/Blvd (end-anchored), ucwords | `Property::saving` -> `street_name_normalised` (Property.php:1343), TPMC.streetParts, PropertyAddressKey, streetNamesAreCloseTypo |
| 6 | `TrackedPropertyAddress::suburbSpellingKeys` (:197) / `neighbouringSuburbKeys` (:240) | spelling twins (St/Saint, alias groups) vs Property24 `surrounding_ids` neighbours | PropertyCmaPropagationService.php:339, scorer, SuburbResolver.variants |
| 7 | `TPMC::streetParts/streetCores/streetNumberSet` (TrackedPropertyMatchOrCreateService.php:1232,1307,1200) | private street/number readers used by the legacy strategies (0,1,2,5 and `numbersConflict`) that were NOT moved to the scorer | TPMC internal (other auditor's file) |
| 8 | `App\Support\Presentations\SuburbMatcher::normaliseSuburbToken/matches` (SuburbMatcher.php:76,114) | strips trailing beach/bay/park/heights/estate/extension/ext/north/south/east/west/central/village; matches if equal after strip or whitespace-bounded subset | MicSnapshotHydrator (:705, deal comps), CompRowGeocoder, CompetitorStockMatchService, CmaCoverageService |
| 9 | `CompFingerprint::normaliseAddress` (CompFingerprint.php:75) | lower + take text before first comma | MicSnapshotHydrator.php:438, CmaCoverageService |
| 10 | `MicSnapshotHydrator::fingerprint` (:886) | `lower(trim(address))|date|price` or scheme/section | MicSnapshotHydrator::deduplicate |
| 11 | `SubjectReportResolver::extractAddressNeedles` (:46) | comma pieces >= 8 chars, plus piece minus leading number | MicSnapshotHydrator, CmaCoverageService |
| 12 | `CompPoolBuilder::normaliseAddr` (:525) | lower, non [a-z0-9] to space | subject self-exclusion |
| 13 | `PropertyCmaPropagationService::normaliseAddress/extractStreetTokens` (:373,385) | lower, `[^\w\s]` to space, tokens >= 3 alpha chars | its Pass-2 token overlap (after scorer veto) |
| 14 | `ProspectingListing::normalizeAddress` (ProspectingListing.php:462) | lower, strips non [a-z0-9\s], appends suburb; also `parseStreetNumber` :494 (last comma segment) | stored in `prospecting_listings.normalized_address` (ProspectingApiController.php:~498), OnMarketStockService, ProspectingStockMatchService.php:52,373, OtherAgentStock, ProspectingListingStateEnricher |
| 15 | `App\Support\Geocoding\AddressNormaliser::normalise/parse` (AddressNormaliser.php:63,166) | geocode cache key: lower, strips Ss/Section/Unit prefixes, punctuation to space, appends suburb/town; no type canonicalisation by design | AddressResolverService, GeocodeCache, PropertyGeoBackfillService, DealPropertyLinkService.php:297,320, LocationGrouper, PropertyAddressKey |
| 16 | `App\Support\Geocoding\PropertyAddressKey` (PropertyAddressKey.php) | composite key `number|street_name_normalised|suburb_normalised|unit` built from #5, #4 and #15 | Property model save hook, LocationGrouper |
| 17 | `App\Support\Geocoding\StreetAddressMatch::normalise/matches` (StreetAddressMatch.php:67,49) | lower, non-alnum to space; number+street regex against stored address | AddressResolverService (report subject lookup), GeocodingBackfillCommand |
| 18 | `Contact::normalizePropertyStreet` (Contact.php:1261) | lower `"number street_name"` | Contact company-property display dedupe |
| 19 | `MarketAnalytics\Support\DealListingMatcher::normalizeAddress` (:225) | token set, lower, non-alnum, sorted unique, Jaccard overlap | deal vs listing matching in MarketAnalytics |
| 20 | `DealPropertyLinkService::scorePair` (:368) over `AddressNormaliser` | fuzzy score deal address vs property | deal-to-property link |
| 21 | `PortalInventoryGuard::normaliseListing` (PortalInventoryGuard.php:344) | PP/P24 portal payload fields only, no text normalising | syndication guard |
| 22 | `ListingNormalizationService::buildFingerprint` (:73) | no address at all: suburb, type, beds, baths, size, price bucket | presentation_active_listings |
| 23 | `PpSuburb::normalise` (PpSuburb.php:17) / `TownSuburb::normaliseSuburb` (TownSuburb.php:30) | PP suburb: strip all non [a-z0-9]; agency town mapping: lower-trim only | PP location resolver; ProspectingConfigurationService (agency town to suburb mapping) |
| 24 | Chrome extension `splitStreetAddress` (public/chrome-extension/portal-capture/content-cmainfo.js:1544) | client-side street number split of the CMA/deeds address | extension (sends parsed street_number/street_name; the server re-parses via #1) |
| 25 | `PortalCaptureController` and `PropagateCmaToProperty` regex `/^(\d+\w*)\s+(.+)$/` (PortalCaptureController.php:1302; PropagateCmaToProperty::fieldsToFacts) | naive number/street split, no complex/unit handling; TPMC's `canonicalFactsForWrite` then re-parses | capture and CMA to TP facts |
| 26 | `PropertyAddressReconciler::cleanStreetPiece` (PropertyAddressReconciler.php:278) | display composer: never says the street number twice | Property/TrackedProperty display (`streetLine`) |

Other services in the brief that are NOT address normalisers: `PropertyMatchScoringService` (buyer wishlist scoring: price/area/type/beds, app/Services/PropertyMatchScoringService.php:1-40), `SuburbReconcileService` (retires listings of a complete suburb capture by exact `suburb` string and portal_ref, app/Services/Prospecting/SuburbReconcileService.php:1-50), `Services/Matching/*` (buyer-to-property matching).

## C.2 Structured address columns per table

| Table | street no | street name | street core/type | unit / complex / scheme | suburb | town / postal | suburb id | erf / deed / SG | GPS | raw/audit |
|---|---|---|---|---|---|---|---|---|---|---|
| `tracked_properties` | street_number | street_name | street_core, street_type | unit_number, complex_name, scheme_name, scheme_number | suburb, suburb_normalised | town, province, postal_code | p24_suburb_id, p24_city_id | erf_number, erf_portion, title_deed_number, township, lpi_code, cadastral_extent | latitude, longitude, cma_gps_lat/lng | address_raw, address_parse_status/_note |
| `tracked_property_addresses` | street_number | street_name | street_core, street_type | unit_number, complex_name | suburb, suburb_normalised | town, province, postal_code | p24_suburb_id | none (erf lives on the TP) | latitude, longitude | address_raw, source_type, source_ref, confidence |
| `properties` | street_number | street_name, street_name_normalised, address | street_core, street_type | unit_number, complex_name, scheme_number | suburb, suburb_normalised | city, town | p24_suburb_id, p24_city_id, pp_suburb_id | erf_number, stand_number, erf_portion, title_deed_number, township, lpi_code, sg_province, sg_farm_name, p24_ref, pp_ref, source_reference | latitude, longitude, cma_gps_lat/lng | address_raw, address_parse_status/_note |
| `prospecting_listings` | none | `address` (single string) | none | none | suburb | none | none | `portal_ref`, `portal_url` (these ARE the portal ids), erf_size_m2 only | latitude, longitude | normalized_address, tracked_property_id, matched_property_id |
| `p24_listings` (legacy P24 stock table) | none | none | none | none | suburb, area | none | none | `p24_listing_number` (UNIQUE), `p24_url` | none | none |
| `portal_listings` | none | none | none | none | none | none | none | `portal_listing_id` + `source_site` (UNIQUE), `canonical_url` | none | current_fields_json (price/beds/baths/size/erf_m2/title), tracked_property_id |
| `presentation_sold_comps` / `presentation_active_listings` | none | none (raw JSON only) | none | none (scheme_name/section_number in JSON) | suburb (text) | none | none | `external_key` (active only, empty) | none (JSON only) | raw_row_json, mic_comp_row_id (dead) |
| `market_report_comp_rows` | none | `address` (single string) | none | scheme_name, section_number, flat_number, ss_number, ss_year | suburb_normalised | none | none | none (erf in raw JSON only) | latitude, longitude | raw_row_json |
| `market_reports` | none | subject_address (single string) | none | subject_scheme_name, subject_section_number | source_suburb | source_town | none | none | subject_latitude/longitude | file_hash |
| `presentations` | none | property_address (single string) | none | none | suburb | none | none | property_id, tracked_property_id (unused), erf_size_m2 | none | none |

Cross-check with the DB: `SELECT table_name, column_name FROM information_schema.columns WHERE ... REGEXP 'suburb|street|address|erf|...'` for the ten tables above (column lists reproduced from that output).

## C.3 Evidence of how well the structured layer is populated on QA1

```
SELECT COUNT(*) tot, SUM(street_name<>'') has_street, SUM(street_number<>'') has_no, SUM(street_name<>'' AND street_number<>'' AND suburb<>'') num_street_suburb, SUM(erf_number<>'') erf, SUM(latitude IS NOT NULL) gps, SUM(title_deed_number<>'') deed, SUM(lpi_code IS NOT NULL) lpi FROM tracked_properties WHERE deleted_at IS NULL
=> 46,356 | 10,177 | 9,552 | 8,224 | 1,268 | 37,080 | 1,929 | 6
same for properties WHERE deleted_at IS NULL (+ p24_ref)
=> 9,920 | 9,590 | 3,904 | 3,890 | 740 | 8,990 | 1,596 | 0 (p24_ref 8,239)
```
`address_parse_status` on tracked_properties: NULL 46,071, parsed 262, review 23. On properties: NULL 9,793, parsed 116, review 10, unparseable 1. `street_core` set on 284 / 124 rows; `p24_suburb_id` set on 255 of 46,356 tracked_properties (9,623 of 9,920 properties) and 154 of 12,437 `tracked_property_addresses`. Despite the spec (§14 step 7) saying the backfill "landed and run on QA1", the DB shows it has not covered the bulk of rows (10,177 TPs have a street name but only 284 have `street_core`). I could not tell from code/DB whether the run was partial or later reverted: UNVERIFIED. Only `tracked_properties.street_number` is a real column for 9,552 of 10,177 street rows.
`prospecting_listings` (45,440 live): all have a suburb, `normalized_address` set on 14,216, `tracked_property_id` on 42,943, `matched_property_id` on 1,072.

---

# D. Suburb / town reference data

## Master lists (QA1 row counts)

| Table | Rows | Role |
|---|---|---|
| `p24_suburbs` | 26,865 | the master suburb list (Property24): name, slug, p24_id (external), p24_city_id, lat/lng centroid, `surrounding_ids` JSON (neighbours), confirmed. Lookup: `P24Suburb::lookup` (app/Models/P24Suburb.php:72): name or slug match; if several, narrow by province, then nearest centroid <= 25 km, else null (never guesses). |
| `pp_suburbs` | 20,712 | PrivatePropertyFeed master (pp_suburb_id, normalised_name); normaliser strips all non [a-z0-9] (PpSuburb.php:17) |
| `suburb_aliases` | 2 | evidence-seeded aliases only: "leisure bay" canonical, "three hills" -> leisure bay (SELECT * FROM suburb_aliases); also config/property-suburb-aliases.php. Global, no agency_id; seeded by migration, travels via `deploy:sync-reference-data`. |
| `towns` / `town_suburbs` | 41 / 16 | PER-AGENCY town and suburb mapping used by Prospecting (ProspectingConfigurationService.php:61,305,376); `town_suburbs.suburb_normalised = strtolower(trim())` (TownSuburb.php:30) |
| `suburb_municipalities` | 112 | p24_suburb_id to municipality (confidence confirmed/needs_review) |
| `region_aliases` | 212 | per-agency municipality alias labels |

## How sources store suburb

Only these tables carry a suburb id: `properties` (p24_suburb_id, pp_suburb_id, p24_city_id, p24_suburb_mismatch), `tracked_properties` (p24_suburb_id), `tracked_property_addresses` (p24_suburb_id), plus contacts, contact wishlists, RTS areas, minion capture runs, `suburb_aliases`, `suburb_municipalities`, `suburb_reports`. **Everything else stores free text only:** `prospecting_listings.suburb`, `market_report_comp_rows.suburb_normalised`, `market_reports.source_suburb`, `presentation_sold_comps.suburb`, `presentation_active_listings.suburb`, `presentations.suburb`, `p24_listings.suburb`, `portal_listings` (none at all), `scheme_owners` (none).

Id coverage on QA1: properties 9,623 / 9,920 (97%), tracked_properties 255 / 46,356 (0.6%), tracked_property_addresses 154 / 12,437 (1.2%).

## How canonicalised

1. `TrackedPropertyAddress::normaliseSuburb` (lower, punctuation out, apostrophes deleted, then alias lookup) is the text key for `suburb_normalised` on tracked_properties/addresses/properties and the CMA comp rows.
2. `SuburbResolver::resolve` (SuburbResolver.php:50) maps text to a `p24_suburbs.id` via exact, normalised, then every alias/twin spelling (St/Saint); unresolved = `review`.
3. Uvongo vs Uvongo Beach: they are two distinct `p24_suburbs` (p24_id 6359 "Uvongo" and 33106 "Uvongo Beach", each other's `surrounding_ids`, both confirmed). The scorer treats them as NEIGHBOURS (POSSIBLE match, agent confirms, never auto-linked; spec §2 decision 1). `SuburbMatcher` (presentations) treats them as the SAME ("Beach" stripped). `OnMarketStockService`/`ProspectingListing::normalizeAddress` keep them distinct. So three different rules for the same pair coexist.
4. Evidence of the split in live data: `suburb_normalised` in `market_report_comp_rows`: uvongo 720, uvongo beach 325; in `tracked_properties`: uvongo 1,608, uvongo beach 577; `presentation_sold_comps.suburb`: UVONGO 310 plus 87 "uvongo beach" plus 15 "UVONGO BEACH (Unit N)"; `presentation_active_listings.suburb`: UVONGO 82.
5. Cardinality of free-text suburbs: 419 distinct `suburb_normalised` among live tracked_properties, 52 among live comp rows, 140 distinct raw suburb strings among live presentation sold comps (many are the "(Unit N)" pollution).
6. The CMA report parsers apply the report's own suburb to all its rows (A/B above), so a comp's suburb is the report suburb, not the comp's own.

## Gaps

- No master-suburb FK on any comp/report/portal/presentation table; the only join is text.
- `suburb_aliases` contains one pair (Leisure Bay / Three Hills); no other Johan-known aliases are loaded.
- The suburb master is KZN-biased in the seeded alias data only; `p24_suburbs` is national (26,865 rows), so a Cape Town agency's suburbs resolve through the same table, but `SuburbMatcher::TRAILING_SUFFIXES` (SuburbMatcher.php:~40-55) is described in its own comment as "curated specifically for the KZN South Coast portfolio" (a hardcoded, non-per-agency list).

---

# Notes on method and limits

- No tests were run, no artisan/composer/npm was run, nothing was edited in /corex-qa1 or any repo.
- All DB access was through the read-only helper (SELECT/SHOW/DESCRIBE only; one query was rejected because it contained the word CREATE, so schema came from DESCRIBE / information_schema).
- Not verified: whether MapPinService folds `market_report_comp_rows` into tracked-property pins; why `address:backfill-structured` coverage on QA1 is low; whether any writer other than `PortalCaptureController` sets `portal_listings.tracked_property_id`; whether any code writes `listing_import_*` outside app/ (grep of app/, routes/, resources/ found none).
- The central matcher `TrackedPropertyMatchOrCreateService` internals (strategies, confidences) were deliberately not audited here; only the facts/source arrays this audit's sources pass into it.
