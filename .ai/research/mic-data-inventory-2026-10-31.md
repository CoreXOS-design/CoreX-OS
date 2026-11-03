# MIC data inventory — what CoreX already holds (investigation only)

**Date:** 2026-10-31 · **Box:** QA1 (`corex_qa1`, code at `/corex-qa1`, branch QA1 @ `8e46774c3`) · **Scope:** agency 1 unless stated
**Method:** read-only SELECT/EXPLAIN on QA1, code read, one in-process request timing inside a rolled-back transaction. No code changed, no data written.
**Reading note:** "fill %" = share of the rows in that table/segment where the column holds a usable value (NULL, empty and 0 counted as missing).
Appendices A–C are the three sub-reports (extension, CMA/deeds, buyers) kept whole for file:line detail.

## 0. The short version

1. **The Work tab is a list of portal ADVERTS, not properties.** It reads `prospecting_listings` (47,990 rows, 21,115 active), grouped by `property_group_id` (45,220 groups) and badged from side tables. The "one rich record per property" lives elsewhere: `tracked_properties` (50,076 rows) and, newly, `shared_properties` (37,370).
2. **What a capture can ever hold is thin.** The extension scrapes search *tiles* only. Never sent from tiles: agent name, floor size (separately), description, photo count, listed date, listing type, mandate type, coordinates. `agent_name`, `property_size_m2`, `listed_date`, `mandate_type` are **0% filled** on all 47,990 listings.
3. **Sold data exists but MIC reads none of it.** 1,726 priced CMA comps in the last 24 months, 1,825 tracked properties with a deeds sold price/date, 3,424 owner rows, 478 bonds. None feed the Work tab or Opportunities ranking.
4. **The "known" set is small.** Of 20,159 tracked files behind active adverts: 3,461 have a street address, 1,839 have a price history, 1,527 are on both P24 and PP, 76 have an owner, 73 a sold price, 62 a deeds capture.
5. **Buyer demand is rich but diluted.** 82% of active adverts have ≥1 "strong" buyer, yet 51% of strong rows belong to cold/lost buyers; counting only new/warm buyers, 51% of adverts have none. Cheap to read per page (~10–20 ms), expensive to *sort* by (falls off the fast path: 4.8 s page).
6. **Current ranking is recency, not value.** Default `last_seen_at DESC`; sorts: last seen, first seen, price, suburb, buyer demand. "Pitch now · high" is a **filter** (no active claim + ≥3 strong buyers), not a rank.

## 1. `prospecting_listings` (the Work tab row) — 47,990 rows, all agency 1, 0 soft-deleted

Every column (type) · fill % by segment (P24 inactive / P24 active / PP inactive / PP active; n = 11,024 / 14,503 / 15,851 / 6,612):

| Column | Fill % (p24-off / p24-on / pp-off / pp-on) | Written at | MIC reads |
|---|---|---|---|
| portal_source (p24/pp), portal_ref, canonical_ref | 100 all | ProspectingApiController.php:241 (create), :144-147 lookup; canonical via `HasCanonicalPortalRef` ProspectingListing.php:15-26 | row, grouping |
| listing_type | 100 (**all 'sale'**; to-rent tiles stored as sale with rent in `price`) | default (migration 2026_10_29_120100) | filter only |
| price (int) | 99.4 / 99.3 / 99.6 / 99.5 | :241 create, :177-200 update | row, sort, filter |
| bedrooms / bathrooms / garages | beds 81.9 / 75.6 / 83.8 / 83.9; baths 82.0 / 75.9 / 84.2 / 84.0; garages 78.8 / 72.6 / 74.8 / 77.4 | :241, :203-212 (`?? existing`) | row (`_listing-row` 1 ref each) |
| erf_size_m2 | 95.0 / 94.4 / 84.9 / 85.2 (floor size is stored here too for flats) | :241 | **not rendered** (0 refs in row/slide-over) |
| property_size_m2 (floor) | **0 / 0 / 0 / 0** (hard null in both scrapers) | :241 | not rendered |
| property_type | 99.7 / 99.7 / 97.5 / 98.8 (P24: townhouse stored as "House", see App. A §1a) | :241 | row, filter rail |
| agent_name | **0 all** | never sent | row has the slot (empty) |
| agency_name | 95.8 / 95.2 / 93.3 / 82.0 | :241 | row |
| address (255) / normalized_address | 44.4 / 22.7 / 33.8 / 15.4 | :202 (**overwritten with NULL on a later address-less sighting**) | row; stock match by normalized_address |
| suburb (NOT NULL), district | 100; district ~0 | :130 | row, filter rail, sort |
| latitude / longitude | 18.7 / 7.3 / 18.4 / 7.5 | geocode jobs, MapActivityController.php:553 (synthetic rows: price 0) | map only |
| thumbnail_path / thumbnail_source_url | 46.5 / 90.9 / 100 / 79.5 | job `DownloadListingThumbnail`, :229/:284 | row |
| first_seen_at / last_seen_at | 100 | :241, :150 | slide-over (first_seen), **sort + recency only** |
| listed_date, listed_date_source | **0 / 0** | `ListedDateRecorder` (only when someone pulls the P24 detail page) | not rendered |
| price_changed_at | 6.7 / 9.1 / 2.9 / 9.1 | :198 | not rendered |
| portal_status, portal_status_changed_at, off_market_at, is_active | status 79.7 / 100 / 89.1 / 64.8; **PP never sets status** | :139-175 (P24 ribbon text), SuburbReconcileService marks absent ones 'withdrawn' | filters (is_active); status not rendered. Totals: active 14,503 / withdrawn 25,901 / under_offer 1,048 / sold 251 / NULL 6,286 |
| mandate_type | **0** | server accepts, extension never sends | row slot (empty) |
| tracked_property_id | 91.5 / 100 / 90.4 / 100 | `linkToTrackedProperty` :543-594 → matchOrCreate | counts, badges |
| matched_property_id (company stock) | 5.9 / 1.5 / 1.3 / 2.8 | OnMarketStockService / identity door | "IN STOCK" tile is computed live by ref/address instead (MarketIntelligenceController.php ~790) |
| property_group_id | 100 (45,220 groups) | assignPropertyGroup :499-530 | grouping (cross-portal P24⇄PP) |
| portal_advert_fact_id | 80.7 / 87.2 / 89.8 / 75.9 | A5 share step (pointer only) | not read |
| pitched_at, linked_deed_*, possible_* | sparse | pitch / deed-link / possible-match flows | claims, slide-over |
| captured_by_user_id, agency_id, branch_id | 100 / 100 / sparse | :241 | scope (`visibleTo`) |

Date range: first seen 2026-03-18 → last seen 2026-10-31; 414 suburbs. No description, no photo list, no price per m².

## 2. Price history / reductions per listing

| Store | Rows | Shape | Written | MIC Work reads |
|---|---|---|---|---|
| `prospecting_price_history` | 3,663 rows / 3,119 listings (2026-03-18 →) | old_price, new_price, changed_at | ProspectingApiController.php:190-199 (only on a price *change*; a jump of 4× or more either way go to `prospecting_price_anomalies`, 596 rows, :181-187, :394-494). **No row on first sighting.** | **No.** Read by PropertyIntelligenceService.php:863 (property page) and RoadToSold PriceDropSelection.php:10-31 |
| `prospecting_listings.price_changed_at` | 9.1% of active | datetime | :198 | not rendered |
| `p24_listings.original_price` vs `asking_price` | 100% / 13.6% differ | alert rows | P24ImapImportService.php:138-185 | attached as `email_original_price` (controller :1282-1307) but **no current view renders it** (only `prospecting/index_legacy_body`) |
| `p24_price_changes` | 2,456 rows / 1,926 listings (2,326 cuts, 130 rises; 2026-03-02 → 2026-10-22) | old/new/change_date | P24ImapImportService.php:148 | not read by Work (Admin P24Controller.php:81) |
| `pp_alert_price_changes` | 0 | — | PpImapImportService.php:150 | — |
| `portal_advert_facts.price_history` (shared pool JSON) | 44,046 of 45,779 have a value; samples are one point `[{"at","price"}]` | JSON | SharedPortalPool::contribute | not read |
| `portal_listing_observations` | 16,522 | per-capture field deltas (old extension only, 3.3.x) | PortalListingTrackingService | not read |

Result: reductions are *stored* for ~1,840 active files but **no screen ranks or even shows "dropped X% in N days"** on the Work row.

## 3. P24 e-mail alert rows (`p24_listings`)

| Item | Fact |
|---|---|
| Rows | 14,228 total, 14,092 agency 1 (all `listing_status='active'`), first seen 2026-03-02 → last 2026-10-22, avg seen 1.4× |
| Fields | p24_listing_number, canonical_ref, asking_price (100%), original_price, property_type (96%), suburb (88.2%), area (0), bedrooms (81.2%), bathrooms/garages (**0**), is_mandated (3.3% true), p24_url (100%), first/last_seen_date, times_seen, tracked_property_id (62.8%), identity_method |
| Linking | `AlertPropertyLinker` (property-identity.md §15) → `tracked_property_id`: 8,853 linked by portal_ref, 5,239 have no property file yet. Join to Work rows by `P24-<n>` portal_ref: 8,912 of 25,527 P24 adverts (35%) |
| Written | P24ImapImportService.php:138-185 (`P24PriceChange` at :148) |
| MIC read | Work: attached as email_* fields (controller :1282-1307), not rendered; Opportunities property page: `PropertyAlertFacts` partial `_alert-facts`; "Portal Alerts" tab = alerts awaiting address (controller :1815) |
| PP alerts | `pp_alert_listings` **0 rows** on QA1; PP mail format unconfirmed (lane C memory) |

## 4. Browser extension — see Appendix A

Summary: 3.12.0 (`public/chrome-extension/portal-capture`) posts search *tiles* to `/api/prospecting/import`. P24 sends ref, url, address (mostly null), suburb, price, beds, baths, garages(+parking combined), one size field, type (guessed), agency name, thumbnail, status ribbon. PP sends the same minus status; address derived from the URL. **Never sent from tiles:** agent, floor size, description, photo count, listed date, listing type, mandate, lat/lng, ribbons like "price reduced". Detail-page scrapes (richer: description, features, agent/agency, lat/lng, levies, rates, listing date, images) go to `properties`/OAS, not MIC — only `listed_date` flows back. A stale 3.3.x build still posts whole HTML to `/portal-captures/ingest` (8,767 `portal_listings`, 6,043 linked; floor size (`size_m2`) present in `current_fields_json` for ~3,800 of them but never copied to `prospecting_listings`).

## 5 & 6. CMA / presentation imports and deeds capture — see Appendix B

Can we get averages? **Per suburb: yes** (1,726 priced comps in 24 months, 94% with suburb, 25 suburbs ≥10 sales, but dedupe 310 repeat groups and merge `uvongo`/`uvongo beach` spellings). **Per type: no** (title type only: Residence/Residential/Vacant Land). **Per bedroom: no** (comp table has no beds; beds on 14 of 213 recent deeds sales). **R/m²: yes within two groups** (714 freehold on erf, 923 sectional on floor); stored `r_per_m2` is unreliable — recompute.
Deeds on tracked properties: sold price+date 1,825 (3.6%), registered date/title deed 1,929, erf no. 1,268, bond 478, LPI 6; single latest sale only, no transfer history; owner rows 3,424 over 2,220 files (294 of 44,401 unpromoted files). Comp rows are linked to **0** tracked properties.

## 7. Buyer → listing match counts — see Appendix C

Chip = stored table `prospecting_buyer_matches` (218,643 rows, agency 1): one row per (advert, buyer contact), best wishlist score; strong ≥ 80, mid 50–79 from `buyer_match_tiers` (agency-configurable); rows < 50 never cached. Rendered `_listing-row.blade.php:359-378` via one grouped query per page (`BuyerMatchTierService::tiersForListings` :35-86) ≈ 10–20 ms. Refresh: observer on listing create/price/beds/suburb/type change, wishlist observer, delayed agency-wide chunks, nightly 04:00 `prospecting:recompute-matches`. Gaps: bulk Chrome imports bypass the observer; 49,936 rows > 2 days stale; counts include cold/lost buyers (51% of strong rows). Distribution of 21,115 active adverts by strong count: 0 = 3,836 · 1–4 = 9,854 · 5–9 = 2,214 · 10+ = 5,211. Wishlists: `contact_matches` 636 active (500 buyers), 529 have ≥3 of price/suburb/type/beds.

## 8. Company stock (`properties`) and its link to tracked files

| Item | Fact |
|---|---|
| `properties` agency 1 | 6,501 live rows (5,937 sale / 563 rental). Sale statuses: withdrawn 2,564, prospecting 1,552, expired 990, sold 518, active 154, draft 63, not_selling 54, sold_by_3rd_party 21, under_offer 11 |
| Links to tracked files | `tracked_properties.promoted_to_property_id` 1,915 files; `tracked_property_agency_work.own_stock_property_id` / `is_own_stock` 671; `prospecting_listings.matched_property_id` only 1.3–5.9% |
| How the Work row knows "IN STOCK" | live `OnMarketStockService::stockMapForListings` by exact portal_ref or normalized_address vs on-market properties (controller ~786-800), not by stored ids |
| Not-selling / pitched suppression | `applyInStockFilter` + pitch-lock filters (controller :157-200); synthetic stock rows injected for managers (`include_in_stock`, controller ~740-775) |

## 9. Claims, work records, reasons, contacts

| Table | Rows (agency 1) | Notes |
|---|---|---|
| `prospecting_claims` | 760: claimed-active 343, claimed-released 326, contacted-active 71, contacted-released 16, not_interested 1, resolved_own_stock 3; 105 pitched, 56 with feedback | status, claimed_at, pitched_at, feedback_at, release_reason (only 4 rows have one: already_own_stock 3, not_selling 1) |
| `tracked_property_agency_work` | 2,321: prospecting_status/prospecting_reason set on **54** (all "not_selling", one auto reason); claimed 207; own stock 671 | the per-agency work layer (lane B); one writer `AgencyWorkService` |
| `tracked_property_agency_work_events` | 935 | history of the above |
| `tracked_property_comments` | 4 rows on 4 files | row comments (spec mic-property-row-comments) |
| `tracked_property_owners` + contacts | 3,424 owner rows; `tracked_properties.owner_contact_id` 1,934 (3.9%) | not read by MIC |
| `prospecting_pitch_locks` | 183 | temp locks; hide listings from other agents |
| `prospecting_seller_removals` | 2 | |
| not-on-market reasons | only the 54 above + 1 claim release | no structured reason taxonomy has real volume |

## 10. Other data in the DB that MIC could use but does not

| Table | Rows | What it holds | MIC use |
|---|---|---|---|
| `property_portal_metrics` | 41,204 (302 company properties, 2026-01-08 → 2026-10-21) | per day per portal: views, alerts, tel/sms/request leads, price | none (company stock only) |
| `portal_leads` | 932 (910 linked to a listing, 915 to a contact; P24 626, PP 243, website 63) | portal enquiries per company listing | only as a *buyer source label* ("N via portal lead", `_listing-row.blade.php:381` reads buyer origin, not listing leads) |
| `property_listing_cycles` / `sold_stock` | 491 / 519 (sale_price on only 11) | relist history, days on market, previous owners/buyers | none |
| `property_sold_records` | 226 (sold price = asking on all) | manual sold records | none |
| `market_data_points` | 6,110 | CMA metrics incl. `comparable_sale_price`, `cma_value_*`, `municipal_valuation`, `suburb_*_year` | only 7 `suburb_*_year` keys (suburb report) |
| `market_report_comp_rows` / `presentation_sold_comps` | 3,923 / 2,180 | sold comps | none (MIC); presentations + Map only |
| `portal_advert_facts` / `shared_properties` / `portal_advert_contributions` | 46,794 / 37,370 / 45,915 | the shared pool (A5): erf 87%, price 96%, agency name 83%, address 24%, lat/lng 0%, floor/agent 0% | none |
| `tracked_property_external_refs` | 52,482 (p24 24,584; pp 20,937; chrome 6,043; deeds 2,220; cmainfo 302) | which sources know the same file | counts only on Opportunities |
| `deals` / `deals_v2` | 176 each (43 registered) | our own sales, registration dates | MIC suburb report only |
| `prospecting_searches` | 68 | capture sessions (url, total_results, pages) | reconcile only |
| `buyer_*` (state transitions 1,889, activity 2,011, lost records 271) | | buyer state/temperature | not used to weight matches |
| viewings / calendar viewings | not inventoried (only `viewing_packs` 136, `viewing_pack_properties` 210, `calendar_event_feedback` 120 seen) | | none |

## 11. Current Work-tab ranking (file:line)

| What | Where |
|---|---|
| Query base | `MarketIntelligenceController::work()` :60-; `ProspectingListing::where('agency_id')->visibleTo($user,$micScope)` :93-130 (AT-380 scope own/branch/all) |
| Default pool filters | not company stock (:157), not pitched (:168-200), other agents' temp locks hidden (:177-188), `include_in_stock` for managers |
| **Default sort** | `last_seen_at DESC` (:576-583); allowed: `last_seen_at, first_seen_at, price, suburb` |
| Extra sorts (slow path) | `buyer_matches` = COUNT(DISTINCT contact) over score 50–100 (:678-680); `match_score` = top score (:681-683); buyer-mode default = selected buyer's score (:688-690); score band (:666-673) |
| UI sort control | `_listing-row` header `_listings.blade.php:35-41`: Last seen, First seen, Price, Suburb, Buyer demand |
| "Pitch now · high" / "Pitch now" | **filters**, not ranks: `ProspectingActionPresetService::applyPreset` (called controller :2840-2850): no active claim + strong count ≥ `high_value_strong_min` (default 3, `SuggestedActionThresholds.php:42,103`) / 1…min−1; tile counts :2990-3131 |
| Fast path | `ProspectingListingPageResolver::canUseFastPath` :52-78 (SQL pagination at group level); any buyer-derived sort or score band falls to the slow path (full set into PHP) |
| Grouping | `COALESCE(property_group_id, CONCAT('single_', id))`; representative = first row under the sort |
| Opportunities tab | view comment says "strong_match_count DESC", code orders `updated_at DESC` (:1570-1577; the strong sort timed out at 30 s, PHASE-9E-TODO) |

## 12. Performance (measured 2026-10-31, in-process on QA1, admin user 22, agency 1, rolled-back transaction; not browser/network time)

| Request | Total | Queries | SQL time | HTML |
|---|---|---|---|---|
| `/corex/market-intelligence/work` cold (empty cache) | **5.9 s** | 169 | 4.2 s | 1.2 MB |
| same, warm | **1.7 s** | 104 | 1.4 s | 1.2 MB |
| page 2, warm | 1.8 s | 99 | 1.4 s | 1.2 MB |
| `?sort=buyer_matches` | **4.8 s** | 104 | 2.0 s | 1.25 MB |

Heaviest queries: distinct buyer/listing count on `prospecting_buyer_matches` 767 ms (cold); `count(*)` of listings `pl` 431–445 ms every request; group-key page resolver 252–266 ms; distinct group count 214–220 ms; strong_count grouped 346 ms; buyer-sort aggregate 501 ms + 405 ms full-set read.
Row counts: prospecting_listings 47,990 · prospecting_buyer_matches 218,643 · tracked_properties 50,076 · tracked_property_external_refs 52,482 · portal_advert_facts 46,794 · p24_listings 14,092 · prospecting_price_history 3,663.
Indexes on `prospecting_listings` (35): PK; unique (agency_id, portal_source, portal_ref); (agency_id, deleted_at, last_seen_at); (agency_id, deleted_at, first_seen_at); (agency_id, listed_date); (agency_id, canonical_ref); (agency_id, normalized_address); (agency_id, possible_property_id); single-column suburb, price, property_type, is_active, portal_status, property_group_id, matched_property_id, tracked_property_id, pitched_at, lat+lng, etc. **No composite index for a rank by (is_active, buyer strength, price-change recency)**; `prospecting_buyer_matches` has the unique `pbm_listing_contact_unique` range scan used per page (~1,292 rows examined). Whole-agency "top 50 by strong then mid" over all 218k match rows: 0.30 s (0.43 s joined to active adverts) — i.e. a SQL-side rank is feasible; the PHP slow path is the cost.

## 13. Data we have but MIC does not use (consolidated)

1. Sold price/date on tracked files (1,825), CMA comps (3,545 priced; 1,726 in 24 m), `cma_value_*`, municipal valuation (4.2% of files), `presentation_sold_comps`, `property_sold_records`.
2. Deeds: bond amount/holder (478), sale type, registered date (1,929), title deed no., owners (3,424 rows, 1,934 linked contacts), ownership history.
3. Price history (3,663 changes, 1,840 active files), P24 alert reductions (2,456), `original_price` vs asking, `times_seen`, alert `is_mandated` (3.3%).
4. Per-advert facts stored but not rendered: erf/floor size, `price_changed_at`, `portal_status`, lat/lng, `listed_date` (empty).
5. Floor size for ~3,800 P24 adverts sits in `portal_listings.current_fields_json.size_m2` but never reaches `prospecting_listings`.
6. Shared pool (`portal_advert_facts` 46,794; `shared_properties` 37,370) incl. `seen_count` (18.1% seen >1×) and per-advert contribution rows.
7. Company-stock performance: `property_portal_metrics` (views/leads per day), `portal_leads` (932), listing cycles, sold stock, deals.
8. Buyer quality: `buyer_state`/temperature, lost records, activity — the stored counts ignore it.
9. Claims/work: `tracked_property_agency_work` status/reason, comments, pitch history as ranking inputs.

## 14. Defects found on the way (report only — would distort any ranking built on these columns)

| # | Defect | Evidence |
|---|---|---|
| 1 | P24 tile type guess: "townhouse" → "House" | App. A §1a (`content-p24-detail.js:48-53`) |
| 2 | Re-sighting overwrites address / suburb / type / agency name with blanks | `ProspectingApiController.php:130-134, 202-213` |
| 3 | `/to-rent/` search pages imported as `sale`, rent stored in `price` | App. A §1b; all 47,990 rows are 'sale' |
| 4 | PP never sends status; PP sold/under-offer only via absence sweep | App. A §2 |
| 5 | `property_size_m2` and `agent_name` always empty from searches | §1 |
| 6 | Synthetic Map-click listings (price 0, no URL) live in the same table | MapActivityController.php:553 |
| 7 | `tracked_properties.last_known_sold_date` has a `1900-01-01` sentinel; `ownership_parse_status` is 'ok' on all rows (column default) | SQL + App. B §6.1 |
| 8 | Stored `r_per_m2` matches price/extent on only 1,965 of 3,697 rows; `uvongo` / `uvongo beach` split | App. B §5.1 |
| 9 | Alert fields attached to Work rows but rendered nowhere | §2, §3 |
| 10 | Buyer match cache includes cold/lost buyers and is stale for 49,936 rows; bulk Chrome imports bypass the rescoring observer | App. C |
| 11 | Older extension builds (3.9.2 download, 3.10.0 zip) may still be in agents' hands; field set per build unverified | App. A §0 |

## 15. What I did not verify
Browser/network page-load time (timings are server-side, in-process); live P24/PP page contents (the "visible but not sent" lists for search tiles are inferred from code, not fixtures — App. A flags each); PP alert mail format; calendar viewings; Staging/live counts (QA1 only, as asked); whether MIC filters on `portal_status` beyond `is_active`.

## 16. "Hot pocket" tiles H1–H6 — can our data power each one? (added 2026-10-31, QA1, agency 1)

Run times are MySQL `SHOW PROFILES` durations on QA1 (one query each, live, no cache). "Pocket" = suburb + type + beds + price band.

**Verdict table**

| Tile | Computable today? | Reliable? | Run time | Nightly cache? |
|---|---|---|---|---|
| H1 Long on the market | Counts yes; **true age no** | **Not reliable**: age = our first capture, not the advert's listing date | 0.04–0.15 s per aggregate | Not for speed; yes if we correct for stale/crawl artefacts |
| H2 Where we have most stock | **Yes** | Yes (suburb text needs normalising) | 0.03–0.04 s | No |
| H3 Our fastest movers | Only on a **thin** set (22–86 sales) | Weak: no reliable sold date | 0.002–0.14 s | Yes (audit-log scan grows) |
| H4 Similar to fast movers | Mechanically yes | Only as good as H3 (37 pockets from 74 sales) | 0.15 s | With H3 |
| H5 Portal leads per property | **Yes** (own stock only) | **Yes** (link verified) | 0.006–0.2 s | No |
| H6 Buyer demand vs supply | **Yes** | Good (369 active sale wishlists) | 0.12 s | Optional |

### H1 — Long on the market (all portal stock)
| Item | Fact |
|---|---|
| Age sources | `prospecting_listings.first_seen_at` (100%), `listed_date` **0%** (21,115 active, 0 with a date), P24 JSON-LD `datePosted` reaches us only via 82 old-extension `portal_captures` and `ListedDateRecorder` (`app/Services/Prospecting/ListedDateRecorder.php`) which only updates adverts already in MIC — none matched. PP listing date: not captured at all. |
| Why `first_seen_at` misleads | Capture started 2026-03-18 (max measurable age **227 days; a 365-day tile is impossible**). 31,225 of 47,990 adverts were first seen in **Aug 2026** (bulk crawl), 6,138 in Mar. Only adverts first seen from 2026-09-01 have a genuine "new since" date: 7,170 (6,566 still active). |
| Stale "active" | 8,897 of 14,503 active **P24** adverts were not seen in 14+ days (PP: 943 of 6,612); `is_active` is only flipped by a sighting or a complete suburb reconcile. |
| Age buckets (active, by first seen) | 0–29 d: 3,621 · 30–89: 15,429 · 90–179: 806 · 180+: 1,259 |
| By suburb ≥90 d (top) | Margate 147 of 1,796 · Zimbali Lakes 134/556 · Zimbali Estate 125/383 · Port Edward 115/592 · Umhlanga Central 87/860 · Ramsgate 81/948 |
| By type ≥90 d | Apartment 708 of 8,548 · House 650/7,026 · Vacant land 526/2,829 · Land 73/1,022 · Townhouse 21/775 |
| By price band ≥90 d | <1m 576/6,917 · 1–2m 406/5,478 · 2–3m 278/2,908 · 3–5m 335/2,713 · 5m+ 468/2,969 |
| Price reductions | Active adverts with ≥1 cut: **1,566** (30 d: 847 · 90 d: 1,518 · 2+ cuts: 127), from `prospecting_price_history` (3,663 rows). Also `p24_price_changes` 2,456 (cuts 2,326). History only starts at the first *change* (no first-price row). |
| Re-listed (same property, new advert number) | `tracked_property_id` with ≥2 adverts on the same portal: **1,821** files; 659 show the re-list pattern (one inactive + one active). Adverts per file: 1 = 38,520 · 2 = 1,607 · 3 = 495 · 4 = 249 · 5+ = 193. |
| To make it reliable | Backfill listed date from detail pages / datePosted for the active set, or restrict to "first seen since 1 Sep" and show "seen N days" honestly; treat adverts unseen for 14 d as "possibly gone". |

### H2 — Where OUR agency has most stock (`properties`, agency 1, sale)
| Cut | Result |
|---|---|
| Totals (5,937) | on market **165** (active 154 + under offer 11) · sold 518 · withdrawn 2,564 · expired 991 · prospecting/not-selling 1,606 · draft 63 · other 30 |
| By suburb (on market / sold / withdrawn / total) | Margate 32 / 74 / 493 / 864 · Ramsgate 19 / 53 / 239 / 434 · Shelly Beach 19 / 45 / 225 / 407 · Manaba Beach 18 / 46 / 126 / 368 · Uvongo Beach 10 / 23 / 74 / 189 · Southbroom 10 / 18 / 67 / 123 · Uvongo 9 / 69 / 330 / 815 · Trafalgar 9 / 10 / 39 / 593 |
| By type (on market / sold) | Apartment/Flat 67 / 212 · House 37 / 159 · Vacant Land 33 / 49 · Townhouse 24 / 94 · Commercial 3 / 4 |
| By price band (on market / sold) | <1m 77 / 322 · 1–2m 53 / 169 · 2–3m 18 / 21 · 3–5m 8 / 4 · 5m+ 9 / 2 · no price 0 / 0 (1,493 rows: prospecting) |
| By beds (on market / sold) | 3 bed 52 / 182 · 2 bed 47 / 168 · 0 (land etc.) 38 / 59 · 4 bed 14 / 56 · 5 bed 5 / 24 · 1 bed 4 / 20 |
| Caveats | `properties.suburb` is mixed-case free text ("MARGATE" / "Margate") — group by `suburb_normalised` or `p24_suburb_id`; "sold" is whatever status says (518, mostly imported). Fill: price 74.9%, beds 100%, type 99.8%, suburb 99.8%. |

### H3 — Our stock that moved quickest (listed → under offer / sold)
| Source | Rows | What it gives | Reliable? |
|---|---|---|---|
| `properties.listed_date` | 422 of 5,937 (7.1%); active 139/154, sold 85/518, under offer 11/11 | start of the clock | only for current/recent stock |
| `properties` sold date | **no column** (`portal_sold_until` only) | — | — |
| `property_audit_log` `status_changed` | 972 events 2026-05-27 →; **4,978 `property_created` on 2026-06-25** = bulk import day, so anything before 26 Jun is import noise | real-time timestamps of →under_offer (42) / →sold (89, mostly import) | from 26 Jun onward only |
| genuine active → under_offer/sold pairs (audit, post-import) | **22 properties**, avg **37.8 days** (0–96) | days to sale | thin |
| `listed_date` + post-import audit sale event | **92 properties** | days to sale | usable, small |
| `sold_stock` (AT-512) | 519; `listed_date` 86; **sale_date = 2026-09-17 on 434 rows (backfill day)**; price on 11 | with real dates: 74 rows, avg **30 d** (apartments 23 d / 34 sales, houses 24 d / 22, townhouses 33 d / 24); Uvongo 43 d, Shelly Beach 20 d, Manaba Beach 16 d, Margate 25 d, Ramsgate 23 d | only the ~74–86 non-backfill rows. Backfill rule: audit "Sold" moment else `updated_at` (`SoldStockService.php:~320-335`) |
| `deals` (agency 1) | 176; `sale_date` **0**, `registration_date` 43, only **29** link a property, 3 of those registered | registration, not sale agreement | no |
| `property_sold_records` | 226 (days_on_market on 1; sold price = asking) | — | no |
| `property_listing_cycles` | 519, all `status_at_start=draft`, `ended_at` never set | — | no |

**Is there a reliable sold date? No.** The only trustworthy sale-side timestamps are audit events since 26 Jun and the ~80 non-backfill sold_stock rows. Capturing offer-accepted / sale-agreement dates going forward (audit already records status flips) will make this real within weeks.

### H4 — Similar to fast movers (match a pocket to non-company adverts in MIC)
Fast mover = sold within 45 days of listing, post-import dates only: **74 sales → 37 pockets** (suburb + type + beds + price band) that **match 1,938 active non-company adverts**; coarse (suburb + beds): 24 pockets from 51 sales. 0.15 s. Blockers: (1) vocab: properties use "Apartment / Flat", MIC adverts use Apartment / Flat / Simplex / Duplex / House / Townhouse / Vacant land / Land / Commercial — needs a mapping table (my test map left Flat/Simplex/Duplex unmapped, so 1,938 is a floor); (2) suburb text differs in case/spelling and the tables use different collations (a text join errors without a cast), so join on `p24_suburb_id` / normalised suburb; (3) evidence per pocket is 1–3 sales — present as "pocket", not proof.

### H5 — Portal leads per property
| Item | Fact |
|---|---|
| Store | `portal_leads` (agency 1): 922 non-test leads, 2026-05-20 → 2026-10-22: P24 616 · PP 243 · website 63. Source constants `BuyerLeadCascadeService::SOURCE_PORTAL_P24/PP/WEBSITE` (`app/Services/Buyers/BuyerLeadCascadeService.php:34-36`); P24 leads written by `P24LeadService` (`app/Services/Syndication/Property24/P24LeadService.php:180`) |
| Property link | `listing_id` → `properties.id`: 900 of 922 linked (22 not). **Verified reliable:** `listing_portal_ref` equals the property's own P24 ref on 603 of 611 P24 leads and PP ref on 226 of 226 PP leads; all 900 belong to agency 1. |
| Coverage | 190 properties (491 leads on sale stock, 409 on rentals); 310 leads on properties on market now. **Own stock only — no leads exist for market adverts.** |
| Per suburb (sale) leads / listings / per listing | Shelly Beach 104 / 17 / 6.1 · Ramsgate 67 / 19 / 3.5 · Uvongo 57 / 12 / 4.8 · Margate 50 / 16 / 3.1 · Glenmore 35 / 6 / 5.8 · Southbroom 29 / 14 / 2.1 |
| Per type (sale) | Townhouse 157 leads / 32 listings · Apartment 137 / 47 · House 109 / 37 · Land 83 / 24 |
| Per listing-week | 512 property-weeks have leads, avg 1.76 (max 18). Weeks with zero leads need an "active weeks" denominator (listed_date / audit). **Better source: `property_portal_metrics`** — 41,204 daily rows, 302 properties, 2026-01-08 → 2026-10-21 (P24 30,659 rows, PP 10,545), views 218,566, leads 1,469 (tel/sms/request/total) — gives zero-lead days for per-week rates; 175 properties are in both stores. |
| Response tracking | `first_response_at` set on only 4 of 922 leads. |
| Run time | 0.006–0.011 s per leads aggregate; 0.2 s for the 41k-row metrics scan. |

### H6 — Buyer demand vs our stock vs market stock
| Item | Fact |
|---|---|
| Buyers | `contacts.is_buyer`: 717 (new 380 · warm 66 · cold 45 · lost 226). Wishlists `contact_matches` (agency 1): 369 active sale, 267 active rental, 193 expired sale. |
| Wishlist fill (active sale) | suburbs 330 (85 list more than one) · price max 354 · beds_min 276 · type 363. Columns: `suburbs` JSON of names, `p24_suburb_ids` JSON, `property_type(s)`, `price_min/max`, `beds_min`, `bedrooms_max`. |
| Demand by suburb (new+warm buyers, active sale wishlists) vs own on-market vs market adverts | Shelly Beach 37 / 19 / 813 · Ramsgate 36 / 19 / 948 · Uvongo 25 / 9 / 887 · Uvongo Beach 24 / 10 / 332 · Southbroom 24 / 10 / 211 · Manaba Beach 18 / 18 / 610 · St Michaels 17 / 6 / 178 · Beacon Rocks 16 / 1 / 111 · Margate 15 / 32 / 1,796 · Marina Beach 13 / 6 / 142. Highest buyers per advert: Beacon Rocks 0.143, Southbroom 0.109, St Michaels 0.092, Marina Beach 0.088. |
| Finer pocket (suburb + type + beds_min) | 168 pockets with demand, **31 with ≥3 buyers**. |
| Run time | 0.12 s (JSON_TABLE expansion of suburbs) for suburb level; 0.005 s finer pocket count. |
| Caveats | Wishlists give ranges/minimums, not bands — pocket assignment needs a rule (a buyer counts in every band their range overlaps); supply side is raw adverts (including land and stale-active), so the "gap" should use cleaned market stock (fix H1 staleness first). Text-column collations differ between contacts/matches and properties/listings: use explicit conversion or ids. |

### Cache recommendation
No aggregate above is slow at today's size (all ≤ 0.2 s). A nightly table is still worth it for **correctness**, not speed: one `mic_pocket_stats` row per (agency, suburb_normalised, type, beds, price band, date) holding market_stock, aged_90/180, cut_30/90, relisted, own_on_market, own_fast_sales, buyers, leads_30d. Reasons: H1 needs a "stale-corrected" active flag and a "first seen since stable crawl" rule applied consistently; H3 reads a JSON-scanned audit log that keeps growing and should freeze each sale's days-to-sale once; H4/H6 need the shared type/suburb/band mapping applied once. H2 and H5 can stay live.

---
# Appendix A — Browser extension capture (sub-report)
# Chrome extension capture inventory (item 4) - read-only, QA1 @ 8e46774c3

Paths: EXT = /corex-qa1/public/chrome-extension/portal-capture  |  SRV = /corex-qa1/app
Nothing was unzipped or executed: the extension source is checked out as plain files.

## 0. Which extension is "the" extension

| Artifact | Version | Status |
|---|---|---|
| EXT/ (public/chrome-extension/portal-capture) | manifest 3.12.0 | SOURCE OF TRUTH. `scripts/package-chrome-extension.sh:6-9` zips only this dir. |
| /corex-qa1/public/downloads/portal-capture-extension.zip | 3.9.2 (Oct 8) | download link copy, OLDER than source |
| /corex-qa1/storage/app/corex-extension.zip | 3.10.0 (Oct 12) | older than source |
| /corex-qa1/chrome-extension/portal-capture/ | 3.3.6 "Portal Capture for HFC" | STALE duplicate (script comment `package-chrome-extension.sh:11-20`). Posts whole page HTML to `/portal-captures/ingest` (popup.js:254, background.js:120). The 3.12.0 extension NEVER calls that route (only a comment, EXT/background.js:1133). Anyone still on 3.3.x feeds portal_captures; nobody on 3.12 does. |

3.12.0 manifest (EXT/manifest.json): content scripts on property24.com/* (content-p24-detail.js), privateproperty.co.za/* (content-pp.js), cmainfo.co.za/Mapping/PropSearch.aspx* (content-cmainfo.js), thevirtualagent.co.za Search/Person + Search/Company (content-tva*.js). The content scripts only SCRAPE and answer chrome messages; all POSTs are made by background.js (service worker) or popup.js.

### Endpoints the 3.12.0 extension hits (all Bearer-token, `auth:sanctum`, `throttle:staff-api` group routes/api.php:507, per-user limiter >=600/min AppServiceProvider.php:1195)
| Flow | URL | Route / controller |
|---|---|---|
| MIC "Capture Listings" (search pages, P24+PP) | POST /api/prospecting/import (EXT/background.js:1051) | routes/api.php:682 (v1) and :1442 (legacy alias) -> `Api\ProspectingApiController@import` |
| duplicate-search check / health | GET /api/prospecting/check-search (background.js:498, 519) | api.php:683, :1443 -> `@checkSearch` |
| Pull Property (UI button hidden: popup.js:158 `PULL_PROPERTY_ACTION_ENABLED=false`) | POST /api/properties/pull-from-portal (background.js:1085) | api.php:707 -> `Api\PropertyPullController@pullFromPortal` |
| Other Agency Stock (P24 + PP listing page) | POST /api/v1/other-agency-stock/import, GET .../consent-wording (background.js:1136,1145) | api.php:518-521 -> `Api\OtherAgencyStockImportController` |
| CMA deeds | POST /api/v1/deeds-capture, /deeds-capture/check-duplicate, /deeds-capture/check-duplicate/decision (background.js:1222,1287,1328) | api.php:689-695 -> `Api\DeedsCaptureController` |
| TVA person contacts / company directors | POST /api/v1/tva-contact-capture, /tva-company-directors (background.js:1362,1427) | api.php:698,702 |
| Desk (support session tab share) | desk-ext.js etc. | out of scope |

**Only the first row feeds MIC (`prospecting_listings`).** Listing-DETAIL pages (P24/PP) never feed MIC directly; they only create `properties` rows (Pull / OAS) and, as a side effect, back-fill `prospecting_listings.listed_date` (section 4).

---

## 1. Property24 SEARCH-RESULTS page -> /api/prospecting/import  (the MIC capture)

Flow: popup startCapture (popup.js:~410) -> background.js `runCaptureLoop` (694-865). Page 1 read from the live tab via content-script message `getListings` (background.js:722); pages 2..N: the tab is navigated to `/pN` (buildPageUrl 264-283) and the content script re-run (`getListingsFromTab` 287-310). Batches of >=100 listings (777-784), 1.5 s between pages (788-791), last page held back for the `capture_complete` batch (799-811). Server: `SRV/Http/Controllers/Api/ProspectingApiController.php` (SRV-PAC).

### 1a. Fields POSTed per P24 listing (EXT/content-p24-detail.js `extractListing` 178-291; page filter `extractAllListings` 293-319)

| Payload key | Scrape (selector) - file:line | Server validate (SRV-PAC) | Stored in |
|---|---|---|---|
| portal_ref `P24-<digits>` | digits from tile URL path segment (>=6 digits), else `data-listing-number` stripped of non-digits; cards `[data-listing-number]`, skips `.p24_development` - cps:208-221, 293-313 | :33 nullable string; row SKIPPED if empty :113 | prospecting_listings.portal_ref (create :245); + canonical_ref via `HasCanonicalPortalRef` trait (Models/ProspectingListing.php:15-26) |
| portal_url | `a.p24_content` href, else any `a[href*="/for-sale/"],[href*="/to-rent/"]` - :192-201 | :36 | prospecting_listings.portal_url (varchar 500) - overwritten every sighting :213 |
| address | `.p24_address` text; null when absent (no placeholder) - :239-242 | :34; blank / "Address not available" -> NULL, cut 255 :127-129 | prospecting_listings.address :202/:247; also TP fact `address` (token-overlap match only) + street_number/street_name/unit_number/complex_name parsed by `AddressLineFacts::split` :552-562 |
| suburb | `.p24_location` text; fallback = 2nd URL path segment title-cased - :238, 244-249 | :37, cut 100 :130 | prospecting_listings.suburb (NOT NULL, '' allowed); TP.suburb |
| price | `p24PriceFrom(card)` = max of `.p24_price[content]` numeric attr, else distinct number groups in text; plausible 100k-500M - :23-42, 223-225 | :35 `integer` | prospecting_listings.price; TP.last_known_asking_price (:571) |
| bedrooms | title regex `^(\d+)\s+Bedroom` (:230-232), then `.p24_featureDetails[title="Bedrooms"] span` (:252) | :39 | prospecting_listings.bedrooms; TP.bedrooms |
| bathrooms | `.p24_featureDetails[title="Bathrooms"] span` :253 | :40 | .bathrooms; TP.bathrooms |
| garages | `.p24_featureDetails[title="Parking Spaces"] span` :254 (P24's "Parking Spaces" = garages+parking combined, per comment popup.js:986) | :41 | .garages; TP.garages |
| erf_size_m2 | `.p24_size span` regex `m²` :257-260 (on apartments/townhouses this is the FLOOR size, see popup.js:1004 note) | :43 | .erf_size_m2; TP.erf_size_m2 |
| property_size_m2 | NEVER set by P24 tile scraper (hard null :181) | :42 | column always NULL from this path |
| property_type | first keyword of PROPERTY_TYPES found in `.p24_title` (:48-53, 233-234) - **BUG: list order tests 'house' before 'townhouse', and "townhouse" contains "house" -> townhouses stored as "House"** (code-verified, not run) | :44, cut 50 :132 | .property_type; TP.property_type |
| agent_name | never set (null :183) | :45 | column always NULL/'' |
| agency_name | `.p24_branding img[alt]` :262 | :46, cut 100 | .agency_name |
| thumbnail_url | `img.js_P24_listingImage` data-original / lazy-src (else src if real http, not blank.gif), fallback `.p24_image img[src]` - :264-285 | :47 | NOT a column. prospecting_listings.thumbnail_source_url (:229/:284), then job `DownloadListingThumbnail` writes thumbnail_path (file) |
| portal_status | ribbon/badge text keyword match -> sold / under_offer / withdrawn / active - :161-176, 288 | :55 in(active,under_offer,sold,withdrawn) | .portal_status, .portal_status_changed_at, .is_active, .off_market_at (:139-175, 267-270) |
| source | 'p24' (listing level) | not validated at listing level | DROPPED (envelope `source` :23 is used -> portal_source) |

Envelope (background.js:597-603, 695-704): `source`, `search_context{url, search_term, total_results, pages_captured, captured_at, capture_complete}`, `listings[]`.
| Envelope key | Source | Stored |
|---|---|---|
| search_context.url | base search URL (h1 / tab url) | prospecting_searches.search_url :89 |
| search_term | `h1` text / title (cps:90-96) | prospecting_searches.search_description :90 |
| total_results | `.p24_topPager` "of N" (cps:98-123) | prospecting_searches.total_results |
| pages_captured | loop counter | prospecting_searches.pages_captured (max() on re-send :81) |
| capture_complete | true only if every page walked, parseWarnings==0, not cancelled (background.js:807-809) | NOT stored; triggers `SuburbReconcileService` (:336-346) |
| captured_at | client clock | **dropped** (not in validate list; server uses now()) |

### 1b. Visible on a P24 search tile but NOT sent
Evidence quality: no real P24 search-page HTML is saved in the repo (tests/Fixtures/property24_search.html is a 3 KB hand-made stub). So this list is **inferred from what the code does/does not read plus `Property24SearchExtractorV1` and notes**, not from a fixture.

| Item | Status |
|---|---|
| Listing agent name / photo on tile | inferred - typical on P24 tiles; the tile scraper has no agent selector (agent_name hard null) |
| Property type as a clean field (it is only guessed from title text) | verified from code |
| Floor size vs erf size distinction (`.p24_size` is sent only as erf_size_m2) | verified from code + popup.js:1004 comment |
| Parking vs garages split (only combined "Parking Spaces") | verified from code |
| Sale vs to-rent flag: `/to-rent/` search pages are accepted (cps:77, 192-198) and imported, listing_type NOT sent; column defaults to 'sale' (migration 2026_10_29_120100) | verified from code |
| Listed / "added" date on tile | Johan 13 Oct: tiles carry none (`ListedDateRecorder.php:7-9`) - verified from repo note |
| Development banners (skipped on purpose :302) | verified from code |
| Tile ribbons other than sold/offer ("New", "Price reduced", "Show day") | inferred - not verified |
| Number of photos on tile, video/3D-tour icon | inferred - not verified |
| P24 street address is mostly absent on tiles (hence "PULL-ALL" null addresses :240-242) | verified from code comments |

### 1c. Sent but dropped / stored only in a blob
| Item | Where |
|---|---|
| search_context.captured_at; listing-level `source` | dropped by `validate()` (SRV-PAC:22-56 whitelist) |
| district, mandate_type, listing_type | accepted/known by server (:38, :51) but the extension NEVER sends them -> NULL / default 'sale' |
| thumbnail image bytes | job-downloaded; only `thumbnail_source_url` (varchar 255) kept; on a re-sight the URL is only re-saved when `thumbnail_path` is empty (:226-232), so a changed photo is never re-fetched |
| Facts passed to the property file (TP) | only: address(match-only), street_number, street_name, unit_number, complex_name, suburb, property_type, bedrooms, bathrooms, garages, floor_size_m2 (=property_size_m2, always null), erf_size_m2, last_known_asking_price (SRV-PAC:556-572). agent/agency name, portal_url, portal_status are NOT passed on. |
| TP audit trail | `tracked_properties.source_chain` JSON entry = {type, ref, date, fields_contributed[key names only, no values]} (TrackedPropertyMatchOrCreateService.php:~1975, 2143); `tracked_property_external_refs.source_payload` JSON = {prospecting_listing_id} only (SRV-PAC:576) |
| Shared pool | `SharedPortalPool::contribute` (PropertyIdentityResolver.php:61) receives the TP facts above -> portal_advert_facts: address_text, street parts, suburb, price (+price_history JSON), beds/baths/garages, sizes, property_type. listing_agent_name / listing_agency_name / status columns exist but this path never supplies them. |

---

## 2. Private Property SEARCH-RESULTS page -> /api/prospecting/import

Scraper: EXT/content-pp.js `extractAllListings` 206-350 (one pass over every `a[href*="/for-sale/"|"/to-rent/"]` ending `/T<digits>`; the `<a>` is the card). PP uses hashed React classes, so almost everything is regex/text based. Same endpoint, same server code, same table as section 1.

| Payload key | Scrape - file:line | Notes / stored |
|---|---|---|
| portal_ref `PP-T<digits>` | href regex `/(T\d{5,})/?$` :216-221 | prospecting_listings.portal_ref |
| portal_url | href, prefixed `https://www.privateproperty.co.za` :332 | .portal_url |
| address | **derived from URL path segments** (segments with digits / `ss-`/`bc-` between suburb and ref) `extractAddressFromUrl` :175-203; often null | .address |
| suburb | last non-numeric URL segment before the address parts :194-196 | .suburb |
| price | `ppPriceFrom`: `.featured-listing__price` first, then `[class*=price]`, then whole card text; "R" + SA grouping check; 100k-500M - :47-65, 233-250 | .price; TP.last_known_asking_price |
| bedrooms, property_type | card text regex `(\d+) Bedroom (House\|Apartment\|Townhouse\|Flat\|Duplex\|Simplex)`; else "NNN m² Land" -> 'Land' :256-263; bedrooms also `[title="Bedrooms"]` :272 | .bedrooms, .property_type |
| bathrooms | `[title="Bathrooms"]` :277 | .bathrooms |
| garages | `[title="Parking spaces"]` :282 | .garages |
| erf_size_m2 | `[title="Land size"],[title="Floor size"]` -> number before "m" :287-291 (floor size is ALSO stored as erf) | .erf_size_m2 |
| property_size_m2 | hard null :339 | always NULL |
| agent_name | hard null :342 | always NULL |
| agency_name | last `img[alt]` in card that is not "Bedroom"/"Land", len 4-59 :296-306 (can pick up non-agency alt text) | .agency_name |
| thumbnail_url | `.featured-listing__main-image, .listing-result__image, img[src*=images.prop24], img[src*=images.pp.co.za]`, fallback any img :320-328 | thumbnail_source_url -> job |
| source 'pp' | :345 | envelope source -> portal_source |
| portal_status | **NOT sent by PP** (no status code in content-pp.js) | .portal_status stays NULL; server treats NULL as "active" (SRV-PAC:173-175). PP sold/under-offer never detected except by the suburb-reconcile absence sweep. |
| search context | h1; "of N results" regex :114-122; pages from `page=` links :139-148 | prospecting_searches |

Background fallback scraper (only when the content script can't answer for page 1 or `handleFetchPage`): EXT/background.js:887-972 (`extractPPListing`, generic `[class*=...]` selectors; also reads agent_name/agency_name/property_type badges). Not the normal path.

### 2b. Visible on PP cards but not sent (all "inferred - typical, not verified": no PP search HTML fixture exists; repo only has tests/Fixtures/pp-alert-sample.html = an email alert)
Agent name, listed/"new" badge, number of photos, description snippet, floor-size vs land-size split on the same card, "sold"/"under offer" ribbon, show-day. Verified from code: no status, no agent, no floor size (property_size_m2 hard null), no listing_type (to-rent URLs accepted, stored as 'sale').

---

## 3. Server rule: dedupe / upsert / re-seen listing (SRV-PAC)

| Question | Answer (file:line) |
|---|---|
| Unique key | `(agency_id, portal_source, portal_ref)` - UNIQUE `prospecting_listings_agency_id_portal_source_portal_ref_unique` (database/schema/mysql-schema.sql:14438; migration 2026_03_18_100000:38). Lookup :144-147. canonical_ref (e.g. p24:123) is derived but only indexed, not unique (migration 2026_10_29_500100). Unique index has no deleted_at, while the Eloquent lookup hides soft-deleted rows -> a trashed row with the same ref would hit the unique key on create; the per-row try/catch (:121,:291-299) swallows it into `skipped` (inferred from code, not run). |
| Agency | `$user->effectiveAgencyId()`, 422 `MissingAgencyContextException` if none :63-67 |
| Search row | one `prospecting_searches` row per (agency, search_url, calendar day); re-send updates total_results, pages_captured=max, description :72-96. listing_count incremented atomically by imported+updated :306-308. |
| New listing | create with first_seen_at = last_seen_at = now, last_search_id, is_active = !offMarket :241-271; then assignPropertyGroup (:273, 499-530), linkToTrackedProperty (:274, 543-594), thumbnail job. NO price-history row on create. |
| Re-seen listing (`$existing`) | last_seen_at = now :150; last_search_id = this search :152; portal_status / changed_at / is_active / off_market_at driven by portal_status when sent (NULL status => is_active=true, :158-175); **price**: only if new price non-null and different :177-200 -> inserts `prospecting_price_history(old_price,new_price,changed_at)` + sets price + price_changed_at; guard: change >=4x or <=1/4 is quarantined in `prospecting_price_anomalies` and the stored price kept :181-187, 394-494 (decimal-strip self-correction exempt :434). first_seen_at, listed_date, listed_date_source, mandate_type(if not sent) untouched. |
| Overwrite behaviour on re-seen (**data-loss risks, code-verified**) | `address` and `portal_url` are written unconditionally (:202, :213) -> a later capture with no address NULLs a stored address. `suburb/district/property_type/agent_name/agency_name` are pre-coerced to '' at :130-134, so the `?? $existing->x` fallbacks (:203-212) never fire -> a tile lacking agency_name or property_type overwrites the stored value with ''. bedrooms/bathrooms/garages/sizes use `?? existing` correctly (null does not wipe). |
| Off-market | sold/under_offer/withdrawn -> is_active=false, off_market_at set once, cached `prospecting_buyer_matches` for it deleted :163-168, 315-326. A later active sighting revives (:169-172). |
| Reconcile | final batch with capture_complete=true -> `SuburbReconcileService` marks still-active listings of the captured suburb(s) not seen in this search (last_search_id != search id) as is_active=false, portal_status='withdrawn' (soft). Never on partial batches. |
| Throttle | Server: `throttle:staff-api` per user (>=600/min). No per-listing throttle. Extension: 100-listing batches, 1.5 s between pages, 10 s/3 s single retry on 403/429/network (background.js:556-575), durable IndexedDB queue replay (`queue-idb.js`, flush 445-472), advisory "already captured today" via check-search (SRV-PAC:600-634) - client warning only, server re-processes anyway. |
| Per-row failure | one bad row increments `skipped`, batch continues :291-299. |
| History kept per sighting? | No per-sighting row for MIC listings: only last_seen_at, last_search_id, price_history (on change), prospecting_searches aggregate. (The legacy portal_listing_observations table, with an observation per capture and TRACKED_FIELDS price/beds/baths/parking/size/erf/title, is fed ONLY by the old 3.3.x HTML-upload path - see section 6.) |

---

## 4. Property24 LISTING-DETAIL page (and PP detail) - agent-initiated imports, not MIC

### 4a. P24 detail scrape A: `extractPropertyDetail` (EXT/content-p24-detail.js:355-657) -> POST /api/properties/pull-from-portal (button currently hidden)
Keys sent: portal_ref (`P24-<n>` from listingLeadFormContext or URL), portal_url, title, description, price, address, suburb, city, region, beds, baths, garages, erf_size_m2, size_m2, property_type, features[], images[] (JSON-LD), first_image_id, image_count, agent_name, agency_name, source, listing_type, property_type_raw, property_type_label_hint, p24_suburb_external_id, levy, rates_taxes, date_posted ("Listing Date" row), pets_allowed, zone_type_raw, parking_count, pool, kitchen_features, garden_features, security_features, bathroom_features, parking_features. Server validate: PropertyPullController.php:24-78 -> `properties` (+ listed_date, spaces_json, notes). Calls `ListedDateRecorder::record` when date_posted+portal_ref (PropertyPullController.php:~213-220).

### 4b. P24 detail scrape B: `p24ExtractOasFn` (EXT/popup.js:667-1148, run through chrome.scripting) -> POST /api/v1/other-agency-stock/import
Payload (popup.js:1078-1147): portal, listing_ref, listing_url, price, description, listing_title, listing_type, property_type_raw, property_type_label_hint, beds, baths (decimal ok), garages, size_m2, erf_size_m2, suburb, province, street_address, latitude, longitude (JSON-LD about.latitude/longitude when P24 shows them), p24_suburb_external_id, image_ids[] (ordered thumbnail strip), first_image_id, image_count, source_agency_name, source_agent_name, source_agent_profile_url, source_agent_image_url, source_agency_logo_url, date_posted, levy, rates_taxes, zone_type_raw, pets_allowed, parking_count, parking_features, pool, garden, kitchen_features, garden_features, feature_rows[{s,k,v[]}] (EVERY row of every accordion except Points of Interest), strip_tags[], bathroom_features. Server validate: OtherAgencyStockImportController.php (rules ~100-190; source_agent_phone/email accepted but extension never sends them) -> `OtherAgencyStockImportService` -> `properties`. Then `ListedDateRecorder::record` (controller :208).

### 4c. PP detail: `ppExtractOasFn` (popup.js:1151-1253, MAIN world, reads `window.serverVariables.bundleParams`)
portal, listing_ref (T-number), listing_url, price, description, listing_type, property_type_raw, beds, baths, garages (from `additionalProperty`), size_m2, erf_size_m2 (`.property-details__list-item` rows), suburb (suburbName), latitude, longitude (mapCoOrdinates), photos[] (galleryPhotos mediumUrl), source_agency_name, source_agent_name, source_agent_profile_url, source_agent_image_url, source_agency_logo_url, listing_title. No date_posted from PP. **By code reading, PP detail pages cannot reach this flow**: content-pp.js has no `getPageType` handler (only getPageInfo/getListings, :353-364), so popup.js:1682-1690 sees isDetail=false and disables all three buttons (popup.js:1722-1726). Inferred, not run.

### 4d. Verified-from-fixture visible-but-unsent on P24 detail pages
Fixtures: EXT/tests/fixtures/p24/*.html (7 real saved pages) + *.payload.json (the extractor output). Compared against page content:
| Visible on page | Sent? | Evidence |
|---|---|---|
| Listing Number, Type of Property, Listing Date, Floor/Erf Size, Rates, Levies, Pets, Furnished, Rooms/Building/External/Other-features rows, strip tags | yes via feature_rows/strip_tags (raw) | payload.json |
| Second/third agent of the listing (listingLeadFormContext.agentDetails[] has 2 agents on 117580701) | NO - only primaryAgent or agentDetails[0] sent (popup.js:1121-1124) | fixture HTML |
| Agent job title (e.g. "Candidate Property Practitioner"), agent id, WhatsApp/phone flags | NO | fixture HTML `agentDetails` JSON |
| Agent phone number | not in DOM (token-gated: `canShowAgentContactNumber`, `tokenString`) - unreachable | fixture |
| YouTube video id (`p24_youtube` embed) | NO | fixture HTML |
| P24 internal mandate id (`data-mandate-id`); actual mandate TYPE (sole/open) | NOT on page as a value (only a "Mandate (sole, dual, joint, etc.)" report-listing option) -> cannot be scraped; mandate_type stays empty everywhere | fixture HTML |
| JSON-LD breadcrumb (province/town hierarchy) | NO (only suburb/province used) | fixture JSON-LD |
| Points of Interest (nearby schools/shops) | skipped on purpose (loaded on demand) popup.js:928 | code |
| "Price per m²" row (1 of 7 pages) | yes inside feature_rows, but not mapped | payload.json |
| Lease Period / Occupation Date (rentals) | in feature_rows only | payload.json |
| Price history / views / days-on-market | NOT present in any of the 7 fixtures | grep of fixtures |

---

## 5. CMA Info (deeds) page -> /api/v1/deeds-capture   (feeds tracked_properties / contacts, not prospecting_listings)

Scrape: EXT/content-cmainfo.js (2260 lines), label-driven. Labels read: PROPERTY_INFORMATION_LABELS :75-135 (LPI Code, Deeds Office, Scheme no, Scheme name, Situated at, Section number, Flat/Unit no, Street number, Estate, Address, Erf no, Suburb, Municipality, Province, GPS, Section extent, Extent, Cadastral extent, Type, Usage); SALE_INFORMATION_LABELS :136-146 (Owner, Owner's ID, Sale Price, Sale Date, Registered Date, Title Deed, Bond Holder, Bond Amount, Sale Type). Payload builder `buildDeedsCapturePayload` :1590-~1700, pre-check payload :855-887.

Payload -> server validate DeedsCaptureController.php:83-150:
source_ref (cmainfo:<lpi>), property{deeds_office, scheme_name, scheme_number, section_number, erf_number, lpi_code, cma_street_number, situated_at, estate, address, street_number, street_name, unit_number, complex_name, suburb, municipality, province, latitude, longitude, section_extent_m2, erf_extent_m2, cadastral_extent_m2, property_type, title_deed_number}, owners[{name, surname, first_names, id_number, id_type}], ownership_history_raw{owner_names, owner_ids, title_deeds}, sale{sale_price, sale_date, registered_date, bond_holder, bond_amount, sale_type}.
Stored (ingestOne :518-830): facts -> tracked_properties via matchOrCreate/enrich (deeds_capture source ALWAYS wins on every field it read): street/unit/complex, suburb, town(=municipality), province, lat/lng, erf_number, lpi_code, title_deed_number, erf_size_m2, cadastral_extent, section_extent_m2, property_type, deeds_office, scheme_name/number, section_number, last_known_sold_price/date, bond_holder, bond_amount, sale_type, deeds_registered_date; plus deeds_captured_at, deeds_captured_by_user_id, capture_kind; owners -> contacts + tracked_property_owners; ownership history -> OwnershipHistoryParser rows.
Read but NOT sent: `Usage` label (:133) - verified from code. `estate`, `cma_street_number`, `situated_at`, `municipality` are sent (municipality lands in TP.town; estate/situated_at/cma_street_number validated but only used for address/LPI parsing, not stored as their own columns - not traced further). Fixtures for the page live in EXT/tests/fixtures/content-cmainfo.v3.4.x.js (older scripts, not page HTML) - no saved CMA page, so other visible panels (e.g. Renovations panels mentioned in .ai/specs/SPEC_Portal_Scraping_Prospecting.md:~372) are "inferred - not verified".

TVA (EXT/content-tva.js, content-tva-company.js): person id_number, first_name, surname, consent_status, contacts[{type cell|tel|email, value, date, link_date, opted_out}] -> `tva_contact_captures` + `tva_contact_capture_items`, linked to tracked_property_id (TvaContactCaptureController.php:46-58, ~126-160); company registration_number/name + directors[{id_number, full_name, gender}] -> contacts. Contact data, not property listing data.

---

## 6. portal_captures / portal_listings / portal_listing_observations (the "Chrome capture ingest" tables)

| Item | Fact |
|---|---|
| Route | POST /portal-captures/ingest routes/web.php:8682, middleware `auth.portal_capture` + `permission:access_presentations` + `feature:presentations`; controller `Presentation\PortalCaptureController@ingest` (:22) |
| Caller | ONLY the STALE 3.3.x extension (chrome-extension/portal-capture/popup.js:254, background.js:120). Not the 3.12.0 extension. |
| Payload | source_site, page_type(search/property/unknown), source_url, final_url, page_title, captured_at, extractor_version, html (WHOLE page), screenshot (base64 PNG), parse_status, extracted_fields, jsonld[], found_image_urls[], presentation_id (PortalCaptureController.php:24-42) |
| Stored | portal_captures row (+ raw html file `portal_captures/<id>.html`, optional png), dom_hash_sha256, extracted_fields_json, jsonld_json, found_image_urls_json |
| Server-side extraction | search pages: `Property24SearchExtractorV1` (Services/Presentations/Evidence/Extractors) -> items {portal_listing_id, url, price, currency, beds, baths, parking, size_m2, erf_m2, title, address, location, image} (:201-213); listing pages: `extractProperty24ListingFields`. |
| portal_listings upsert | `PortalListingTrackingService` (app/Services/PortalListingTrackingService.php): key (agency_id, source_site, portal_listing_id) - unique `portal_listings_agency_site_id_unique` (migration 2026_12_31_100000:51); new -> create with canonical_url, first/last_seen_at, last_capture_id, current_fields_json, primary_image_url; existing -> deltas on TRACKED_FIELDS [price, beds, baths, parking, size_m2, erf_m2, title], merge non-null into current_fields_json, last_seen_at, last_capture_id; ALWAYS inserts a portal_listing_observations row (observed_fields_json, changed_fields_json, capture_id, observed_at). |
| Dropped here | address / location / currency never persisted on portal_listings (AUDIT_PROPERTIES_MATCHING_2026-10-29_APPENDIX.md:631: stays only in portal_captures.extracted_fields_json); the full HTML + JSON-LD (incl. datePosted) is kept raw. |
| Side effect | JSON-LD datePosted -> `ListedDateRecorder` (PortalCaptureController.php:~1253-1259) |

---

## 7. Other writer into prospecting_listings (not the extension)

`Http/Controllers/Map/MapActivityController.php::resolveProspectingListingId` (:520-575): when an agent clicks a map record whose id is `mrcr:N` / `pal:N` / `deal:N`, it find-or-creates a SYNTHETIC listing: DB::table insert at :553 with agency_id, captured_by_user_id, tracked_property_id, portal_source='p24', portal_ref=<record id>, portal_url='', address (default 'Unknown'), suburb, price=0, first/last_seen_at, is_active=true. Idempotent on (agency, 'p24', portal_ref). These rows have price 0, no URL, no beds/baths etc. Relevant to a "how much do we know" ranking: they must not score as captured portal adverts (portal_ref does not start with P24-/PP-).

---

## 8. Summary for the ranking spec

- What a captured MIC listing can ever hold from the extension: ref, URL, address (often null), suburb, price, beds, baths, garages(+parking), erf/floor size (single conflated field), type (guessed), agency name (P24 reliable, PP by alt text), thumbnail, P24 status. Never: agent name, floor size separately, description, photo count, listed date (arrives only if someone separately pulls/imports the same advert's detail page), listing type, mandate type, district, coordinates (TP geocoded later by GeocodeTrackedPropertyAddressesJob, not from the portal).
- Richest source is the P24 DETAIL page (OAS/Pull, section 4) but it writes `properties`, not MIC; only `listed_date` flows back.
- Defects worth knowing before ranking on these columns: (1) townhouse -> "House" in P24 tile type guess; (2) re-sight wipes address/suburb/type/agency with blanks (SRV-PAC:202,213,130-134,203-212); (3) /to-rent/ search pages imported as 'sale' with rent in `price`; (4) PP never sets portal_status; (5) property_size_m2 and agent_name are always empty from search captures; (6) older extension builds (3.9.2 download, 3.10.0 storage zip) are what some agents may run - field set per build unverified.

---
# Appendix B — CMA / presentation imports and deeds capture (sub-report)
# CMA / presentation imports + deeds capture: inventory (QA1, agency 1, read 2026-10-31)

All counts are agency_id = 1, soft-deleted rows excluded unless stated. "24m" = sale/registration date on or after 2024-10-31.
Code paths are under /corex-qa1 (branch QA1). "MIC" = Market Intelligence Centre screens (app/Http/Controllers/CoreX/MarketIntelligenceController.php + resources/views/corex/market-intelligence/*). Other readers (presentations, Map) are listed separately because they are not MIC.

## Headline answers

| Question | Answer |
|---|---|
| Is there sold-price data? | Yes. 3,545 live CMA comp rows with a sale price; 1,726 of them dated in the last 24m. Range 1987-10-16 to 2026-08-25. Heavily recent: 2024 = 505 rows, 2025 = 994, 2026 = 671 (all row types). |
| (i) Average sold price per suburb | YES. 24m comps with suburb: 1,621 of 1,726 (94%). 42 suburbs have at least one; 25 have 10 or more. One suburb spelling problem: `uvongo` (317) and `uvongo beach` (163) are separate buckets. 105 comps (6%) have no suburb. |
| (ii) per property type | PARTLY. `property_type` is only Residence / Residential / Vacant Land / Full Title / Commercial. "Residence" is the sectional parser's default and "Residential" is the vicinity (freehold) parser's default, so this is title type, not house/flat/townhouse. 99% filled but not a usable type split. |
| (iii) per bedroom count | NO. `market_report_comp_rows` has no bedrooms/bathrooms column and the raw row JSON never has them (the sold adapter literally returns `bedrooms => null`, MarketCompRowsSoldAdapter.php:94). Beds only exist on tracked_properties (38,528 of 50,076 overall but 14 of 213 on recent sold ones), prospecting_listings (226 of 251 sold-marked) and sold_stock (519 of 519, but only 11 have a real sale price). |
| (iv) price per m2 | ERF/FLOOR NOT SEPARATED. `extent_m2` is one field: freehold rows hold the erf extent (vicinity parser), sectional rows hold the section (floor) extent. 24m, usable (price 50k-20m, extent present): 1,637 of 1,725; 714 freehold (erf basis) and 923 sectional (floor basis). Computed R/m2 is trustworthy only within each of those two groups. The stored `r_per_m2` is filled on 2,452 of 3,697 priced rows and matches price/extent within 5% on only 1,965. |
| Owner / buyer / seller name on sale rows | NO. Only `scheme_owners` (13 rows) and `row_type='owner'` comp rows (13) carry an owner name. |
| Bond / bondholder / transfer history | Only on tracked_properties from deeds capture (bond on 478, 449 of them deeds-captured). Single latest sale only; no per-transfer history table. |
| Used by MIC for any of this? | Almost none. See the "present but not used by MIC" list. |

## 5. CMA / presentation imports

### 5.1 market_report_comp_rows  (rows: 4,002 incl. 79 soft-deleted; 3,923 live; 343 distinct reports; is_demo = 0)
Key columns: market_report_id, row_type (subject 196 / comp 3,631 / listing 162 / owner 13 incl. deleted), scheme_name, section_number, ss_number/ss_year, address, suburb_normalised, property_type, extent_m2, sale_date, sale_price, estimated_value, r_per_m2, list_price, days_on_market, municipal_valuation(+year), latitude, longitude, tracked_property_id, identity_confidence/method, raw_row_json.

| Column | comp rows (n=3,631 incl. deleted) | fill % | notes |
|---|---|---|---|
| sale_price | 3,619 | 99.7% | 1 row at 20m or more, 2 under R50k (outliers; a NULL-type 36-row bucket averaged R419m before cleaning) |
| sale_date | 3,631 | 100% | |
| price AND date | 3,619 | 99.7% | |
| extent_m2 | 3,171 | 87% | single field, erf for freehold / section for sectional |
| r_per_m2 | 2,479 | 68% | unreliable (see above) |
| property_type | 3,576 | 98% | title-type only |
| suburb_normalised | 3,327 | 92% | |
| address | 2,080 | 57% | |
| scheme_name | 1,680 | 46% | sectional marker |
| ss_number | 1,620 | 45% | |
| erf number | 740 comp+other rows carry `erf_number` only inside raw_row_json (738 non-null), NOT a column | ~20% | vicinity parser rows |
| latitude/longitude | 1,786 | 49% | |
| estimated_value | 68 | 2% | |
| list_price | 169 | 5% | |
| municipal_valuation | 0 on comps; 194 of 196 on subject rows | | |
| beds / baths / floor size / owner / bond / LPI / transfer history | no column, not in raw JSON | 0% | |
| tracked_property_id | 0 of 4,002 | 0% | comp rows are NOT linked to tracked properties on QA1 (identity_method/confidence also 0). |

Subject rows (196): sale_price 154, sale_date 153, extent 115, municipal_valuation 194, lat/lng 196. Listing rows (162): list_price 162, extent 162, property_type 162, no sale price (active listings). Owner rows (13): scheme_name + extent only.

Duplicates: 310 groups of identical (address, price, date) among live comps, i.e. overlapping reports repeat the same sale. Any average must dedupe.

24m top-10 suburbs (comp rows, price R50k-R20m, deduping NOT applied):

| suburb_normalised | sales | avg price | with size | avg calc R/m2 (mixed erf/floor) |
|---|---|---|---|---|
| uvongo | 317 | R1,251,207 | 286 | 4,973 |
| margate | 219 | R976,660 | 211 | 7,240 |
| uvongo beach | 163 | R1,275,877 | 144 | 9,363 |
| manaba beach | 153 | R1,135,111 | 150 | 8,946 |
| shelly beach | 121 | R1,288,116 | 121 | 6,429 |
| (no suburb) | 105 | R1,618,512 | 98 | 7,181 |
| ramsgate beach | 84 | R1,061,643 | 74 | 9,256 |
| beacon rocks | 61 | R951,918 | 60 | 11,245 |
| margate beach | 54 | R794,208 | 54 | 9,103 |
| ramsgate | 52 | R1,097,547 | 50 | 6,169 |

24m by quarter (comp, priced): 2024-Q4 82, 2025-Q1 201, Q2 156, Q3 282, Q4 342, 2026-Q1 387, Q2 248, Q3 28 (data stops 2026-08-25).
24m rows with price + date + suburb + type + size all present: 1,539 of 1,726 (89%).
24m by type (price R50k-R20m): Residence 1,286 (avg R1,036,636), Residential 371 (R1,597,187), Vacant Land 31 (R329,457), Full Title 15, NULL 19, Commercial 3.

Written at:
- Row create: app/Jobs/MarketReports/ParseMarketReportJob.php:245 (`MarketReportCompRow::create`), after `data points` at :218 and `scheme_owners` at :278.
- Row construction per parser: Parsers/CmaInfoVicinitySaleParser.php:161-172 (freehold, 'Residential' default :168, erf_number only in raw :190); Parsers/CmaInfoSectionalTitleSalesParser.php:127-138 ('Residence' default :134); Parsers/CmaInfoMarketAnalysisParser.php:193-199 (property_type and extent forced null); Parsers/CmaInfoSchemeOwnersListParser.php:144-159 (owner rows); CmaInfoPropertyValuationParser.php:103-168 (subject + municipal valuation); AI/learned reads via CanonicalReadMapper.php and LearnedLayoutParser.php (1 AI + 1 learned + 16 "unread" reports on QA1).
- Observer link attempt (never populated on QA1): app/Providers/AppServiceProvider.php:275 -> app/Observers/PropertyIdentityLinkObserver.php:52 (`compRowCreated`).
- Sale price/date also pushed into tracked_properties via matchOrCreate: ParseMarketReportJob.php:306 with rename sale_price->last_known_sold_price at :506-515 (only 50 tracked properties end up with `cmainfo` as their sold source).

Read by MIC: NOT read by any MIC screen except the admin recogniser "View figures" page (MarketReportRecogniserController.php:186 -> views/corex/market-intelligence/reports/recogniser/figures.blade.php:81-103, owner roles only). Not read by Opportunities, listing rows, or the suburb report.
Read elsewhere (not MIC): Presentations MarketAnalytics sold adapter (app/Services/MarketAnalytics/Adapters/MarketCompRowsSoldAdapter.php:47-62), MicSnapshotHydrator.php:186-499 (copies rows into presentation_sold_comps), CmaCoverageService, Map pins (MapPinService.php:838,940; MapController.php:314,721,835).

### 5.2 market_data_points  (6,110 rows, all agency 1, source_type market_report, tracked_property_id 0 of 6,110)
Key columns: report_id, suburb_normalised, town, metric_key, metric_value_numeric/date/string, metric_date, confidence, is_superseded. Not a flat sales table; it is metric-keyed.

| metric_key | rows | date range |
|---|---|---|
| comparable_sale_price | 1,553 | 1987-10-16 to 2026-08-25 |
| sectional_radius_sale_price | 1,257 | 1987-10-16 to 2026-08-25 |
| vicinity_radius_sale_price | 628 | 2000-09-07 to 2026-07-01 |
| cma_value_middle / upper / lower | 507 / 323 / 323 | 2026-06-03 to 2026-10-26 |
| suburb_sales_count_year | 265 | 2017 to 2026 (year-end dates) |
| suburb_median_price_year | 205 | 2017 to 2026 |
| suburb_average_price_year | 100 | 2017 to 2026 |
| suburb_low/high/max_year | 125 each | 2017 to 2026 |
| suburb_annual_change_pct | 136 | 2017 to 2026 |
| municipal_valuation | 194 | 2017-07-01 to 2026-07-01 |
| cma_value_average, cma_value_average_r_per_m2, vicinity_radius_metres | 54 each | |
| sectional_radius_metres | 81 | |
| scheme_owner_count | 1 | |

suburb_normalised filled on about 80-100% per metric. Duplicates comp-row sales as data points (comparable/sectional/vicinity_radius_sale_price = 3,438 rows vs 3,545 comp rows).
Written at: ParseMarketReportJob.php:218; per-parser dataPoints in each CmaInfo*Parser.
Read by MIC:
- app/Services/SuburbReports/SuburbReportDataService.php:161 and :170 (only the 7 `suburb_*_year` keys, LAYER_A_METRIC_KEYS :122-130), used by MarketIntelligenceController.php:2168 for the suburb report page + print (views suburb-report*.blade.php).
- MarketIntelligenceController.php:2697 (suburb deep-dive slide-over): returns ONE row, `orderByDesc('metric_date')->first()`, any metric_key, so it shows an arbitrary latest metric (L2696-2710). Effectively unused as a price feed.
- Not read by MIC: comparable_sale_price, vicinity/sectional_radius_sale_price, cma_value_*, municipal_valuation (Map reads municipal_valuation: MapController.php:472).

### 5.3 market_reports  (436 live, 1 deleted; all parse_status = parsed)
report_date 2025-08-01 to 2026-10-26 (419 parser, 16 "unread", 1 ai, 1 learned). Columns: report_type_id, file_hash, source_suburb, source_town, subject_address/scheme/section, subject_latitude/longitude, subject_extent_m2, radius_metres, raw_extracted_json, data_points_count. Written by MarketReportIngestService (sync parse) + ParseMarketReportJob.php. Read by MIC: SuburbReportDataService.php:201-204 (report metadata cards) and MarketReportController (library UI). Parsers present: CmaInfoMarketAnalysis, MedianSalesAnalysis, PropertyValuation, SchemeOwnersList, SectionalTitleSales, VicinitySale, GenericFallback, LearnedLayout (app/Services/MarketReports/Parsers).

### 5.4 scheme_owners  (13 rows)
owner_name 13, extent_m2 13, property_type, contact_id 0, lat/lng 0. Written ParseMarketReportJob.php:278 (CmaInfoSchemeOwnersListParser). Read: MapActivityController.php:200 (Map), not MIC.

### 5.5 presentation_sold_comps  (4,277 rows; 2,180 live, 2,097 soft-deleted; 158 presentations / 60 uploads)
Key columns: presentation_id, sold_date, sold_price_inc, suburb, property_type, beds, baths, size_m2, listed_date, raw_row_json, parser_version, mic_comp_row_id, tracked_property_id.
Live rows: sold_price 2,180 (100%), sold_date 100%, size_m2 2,153 (99%), suburb 2,081 (95%), property_type 1,473 (68%), beds 0, baths 0, listed_date 0, mic_comp_row_id 0, tracked_property_id 0. Range 1986-01-24 to 2026-07-07; 1,199 in 24m (1,178 with size).
parser_version mix (live): mic_snapshot_v1 1,321 (copies of market_report_comp_rows, so double-counts 5.1), doc_extract_v1:street_sales 299, vicinity_sales 250, cma_comps 153, vicinity_sales_sectional 113, agent_browse 39, sales_report_v1 5. Suburb is free-text case-mixed (UVONGO / uvongo beach / RAMSGATE BEACH), so group by lower().
24m top suburbs: UVONGO 247 (avg R1,625,583), MARGATE 137 (R905,976), manaba beach 105 (R1,135,762), SHELLY BEACH 78, uvongo beach 68.
Written at: Evidence/UploadExtractionService.php:482; Presentations/MicSnapshotHydrator.php:186, :499; Evidence/Parsers/SalesReportParserV1.php:53; Http/Controllers/Presentation/PresentationReviewController.php:792.
Read by MIC: not read. Read by presentations (PriceBandService, MapPinService.php:815-940, PropertyIntelligenceService via CmaCoverageService).
Related, not sold: presentation_active_listings (6,988 rows, 5,635 live; asking prices) fed by Property24SearchParserV1 (Evidence/Parsers/Property24SearchParserV1.php, via UrlIngestionService.php:258) which parses P24 search-result listings (price/beds/baths), not sold data, and writes no DB itself.

### 5.6 commercial_evaluation_comparables  (3 rows: address, suburb, size_m2, sale_price, sale_date, price_per_m2). Negligible. Not read by MIC.

### 5.7 PropertyCmaPropagationService  (app/Services/Presentation/PropertyCmaPropagationService.php)
Moves presentation_fields (subject.erf, subject.gps, subject.address, subject.suburb, subject.title_deed, municipal.total_value, municipal.valuation_year) onto `properties` (stock) via DB update at ~:100 and sets last_cma_at. Triggered by Listeners/Presentation/PropagateCmaToProperty.php; the same listener sets tracked_properties.last_known_sold_price/date from `subject.purchase_price/purchase_date` (PropagateCmaToProperty.php:200-205). It does NOT touch comp rows and writes no market-level sales. Not read by MIC.

## 6. Deeds capture on tracked properties

Spec: .ai/specs/deeds-capture.md. There is no dedicated deeds table. Everything lands on tracked_properties + tracked_property_owners + a contact. (title_deeds / title_deed_* tables are the unrelated Title Deed Register for physical deed files.)

### 6.1 tracked_properties  (agency 1: 50,076 live of 50,367; 1,810 are capture_kind='deeds_capture'; 1,912 have deeds_captured_at)

| Column | rows filled (of 50,076) | fill % | among 1,810 deeds_capture rows |
|---|---|---|---|
| last_known_sold_price | 1,834 | 3.7% | 1,705 |
| last_known_sold_date | 1,826 | 3.6% | |
| price AND date | 1,825 | 3.6% | |
| deeds_registered_date | 1,929 | 3.9% | |
| title_deed_number | 1,929 | 3.9% | 1,806 |
| erf_number | 1,268 | 2.5% | 849 |
| erf_size_m2 | 41,565 | 83% (mostly portal listings, not deeds) | 864 |
| floor_size_m2 | 2,399 | 4.8% | 0 |
| cadastral_extent | 933 | 1.9% | |
| section_extent_m2 | 1,007 | 2.0% | |
| municipal_valuation | 2,121 | 4.2% | |
| bond_holder / bond_amount | 478 / 478 | 1.0% | 449 amount |
| sale_type | 1,846 | 3.7% | |
| deeds_office | 1,934 | 3.9% | |
| scheme_name | 1,007 | 2.0% | |
| lpi_code | 6 | 0.01% | 5 |
| owner_contact_id | 1,934 | 3.9% | 1,809 |
| property_type | 42,308 | 84.5% | 993 |
| bedrooms / bathrooms | 38,528 / 38,647 | 77% (portal) | 22 / |
| suburb_normalised | 44,036 | 88% | 1,810 |
| lat/lng | 39,765 | 79% | |
| ownership_parse_status | 50,076 | meaningless: column default 'ok' on every row | |

Sold data range: last_known_sold_date 1982-10-18 to 2026-07-01; deeds_registered_date 1967-04-05 to 2026-09-04. 24m: 213 sold (price+date; 184 from deeds capture, others cmainfo 50 / p24 49 / pp 40 overall by source); 250 registered. Sold by year: 2020 108, 2021 154, 2022 122, 2023 94, 2024 109, 2025 130, 2026 66 (only to July).
Recent-sold (24m, n=213) usable fields: suburb 213 (100%), property_type 133 (62%), erf_size_m2 98 (46%), section_extent 122 (57%), floor_size_m2 0, bedrooms 14 (7%). Top 24m suburbs: trafalgar 65 (avg R799,046), uvongo 34 (R1,188,632), ramsgate beach 19, scottburgh south 16, manaba beach 15.
Deeds-capture rows registered 24m: 234, of which 70 have bond_amount.

Written at (all via matchOrCreate, universal rule):
- app/Http/Controllers/Api/DeedsCaptureController.php: validation :88-147; facts build + `last_known_sold_price/date`, `bond_holder`, `bond_amount`, `sale_type`, `deeds_registered_date`, `erf_number`, `lpi_code`, `title_deed_number`, extents :648-665; `$matcher->matchOrCreate` :668. Sale-date/price guard (older sale never overwrites newer): TrackedPropertyMatchOrCreateService.php:2054-2118 (field whitelist :125-126, :2244).
- Also last_known_sold_*: ParseMarketReportJob.php:306/:515 (cmainfo), PropagateCmaToProperty.php:200-205, portal feeds (p24/pp).
Read by MIC: only Opportunities: MarketIntelligenceController.php:1508 (EXCLUDES deeds_capture rows from the list), :1561 (search on erf_number); views opportunity-detail.blade.php:161-191 (erf, title deed, municipal valuation, last_known_asking_price) and partials/opportunities-list.blade.php:82-83 (bedrooms, erf). MIC never reads last_known_sold_price/date, bond_*, sale_type, deeds_registered_date, lpi_code, floor/erf size, owner_contact_id.
Read elsewhere: DeedsCaptureController (CoreX) screen :1084-1092, DeedsCaptureLinkService.php:537-538, PropertyDuplicateMatchEvidence.php:235-241, Map owner card (MapController.php:593 bond amount), Road to Sold OwnerKnownSelection.php:43-60.

### 6.2 tracked_property_owners  (3,424 rows, all agency 1; 2,220 distinct tracked properties; 1,933 are live agency-1 tracked properties; 1,012 properties have more than one owner row)
Fill: name 100%, contact_id 100%, role 100% (owner 3,268 incl. past / director 156), ownership_status 100% (current 3,335 / past 89), id_number 3,386 (99%), id_type 3,199 (93%), is_primary 2,142, deed_reference 205 (6%), ownership_share_pct 185 (5%), conflict_flagged_at 41. No purchase price/date/bond column on owners.
Written at: Api/DeedsCaptureController.php:923 (conflict row), :950-960 (`syncOwners` updateOrCreate/create), :823 (`captureOwnershipHistory`); Services/Prospecting/OwnerContactResolver.php:72-87 (share %, deed_reference, ownership_status); Services/Prospecting/OwnershipHistoryParser.php (parse of "a;b;c" owner history); RoadToSold/TaskCaptureService.php:435; Map/MapController.php:682 (firstOrCreate).
Read by MIC: not read (no reference in MarketIntelligenceController or its views). Read by Road to Sold OwnerKnownSelection.php:43-60, Map, ComposeSellerService, OwnerSurnameEvidence.
Coverage: only 294 of 44,401 non-duplicate, non-promoted tracked properties have an owner row.
Transfer history: only "past" owner rows (89) with a deed_reference year (205 rows have deed refs); no per-transfer price/date/bond. Previous-sale history is NOT stored; just one latest sale on the tracked property.

### 6.3 tracked_property_addresses  (13,062 rows agency 1, 9,579 distinct tracked properties)
street_name 12,630, suburb_normalised 12,980, latitude 2,606 (20%), is_primary 189. source_type: p24 6,512, pp 3,746, deeds_capture 2,134, property24 340, cmainfo 214, chrome_capture 80. Alternate address spellings, no sale data. Read by MIC: Opportunities search (MarketIntelligenceController.php:1562-1565 `primaryAddress`).

### 6.4 tracked_property_external_refs  (agency 1: p24 24,584; pp 20,937; chrome_capture 6,043; deeds_capture 2,220; property24 389; cmainfo 302). Identity refs, no sale data.

## Other tables holding sold prices / transfers (agency 1)

- property_sold_records: 226 rows, all `manual`; sold_price 226, sold_date 226 (2025-03-18 to 2026-08-19), suburb 226, property_type 226, beds 0, baths 0, sqm 0; sold_price equals listing_price_at_sale on all 226 (mirrors the advertised price; SuburbReportDataService.php:260-265 documents this and does not use it).
- sold_stock: 519 rows, source 'backfill', sale_date 519 (2026-05-04 to 2026-10-21), beds/baths 519, size_m2 305, suburb 518, but sale_price > 0 on only 11; asking_price 519; seller_contact_ids on 88, buyer_contact_ids on 11. (AT-512 sold stock, QA1 only.)
- deals (DR1): 176 rows; sale_price filled 0; registration_date 43 (2025-12-31 to 2026-10-14). Read by MIC suburb report: SuburbReportDataService.php:507-565 (sold = granted/registered).
- deals_v2 (DR2): 176 rows; purchase_price 176; actual_registration 43 (2025-12-31 to 2026-10-14); property_id 0 of 176 on QA1. Read by MIC suburb report (same method).
- prospecting_listings (45,753 rows; 251 with portal_status='sold', all p24/sale): price 248 (portal asking price, not achieved), bedrooms 226, bathrooms 224, erf_size_m2 239, property_size_m2 NULL, property_type 251, tracked_property_id 251, status change dates 2026-08-12 to 2026-10-31 (all in the last 3 months). 215 of them have price + beds + erf size + type. Other statuses: withdrawn 25,902, active 14,503, under_offer 1,048, null 6,286. Written by Api/ProspectingApiController.php:55 validation + listing sync; Not verified whether MIC filters on portal_status (no direct reference in MarketIntelligenceController.php).
- properties (stock 9,269): no sold price column (only `portal_sold_until` on 519 rows, title_deed_number).

## Present but NOT used by MIC

1. market_report_comp_rows sale_price / sale_date / extent_m2 / suburb (3,545 priced comps, 1,726 in the last 24m): used only by presentations and Map pins. MIC has no sold-price average anywhere.
2. market_data_points comparable_sale_price / sectional_radius_sale_price / vicinity_radius_sale_price (3,438 rows) and cma_value_lower/middle/upper (1,153 rows, 2026): not read. municipal_valuation points (194) read only by Map.
3. tracked_properties last_known_sold_price / last_known_sold_date (1,825 rows), deeds_registered_date (1,929), bond_amount / bond_holder (478), sale_type (1,846), title_deed_number in ranking terms: not read by MIC (only displayed for erf/title deed/municipal value on the opportunity detail).
4. tracked_property_owners (3,424 rows; owner names, ID numbers, past owners, co-owner shares): not read by MIC; used by Road to Sold and Map.
5. presentation_sold_comps live (2,180, price/size fill 99%): presentation-only, and 1,321 of them are copies of the comp rows.
6. prospecting_listings portal_status='sold' (251 with asking price, beds, erf size, type): the only sold-marked source with bedrooms, but price is asking, not achieved.
7. sold_stock / property_sold_records / deals(_v2): internal sales; only deals are read (suburb report), not by the Opportunities ranking.
8. lpi_code on 6 tracked properties only: not usable for identity at this fill.

## Caveats for the spec

- Comp rows are never linked to tracked_properties on QA1 (0 of 4,002; presentation_sold_comps 0 of 4,277), so "what do we know about this property" cannot join sold comps to a tracked property without first running a match; there is no key on the comp rows other than address/scheme/ss_number/erf-in-JSON.
- Duplicates and mixed bases (erf vs section extent) must be handled before computing averages or R/m2. Suburb spelling splits (uvongo vs uvongo beach, margate vs margate beach) need the existing suburb reconcile.
- Beds/baths exist for only a thin slice of sold data (tracked_properties sold: 14 of 213 recent; prospecting sold listings: 226 of 251).
- Date range stops 2026-08-25 (comps) / 2026-09-04 (deeds registered).

---
# Appendix C — Buyer match counts and Core Matches (sub-report)
# MIC Work tab "strong / mid" buyer chips - source, writers, cost, distribution (QA1, read-only)

All paths under /corex-qa1 (branch QA1). Data = corex_qa1, agency_id = 1, measured 2026-10-31.

## 1. Where the chip is rendered and where the number comes from

| Step | file:line |
|---|---|
| Chip markup ("N strong", "N mid", "% match", "via portal lead") | resources/views/corex/market-intelligence/_listing-row.blade.php:359-378 (button 368-373); defaults at :35 |
| `$tiers` passed per row from `$buyerTiers[$listing id]` | resources/views/corex/market-intelligence/_listings.blade.php:244 |
| Controller computes `$buyerTiers` for the 50 rows on the page | app/Http/Controllers/CoreX/MarketIntelligenceController.php:1076-1079 (`work()`) |
| The count itself | app/Services/Prospecting/BuyerMatchTierService.php:35-86 `tiersForListings()` |
| Drill-down panel (click) | same service `buyersForListing()` :94-; controller :3318 |
| Separate per-row `buyer_match_count` / `buyer_match_top_score` (drives "View N matched buyers" at _listing-row:529, sort, score band) | MarketIntelligenceController.php:1351-1377 `attachBuyerMatchCounts()` |

**It is the STORED table `prospecting_buyer_matches`, not a live query.** No scoring happens at render time. The chip is one `GROUP BY prospecting_listing_id` over the cache.

### Tier / threshold definition
- strong = `score >= strong_min_score`, mid = `mid_min_score <= score < strong_min_score` (BuyerMatchTierService.php:56-65).
- Thresholds are per agency from table `buyer_match_tiers` (one row per agency, soft-deleted; model app/Models/Prospecting/BuyerMatchTier.php, defaults `defaultsFor()` strong 80 / mid 50 / weak 0). Loaded by ProspectingConfigurationService.php:119-144. QA1: agency 1 = 80/50/0 (also agencies 20-23 same).
- "weak" is always 0 on QA1 because rows below 50 are never cached: `PropertyMatchScoringService::MIN_SCORE_TO_CACHE = 50` (PropertyMatchScoringService.php:47). So visible tiers = strong (>=80) and mid (50-79); table has no sub-50 rows (MIN(score)=50).
- The table's own `tier` enum column (perfect >=90 / strong >=80 / approximate >=50) is set by `determineTier()` (PropertyMatchScoringService.php:988-1001) but the chip ignores it and re-derives from `score`.
- "Portal lead" split uses `source` column (portal_p24, portal_pp); `source` is copied from `contacts.buyer_source` at write time.

### What the unit is
- One row per **(prospecting_listing_id, contact_id)** - unique key `pbm_listing_contact_unique`. A prospecting_listing = one portal advert row (a scraped P24/PP/website ad). It is NOT per tracked_property and NOT per company-stock property.
- A MIC Work row is a *group* of adverts for the same property (`property_group_id`); buildGroupedRows (controller :1319-1341) takes `$group->first()` as representative, so the chip shows the counts of that **representative advert only**, not a union across the group's adverts.
- Keyed to the **buyer contact** (not wishlist): one row per buyer = best canonical score across that buyer's active wishlists (`canonicalBestAcross`, PropertyMatchScoringService.php:783-806). Which wishlist won is NOT stored (comment in BuyerMatchTierService.php:98-107).
- Agency: `agency_id` column (+ `branch_id`), written from listing/contact agency; read always filtered `agency_id = $agencyId` and `dismissed_at IS NULL`. Model uses BelongsToAgency (app/Models/ProspectingBuyerMatch.php).
- Per-property rollup exists elsewhere: Opportunities tab counts per **tracked_property** = listings with any buyerMatch score >= 80 (MarketIntelligenceController.php:1520-1525). Its ORDER BY strong_match_count was disabled (PHASE-9E-TODO at :1570-1576, orders by updated_at; page subtitle still claims "ranked by strong-match count").

## 2. Who writes it / what triggers a refresh

Scorer: `MatchingService::score()` (app/Services/Matching/MatchingService.php:824) via `PropertyMatchScoringService::canonicalBestAcross()`. Gates before scoring: wishlist `isCountable()` (AT-71, ContactMatch.php:571+), deal-breakers, hard `suburbCompatible()` (MatchingService.php:1166).

| Writer | file:line | Trigger |
|---|---|---|
| `recomputeProspectingMatches($listingId)` - one listing vs all agency buyers with active wishlist (`is_buyer=1`) | PropertyMatchScoringService.php:580-657 (upsert via chunkedUpsert :424) | ProspectingListingObserver `created()` :18-23 and `updated()` when price/bedrooms/bathrooms/suburb/property_type/erf_size_m2 dirty :29-37 -> `recomputeAndNotify` :66-; **synchronous**. Observer registered AppServiceProvider.php:478. Bulk Chrome-extension imports use insert() and bypass observers (comment ProspectingListingObserver.php:14-16) |
| `recomputeProspectingMatchesForBuyer($contactId, $pool)` - one buyer vs all active listings of the agency (evicts stale rows) | PropertyMatchScoringService.php:674-757 | (a) RegenerateBuyerMatchesJob (below); (b) console `prospecting:recompute-matches` |
| `RegenerateBuyerMatchesJob` (queue `buyer-matching`, unique per agency+contact, timeout 600s, agency-wide run chunked 40 contacts/run, ~10 s/contact measured by its authors) | app/Jobs/RegenerateBuyerMatchesJob.php:55-260 (loop :219-222) | **New/changed/deleted wishlist** -> ContactMatchObserver::saved/deleted -> `dispatchRecompute` (ContactMatchObserver.php:156-164, 189-193, 217-232) per contact, truncate=true. **Any Property save** -> PropertyObserver::queueBuyerMatchRecompute (PropertyObserver.php:1063-1090, called :587 and :1134), agency-wide, 60 s delay. **Core Matches settings change** -> ContactGovernanceController.php:154. Manual: `wishlist:regenerate-matches` (WishlistRegenerateMatches.php:36) |
| Nightly cron | routes/console.php:485-486: `prospecting:flag-stale-listings` 03:50 then `prospecting:recompute-matches` 04:00 (app/Console/Commands/RecomputeProspectingMatches.php) | daily; also `matches:recompute` 04:30 (:479) |
| Deleters | FlagStaleProspectingListings.php:134, SuburbReconcileService.php:105, RegenerateBuyerMatchesJob.php:302/308, DemoCleanup.php:71 | listing goes inactive/merged; truncate |

Gaps worth knowing:
- A **new buyer** (contact with no wishlist yet) triggers nothing until a wishlist is saved (then per-contact job). A **new listing** from bulk Chrome import (insert()) is only scored by the nightly cron.
- Cache is **not filtered by buyer_state**: `recomputeProspectingMatches` only requires `is_buyer=1`, the buyer job iterates every active wishlist. Core Matches "active demand" counts only buyer_state new/warm (PropertyMatchScoringService.php:355-380, ACTIVE_BUYER_STATES :53). On QA1 cold/lost buyers hold 69,839 of the 136,770 strong rows (51%) - see section 4.
- Staleness on QA1: MAX(last_recompute_at) 2026-10-31 11:16, MIN 2026-09-29; 49,936 rows not touched in >2 days (QA1 scheduler/worker coverage differs from live; do not assume nightly runs on QA1).
- `dismissed_at`/`dismissed_by_user_id` are read everywhere but nothing in app/ sets them (0 dismissed rows).

## 3. Table: prospecting_buyer_matches (QA1)

Columns: id, prospecting_listing_id, contact_id, agency_id, branch_id, score smallint (0-100), tier enum(perfect,strong,approximate), source varchar(32), matched_features json, missing_features json, matched_at, last_recompute_at, agent_notified_at, dismissed_at, dismissed_by_user_id, created_at, updated_at. (Migrations 2026_05_06_000017, 2026_05_13_100003, 2026_07_05_000002, 2026_07_19_000003, 2026_08_22_140000.)

Indexes: PK id; UNIQUE pbm_listing_contact_unique(listing, contact); pbm_listing_score(listing, score); pbm_contact_score(contact, score); pbm_tier_date(tier, matched_at); pbm_agency_contact_idx; pbm_agency_listing_idx(agency, listing); pbm_listing_source_score(listing, source, score); pbm_agency_dismissed_score_listing_idx(agency, dismissed_at, score, listing); pbm_agency_dismissed_contact_idx; pbm_listing_dismissed_idx(listing, dismissed_at); branch_id FK; dismissed_by FK.

Counts (agency 1; it is the only agency with rows): **218,643 rows** (QA1; your 227,314 is a different snapshot/box), 100% active (dismissed 0), 17,781 distinct listings, 338 distinct buyers. Strong (>=80) 136,770; mid (50-79) 81,873; scores 50-100. Tier column: perfect 60,630 / strong 76,140 / approximate 81,873. Source: manual 132,856; portal_p24 57,269; portal_pp 17,778; portal_website 10,738; NULL 2. 737 rows sit on inactive listings.
Listings: prospecting_listings agency 1 = 47,990 (21,115 active, 26,874 inactive; all listing_type sale).

## 4. Distribution on QA1 (active listings, agency 1, n = 21,115)

| Bucket | listings with N **strong** | listings with N **mid** |
|---|---|---|
| 0 | 3,836 | 9,665 |
| 1-4 | 9,854 | 4,860 |
| 5-9 | 2,214 | 3,502 |
| 10+ | 5,211 | 3,088 |
| >=1 | 17,279 (82%) | 11,450 (54%) |
| listings with neither | 3,384 | |

Strong tail (listings having rows): 10-49 = 5,213; 50-199 = 23 (max 61). Mid max 51.
Implication: strong>=1 is near-universal (82%), so "has a strong buyer" will not discriminate; ranking needs the count (or a quality-weighted count). Cause: the scorer only counts criteria the buyer set and the nominal 80 bar is easy to clear for broad wishlists; 17 buyers hold 2,000+ strong rows each (max 11,961), 42 hold 501-2,000.

Buyer-state split of strong rows (agency 1): cold 31,078 (37 buyers), lost 38,761 (118), new 33,170 (131), warm 33,761 (52). Restricting to new/warm buyers only, active listings by strong count: 0 = 10,810; 1-4 = 4,482; 5-9 = 3,219; 10+ = 2,604 (max 26). So the active-buyer view is a much better discriminator (49% have none) than the all-state chip.

## 5. Cost

- **Per page: ONE grouped query, no N+1.** `tiersForListings()` runs one `SELECT ... SUM(CASE...) GROUP BY prospecting_listing_id` for the page's ~50 listing ids (controller :1076-1079). Plus a second one-query batch `attachBuyerMatchCounts()` (:1351-1377, `COUNT(DISTINCT contact_id)`, `MAX(score)`), and a third only in buyer mode (`attachSelectedBuyerScore` :1383).
- EXPLAIN (50 ids): `type=range`, key `pbm_listing_contact_unique`, ~1,292 rows examined, "Using index condition; Using where". Same plan for attachBuyerMatchCounts.
- Measured (mysql CLI from the box, 50 most-recent active listings, 3 runs): 0.08-0.09 s wall vs 0.07 s for `SELECT 1` -> **~10-20 ms of query time** per page.
- Ranking across the whole agency (what a "rank properties by buyers" feature would need): `GROUP BY prospecting_listing_id ORDER BY strong DESC, mid DESC LIMIT 50` over all 218k rows = **0.30 s** (covering index `pbm_agency_dismissed_score_listing_idx`, 113,657 rows in the derived scan, no filesort on base table); same joined to active prospecting_listings = **0.43 s**. Acceptable per request, but cache or denormalise if it is to be sortable on a 50-row paginated list with other filters.
- Existing code path warning: `sort=buyer_matches`, `sort=match_score`, `matched_only=1`, `score_min/score_max`, buyer mode, and manager `include_in_stock` force the SLOW path (ProspectingListingPageResolver::canUseFastPath, app/Services/Prospecting/ProspectingListingPageResolver.php:~58-82): `$query->get()` hydrates the whole matching set (tens of thousands of rows), groups in PHP, attaches counts, then sorts/slices in memory (controller :654-699). The controller docblock at :1054-1074 records this class of path at ~10 s before the 2026-08-22 fix. A strong/mid-based sort must be added as a SQL-side join/sort in the fast path (resolvePage) rather than reuse the `buyer_matches` slow path. Note the existing sort key is `COUNT(DISTINCT contact_id)` over score 50-100 (strong+mid together, `$countFloor` 50), not the strong count.
- Write cost (matters if the counts are made richer): ~10 s per buyer per full recompute against ~21-33k listings (RegenerateBuyerMatchesJob.php docblock :70-100); the per-listing synchronous observer path loads all active wishlists and scores each (PropertyMatchScoringService.php:580-657).

## 6. Core Matches (company stock) - how it differs, can MIC reuse it?

- Spec .ai/specs/core-matches.md = the Core Matches **board** (buyer-facing list screen, scoping own/branch/agency, ContactScope/BranchScope traps). Matching engine: app/Services/Matching/MatchingService.php. Constants: MIN_SCORE_TO_SURFACE 40 (:19), MIN_SCORE_TO_DISPLAY 50 (:25), TIER_STRONG_MIN 80 / GOOD 65 / FAIR 50 (:28-30), RELAXED_PRICE_BAND 0.30 (:46); `tierFor()` :159 returns strong/good/fair (3 tiers, "good" 65-79 has no MIC equivalent; MIC collapses 50-79 to "mid").
- Unit: a company-stock **Property** (`properties`) vs buyer **wishlists** (`contact_matches`). Cache table `property_buyer_matches` (1,910 rows agency 1; written by `recomputeForBuyer`, PropertyMatchScoringService.php:486-565, same job/observers/crons as above). Rows are floored at MIN_SCORE_TO_DISPLAY 50 and include hidden-property and agency "allowed statuses" rules (core_matches_allowed_statuses, working window), so the count equals the live Core Matches count (docblock :494-504).
- Demand claim count for a property (seller outreach): `countableActiveBuyerCountForProperty()` :334 / `activeCanonicalBuyersForProperty()` :355 - **live** (runs `matchesForProperty`), dedupes per buyer, applies suburbCompatible, and keeps only buyer_state new/warm. MIC's cached count does NOT apply the buyer_state filter.
- `core_match_misses` (1 table, 65 rows total, model CoreMatchMiss, migration 2026_06_28_140001) is unrelated to counts: capture-only snapshot written when an agent adds a non-Core-Match property to a Viewing Pack (ViewingPackSelectionService).
- Reuse: MIC already reuses the same scorer (`MatchingService::score`, suburbCompatible, isCountable) - the **scores are the same engine**; only the target differs (portal advert vs stock Property, via `wrapCaptureAsProperty`, PropertyMatchScoringService.php:1050) and the cache table. MIC cannot read `property_buyer_matches` (stock only). Reuse is at engine level, so a new rank feature should read `prospecting_buyer_matches` and optionally join `contacts.buyer_state` to match Core Matches' "active buyers" meaning.

## 7. Buyer data available for matching (agency 1, QA1)

`buyer_preferences` = 0 rows (legacy, unused). Real source: **`contact_matches`** (the "wishlist", model ContactMatch; spec .ai/specs/unified-buyer-wishlist-spec.md) + `contacts` (`is_buyer`, `buyer_state` new/warm/cold/lost, `buyer_source`).

- contacts is_buyer=1 (not deleted): 717; buyers with >=1 active wishlist: 500. `buyer_source`: portal_p24 422, portal_pp 138, manual 99, portal_website 45, NULL 13.
- contact_matches agency 1 not deleted: active 636 (501 contacts), expired 265, fulfilled 3, paused 1.
- Active wishlists (n = 636) field fill: listing_type sale 369 / rental 267 (rental wishlists exist, but the MIC pool is all sale adverts); price_min set 518, price_max set 612 (any price 613); beds_min 539 (beds max 18); baths_min 46; garages_min 19; suburbs (+p24_suburb_ids) 595; property type(s) 628; must_have_features 56; deal_breakers 13; erf_size_min 3; floor_size_min 0. Core criteria coverage (price, suburb, type, beds): none 0; >=3 of 4 present 529.
- Other columns: name, category, property_types json, bedrooms_max, floor/erf min-max, nice_to_have_features, hidden_property_ids, open_to_auction, last_engaged_at, move_in_date, rental_term_months, is_primary, agent_id, branch_id, share_token.
- Matching uses only criteria the buyer specified (score() docblock MatchingService.php:812-818); suburb is a HARD gate in the MIC cache path (AT-289) but soft in score().
- Related per-buyer tables: buyer_state_transitions, buyer_activity_log, buyer_lost_records, buyer_lost_risk_scores, buyer_property_responses/views, contact_match_feedback, contact_match_shares.

## 8. Facts to carry into the spec

1. Per-advert (prospecting_listing) counts exist today; per-property ranking must pick a group representative or aggregate across `property_group_id` / `tracked_property_id` (Opportunities already does the latter via relation, unindexed sort disabled).
2. strong>=1 is true for 82% of active listings; use count and/or active-buyer-state filter to discriminate.
3. Cache is not buyer_state-aware; 51% of strong rows belong to cold/lost buyers.
4. Fast path cannot sort by buyer aggregate; add SQL-side sort (join to a grouped subquery on `pbm_agency_dismissed_score_listing_idx`, ~0.3-0.4 s unpaged on QA1) or denormalise strong/mid counts.
5. Freshness: wishlist change -> queued per-buyer refresh; property save -> agency-wide queued refresh (60 s delay, chunked); portal-advert edit -> synchronous per-listing; bulk imports + nightly 04:00 cron otherwise.

## 17. Follow-up facts (added 2026-10-31, QA1, agency 1)

**Agent activity (user ids; names in `users`)** — CMA reports uploaded (`market_reports.uploaded_by_user_id`, 436 total): Johan 151 · Gerda 53 · Maggie 53 · Retha 33 · Shawn 31 · Angelique 28 · Falan 21 · Barbara 16 · Jenny 10 · Kym 8 · Shalan 8 · Rochelle 7 · Elize 6 · others ≤4. Deeds captures (`tracked_properties.deeds_captured_by_user_id`, 1,912 total, 2026-08-20 →): Dru 578 · Maggie 442 · Gerda 295 · Angelique 194 · Jenny 133 · Barbara 95 · Retha 58 · Diva 48 · Elize 24 · Elise 15 · Shawn 14 · Kym 8 · Falan 7 · Johan 1. Extension adverts (`prospecting_listings.captured_by_user_id` = FIRST capturer only, no per-sighting user): Johan 44,919 · Gerda 2,995 · Elize 51 · Falan 22 · Barbara 2 · scratch 1. Capture sessions (`prospecting_searches`, 68 sessions, 9,994 pages, 92,935 listings sent; P24 56 sessions / 58,236, PP 12 / 34,699): Johan 52 · Gerda 13 · Falan 1 · Elize 1 · Barbara 1. Searched areas are free text in `search_description` ("Hibiscus Coast" 13, "Multiple Areas" 14, Margate 10, Umhlanga and other areas 5, Ballito 3, Uvongo 3, Hibberdene 2); no structured suburb list per session.

**Years owned (deeds-captured files with a registered/sold date, n = 1,908)** — avg 10.3 years; <3 y 350 · 3–6 y 497 · 7–10 y 322 · >10 y 739; sold price on 1,803; bond on 475 (469 with both). Computed as `COALESCE(deeds_registered_date, last_known_sold_date)` → today, dates before 1950 excluded.

**Ribbon / status counts (all portals)** — P24: active 14,503 · under_offer 1,048 · sold 251 · withdrawn 7,492 · NULL 2,233. PP: withdrawn 18,410 · NULL 4,053 (PP never sends status). 351 adverts flipped to sold/under_offer in the last 30 days. **State conflict:** 4,286 PP adverts carry `portal_status='withdrawn'` but `is_active=1` (re-sighted after a reconcile; a NULL status from the tile never clears the old 'withdrawn').

**Fell off the market** — detectable today only through `SuburbReconcileService` (complete captures): `portal_status='withdrawn'` + `off_market_at` = 25,902 adverts, 976 in the last 30 days, 17 suburbs reconciled in the last 30 days. Adverts still active but older than their own suburb's latest sighting: 937 in 14 suburbs (all 7+ days older; Amanzimtoti 169, Warner Beach 137, Winklespruit 122, Doonside 117, Lovu 110, Illovo Beach 93, last crawl 2026-10-12). Against the newest crawl overall, 8,897 active P24 adverts are unseen for 14+ days.
