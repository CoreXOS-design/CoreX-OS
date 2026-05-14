# Market Intelligence — Data Sources Discovery Audit

**Date:** 2026-05-14
**Author:** VS Code Claude (read-only investigation)
**Recipient:** Johan Reichel
**Status:** Foundation document — NOT a spec. Spec follows after this is reviewed.
**Scope:** Read-only inspection of the CoreX OS codebase. No code changes. No data writes outside this file.

---

## Executive Summary

CoreX already has substantially more Market Intelligence foundation than would be apparent from the sidebar. **The OCR pipeline exists** — `pdftotext` + Smalot in [TextExtractionService.php](app/Domain/Presentation/TextExtractionService.php) — and it already extracts subject-property **erf, GPS coordinates, address, municipal valuation, and CMA price bands** from CMAInfo Vicinity/CMA/Suburb reports. **898 P24 + 85 PP listings already sit in [prospecting_listings](database/migrations/2026_03_18_100000_create_prospecting_tables.php)** with a working cross-portal deduplication mechanism (`normalized_address` + `property_group_id`). A **Chrome Portal Capture extension already ships full HTML + screenshots + structured fields** into `portal_captures` and `portal_listings`. The agent claim system, P24 IMAP email parser, PP SOAP outbound, and `external_id` traceability on `properties` are all live.

The biggest gap is not extraction — it is **linkage**. CMA data is locked inside presentations with no Property backlink. Vicinity/Suburb comp rows store `raw_row_json` but never parse out per-comp erf or GPS. The `properties` table has no `erf_number`, no `title_deed_number`, no `municipal_valuation` column, and no `street_name_normalised`. No reverse/forward geocoder exists, and no map UI is live (only stubbed in [evaluation/index.blade.php](resources/views/evaluation/index.blade.php)).

**The smallest demonstrable demo before Wednesday** is a **Property Intelligence Drawer**: a single page keyed by `properties.id` that aggregates every fragment CoreX already knows about that property — CMAInfo-extracted fields from any matched presentation, all prospecting_listings linked via `matched_property_id`, all contact_property owners/tenants, all buyer wishlists scoring it, all portal_captures referencing it, all syndication status, and a Leaflet pin at `latitude`/`longitude` if populated. The data is already in the database, in pieces. What's missing is the join.

**Is this feasible before Wednesday?** Yes — for the demo drawer. No — for the full Market Intelligence vision, which is a multi-week build.

---

## Table of Contents

1. [CMAInfo Integration](#section-1--cmainfo-integration)
2. [VirtualAgent Integration](#section-2--virtualagent-integration)
3. [P24 Email Alerts](#section-3--p24-email-alerts)
4. [Private Property (PP) Integration](#section-4--private-property-pp-integration)
5. [Chrome Portal Capture Extension](#section-5--chrome-portal-capture-extension)
6. [HFC's Own Stock (Agency Properties)](#section-6--hfcs-own-stock-agency-properties)
7. [Buyer Wishlist Intelligence](#section-7--buyer-wishlist-intelligence)
8. [Presentation System (the OCR goldmine)](#section-8--presentation-system-the-ocr-goldmine)
9. [Property Identity Across Sources — Matrix](#section-9--property-identity-across-sources)
10. [Existing Geospatial Capability](#section-10--existing-geospatial-capability)
11. [Address Normalisation](#section-11--address-normalisation)
12. [Storage Considerations](#section-12--storage-considerations)
13. [Gaps, Risks & Quick Wins](#section-13--gaps-risks--quick-wins)
14. [What I Would Build Next](#what-i-would-build-next)
15. [Reusability Assessment](#reusability-assessment)

---

## Section 1 — CMAInfo Integration

### 1.1 PDF ingestion path

**Upload endpoint:** `POST /presentations/{presentation}/upload` — [PresentationController.php:601-626](app/Http/Controllers/Presentation/PresentationController.php#L601-L626).

Mechanism:
- Web form upload from inside a presentation.
- Validates PDF, max 20 MB.
- `doc_type` is one of: `auto | suburb_stats | vicinity_sales | cma | market_article | other`.
- Handed to `UploadProcessor::process()` (line 613) → stores file deterministically at `presentations/{presentation.id}/{file_slug}` → creates `PresentationUpload` row → synchronously runs extraction.

**No email-attachment ingester exists for CMAInfo PDFs.** `webklex/php-imap` is in composer.json but is only used by [P24ImapImportService.php](app/Services/P24/P24ImapImportService.php) for the P24 alert mailbox. No scheduled folder polling.

### 1.2 OCR / PDF parsing

**Libraries in use:**
- `smalot/pdfparser` v2.12 ([composer.json:19](composer.json#L19)) — pure-PHP PDF text extraction.
- `pdftotext` CLI from poppler-utils — shelled via `shell_exec` when present (faster + cleaner output).

**Primary extractor:** [TextExtractionService.php:20-46](app/Domain/Presentation/TextExtractionService.php#L20-L46) — tries `pdftotext` first, falls back to Smalot, never throws. Failure → `extraction_status='failed'` on the upload row.

**Field-level extractor:** [DocumentExtractor.php:67-403](app/Support/Presentation/DocumentExtractor.php#L67-L403) — feature-flagged via `presentation_doc_extract_v1`. Runs regex extraction over the extracted text and writes structured rows to `presentation_fields`.

### 1.3 What fields are extracted from CMAInfo PDFs

**CMA reports** ([DocumentExtractor.php:115-195](app/Support/Presentation/DocumentExtractor.php#L115-L195)):
- `cma.lower_range`, `cma.middle_range`, `cma.upper_range`
- `municipal.total_value` (e.g. "Total Value: R1,800,000")
- `municipal.valuation_year`
- `subject.address`
- `subject.suburb`
- **`subject.gps`** — extracted as "30.384764°E 30.838421°S" (line 153)
- **`subject.erf`** — extracted as "Erf 180" or "Stand 5" (line 158)
- `subject.extent_m2`
- `subject.purchase_date`, `subject.purchase_price`, `subject.indexed_value`, `subject.cagr`

**Suburb sales reports** (lines 199-296): `suburb.latest_year`, `suburb.latest_sales_count`, `suburb.latest_median_price`, `suburb.latest_low`, `suburb.latest_high`, `suburb.latest_max`.

**Vicinity sales reports** (lines 300-403): `vicinity.property_type`, `vicinity.lower_range`, `vicinity.middle_range`, `vicinity.upper_range`, `vicinity.average_price`, `vicinity.avg_price_per_m2`, `vicinity.comps_count`.

**The critical limitation:** GPS and erf are only extracted for **the subject property** — never for the individual comparables inside Vicinity/Suburb tables. Per-comp rows store the original row text in `raw_row_json` but only parse out price/date/type/beds/baths/size. Erf and GPS per comp would require extending the parsers — the source data is in the PDF text, just not pulled out today.

### 1.4 Storage of parsed CMAInfo data

| Table | Migration | Purpose | agency_id? | Permanent? |
|-------|-----------|---------|------------|------------|
| `presentation_uploads` | 2026_02_20_200003 | original PDF + `text_extracted` (longText) + `extraction_json` | inherits via presentation | yes, soft-deleted |
| `presentation_sold_comps` | 2026_02_20_500001 | comp rows from Vicinity reports | inherits | yes, soft-deleted |
| `presentation_active_listings` | 2026_02_20_500002 | active listings from Suburb Stock | inherits | yes, soft-deleted |
| `presentation_fields` | 2026_02_20_200004 | structured `subject.*`, `vicinity.*`, `suburb.*`, `cma.*` fields | inherits | yes, soft-deleted |
| `presentations` | 2026_02_20_200000 | parent record | **no agency_id — branch_id only** | yes |

**Multi-tenancy gap:** [presentations](database/migrations/2026_02_20_200000_create_presentations_table.php) has `branch_id` but no `agency_id`. AgencyScope is not enforced. Access control is in `PresentationController::authorizePresentation()` lines 50-68 — branch-based, not agency-based.

### 1.5 Comparable properties — discrete or text?

**Discrete rows in `presentation_sold_comps` and `presentation_active_listings`**, with `presentation_id` + `source_upload_id` FKs. Schema:

```
sold_date, sold_price_inc, suburb, property_type, beds, baths, size_m2, listed_date, raw_row_json
```

**Identifier per comp:** suburb + property_type + beds/baths/size — no erf, no GPS, no address. Cross-presentation deduplication is impossible today; the same comp appearing in two presentations would create two unlinked rows.

### 1.6 Historical sale data

CMAInfo Vicinity reports contain sales going back to the 1990s. **The current parser ingests every sold comp** — `SalesReportParserV1` does not filter by date. So if a Vicinity report shows 15 sales from 1996-2025, all 15 land in `presentation_sold_comps`. But because they're scoped to one presentation and not joined back to a Property, the historical depth is locked inside that presentation's data island.

---

## Section 2 — VirtualAgent Integration

**Status:** DORMANT. Reference-only.

**Only occurrence in codebase:** [HfcRmcpMasterSeeder.php:151](database/seeders/HfcRmcpMasterSeeder.php#L151):
```html
<li><strong>"TVA"</strong> means The Virtual Agent (thevirtualagent.co.za).</li>
```

That seeder is the RMCP (Risk Management and Compliance Programme) — TVA is named as a definition in Schedule 1 alongside Lightstone and CMA Info as data verification tools. **Zero active code path.**

No table:
- No `virtual_agent_*` or `*_lookups` table in migrations.
- No SOAP/HTTP client class.
- No controller, route, job, or service referencing TVA programmatically.

**How it's used today:** Manually. Agents log into thevirtualagent.co.za, run a lookup against deeds office data, eyeball the result, and (sometimes) save what they learn directly onto a `contacts` row or in `notes`. Nothing is cached. Nothing is rate-limited. Nothing is auditable.

**Implication for Market Intelligence:** TVA is the highest-yield missing integration. The data it returns (owner phone/email keyed by property address) is exactly what fills the gap between an unmatched P24 alert and an actionable outbound contact. **No reusable infrastructure exists — this would be a green-field integration.**

---

## Section 3 — P24 Email Alerts

**Status:** ACTIVE and working.

### 3.1 Ingestion path

| Component | File |
|-----------|------|
| Config | [config/services.php](config/services.php) — `p24_imap` block, env vars `P24_IMAP_HOST/USERNAME/PASSWORD/FOLDER`, `P24_IMPORT_ENABLED` |
| Email parser | [P24EmailParserService.php](app/Services/P24/P24EmailParserService.php) |
| IMAP importer | [P24ImapImportService.php](app/Services/P24/P24ImapImportService.php) |
| Console command | [ImportP24Alerts.php](app/Console/Commands/ImportP24Alerts.php) |
| Job | [ImportP24AlertsJob.php](app/Jobs/ImportP24AlertsJob.php) |

Flow: console command (or job) → IMAP fetch → `P24EmailParserService` extracts listings from email body via regex (price `R\s*([\d\s,]+)`, type `\d+\s*Bedroom\s+(House|Apartment|...)`, listing number `P24-\d+`) → multi-listing emails split on `listingNumber=` position with 2000-char context windows → ingested into `p24_listings` (legacy table) **and/or** `prospecting_listings` (current target).

### 3.2 Address handling

P24 emails typically include a suburb but **hide the full street address** until a buyer enquires. So `prospecting_listings.address` is often `'Address not available'` or empty — `prospecting_listings.suburb` is usually all the system has.

### 3.3 P24 listing reference

`prospecting_listings.portal_ref` stores the P24 listing number (`portal_source='p24'`). Unique with `(agency_id, portal_source, portal_ref)`. **This is the primary cross-reference key for P24 data.**

### 3.4 Linkage to `properties`

`prospecting_listings.matched_property_id` (FK → properties.id, nullable) — added in migration `2026_05_12_105607`.

**Population today:** Mostly NULL. Matching happens in [ProspectingStockMatchService.php:15-106](app/Services/Prospecting/ProspectingStockMatchService.php#L15-L106) via 2-pass strategy (exact normalized → fuzzy suburb+street overlap). It's invoked from the seller-outreach module (recent build) when an agent promotes a prospect to a Property, but is NOT run automatically on every ingestion.

**No other linkage paths exist.** No portal_listings ↔ properties pivot. No property_external_refs table.

---

## Section 4 — Private Property (PP) Integration

**Status:** Outbound syndication complete. Inbound listing-discovery NOT implemented.

### 4.1 SOAP client

[PrivatePropertySoapClient.php](app/Services/PrivateProperty/PrivatePropertySoapClient.php) — 16+ SOAP methods. Config in `config/services.php`: `PP_USERNAME`, `PP_PASSWORD`, `PP_BRANCH_GUID`, `PP_WSDL` (default sandbox `https://services.sandbox.pp.co.za/AgentImport/AgentImport.asmx?WSDL`), `PP_SANDBOX`, `PP_WEBHOOK_SECRET`.

| Direction | Methods |
|-----------|---------|
| OUT (push) | UpdateAgent, UpdateListing, ListingStatusUpdate, UpdateUniqueAgentID, UpdateUniqueListingID, ListingShowdayUpdate, UpdateListingVideoOrMatterport, UpdateAgentImage |
| QUERY (pull state) | GetListingStatus, GetReferenceNumberByListing, GetActiveListings, GetAllAgentsForBranch, GetAgent, GetBranchDetails |
| EVENT FEED | GetListingEventFeed — polled by [ProcessPrivatePropertyEventFeed.php:23-58](app/Jobs/ProcessPrivatePropertyEventFeed.php#L23-L58) |

### 4.2 The event feed pulls back ONLY events for HFC's own syndicated stock

Event types handled: Activated, Deactivated, ErrorDownloadingImages, ImagesDownloading, ImagesDownloaded. Used to update `pp_syndication_status` and related columns on `properties`. **It does not discover external listings.**

### 4.3 PP listings in `prospecting_listings`

The 85 PP rows in `prospecting_listings` come from the **Chrome Portal Capture extension** scraping portal.pp.co.za listing pages — not from the SOAP API. See Section 5.

### 4.4 Could the SOAP API return external listings?

`GetActiveListings` exists in the WSDL and the SOAP client wraps it ([PrivatePropertySoapClient.php](app/Services/PrivateProperty/PrivatePropertySoapClient.php) lines 107-314), but **no code today calls it for market-intelligence purposes**. Calling it would require investigation of: (a) does it return only the calling branch's listings or all PP listings, (b) what fields are returned vs the public listing page, (c) does it expose owner contact data.

---

## Section 5 — Chrome Portal Capture Extension

**Status:** ACTIVE — most complete ingestion path in the system.

### 5.1 Backend endpoints

All in [routes/web.php](routes/web.php):

| Route | Handler |
|-------|---------|
| `POST /portal-captures/ingest` | [PortalCaptureController::ingest](app/Http/Controllers/Presentation/PortalCaptureController.php) line 2493 |
| `GET /presentations/{id}/portal-captures` | `::index` line 2045 |
| `POST /presentations/{id}/portal-captures/reclassify` | `::reclassify` line 2047 |
| `POST /presentations/{id}/portal-captures/{id}/attach` | `::attach` line 2049 |
| `DELETE /presentations/{id}/portal-captures/{id}` | `::destroy` line 2051 |
| `GET /presentations/{id}/live-snapshot` | `::liveSnapshot` line 2055 |

Auth middleware: `auth.portal_capture` (custom).

### 5.2 What the extension captures per page

From [PortalCaptureController.php:18-209](app/Http/Controllers/Presentation/PortalCaptureController.php#L18-L209) — validation block:
- `source_site` (string), `page_type` (search|property|unknown)
- `source_url`, `final_url`, `page_title`, `captured_at`, `extractor_version`
- **`html`** — full raw HTML, stored at `storage/portal_captures/{capture_id}.html`
- **`screenshot`** — base64 PNG, stored at `storage/portal_captures/{capture_id}.png`
- `extracted_fields` — extension-side parsed fields
- `jsonld` — structured data scraped from the page
- `found_image_urls` — list of every `<img>` on the page

### 5.3 Server-side extraction

The server re-classifies and re-extracts independently of the extension's guess:
- Search pages → `Property24SearchExtractorV1` (extracts listing cards)
- Property pages → `extractProperty24ListingFields()` using DOMDocument/XPath (lines 820-956)

**Fields extracted from a P24 listing page:**
```
listing_id, price, title, suburb, property_type, bedrooms, bathrooms,
floor_m2, erf_m2, agent_name, agent_phone, image, url
```

Extraction strategy is layered: `og:price` meta → JSON-LD → DOM class → regex fallback for price; `icon_bed`/`icon_bath`/`icon_floor`/`icon_erf` patterns for counts and sizes; `href="tel:"` for agent phone; `og:image` → JSON-LD → gallery → first large `<img>` for images.

### 5.4 Where the data lands

| Table | Purpose |
|-------|---------|
| `portal_captures` | per-capture raw HTML + screenshot + extracted fields |
| `portal_listings` | dedup'd listing identity (one row per `portal_listing_id`) — `first_seen_at`, `last_seen_at`, `current_fields_json` |
| `portal_listing_observations` | historical changes (price, availability) per portal_listing over time |
| `prospecting_listings` | the "active prospecting pipeline" view — also updated by extension ingestion |

### 5.5 Cross-portal deduplication

[ProspectingListingResolver.php](app/Services/Prospecting/ProspectingListingResolver.php) and [ProspectingApiController.php:177-207](app/Http/Controllers/Prospecting/ProspectingApiController.php#L177-L207).

Mechanism:
- `prospecting_listings.normalized_address` (lowercase, strip punctuation, append suburb — via `ProspectingListing::normalizeAddress()` lines 91-108).
- When a new capture arrives, query for the same `normalized_address` with a different `portal_source` → assign the same `property_group_id` → "this is the same property listed on both P24 and PP".
- No photo hashing, no owner matching, no GPS distance — purely string-based on the normalized address.

### 5.6 The agent claim system

[`prospecting_claims`](database/migrations/2026_03_18_140000_create_prospecting_claims_table.php):

```
agency_id, prospecting_listing_id, user_id, status, notes,
claimed_at, feedback_at, last_updated_at, released_at, flagged_at, is_active
```

Status values: `claimed | contacted | meeting_set | listing | lost`.

Workflow (from seller-outreach module, recent build):
1. Agent claims a prospecting listing — creates ProspectingClaim row.
2. Status moves as agent works the lead.
3. BM gets flagged if claim is stale (>48h no feedback) or sits in `contacted` for >7d.

**Tied directly to mandate detection:** if a prospecting listing's status changes upstream (e.g. P24 listing goes inactive) while an agent's claim is `meeting_set` or beyond, that's a strong signal a competitor won the mandate.

---

## Section 6 — HFC's Own Stock (Agency Properties)

### 6.1 Properties table — identifier columns

The `properties` table has ~129 columns. Identifier-relevant columns:

| Column | Type | Populated? | Source | Notes |
|--------|------|-----------|--------|-------|
| `id` | bigint | always | auto | primary key |
| `external_id` | UUID | always | [Property.php:196-198](app/Models/Property.php#L196-L198) auto-generates | system-wide unique |
| `p24_listing_number` | string | sometimes | P24 CSV import | inbound listing ref |
| `p24_ref` | string | when syndicated | P24 sync | outbound syndication ref |
| `pp_ref` | string | when syndicated | PP SOAP | outbound syndication ref |
| `pp_listing_feed_ref` | string | when syndicated | PP event feed | feed-level ref |
| `property_number` | string(100) | sometimes | P24 `StandNumber` | free-text erf/stand proxy |
| `stand_number` | string(100) | sometimes | manual | another proxy |
| `latitude` / `longitude` | decimal(10,7) | **only from P24 CSV** | [P24ListingsCsvParser.php:136-137](app/Services/Importer/P24ListingsCsvParser.php#L136-L137) | NO geocoder generates these |
| `pp_suburb_id` | unsigned int | when syndicated | PP taxonomy | different from CoreX `town_suburbs` |

### 6.2 Address columns (all confirmed present)

`address`, `street_number`, `street_name`, `suburb`, `city`, `district`, `region`, `province`, `town`, `unit_number`, `floor_number`, `unit_section_block`, `property_number`, `stand_number`, `complex_name`, `zone_type`, `address_internal_note`.

Display logic in [Property::buildDisplayAddress()](app/Models/Property.php#L275-L308) — priority: `street_number + street_name` → `address` → `title`. Search logic in [Property::scopeSearchAddress()](app/Models/Property.php#L315-L329) — LIKE across 10 columns, no fuzzy.

### 6.3 What's MISSING

| Identifier | Status | Implication |
|-----------|--------|-------------|
| `erf_number` | **NOT a dedicated column** | Best proxy is `property_number` or `stand_number`, both free text |
| `title_deed_number` / `deed_number` | **NOT present** | Cannot key by Deeds Office record |
| `municipal_valuation` | **NOT present** | Only `rates_taxes` (annual amount) — no valuation amount |
| `mandate_start_date`, `mandate_end_date`, `mandate_signed_at` | **NOT present** | Only `mandate_type`. Mandate lifecycle untrackable on the property record |
| `property_external_refs` table | **NOT present** | Multiple-source IDs all sit as columns on properties; no normalised mapping table |

`commercial_evaluations` table (migration 2026_02_25_600000) does have `erf_number` and `municipal_evaluation` columns — but that table is for commercial valuations only, not the main `properties` pillar.

### 6.4 Linkage between `properties` and other data sources

| Source | Mechanism | Direction | Strength |
|--------|-----------|-----------|----------|
| P24 syndication | `p24_ref`, `p24_listing_number` columns | bi-directional | strong |
| PP syndication | `pp_ref`, `pp_listing_feed_ref` columns | bi-directional | strong |
| Prospecting listings | `prospecting_listings.matched_property_id` FK | reverse only | weak (mostly NULL) |
| CMAInfo / CMA reports | `compliance_snapshot_data` JSON OR none | none direct | **no FK** |
| Presentations | `PropertyPresentationSnapshot` join table OR `presentations.listing_id` (unconstrained) | weak | **no enforced FK** |
| Contacts (owner/tenant/buyer) | `contact_property` pivot with `role` column | bi-directional | strong |
| Buyer wishlists (contact_matches) | `ContactMatchFeedback.property_id`, `ContactMatchNotifications.property_id` | reverse only | medium |

**Key finding:** The strongest linkages are to external syndication portals. The weakest linkages are to the system's own CMA/presentation data — which is the exact data Market Intelligence needs to surface back through the Property pillar.

### 6.5 Row counts

Cannot be inferred from static inspection alone. Per memory: 898 P24 + 85 PP listings in `prospecting_listings` for HFC.

---

## Section 7 — Buyer Wishlist Intelligence

### 7.1 contact_matches schema

Confirmed columns (from migrations 2026_04_28_100001, 2026_03_07_100002-4, 2026_05_13_100002):

```
id, contact_id (FK), agency_id (FK), name, status (active|paused|fulfilled|expired),
is_primary, share_token, share_slug,
listing_type, category, property_type (deprecated), property_types (json),
price_min, price_max,
beds_min, bedrooms_max, baths_min, garages_min, parking_min,
floor_size_min/max, erf_size_min/max,
suburb (deprecated), suburbs (json),
must_have_features (json), nice_to_have_features (json), deal_breakers (json),
notes, hidden_property_ids (json), property_view_counts (json),
last_engaged_at, auto_archive_at,
created_by_user_id, updated_by_user_id, deleted_at, timestamps
```

Status enum (from [ContactMatch.php](app/Models/ContactMatch.php)): `STATUS_ACTIVE | STATUS_PAUSED | STATUS_FULFILLED | STATUS_EXPIRED`.

### 7.2 Related tables

| Table | Purpose | Time-series? |
|-------|---------|--------------|
| `contact_match_feedback` | per-property reaction (`interested|not_interested|saved`) + note | yes (created_at per reaction) |
| `contact_match_notifications` | per-property notification log + match `score` 0-100 | yes (created_at per send) |
| `prospecting_buyer_matches` | links prospecting_listings → contacts (with `score`, `tier`, `matched_features`, `missing_features`) | partial (`matched_at`, `last_recompute_at`, `dismissed_at`) |

### 7.3 Demand-curve capability

**What you can answer today:**
- "How many active wishlists target Shelly Beach 3-bed under R2M right now?" — yes, via JSON_CONTAINS on `suburbs`.
- "When did each wishlist enter the system?" — yes, via `created_at`.
- "Which properties did this buyer react to and when?" — yes, via `contact_match_feedback`.

**What you CANNOT answer today:**
- "How did demand for Shelly Beach 3-bed under R2M change week-over-week over 90 days?" — partial. You can group `created_at` by week, but if a wishlist's `suburbs` or `price_max` was edited, the old value is overwritten — no audit trail.
- "When did this wishlist transition from `active` → `paused`?" — no. No `status_changed_at` column.
- "Which suburbs are gaining demand?" — no clean answer. There's no `contact_match_history` or event-sourcing table.

### 7.4 Honest assessment

Snapshot intelligence is solid. **Time-series intelligence requires a missing audit table.** Either a `contact_match_events` table (one row per state change) or weekly snapshot rollups. Neither exists today.

---

## Section 8 — Presentation System (the OCR goldmine)

### 8.1 Controller / service map

| Component | Path |
|-----------|------|
| Main controller | [PresentationController.php](app/Http/Controllers/Presentation/PresentationController.php) (~26k tokens) |
| PDF export | [PresentationPdfController.php](app/Http/Controllers/Presentation/PresentationPdfController.php) |
| Snapshot | [PresentationSnapshotController.php](app/Http/Controllers/Presentation/PresentationSnapshotController.php) |
| Articles | [PresentationArticleController.php](app/Http/Controllers/Presentation/PresentationArticleController.php) |
| Versioning | [PresentationVersionController.php](app/Http/Controllers/Presentation/PresentationVersionController.php) |
| Upload processor | `app/Services/Presentations/Evidence/UploadProcessor.php` |
| Extraction router | `app/Services/Presentations/Evidence/UploadExtractionService.php` |
| Link extraction | `app/Services/Presentations/Evidence/LinkExtractionService.php` |
| Analysis assembly | `app/Services/Presentations/AnalysisDataService.php` |
| CMA parser | `app/Services/Presentations/Evidence/Parsers/CmaParserV1.php` |
| Vicinity sales parser | `app/Services/Presentations/Evidence/Parsers/SalesReportParserV1.php` |
| Suburb stock parser | `app/Services/Presentations/Evidence/Parsers/SuburbStockParserV1.php` |
| Text extraction | [TextExtractionService.php](app/Domain/Presentation/TextExtractionService.php) |
| Field extraction | [DocumentExtractor.php](app/Support/Presentation/DocumentExtractor.php) |

### 8.2 PDF upload path

1. `POST /presentations/{presentation}/upload` ([PresentationController.php:601-626](app/Http/Controllers/Presentation/PresentationController.php#L601-L626)).
2. `UploadProcessor::process()` stores PDF at `presentations/{id}/{file_slug}`, creates `PresentationUpload` row (status `pending`).
3. `TextExtractionService::extractText()` runs `pdftotext` CLI → fallback to Smalot.
4. `UploadExtractionService::run()` auto-detects doc type from filename ("Valuation" → cma, "sales.in" → vicinity_sales, "Median" → suburb_stats) or text content.
5. Routes to one of three parsers.
6. Parser writes `extraction_json` + creates `presentation_sold_comps` and/or `presentation_active_listings` rows.
7. If `presentation_doc_extract_v1` flag is on, `DocumentExtractor` runs and writes `presentation_fields` rows.

### 8.3 Suburb / Town reports

Suburb reports and town reports are detected via filename patterns ("Median", "Sales.Analysis") and routed to `SuburbStockParserV1`. No separate town-level parser — town reports get the same treatment as suburb-stock reports.

### 8.4 Presentation-to-property linkage

`presentations.listing_id` (unsignedBigInteger, nullable, **no FK constraint**) — set when an agent creates a presentation for an existing listing. Many presentations have it NULL (created for a property not yet in the system).

`PropertyPresentationSnapshot` table (migration 2026_05_06_000003) has both `presentation_id` and `property_id` as a weak join — but it's a snapshot table, not a primary linkage.

**Critical gap:** A presentation built for "Mitchell Street, Shelly Beach" with no existing Property row leaves all extracted CMA data (erf, GPS, municipal value, comps) **orphaned** — never visible from any future view of that property.

### 8.5 Presentation history per property

If three presentations exist for the same property, **none of the extracted CMA data is consolidated**. Each presentation re-extracts from its uploaded PDF, stores its own `presentation_fields`, `sold_comps`, `active_listings`. No "show me all comps ever extracted for this property" query exists.

### 8.6 Data leakage assessment

**Extracted data stays accessible permanently — but only within that presentation.** Queryable via Eloquent (`$presentation->soldComps`, `$presentation->fields`) but NOT cross-presentation, NOT from Property, NOT from any global "market data" surface.

**This is the single biggest reusable asset and the single biggest gap simultaneously.** The OCR runs and writes structured data. The data just doesn't propagate to where Market Intelligence needs it.

### 8.7 Spec vs reality

[.ai/specs/presentations.md](.ai/specs/presentations.md) is 4-5 months stale. Spec says "P24 image scraping, TVA API CMA system, TVA data flywheel — pending." Reality: Property24 LINK ingestion is partly built ([LinkExtractionService.php](app/Services/Presentations/Evidence/LinkExtractionService.php)); P24 image scraping is NOT built; TVA integration is NOT built. The `DocumentExtractor` (the most useful single piece of OCR infrastructure) is newer than the spec and not documented in it.

---

## Section 9 — Property Identity Across Sources

The matrix. **The most valuable single output of this audit.** Read this row by row: a `✓` means the source captures the identifier today; `✗` means it does not; `partial` means the data is in the raw blob but not parsed out.

| Identifier | CMAInfo (subject) | CMAInfo (comps) | VirtualAgent | P24 Email | P24 Scrape (Chrome ext) | PP SOAP (outbound feed) | PP Scrape (Chrome ext) | HFC Stock (properties) | Presentation (subject) | contact_matches |
|---|---|---|---|---|---|---|---|---|---|---|
| Erf number | ✓ ([DocumentExtractor.php:158](app/Support/Presentation/DocumentExtractor.php#L158)) | partial (raw_row_json only) | dormant | ✗ | partial (icon_erf is size, not erf no.) | ✗ | partial | partial (`property_number` / `stand_number` free text) | ✓ via subject extraction | n/a |
| GPS lat/long | ✓ ("30.384764°E 30.838421°S") | ✗ | dormant | ✗ | ✗ | ✗ | ✗ | partial (cols exist; populated only from P24 CSV) | ✓ via subject extraction | n/a |
| Address (full street) | ✓ | partial | dormant | ✗ (P24 hides until enquiry) | ✓ DOM | ✗ | ✓ DOM | ✓ structured (street_number + street_name) | inherited from properties | suburb only |
| Suburb | ✓ | ✓ | n/a | ✓ regex | ✓ | n/a | ✓ | ✓ | ✓ | ✓ (json array) |
| Title deed number | ✗ | ✗ | dormant (TVA returns this manually) | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | n/a |
| Portal reference | n/a | n/a | n/a | ✓ (`P24-\d+`) | ✓ (URL-extracted listing_id) | ✓ (pp_ref, pp_listing_feed_ref) | ✓ | ✓ (`p24_ref`, `pp_ref`) | inherited | n/a |
| Municipal valuation | ✓ ("Total Value: R1,800,000") | ✗ | dormant | ✗ | ✗ | ✗ | ✗ | ✗ (only `rates_taxes` annual amount) | ✓ via subject extraction | n/a |
| Asking price | n/a | n/a | n/a | ✓ regex | ✓ og:price / JSON-LD | ✓ | ✓ | ✓ | partial | range only |
| Sale price (historical) | n/a | ✓ (`sold_price_inc` in sold_comps) | dormant | ✗ | ✗ | ✗ | ✗ | ✗ (deal-side has it, property-side does not) | n/a | n/a |
| Beds / baths / size | ✓ | ✓ | n/a | ✓ regex | ✓ icon_bed/icon_bath | ✗ | ✓ | ✓ | ✓ | bounds only |
| Owner contact (phone/email) | ✗ | ✗ | ✓ (manual lookup, NOT stored) | ✗ | ✗ (listing agent only) | ✗ | ✗ | ✓ via contact_property pivot | inherited | n/a |
| Property history (prior sales) | n/a | ✓ (Vicinity reports include 1990s+ sales) | dormant | ✗ | ✗ | ✗ | ✗ | ✗ on property record (deals_v1 / deals_v2 hold sale events) | ✓ within presentation | n/a |
| Listing photos | n/a | n/a | n/a | ✗ | ✓ og:image + gallery | ✓ via outbound | ✓ | ✓ (images_json, dawn/noon/dusk) | inherited | n/a |

**The single most underused row:** GPS. It's extracted from CMA PDFs and stored in `presentation_fields` (subject.gps), but never propagated to `properties.latitude/longitude` — even when a presentation has a `listing_id`. That propagation is a 10-line listener if the domain-events catalogue is followed.

---

## Section 10 — Existing Geospatial Capability

### 10.1 Database

MySQL with **no spatial extension, no PostGIS, no `POINT` columns**. `properties.latitude` and `properties.longitude` are plain `decimal(10,7)` — added [2026_03_23_100002](database/migrations/2026_03_23_100002_add_pp_suburb_id_and_coordinates_to_properties_table.php).

No `ST_Distance`, `ST_DWithin`, haversine, or spatial index anywhere in the codebase.

### 10.2 Map UI

Stubbed only. [evaluation/index.blade.php:15-49](resources/views/evaluation/index.blade.php#L15-L49) has Alpine.js tabs labelled `['Suburb / Province', 'Sales & Transfers', 'Prospecting', 'Documents']` and search-type pills `['Full Title', 'Person', 'ERF', 'Suburb', 'Street', 'Transfer']`. **No Leaflet/Mapbox/Google Maps library is actually imported or rendering.** Memory referenced Leaflet — that may be in a JS bundle not yet built, or may have been removed.

### 10.3 Geocoding

**Inbound only.** [P24ListingsCsvParser.php:136-137](app/Services/Importer/P24ListingsCsvParser.php#L136-L137) reads `Latitude` and `Longitude` columns straight from the P24 CSV. **No forward (address→GPS) or reverse (GPS→address) geocoder exists.** No Nominatim, no Google Geocoding, no Mapbox geocode, no OpenCage.

**Implication:** properties without P24 CSV import have NULL GPS. The CMA-extracted GPS in `presentation_fields` is never copied back.

### 10.4 Radius queries

None exist. Cannot be done in SQL today (no spatial indexes) — would need application-level haversine over a candidate set, or addition of MySQL spatial columns + `ST_Distance_Sphere`.

---

## Section 11 — Address Normalisation

### 11.1 What exists

| Asset | File | Logic |
|-------|------|-------|
| `SuburbNormalizer::normalize()` | [SuburbNormalizer.php](app/Services/MarketAnalytics/Helpers/SuburbNormalizer.php) | `mb_strtolower(trim(preg_replace('/\s+/', ' ', $suburb)))` |
| `ProspectingListing::normalizeAddress()` | [ProspectingListing.php:91-108](app/Models/ProspectingListing.php#L91-L108) | lowercase + strip punctuation + collapse whitespace + append suburb |
| `town_suburbs.suburb_normalised` | [2026_05_13_150002 migration](database/migrations/2026_05_13_150002_create_town_suburbs_table.php) | populated explicitly at insertion |
| `ProspectingStockMatchService::matchProspect()` | [ProspectingStockMatchService.php:15-106](app/Services/Prospecting/ProspectingStockMatchService.php#L15-L106) | 2-pass: exact-normalised → suburb-match + street-number-match + 3+ char street word overlap |

### 11.2 What does NOT exist

- No general `AddressNormaliser`/`AddressMatcher` class.
- **No `street_name_normalised` column** anywhere. "Mitchell St" vs "Mitchell Street" vs "MITCHELL STREET" will not match.
- No Levenshtein / Soundex / Metaphone / Jaro-Winkler use. The "fuzzy" matcher in ProspectingStockMatchService is custom token-overlap logic, not a known similarity algorithm.
- `Property::scopeSearchAddress()` is LIKE-only across raw columns ([Property.php:315-329](app/Models/Property.php#L315-L329)) — case-insensitive in MySQL but no abbreviation handling.

### 11.3 Property addresses today

Mixed: structured (`street_number` + `street_name`) when imported from P24 CSV, free text in `address` as fallback. No normalisation at write-time. **Casing and punctuation pass through unchanged from the upstream source.**

---

## Section 12 — Storage Considerations

### 12.1 Filesystem disks

[config/filesystems.php](config/filesystems.php):
- `local` (default) — `storage_path('app/private')`
- `public` — `storage_path('app/public')` with `{APP_URL}/storage` prefix
- `s3` — optional, only active if `AWS_ACCESS_KEY_ID` etc. are set. Supports `AWS_ENDPOINT` (Hetzner S3-compatible works).

### 12.2 Existing storage paths

| Use | Path | Notes |
|-----|------|-------|
| Presentation PDFs | `storage/app/presentations/{presentation.id}/{file_slug}` | deterministic, soft-deleted |
| Portal capture HTML | `storage/app/portal_captures/{capture_id}.html` | raw scraped HTML |
| Portal capture screenshots | `storage/app/portal_captures/{capture_id}.png` | base64 PNG decoded |
| Signed contract PDFs | `storage/app/{baseDir}/{path}` | per signature_templates.signed_pdf_path |
| Compliance/whistleblow PDFs | `storage/app/whistleblow/complaints/{id}/` | audit-grade |
| Temp workfiles | `storage/app/temp/` | ephemeral |

### 12.3 CMAInfo PDF sizing

Not measured directly in this audit. Typical CMAInfo PDFs are 1-3 MB (Vicinity), 2-5 MB (CMA with graphs), 5-15 MB (Suburb/Town with maps). With 50 agents averaging 5 presentations/month each, the archive grows ~50-100 GB/year at high quality. Local disk fine for HFC scale; S3 with `AWS_ENDPOINT` targeted at Hetzner Object Storage is the obvious next step.

### 12.4 Permanent reference archive convention

**Does not exist as a documented pattern.** Each module invents its own subdirectory. A `storage/app/market-intelligence/archive/YYYY/MM/` convention (or an S3 bucket) would need to be created.

---

## Section 13 — Gaps, Risks & Quick Wins

### 13.1 Top 5 reusable assets

1. **The OCR pipeline already extracts erf, GPS, address, municipal valuation, CMA bands** from CMAInfo PDFs ([DocumentExtractor.php](app/Support/Presentation/DocumentExtractor.php)). Battle-tested on Vicinity/CMA/Suburb formats. ~80% of the "market intelligence input layer" already exists in code that's already passing through dev-check.
2. **The Chrome Portal Capture extension is the richest ingestion path** — full HTML, full screenshots, DOM-extracted fields, agent contact details, cross-portal dedup via `normalized_address` + `property_group_id`. Already wired to portal_listings/portal_captures and to prospecting_listings.
3. **`external_id` (UUID) on every Property** provides a system-wide stable identifier independent of P24/PP refs ([Property.php:196-198](app/Models/Property.php#L196-L198)).
4. **`prospecting_listings.matched_property_id`** is the ready-made bridge between external listings and the Property pillar. The matcher exists ([ProspectingStockMatchService.php](app/Services/Prospecting/ProspectingStockMatchService.php)) — it just isn't run on every ingestion.
5. **The domain-events catalogue** ([.ai/specs/corex-domain-events-spec.md](.ai/specs/corex-domain-events-spec.md)) provides the architectural mechanism to propagate CMA-extracted data from a presentation back to a Property without inventing ad-hoc plumbing. This is the right pattern for the cross-source linkage that Market Intelligence requires.

### 13.2 Top 5 gaps

1. **No `erf_number`, `title_deed_number`, or `municipal_valuation` columns on `properties`.** The system has these values inside `presentation_fields` and inside CMAInfo PDFs but cannot store them on the Property pillar. Schema gap.
2. **CMA data is locked inside presentations.** No back-propagation to `properties` even when `presentations.listing_id` is set. The OCR ran, but the data doesn't reach the pillar.
3. **No address normalisation for street names.** Mitchell St vs Mitchell Street will not match. Cross-source matching that depends on street names will silently fail on common abbreviations.
4. **No forward geocoder.** Properties with addresses but no GPS (probably the majority of mandated stock not imported from P24 CSV) cannot be plotted on a map. Cross-source matching by proximity is impossible without GPS.
5. **No central "property identity" table.** Multiple identifier columns sit on `properties` (`external_id`, `p24_listing_number`, `pp_ref`, `property_number`, `stand_number`) without a normalised mapping table to resolve "which source IDs point to this canonical property". Adding sources later (TVA, Lightstone, Deeds Office) means more sparse columns rather than rows in a `property_external_refs` table.

### 13.3 Top 3 risks

1. **CMAInfo OCR is regex-brittle.** [DocumentExtractor.php](app/Support/Presentation/DocumentExtractor.php) hard-codes patterns like the GPS regex on line 153 and the erf regex on line 158. CMAInfo changing PDF layout silently breaks extraction — and because parsers don't throw, breakage shows up as null `presentation_fields` rather than failed uploads. Need monitoring on extraction success rates.
2. **VirtualAgent and PP `GetActiveListings` have unknown rate limits and unknown cost structures.** Building Market Intelligence on an assumption of "we can query TVA per property" without confirming TVA's API limits and pricing is a critical risk. Per-property lookup costs must be characterised before automated lookups are wired in.
3. **`prospecting_listings.address` is `'Address not available'` for many P24 rows.** Cross-source matching cannot rely on the address coming from P24 emails. Strategy must allow for portal-ref-only matching as a primary path with address-match as a fallback.

### 13.4 Quick wins before Wednesday

**Single smallest demo-able build (1-2 days):** a **Property Intelligence Drawer** on the Property detail page that aggregates everything CoreX already knows about that property — keyed only by `properties.id`. No new ingestion. No new OCR. Just a single read-only Blade panel that runs four-to-five existing queries and stacks the results:

1. Linked presentations (via `PropertyPresentationSnapshot` and `presentations.listing_id`) and the most recent `presentation_fields` values for `subject.erf`, `subject.gps`, `subject.extent_m2`, `municipal.total_value`, `cma.middle_range`.
2. Linked `prospecting_listings` (via `matched_property_id`) — show portal_source, portal_ref, price history (`prospecting_price_history`), claim history.
3. Owners/tenants/buyers via `contact_property`.
4. Active buyer wishlists scoring this property via `contact_match_notifications.score` desc.
5. Syndication state (`pp_syndication_status`, `p24_syndication_status`, last sync times).

Single page. No migrations needed. Demonstrates the strategic point — "CoreX already knows N things about this property; they were just never on one screen."

**Stretch (3-5 days):** add a `dispatch(new BackfillPropertyFromPresentation($presentation))` listener that, when a presentation is saved with a `listing_id`, copies `subject.erf` / `subject.gps` / `municipal.total_value` from `presentation_fields` into corresponding new columns on `properties`. That's the migration that should run first — it converts the existing OCR output into permanent pillar enrichment with zero new ingestion.

---

## What I Would Build Next

The full Market Intelligence module should be designed around **one canonical `properties_master` view that joins every known fragment, indexed by a unified `property_external_refs` mapping table** (one row per `(source, source_id, property_id)`). Add three new columns to `properties` — `erf_number`, `title_deed_number`, `municipal_valuation` — and a forward-geocoder service that fills `latitude`/`longitude` for any address that has structured `street_number + street_name + suburb`. Then wire a listener that fires on `PresentationFieldExtracted` (new event) to backfill from any presentation into the matching Property, and a second listener that fires on `ProspectingListingMatchedToProperty` (likely already specced) to backfill identifiers like portal refs into the same record.

The architecture pattern is **single source of truth = `properties` table; everything else feeds in via events**. This mirrors the existing pillar-and-events doctrine ([.ai/specs/corex-domain-events-spec.md](.ai/specs/corex-domain-events-spec.md)) and aligns with the multi-tenancy rule (Property is agency-scoped via the existing trait). No new pillars. No new islands. Just a denser, more linked Property pillar with a `property_external_refs` mapping table and the events catalogue doing the cross-source connective tissue.

---

## Reusability Assessment

**Reusable as-is (high confidence): ~60%**
- OCR pipeline (TextExtractionService + DocumentExtractor)
- All three parsers (CmaParserV1, SalesReportParserV1, SuburbStockParserV1)
- Chrome Portal Capture ingestion (controller, services, storage convention)
- Prospecting tables (prospecting_listings, prospecting_claims, prospecting_price_history, prospecting_buyer_matches)
- Portal capture tables (portal_captures, portal_listings, portal_listing_observations)
- P24 IMAP import (P24ImapImportService, P24EmailParserService, ImportP24Alerts command)
- PP SOAP outbound (PrivatePropertySoapClient) — though the inbound `GetActiveListings` is unused
- Cross-portal dedup logic (normalized_address + property_group_id)
- ProspectingStockMatchService 2-pass matcher (use it as the address-match fallback)
- BelongsToAgency trait + AgencyScope (multi-tenancy infrastructure)
- contact_property pivot for owner/tenant linkage
- contact_matches for buyer wishlist intelligence (snapshot only)

**Reusable with extension (medium): ~25%**
- Vicinity / Suburb parsers need extension to extract per-comp erf and GPS from `raw_row_json` (the data is there in the text, just not parsed).
- `Property::scopeSearchAddress` needs a normalised-address index column to match across abbreviations.
- `presentations.listing_id` needs to become a real FK and gain back-propagation listeners.

**Needs rewriting or net-new build (~15%)**
- Forward/reverse geocoding service — green field.
- TVA integration — green field.
- `property_external_refs` mapping table + supporting model and service — net new schema.
- `contact_match_events` audit table — net new schema, needed for time-series demand curves.
- Map UI with Leaflet — green field (or rebuild from whatever existed in evaluation module).
- `presentations.agency_id` enforcement — schema migration + AgencyScope wiring.
- Three new columns on `properties` (erf_number, title_deed_number, municipal_valuation) — small migration.

**Honest closing assessment:** the unflattering finding from this audit is that CoreX already has roughly two-thirds of the data plumbing needed for Market Intelligence — it's just been built in service of presentations, prospecting, and syndication rather than as a unified intelligence layer. The right next move is connective tissue (events + a mapping table + a few backfill listeners), not new ingestion. The Wednesday demo should make that visible by literally putting all the existing fragments on one screen and letting the gap between "data we have" and "data we surface" speak for itself.
