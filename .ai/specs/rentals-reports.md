# Rentals Reports (AT-443)

**Status:** BUILT (partial, per Johan's own build brief — §0 below). Landed on QA1, 2026-10-04.
**Ticket:** AT-443. **Date:** 2026-10-04. **Pillar:** Property, Contact (tenant/landlord, as report
dimensions), Agent (`User`, as a report dimension and as the viewer).
**Master spec:** `.ai/specs/rentals-rebuild.md`.

This revision replaces the original design draft with what was actually built, per Johan's own
build brief for AT-443 (which took precedence over the draft on every point where the two differed —
each is called out below with **"Brief vs draft"**). The draft's original wording is preserved in git
history (`.ai/specs/rentals-reports.md` at commit `f52a13d4e`) for reference.

---

## 0. What's built, what's deferred, and why

Per the build brief, this round explicitly **excludes**:
- **The Outstanding/Debtors report (§8 of the original draft)** — Stage 8 (`rental-money.md`,
  invoices/payments) is not built yet; there is no data for this report to show. Not stubbed. See
  `rental-money.md` §9 for where this still lives as a forward-looking spec note.
- **"Notice given" / "Renewals in progress" as Lease Status ticks** — `leases.notice_date` (AT-444)
  does not exist on QA1 yet. The report reads `RentalCommandCentreService`'s own derived query, which
  already defines these two buckets (one hardcoded to 0, one already schema-ready but unwritten-to) —
  see §4.4 below. Nothing further is needed here when AT-444 ships; the report will start reflecting
  real values the same day the command centre's own tiles do, since both read the identical query.
- **The Tenancy report and the Job Card print** — both belong to other lanes' own screens (the Lease
  Hub, AT-440; the job card print, AT-442) and are cross-referenced from the Reports picker, not
  rebuilt here, exactly as the original draft already said.
- **The Property Rental tab entry point** for the Landlord Property Activity Report (§5) — that file
  (`properties/show.blade.php`) belongs to the Properties module, out of this ticket's own file scope
  per CLAUDE.md non-negotiable #4 ("stay in your lane"). The report is reachable from the Reports
  screen only.

**Built:** 6 period reports (Fault reports, Work orders, Job cards, Lease status, Lease expiries,
Inspections), 1 whole-history report (Property history — single-property, see §4.7's "Brief vs
draft"), 1 single-record PDF print (Landlord Property Activity Report), one shared shell, one JSON
API per report, PHPUnit coverage of status-tick defaults/narrowing, period boundaries, own/branch/all
scoping, agency isolation, totals-equals-sum-of-rows, group-by, and a no-N+1 query-count budget.

**Job cards and the Work Orders "done-by" filter were ORIGINALLY deferred, then built after all**:
AT-442 (job cards) landed on `origin/QA1` partway through this build (its own commit history shows
`RentalJobCard`/`RentalJobCardLine`/`RentalCatalogueItem` landing while AT-443 was already in
progress). The brief's own condition — "build ONLY if AT-442 is on `origin/QA1` when you land" —
flipped true before the final landing merge, so both are built, not left deferred. This is recorded
here rather than silently absorbed: the shared shell and report-picker screen were deliberately built
(per the brief's own note) so that adding a report key required no structural rework — exactly what
happened here, confirming that design held.

---

## 0a. Tidy pass (2026-10-05) — three defects found and fixed on QA1

1. **Duplicate "Property history" picker entry, one of them dead.** `RentalReportService::REPORTS`
   listed `'property-history' => 'Property history'`, and the picker's generic `@foreach($reports as
   $key => $label)` loop rendered it as an in-page entry (`?report=property-history`) alongside the
   real, separately-rendered link to `corex.rentals.reports.property-history` (§4.7). The in-page one
   was dead: `RentalReportController::runReport()`'s `match` has no `'property-history'` case, so it
   silently fell through to `default => $service->faultReports(...)` — rendering the Fault Reports
   grid (13 rows / 9 columns) under a mislabelled "Property history" heading. Fixed by removing
   `'property-history'` from `REPORTS` — it was never meant to be a flat-grid report key (it's a
   single-property timeline with its own controller actions/routes, not dispatched by `runReport()`),
   so it has no business being in the constant the generic picker and the API's key-validation both
   read from. The one remaining entry is the dedicated link; no dead route is linked anywhere.
2. **Lease status "Lease end"/"Term" columns silently never printed a real date.** Root cause:
   `active_start_date`/`active_end_date` are raw `SELECT`-aliased strings from
   `RentalCommandCentreService::derivedPropertyQuery()`, not Carbon instances (`Property::$casts` has
   no entry for either alias) — `optional($string)->format('Y-m-d')` returns `null` for **any**
   non-null value, not just a null one, because `Illuminate\Support\Optional::__call()` only forwards
   the method call when `is_object($this->value)` is true; for a plain string it falls through and
   returns nothing. So every fixed-term lease printed Term "– open" / Lease end "—" exactly like a
   genuine month-to-month lease with no end date at all — confirmed against real QA1 data (e.g. "1
   Kenmuir Road" has `active_end_date = "2027-05-31"`, a real date, yet rendered "—"). **Days left was
   never broken** — it's computed with `Carbon::parse($p->active_end_date)` directly, bypassing
   `optional()` entirely, so it already showed the correct number (238, 228, etc.) for those same
   rows. The column was the bug, not the number. Fixed by parsing before formatting
   (`$p->active_end_date ? Carbon::parse($p->active_end_date)->format('Y-m-d') : null`) for both Term
   and Lease end. A genuinely open-ended lease (`end_date` really `NULL`) still correctly prints "—"/
   "open" and `days_left` stays `null` — unchanged, covered by its own test. Since print/PDF/XLSX/CSV
   all render the same `RentalReportService::leaseStatus()` row array, fixing the one source fixes
   every output format at once — no per-format change needed.
3. **Lease status "Include" ticks rendered all-unticked on first load despite rows being shown.**
   `leaseStatus()` set `'selectedBuckets' => $tileFilter ? [$tileFilter] : []` — every other report
   method defaults an empty selection to "every bucket" via `defaultBucketKeys()`; this one didn't,
   leaving every tick visually unchecked while, underneath, no `?buckets[]=` means no `tile` filter is
   applied (`RentalCommandCentreService::applyTile()`'s `default` case is a no-op) — i.e. every
   property is shown, the same result as the explicit "Rental properties" tile. Fixed by defaulting
   `selectedBuckets` to `['all']` when no tile is selected, so the tick now matches what is actually on
   screen. Zero query-behaviour change (`tile => 'all'` and `tile => null` both hit `applyTile()`'s
   `default: break;`).
4. **Job cards labour/parts/total-cost totals and the Work Orders done-by filter: confirmed working,
   no defect found.** QA1 currently has **zero** `rental_job_cards` rows (table empty at the time of
   this check, 2026-10-05) — there was no live row to check against despite the task's premise, so
   this was verified two ways instead: (a) the existing factory-backed tests
   (`test_job_cards_aggregates_labour_hours_and_parts_from_its_own_lines`,
   `test_job_cards_total_cost_is_null_not_zero_when_unpriced`) already cover the aggregation logic and
   pass; (b) the Work Orders done-by filter was exercised directly against QA1's real data (4 live
   work orders: 2 with `agency_service_provider_id` set, 0 linked to a job card) — `done_by=supplier`
   correctly returned the 2 supplier-done orders, `done_by=own_team` correctly returned 0 (no job
   cards exist to link), and orders with neither signal were correctly excluded from both. No code
   changed for this item.

Tests: `tests/Feature/Rentals/RentalReportServiceTest.php` —
`test_reports_constant_does_not_list_property_history_as_a_flat_grid_report`,
`test_lease_status_lease_end_and_term_print_a_real_end_date_not_open`,
`test_lease_status_genuinely_open_ended_lease_still_prints_open`,
`test_lease_status_default_tick_reflects_the_unfiltered_view_actually_shown`.

---

## 1. What this screen does and why

One Reports screen under Rentals: `/corex/rentals/reports`, route `corex.rentals.reports.index`. A
report picker on the left, the chosen report on the right — one screen, no page per report, per the
brief. Every report shares one shape so an agent learns it once: pick a period, pick a scope, tick
which statuses to include/exclude, pick a grouping, search, sort, print/PDF/export.

## 2. Navigation

New entry in the Rentals nav panel (`rental-applications` Alpine group), immediately after "Command
Centre": **"Reports"**. Permission key (new): `rental_reports.view`. The Rentals panel's own
route-match list (`corex-sidebar.blade.php`) was widened to recognise `corex.rentals.reports.*`, same
pattern as AT-441's own entry — this screen's own nav entry now auto-opens the panel on landing. The
Rental Command Centre (AT-441) already shipped a forward-compatible `@if(Route::has('corex.rentals.reports.index'))`
link to this screen; it now resolves.

## 3. Shared report shell (every report below uses this, not reimplemented per report)

**Brief vs draft:** the brief's own wording is "Own | Branch | All", matching the SAME terminology
and mechanism every other rentals screen already uses (`PermissionService::getDataScope()` +
`clampScope()`, scope values `own`/`branch`/`all`) — not the draft's "Agency" relabelling. Built per
the brief, for consistency with the rest of the module.

- **Period** — date range picker with named presets. Backward-looking reports (Fault reports, Work
  orders, Inspections): this month (default), last month, last 30/60/90 days, this year, custom,
  any period. Forward-looking (Lease expiries only, since it reads a future `end_date`): next 30
  days (default), next 60, next 90, this month, custom, any period.
- **Scope** — Own | Branch | All, per `rental_reports.view`'s own ceiling, clamped a SECOND time
  against the underlying entity's own permission module (`rental_fault_reports`/`rental_work_orders`/
  `leases`/`rental_inspections`) so the report's own scope control can only narrow, never widen, past
  what the user could already see on that entity's own list screen (§9).
- **Include/exclude ticks per status** — see each report's own bucket table below. Every report
  defaults to **every bucket ticked** (a period report's natural default is the full picture; ticking
  narrows it) except Inspections, where "Cancelled" defaults OFF (an addition beyond the brief's own
  tick list, to keep the full status enum individually tickable per BUILD_STANDARD §1b — see §4.6).
- **Group-by** — per report, named below. "None" is always an option.
- **Search, sort, per-page** — per report, named below.
- **Print / PDF / Export (XLSX/CSV)** — every period report, no exceptions. Uses the project's
  existing `phpoffice/openspout` writer (same library `ContactExportController` already uses) for
  XLSX/CSV, and the project's existing `barryvdh/laravel-dompdf` convention (same dompdf options as
  `RentalDocumentPdfService`) for PDF.
- **Totals row** — sum of every numeric column (amount/rent/days-open columns), for the CURRENT
  filtered set, never just the current page.
- **Scoping at the query layer** — every report reuses the SAME `scopeVisibleTo()` each entity's own
  list screen already has (`RentalFaultReport`/`RentalWorkOrder`/`Lease`/`RentalInspection`), never a
  re-derived predicate — except Lease Status, which calls `RentalCommandCentreService` directly per
  the brief (§4.4).

## 4. The reports

### 4.1 Fault reports for a period

**Brief vs draft:** the brief's richer tick/grouping/column list won.

- **Ticks** (default: all): Outstanding (every non-terminal status — reported, awaiting landlord,
  approved, work-order-raised, owner-handling), Awaiting landlord, Approved, Resolved, Declined,
  Cancelled. The first bucket deliberately overlaps the two narrower ones — ticking "Outstanding"
  alone already includes awaiting-landlord/approved rows; the narrower ticks exist to isolate just
  one of those sub-states.
- **Group by:** property, landlord, agent, fault type.
- **Columns:** date reported, property, tenant, fault, urgency, status, outcome, days open, linked
  work order.
  - **Urgency** reads from the fault's linked `RentalFaultType.urgency` (routine/urgent/emergency) —
    not a field on the fault report itself. A fault report with no linked fault type (free-text
    title only) shows "—".
- **Search:** property address, tenant name, fault type name, title.

### 4.2 Work orders for a period

**Brief vs draft:** the brief's richer tick/grouping/column list won.

- **Ticks** (default: all): Outstanding (reported/ordered/in-progress), In progress, Overdue
  (computed — `RentalWorkOrder::scopeOverdue()`, the agency's own `overdue_reminder_days` setting,
  already live, reused, no new setting), Completed, Cancelled.
- **Group by:** property, supplier, trade.
- **Columns:** date raised, property, supplier, trade, quoted amount, status, paid by, days open.
  - **Quoted amount** = the selected quote's `amount` (`rental_work_order_quotes.is_selected`), falling
    back to `cost_amount` when no quote has been selected yet.
- **"Done by" filter** (supplier vs. own team) — **built**, once AT-442 landed mid-build (see §0): a
  work order is "own team" when it has a linked `RentalJobCard` (1:1, `rental_work_order_id`);
  "supplier" when `agency_service_provider_id` is set directly. The two are mutually exclusive in
  practice.
- **Search:** property address, tenant name, supplier name, title.

### 4.3 Job cards for a period

**Built** after AT-442 landed mid-build — see §0. One row per job card (same per-record granularity
as every other report here).

- **Ticks** (default: all): Outstanding (draft/quoted/approved/scheduled/in-progress), In progress,
  Overdue (`RentalJobCard::scopeOverdue()` — its own `due_at`, no agency setting needed), Completed,
  Cancelled.
- **Group by:** crew member, property.
  - **"By labour/part item"** (named in the original draft) is **not built** — that grouping needs one
    row per LINE, which doesn't fit this report's per-job-card column shape (date/property/crew/total
    cost); a materials-usage breakdown would be a different report. Reported as a deviation, not
    silently dropped.
- **Columns:** date, property, crew member, labour hours, parts used, status, total cost.
  - **Labour hours** = sum of `quantity` across the job card's own `labour`-type lines.
  - **Parts used** = the job card's own `part`-type lines' descriptions (+ quantity), comma-joined.
  - **Total cost** is `null` (renders as "—", never a forced `0`) when the job card's `total_amount`
    hasn't been priced yet — per the original draft's own instruction.
- **Search:** property address, crew member name, title.

### 4.4 Lease status

**Brief vs draft:** built to call `RentalCommandCentreService` directly (`derivedPropertyQuery()` /
`tableQuery()` / `applyTile()` / `tileCounts()`), per the brief's explicit instruction, so this report
and the command centre can never disagree. One row per rental PROPERTY (matching the command centre's
own property-first model), not one row per lease.

- **Buckets available** (single-select, mirrors the command centre's own `?tile=`): the same ten
  tiles `RentalCommandCentreService::TILES` defines — Rental properties, Occupied, Unoccupied,
  Expiring in window, Notice given (hardcoded 0 today — AT-444), Renewals in progress (0 until a
  `Renew` action exists — AT-444), Month-to-month, Open faults, Open work orders, Inspections due.
- **Group by:** branch, agent.
- **Columns:** property, tenant, status, rent, term, lease end, days left.
- **Expiring-in-window** reuses `LeaseSetting::expiryNoticeWindowDaysFor()` — the EXISTING
  renewal-reminder lead-time agency setting — no second setting added, per the brief's instruction.
- **Search:** property address, tenant name, erf number (via the command centre's own search).

### 4.5 Lease expiries

**Brief vs draft:** the brief's "next 30/60/90 or any period" + landlord/rent columns won over the
draft's "by month of expiry" grouping (kept as an additional, non-conflicting `group_by` option).

- **Period:** forward-looking (next 30/60/90 days, this month, custom, any) against `leases.end_date`
  on ACTIVE leases only.
- **Group by:** none (default), month of expiry.
- **Columns:** property, tenant, landlord, rent, end date, days left.
  - **Landlord** via `Property::sellerOwnerContact()` (the existing derive-don't-duplicate relation —
    audit Part 3 item G, unchanged here).
- **Search:** property address, tenant name.

### 4.6 Inspections

**Brief vs draft:** "due"/"overdue" are not raw statuses on `RentalInspection` (only `scheduled_for`
exists) — both are derived against today's date for inspections still in an open status
(draft/in_progress). "Cancelled" is added as a 5th, default-OFF tick beyond the brief's own four, so
the full status enum stays individually tickable per BUILD_STANDARD §1b ("every status individually
tickable, not a single on/off toggle").

- **Ticks** (default: Due, Overdue, Awaiting signature, Completed — all ON; Cancelled OFF).
- **Group by:** property, type, inspecting agent.
- **Columns:** property, lease, type, due/scheduled date, status, signed date.
  - **Signed date** = `MAX(disposition_recorded_at)` across that inspection's own
    `rental_inspection_signatures` where `disposition='signed'` and not superseded — a single
    correlated-subquery aggregate (no N+1), not the full `signatureSummaryRows()` relation walk the
    inspection's own detail page uses (that walk is per-record UI, too heavy for a report grid).
- **Search:** property address.

### 4.7 Property history

**Brief vs draft, reported explicitly as a deviation:** the ORIGINAL draft (and the version Johan
approved on 4 Oct before this build brief) described this as covering "every property in the selected
scope/period at once." **The build brief instead says "pick a property"** — a single-property,
whole-history timeline, no period filter. The brief's wording governs this build; the deviation is
recorded here rather than silently reconciled, per the task's own instruction ("where this brief
differs, this brief wins and you report the difference").

- **Shape:** pick one property (own/branch/all-scoped picker, re-checked server-side on submit —
  direct-URL-by-`property_id` is blocked for a property outside the viewer's scope, not just
  unlinked). Output: one block per lease ever recorded on that property (newest first), each showing
  tenant/start/end/rent and every fault/work-order/inspection event that falls inside that lease's own
  span — plus a trailing "Between tenancies" block for property-level fault/work-order rows with no
  `lease_id` (vacancy-period items; inspections are never property-level, since
  `rental_inspections.lease_id` is a required FK, confirmed by migration).
- **No group-by/search/sort/pagination** — a single property's full history is expected to be small;
  if that assumption breaks on real data, paginate (flagged, not built defensively here, per
  BUILD_STANDARD §0).
- **Print/PDF:** yes, same property, full history.

## 5. Single-record prints (not period reports — one record, printed/PDF'd on demand)

- **Tenancy report** — belongs to the Lease Hub (AT-440, cc3's own build, not yet on QA1 at the time
  of this build). Not built here. No cross-reference link rendered yet (the route does not exist to
  link to) — add the link in the AT-440 landing, not here, once that route exists.
- **Landlord property activity report** — **BUILT.** Property + period (defaults to "this month", any
  preset available) → faults, work orders (with amounts), inspections, as a PDF. Reachable from the
  Reports screen's Property History picker only (see §0 — the Property Rental tab entry point named in
  the original spec is NOT added in this build, out of this ticket's own file scope).
- **Job card** — belongs to AT-442 (job cards, not yet on QA1). Not built.

## 6. Search / sort / filter / pagination (BUILD_STANDARD §1b) — on each report's own result grid

- **Search:** per report, named in §4 above.
- **Sort:** every column is independently sortable via its header link; stated defaults — Fault
  reports: date reported, descending. Work orders: date raised, descending. Lease status: property,
  ascending. Lease expiries: end date, ascending. Inspections: scheduled date, descending.
- **Filter:** the shared shell's period/scope/status-ticks/group-by/entity-specific filters (trade
  type on Work Orders; agent/branch on Lease Status; type on Inspections) ARE the filter — no separate
  second filter layer, per the draft's own instruction.
- **Pagination:** 10/25/50/100 per-page selector (default 25) on the result grid; PDF/print/export
  always render the FULL filtered set, never just the current page.
- **Empty state:** "No {report} in this period for this scope" on every report — distinguishes a
  genuinely quiet period from a misconfigured filter.

## 7. API

`GET /api/v1/rentals/reports/{report-key}` — ONE route (`v1.rentals.reports.show`, versioned, named,
auto-listed on `/admin/api`), dispatching on `{report-key}` ∈ `fault-reports` / `work-orders` /
`job-cards` / `lease-status` / `lease-expiries` / `inspections` (NOT `outstanding` — §0). Same
`RentalReportService` the web screen reads from, same scope/filter query params, same
own/branch/agency guard. Response: `columns`, `rows` (current page, `_model`/`_group` keys stripped),
`groups`, `totals`, `count`, pagination metadata.

## 8. Outstanding (Debtors) report — NOT BUILT

Per the task brief, explicitly excluded from this round (Stage 8/`rental-money.md` does not exist on
QA1 yet — no invoices/payments table to report on). No stub route, no stub screen, no placeholder
report-picker entry. The original draft's full design (aging buckets, drill-down, owner-side toggle)
is preserved below, UNCHANGED, as the forward spec for whenever Stage 8 (AT-446) builds it — nothing
in this section has been built or altered by this round.

### 8.1 Shape
One row per tenant/lease: total outstanding + an aging analysis. **Aging buckets are agency-
configurable**, default **Current / 30 / 60 / 90 / 120+ days**, measured from each unpaid/part-paid
invoice's own due date (never from the report-run date relative to the lease, per invoice, per
bucket — an invoice doesn't change bucket just because a different, newer invoice on the same lease
is also unpaid).

### 8.2 Drill-down
**Every amount is clickable** and drills into exactly the invoices that make it up — same breakdown
shape as the Lease Hub's own outstanding-indicator click-through (`rental-money.md` §9.1): invoice
date, charge type, amount, paid, balance, per line.

### 8.3 Scope, search, sort, filter
- **Own | Branch | Agency.**
- **Search:** tenant name, property address.
- **Sort:** any column, default = total outstanding (largest first).
- **Filters:** property, landlord, agent, charge type (per Addendum 2's own wording) — in addition
  to the shared shell's period/scope.
- **Totals row** — sum of every bucket + grand total, for the current filtered set.

### 8.4 Owner-side view
A second mode on the same report: amounts owed **to each owner** (what the agency has collected on
their behalf but not yet paid out) rather than amounts owed **by** each tenant — same aging-bucket
mechanic, same drill-down, grouped by landlord instead of by tenant. Toggle between the two modes on
the same screen, not two separate reports.

### 8.5 Query-layer scoping (per instruction, stated explicitly)
Both modes enforce own/branch/agency at the query layer exactly as every other list/report in this
module (BUILD_STANDARD §1c) — a branch-scoped user never sees another branch's debtors or another
branch's owner payables, and direct-URL access to a specific lease/owner's drill-down re-checks scope
independent of how the request arrived.

### 8.6 API
`GET /api/v1/rentals/reports/outstanding` (tenant mode) and `GET /api/v1/rentals/reports/outstanding?view=owner`
(owner mode) — one endpoint, one query param, not two parallel implementations.

### 8.7 Acceptance criteria
- [ ] Aging buckets are agency-configurable with the stated default, surfaced in the Setup Wizard.
- [ ] Every amount on the report is clickable and opens the correct, exact invoice breakdown.
- [ ] Tenant mode and owner mode share one screen, one toggle, one API endpoint.
- [ ] Scope/search/sort/filter/totals-row all function per §8.3.
- [ ] Direct-URL access to a drill-down outside the user's scope is blocked, not just unlinked.

## 9. Permissions

`rental_reports.view` — single access key for the whole Reports screen (reports are read-only; no
separate create/edit/archive action exists on this screen). **Built exactly as specced**: report data
still respects each underlying entity's own view-scope ceiling — a user whose `rental_fault_reports.view`
ceiling is "own" sees only "own" fault-report rows in the Fault Reports report regardless of what they
pick in the report's own Own/Branch/All control; the report's scope control can only narrow, never
widen, past the user's real ceiling on that entity. Covered by
`test_fault_reports_report_scope_cannot_widen_past_the_entity_ceiling`.

## 10. Acceptance criteria (whole-screen)

- [x] Six period reports (§4.1-4.4, 4.5, 4.6) + Property History (§4.7) + the Landlord Activity
      single-record print (§5) are reachable from one Reports screen. Outstanding (§8) is NOT
      built — see §0.
- [x] Every built report's shared shell (period/scope/status-ticks/group-by/search/sort/print/PDF/
      export/totals) functions identically across all of them.
- [x] Every report enforces own/branch/agency scoping at the query layer — covered by
      `RentalReportServiceTest`'s agency-isolation, own-scope, and ceiling-clamp tests.
- [x] Every report has a real empty state.
- [x] The one JSON API endpoint (`v1.rentals.reports.show`) is versioned, named; `/admin/api` catalogue
      listing follows the same route-table auto-discovery every other `v1.*` route already uses —
      not independently re-opened in a browser this build (same caveat AT-441's own spec recorded).

## 11. Open questions for Johan

None structural. Two items carried forward from the master spec, unchanged by this build:
- Default aging buckets for the (not-yet-built) Outstanding report — master spec §6, Stage 8.
- Exact "expiring in window" threshold — already resolved by reusing the existing
  `LeaseSetting::expiryNoticeWindowDaysFor()` setting; no second threshold introduced.
