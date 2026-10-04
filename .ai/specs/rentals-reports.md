# Rentals Reports (AT-443)

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026.
**Ticket:** AT-443. **Date:** 2026-10-04. **Pillar:** Property, Contact (tenant/landlord, as report
dimensions), Agent (`User`, as a report dimension and as the viewer).
**Master spec:** `.ai/specs/rentals-rebuild.md`. **Addendum:** this file also carries the Outstanding
(Debtors) report (§8), folded in from Johan's Addendum 2 (2026-10-04) on `rental-money.md` — written
here, not duplicated there, because it is a report, not a money-engine concept; `rental-money.md` §9
cross-references this section rather than restating it.

---

## 1. What this screen does and why

One Reports screen under Rentals, replacing the pattern of "no reporting at all" the audit confirmed
(no rentals-specific reporting surface was found anywhere in the current build). Every report shares
one shape so an agent learns it once: pick a period, pick a scope, tick which statuses to
include/exclude, pick a grouping, print/PDF/export.

## 2. Navigation
New entry in the Rentals nav panel: `corex.rentals.reports.index`. Permission key (new):
`rental_reports.view`.

## 3. Shared report shell (every report below uses this, not reimplemented per report)
- **Period** — date range picker, with named presets (this month, last month, this quarter, custom).
- **Scope** — Own | Branch | Agency (per master spec §1.1 — Agency, not "All," since a report is an
  agency-wide instrument by nature; "Own"/"Branch" narrow it for a user whose permission ceiling
  doesn't reach Agency).
- **Include/exclude ticks per status** — the relevant entity's own status list, every status
  individually tickable, not a single on/off toggle.
- **Grouping** — per report, named below.
- **Print / PDF / Export (CSV)** — every report, no exceptions.
- **Scoping at the query layer** — identical own/branch/agency enforcement as every other rentals
  screen (BUILD_STANDARD §1c) — a report is a read surface, not exempt from the scoping floor.

## 4. The reports

### 4.1 Fault reports for a period
Grouping: by property, by fault type, by outcome. Columns: date reported, property, fault type,
outcome, days to resolution.

### 4.2 Work orders for a period
Grouping: by property, by supplier, by status. Columns: date raised, property, supplier/own-team,
amount, status, days open.

### 4.3 Job cards for a period (labour/parts used)
Grouping: by crew member, by property, by labour/part item. Columns: date, property, crew member,
labour hours, parts used, total cost (where priced — blank where the agency doesn't price internal
labour, per `rental-work-orders.md` §14.3, never a forced zero).

### 4.4 Lease status (occupied, unoccupied, month-to-month, expiring, notice given, renewals in progress)
Grouping: by property, by branch, by agent. One row per lease/property, current status bucket per
`leases.md` §12.5's transition set. Columns: property, tenant, status bucket, rent, term/notice date.

### 4.5 Lease expiries
Grouping: by month of expiry. Columns: property, tenant, end date, days remaining, notice/renewal
state. This is the reporting-surface companion to the Command Centre's "expiring in window" tile
(`rental-command-centre.md` §3.1) — same underlying query, a different presentation (a point-in-time
operational view there, a period report here).

### 4.6 Inspections (due, overdue, awaiting signature, completed)
Grouping: by property, by type (in/out/ad-hoc), by inspecting agent. Columns: property, lease, type,
due/scheduled date, status, signed date(s).

### 4.7 Property history
Grouping: by property. One row per historical event on that property across its rental life (lease
start/end, inspections, faults, work orders) — the report-surface form of the occupancy history added
to the Property Rental tab (`leases.md` §12.6), covering every property in the selected scope/period
at once rather than one property at a time.

## 5. Single-record prints (not period reports — one record, printed/PDF'd on demand)
- **Tenancy report** — the Lease Hub's own print action (`leases.md` §12.2), listed here because it
  lives in the same "Rentals → printable output" family a user would look for under Reports; the
  action itself originates on the Lease Hub, not a new entry point.
- **Landlord property activity report** — one property's full rental activity (leases, inspections,
  faults, work orders, documents) for the owner — printable from the Property Rental tab and
  reachable from this Reports screen as "run for a specific property."
- **Job card** — the printable worker-facing job card (`rental-work-orders.md` §14.2) — same
  cross-reference pattern as the tenancy report above.

## 6. Search / sort / filter / pagination (BUILD_STANDARD §1b) — on each report's own result grid
- **Search:** property address / tenant / supplier / crew member name, per report (named in each
  report's own column list above).
- **Sort:** every report's default is the grouping's natural order (soonest/most-recent-first per
  §4's definitions); every column remains independently sortable.
- **Filter:** the shared shell's period/scope/status ticks ARE the filter — no separate second filter
  layer.
- **Pagination:** standard page size, applies to the result grid, never to the PDF/print output
  (which renders the full filtered set).
- **Empty state:** "No {report} in this period for this scope" — distinguishes a genuinely quiet
  period from a misconfigured filter (e.g. ticking only a status with zero rows).

## 7. API
`GET /api/v1/rentals/reports/{report-key}` per report (e.g. `fault-reports`, `work-orders`,
`job-cards`, `lease-status`, `lease-expiries`, `inspections`, `property-history`, `outstanding` —
§8), same scope guard, same filter query params as the screen itself — Andre's mobile app or any
future BI export reads the identical endpoint, not a second implementation of each report's query.

## 8. Outstanding (Debtors) report — Johan's Addendum 2, 2026-10-04
**Depends on:** `rental-money.md` (Stage 8 — invoices, payments, allocations). This report has no
data to show until Stage 8 ships; specced here now so Stage 8's data model is built with this report
as a known consumer from day one, not retrofitted.

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
independent of how the request arrived (same per-record guard pattern as `rentals-foundation-at439.md`
§4.1, applied to the invoice rows this report drills into).

### 8.6 API
`GET /api/v1/rentals/reports/outstanding` (tenant mode) and `GET /api/v1/rentals/reports/outstanding?view=owner`
(owner mode) — one endpoint, one query param, not two parallel implementations.

### 8.7 Acceptance criteria
- [ ] Aging buckets are agency-configurable with the stated default, surfaced in the Setup Wizard
      (`rental-money.md` §9's own wizard entries cover this — not duplicated here).
- [ ] Every amount on the report is clickable and opens the correct, exact invoice breakdown.
- [ ] Tenant mode and owner mode share one screen, one toggle, one API endpoint.
- [ ] Scope/search/sort/filter/totals-row all function per §8.3.
- [ ] Direct-URL access to a drill-down outside the user's scope is blocked, not just unlinked.

## 9. Permissions
`rental_reports.view` — single access key for the whole Reports screen (reports are read-only; no
separate create/edit/archive action exists on this screen). Report-specific data still respects each
underlying entity's own view-scope ceiling (a user who can only see "Own" leases sees only "Own"
leases' data in the lease-status report, regardless of what they tick in the report's own scope
control — the report's scope control can only narrow, never widen, past the user's real ceiling).

## 10. Acceptance criteria (whole-screen)
- [ ] All seven period reports (§4) + the three single-record prints (§5) + the outstanding report
      (§8) are reachable from one Reports screen.
- [ ] Every report's shared shell (period/scope/status-ticks/grouping/print/PDF/export) functions
      identically across all of them.
- [ ] Every report enforces own/branch/agency scoping at the query layer, verified per report.
- [ ] Every report has a real empty state.
- [ ] Every report's API endpoint is versioned, named, and appears in the `/admin/api` catalogue.

## 11. Open questions for Johan
None structural — this stage reads existing (and Stage-8-forthcoming) data; no new business rule is
introduced beyond the aging-bucket defaults already flagged in the master spec §6 and repeated in
§8.1 above.
