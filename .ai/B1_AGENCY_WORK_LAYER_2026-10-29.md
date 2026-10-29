# B1 - Per-agency work layer: investigation (Lane B, 2026-10-29)

> Read-only investigation of QA1 `5460a660f`. Nothing built. File:line lists are in
> `.ai/B1_AGENCY_WORK_LAYER_APPENDIX_2026-10-29.md`. Johan's rulings (29 Oct): the MIC pool is shared and holds public portal facts only;
> each agency has its own work layer on top, never visible to another agency.

## 1. The one thing to know first

**There is no shared pool today.** Every MIC and tracked-property row belongs to exactly one agency:

| Table | Has `agency_id` | Scoped by agency (`BelongsToAgency`) | Unique key |
|---|---|---|---|
| `tracked_properties` | yes, NOT NULL | yes | `external_id` (a UUID) |
| `tracked_property_external_refs` | yes | yes | `(agency_id, source_type, source_ref)` |
| `tracked_property_addresses` | yes | yes | |
| `prospecting_listings` (the MIC rows) | yes | yes | `(agency_id, portal_source, portal_ref)` |
| `prospecting_claims`, `tracked_property_comments`, pitch locks, seller removals, price anomalies, date corrections, buyer matches | yes | yes | |
| `tracked_property_owners` (names, ID numbers) | **no** - hangs off the tracked property | no | |
| `prospecting_price_history` | **no** - hangs off the MIC row | no | |

So today "agency 1 claims, agency 2 sees nothing" is already true, but only because agency 2 has its *own copy* of the property and its own copy
of the advert. The facts are duplicated per agency, not shared. The ruling needs the opposite: **one row per real property and per advert, work on
top per agency.** That is two changes, not one, and they must land in order:

1. **Work layer** (this lane): give each agency a work record that holds everything that is private. After this, the shared row carries only public facts.
2. **Pool sharing**: make the row itself agency-less (read by every agency, enriched by any agency's scrape), change the unique keys from
   `(agency_id, ...)` to `(source_type, source_ref)`, and merge the per-agency duplicates already in existence. Today only HFC has MIC data
   (45,440 rows, audit leg 1), so on QA1 there is nothing to merge yet. **This step is the same code Lane A is rewriting** (canonical portal
   refs + the identity resolver both key on `(agency_id, source_type, source_ref)`), so it should not be done by two lanes.

## 2. What carries per-agency WORK today

### 2.1 On the shared property row (`tracked_properties`) - to move to the work record

| Column(s) | What it is | Private? |
|---|---|---|
| `owner_contact_id` | which of the agency's contacts is the owner | yes - a contact is an agency's own record |
| `promoted_to_property_id`, `promoted_at`, `promoted_by_user_id` | "we hold this" - link to the agency's own stock row | yes - and it is the own-stock flag (B3) |
| `bond_holder`, `bond_amount`, `sale_type`, `deeds_registered_date`, `deeds_office`, `deeds_captured_at`, `deeds_captured_by_user_id`, `capture_kind`, `ownership_parse_status`, `ownership_parse_note`, `title_deed_number`, `municipal_valuation`, `municipal_valuation_year` | the paid deeds data | yes - Johan: deed data is private, paid for |
| `status` (active / archived / duplicate / promoted), `duplicate_of_tracked_property_id` | the agency's own archive / merge decisions | yes - agency 1 archiving must not hide it from agency 2 |
| `source_chain` | audit of who contributed which fact (carries user ids) | the facts stay, the *who* is agency-private |
| `is_demo` | demo flag | stays on the row (not work) |

Public facts that **stay** on the shared row: address parts, suburb, GPS, erf number/portion/size, scheme/section, property type, beds/baths/garages,
sizes, last asking / sold price and date (portal and deeds public record), the structured-address columns, `lpi_code`.
*Judgement call to confirm:* `title_deed_number`, `municipal_valuation` and the sale price come from the paid deeds source. Ruling says deed data is
private, so I treat them as work; the last sold price/date stay shared because they also arrive free from portals and CMA reports.

### 2.2 On the shared MIC row (`prospecting_listings`) - to move to the work record

| Column(s) | What it is |
|---|---|
| `matched_property_id`, `matched_at`, `possible_property_id`, `possible_match_verdict`, `possible_match_candidate_ids`, `possible_matched_at` | "this advert is OUR stock" link and the suggested links - per agency |
| `linked_deed_tracked_property_id`, `linked_deed_by_user_id`, `linked_deed_at` | agent's private link from an advert to a deed |
| `pitched_at` | the agency pitched this seller |
| `captured_by_user_id`, `branch_id` | which agent / branch of that agency captured it |

Everything else on the MIC row (portal ref and url, address, price, beds, size, agency name, status, first/last seen, thumbnail) is public portal fact and stays.

### 2.3 Tables that are already per-agency work (they keep their shape, and keep `agency_id`)

`prospecting_claims` (claim agent, status, notes, claimed/pitched/feedback/released dates), `tracked_property_comments`, `prospecting_pitch_locks`,
`prospecting_seller_removals`, `prospecting_listing_date_corrections`, `prospecting_buyer_matches`, `prospecting_price_anomalies`, `rts_prospects` and the
Road to Sold tables. These point at the shared rows by id; once the rows are shared they simply keep working, because they already carry the agency.
The brief's single table would *index* them (one work record per agency per property: claim state, status + reason, own-stock flag, deed link, who/when)
rather than copy the comments and claim history into it - copying would create two truths.

### 2.4 A leak the move does NOT fix by itself

`tracked_property_owners` (owner name + ID number, from the deeds capture) has **no `agency_id`** and is keyed only by `tracked_property_id`. Once
the property row is shared, every agency would read the owners another agency paid for. It must get `agency_id` (backfill = the property's agency) in
step 1, before the row is shared.

## 3. Who reads the work fields (full list in the appendix)

- **Market Intelligence screen** `MarketIntelligenceController` (3,900+ lines): list filters, KPI counts, claim create / feedback / release / resolve-as-own-stock
  (`:208-231` pitched/claimed exclusion, `:704-740` own stock, `:914` comment counts, `:1033-1035` claim KPIs, `:3392-3911` claim writes), cached per agency by
  `ProspectingClaim::countsCacheVersion`.
- `ProspectingListingStateEnricher`, `PropertyIntelligencePanelService`, `SuggestedActionResolver`, `ProspectingClaimService` (locks, claims, stale sweep),
  `OnMarketStockService`, `MicPropertyReconciliationService`, `ProspectingStockMatchService` + `ProspectingListingObserver` (the own-stock match).
- **Tracked Properties** `TrackedPropertyController` (comments, promote), `corex/tracked-properties/show.blade.php`.
- **Deeds** `Api/DeedsCaptureController` and `CoreX/DeedsCaptureController` (bond, owners, owner link, promote), `DeedsCaptureLinkService`, `OwnerContactResolver`.
- **Map** `MapController` / `MapPinService` / `MapProspectStatusService` (owner, deeds, promoted state on pins).
- **Road to Sold** (`ProspectListService`, `OwnStockSelection`, `OwnerKnownSelection`, `MicPoolSelection`, `OtherAgentStock`, `PitchPathService`, `TaskCaptureService`)
  reads `owner_contact_id` and `promoted_to_property_id`.
- Also: Presentations (`CompetitorStockMatchService`, `HoldingCostEstimator`), P24/PP lead services, `ContactAddressPropertyGuard`, `Contact`, seller outreach.

Size of the rewrite: roughly **85 files** touch these fields, 36 of them `promoted_to_property_id` and 36 `matched_property_id`.
Some queries already step outside the agency scope on purpose (`withoutGlobalScopes()` in the MIC controller, scheduled commands, `Contact:1242`): each of
those needs an explicit agency condition once the rows are shared, or it becomes a cross-agency read.

## 4. Email-alert and portal-capture tables

| Table | State today | Defect for a second agency |
|---|---|---|
| `p24_listings` (email alerts) | HAS `agency_id` and the agency scope (the audit called it global; it is not) | its **unique key is `p24_listing_number` alone**, so the database refuses a second agency's row for the same advert number. What the importer does then was not verified: its lookup (`P24ImapImportService:142`) relies on the agency scope, which may not apply in a scheduled job with no signed-in user (UNVERIFIED) - in which case agency 2's import would quietly update agency 1's row instead. Fix: unique `(agency_id, p24_listing_number)` and an explicit `agency_id` condition in the lookup. Several readers also count or average **all** rows (`Admin/P24Controller`, `AbsorptionInflowService:53,159,274`, `P24MarketDataService:18-138`) - these read through the scope, so they are fine today and must be re-checked when the alert rows become shared. |
| `portal_captures` | **no `agency_id`**, no scope; `user_id` only | any agency's capture rows are readable by id; `PortalListingTrackingService` and `BackfillTrackedProperties:201` infer the agency from the capturing user |
| `portal_listings` | **no `agency_id`**, no scope | `source_site + portal_listing_id` is unique globally, so two agencies capturing the same advert write one shared row with `last_capture_id` pointing at whoever captured last |
| `portal_listing_observations` | **no `agency_id`**, no scope | same |

Plan: add `agency_id` (indexed) to the three portal tables, backfill from `portal_captures.user_id -> users.agency_id` (existing rows are HFC's), stamp on
write, add the scope, and fix the alert unique key. These are independent of Lane A and can be done first.

## 5. Proposed build (one commit per step, QA1 deploy per step)

| Step | What | Visible change |
|---|---|---|
| **S1** | Alerts + portal captures: agency columns, backfill (HFC), unique-key fix, scope, tests with two agencies. | none for HFC |
| **S2** | `tracked_property_agency_work` (unique `agency_id + tracked_property_id`): claim pointer, prospecting status + reason, own-stock flag, private deed link, owner contact, deeds-private fields, archive/duplicate state, history rows. Migration + backfill from HFC's values. `tracked_property_owners.agency_id` + backfill. A single `AgencyWork` service is the only reader/writer. | none (rows are still per agency) |
| **S3** | Rewrite readers and writers (MIC list/detail/claim/status/comment, tracked properties, deeds, map, Road to Sold, presentations) to go through the work record; old columns stop being read, then are dropped in a later migration once QA1 has run a week. | none for HFC; own-stock flag follows the "our stock" tick |
| **S4** | Prospecting status screen action ("not selling", "mandate with another agency", ...) written to the work record, plus the own-stock flag per agency (B3). | agents see their agency's own status |
| **S5 (after Lane A)** | Make the pool shared: row loses its agency, keys become agency-less, merge duplicates, every list reads shared facts + the viewing agency's work. | agency 2 sees HFC's public facts, never HFC's work |

Tests at each step: two agencies on one property (claim / status / comment / owner / deed by agency 1 invisible to agency 2), own-stock flag per agency,
scoping on every MIC list and export, direct-URL access blocked.

## 6. Decisions needed from the conductor (technical coordination, not a business question for Johan)

1. **Who does S5, the pool sharing?** It rewrites the same match keys Lane A is rewriting. Recommendation: Lane A owns the shared-row keys and the merge;
   I deliver S1-S4 so that, when the row is shared, the work layer is already in place and nothing private moves with it.
2. **Order.** S1-S4 change no behaviour for HFC and can ship on QA1 now, before Lane A lands. The one risk is a clash on `TrackedPropertyMatchOrCreateService`
   (it writes `owner_contact_id`, `promoted_*`, deeds fields): I will touch only those writes and route them through the work service, and not the match,
   resolver or normaliser code.
3. **Deed price fields.** Confirm they count as private (my reading of "deed data, private, paid for"), with the last sold price/date staying shared.

Nothing was built or changed in this step. On go: S1 first.
