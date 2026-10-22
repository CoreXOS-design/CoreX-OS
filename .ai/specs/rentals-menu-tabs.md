# Rentals menu — headings removed, groups become tabbed items

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre: "do the same with rentals and also in the sidebar remove the
> headers Maintenance"). Not committed / not on QA2.**
> ⚠ This **reverses part of AT-466** (Johan, 15 Oct 2026: "the rental menu is quite large, we need to group by actions. Command
> centre at the top"), which added the Letting / Leases / Maintenance / Set-up / Reports headings. The grouping by action is KEPT —
> each group is now one tabbed item instead of a heading — and Command Centre stays at the top. Johan must see this before it
> leaves QA2.
> Owner: Andre · Same mechanism and rules as `.ai/specs/real-estate-menu-tabs.md` (§2 there applies word for word).

## 1. The new Rentals menu (19 links under 5 headings → 9 items, no headings)

| # | Menu item | Tabs (in order) — each is today's page on today's route | Replaces |
|---|---|---|---|
| 1 | Command Centre | *(unchanged)* | Command Centre |
| 2 | Properties | *(unchanged — the Rentals entry of the shared Properties screen)* | Properties |
| 3 | Contacts | *(unchanged)* | Contacts |
| 4 | **Letting** | Applications · Application Authorisation · Core Matches · Rental Pipeline | the "Letting" heading's four screens |
| 5 | **Leases** | Leases · Inspections | Leases, Inspections |
| 6 | Notices | *(unchanged — already a tabbed screen, AT-466; a tab never holds tabs)* | Notices |
| 7 | **Maintenance** | Fault Reports · Job Cards · Crews · Parts & Labour Catalogue · Rental Work Orders · Suppliers & contractors · Fault Types | the "Maintenance" heading's six screens + Fault Types (was "Set-up") |
| 8 | Rental Take-On Import | *(unchanged)* | Rental Take-On Import |
| 9 | Reports | *(unchanged)* | Reports |

### 1.1 Gates per tab (copied verbatim from the sidebar before the change — not changed)

| Hub | Tab | Route | Feature | Permission / condition |
|---|---|---|---|---|
| Letting | Applications | `corex.rental-applications.index` | `rental-applications` | `rental_applications.view` |
| Letting | Application Authorisation | `corex.rental-applications.authorisation.index` | `rental-applications` | `rental_applications.authorise` AND the user is a named authoriser (`seesRentalAuthorisationQueue()`) |
| Letting | Core Matches | `corex.rentals.core-matches.index` | `core-matches` | `core_matches.view` AND setting `matches_enabled` |
| Letting | Rental Pipeline | `corex.rentals.pipeline.index` | `buyer-pipeline` | `buyer_pipeline.view` |
| Leases | Leases | `corex.leases.index` | `rental-leases` | `leases.view` |
| Leases | Inspections | `corex.rental-inspections.index` | `rental-inspections` | `rental_inspections.view` |
| Maintenance | Fault Reports | `corex.rental-fault-reports.index` | `rental-faults` | `rental_fault_reports.view` |
| Maintenance | Job Cards | `corex.rental-job-cards.index` | `rental-job-cards` | `rental_job_cards.view` |
| Maintenance | Crews | `corex.rental-crews.index` | `rental-crews` | `rental_catalogue.view` |
| Maintenance | Parts & Labour Catalogue | `corex.rental-catalogue-items.index` | `rental-catalogue` | `rental_catalogue.view` |
| Maintenance | Rental Work Orders | `corex.rental-work-orders.index` | `rental-work-orders` | `rental_work_orders.view` |
| Maintenance | Suppliers & contractors | `deals-v2.suppliers.index?nav=rentals` | core (`deals`) | `deals_v2.manage_suppliers` |
| Maintenance | Fault Types | `corex.rental-fault-types.index` | `rental-faults` | `rental_fault_types.view` |

## 2. Rules carried over

- Every page keeps its address; nobody gains or loses a screen (equivalence test, below).
- AT-461's short labels ("Crews", not "Rental Crews") are the tab labels; both the short name and the old "Rental …" name stay
  findable in the menu search, pointing at the tab (`old` may now list several names).
- Shared screens show the bar of the menu they were opened from: Core Matches / the pipeline on their Rentals routes show the
  Letting bar, on their sales routes the Real Estate › Buyers bar; the supplier directory shows the Maintenance bar only with
  `?nav=rentals` and no bar when opened from Deals. The lens highlighting (a match / buyer opened from the Rentals list keeps
  Letting lit) is unchanged.
- The panel keeps `data-manual-order`. The heading-hiding script stays in the sidebar (now unused here; harmless).
- No pillar data, migration, permission, feature key or agency setting — nothing for the Setup Wizard. Labels are neutral.

## 3. As built / proof

- `App\Support\Navigation\Hubs\{Letting,Leases,Maintenance}Tabs`, registered in `TabHubs`; landing routes `corex.hubs.letting`,
  `corex.hubs.leases`, `corex.hubs.maintenance` (resolve to the Rentals panel).
- `tests/Feature/Navigation/RentalsMenuTabsTest.php` — equivalence (5 user types × 13 tabs, incl. a named authoriser and a
  cut-down user), every one of the 13 tab screens opens with its bar and its own tab marked, the shared screens show the right bar.
- `TabHubSwitchesTest` now covers these three hubs too (feature off / Role Manager permission off hides each tab, back on returns it).
- `RentalsMenuGroupsTest` rewritten for the new nine-item order (still: Command Centre first, fixed order); `RentalsMenuLabelsTest`
  rewritten to check the short labels + both search names on the tabs. `NoticesHubTest` now reads the Notices link's own search
  entries (other tabbed items carry theirs too). Six `rental-applications.authorisation.*` entries came off the sidebar allow-list —
  those screens now light exactly one item (Letting).
- Guided tours: none of the 13 Rentals tab screens has a tour — nothing to carry over.
- Local note: the local dev DB is 286 migrations behind, so Applications and the supplier directory 500 on localhost (missing
  columns) — environment only; both render in the test suite against the current schema.
