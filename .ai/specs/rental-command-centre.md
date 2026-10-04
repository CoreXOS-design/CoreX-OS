# Rental Command Centre (AT-441)

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026.
**Ticket:** AT-441. **Date:** 2026-10-04. **Pillar:** Property (primary — every row is a property),
Contact (tenant/landlord, read-only context), Agent (`User`, who acts from the queue).
**Master spec:** `.ai/specs/rentals-rebuild.md` — read its §1 (shared standards) first.
**Builds on:** `leases.md` (lease data + §12 Lease Hub), `rental-work-orders.md` (fault/work-order
counts), `rental-inspections.md` (inspection-due counts), all already-built or specced in this round.

---

## 1. What this screen does and why

Today an agent has no single place to see every rental property's current state across the whole
module — they check Leases for expiries, Fault Reports for open issues, Work Orders for pending
jobs, and Inspections for what's due, as four separate screens with no shared view. The Command
Centre is the first item in the Rentals menu (master spec §2, row 3) — the "walk in, see everything
that needs attention, act" screen the module has been missing.

---

## 2. Navigation

New first entry in the existing Group-1 Rentals nav panel (`corex-sidebar.blade.php:1008-1205`),
above "Rental Applications." Route: `corex.rentals.command-centre.index`. Permission key (new):
`rental_command_centre.view`.

---

## 3. Layout

### 3.1 Tiles (clickable, filter the table below — master spec §1.1 tile standard)
| Tile | Definition |
|---|---|
| Rental properties | count of properties with `listing_type=rental` ever having had a lease (or currently marked for rental use) |
| Occupied | properties with an active lease today |
| Unoccupied | rental properties with no active lease today |
| Expiring in window | active leases with `end_date` inside the agency's reminder-lead-time setting (`LeaseSetting::expiryNoticeWindowDaysFor()`, already live) and no notice/renewal recorded yet |
| Notice given | leases with `notice_date` set (new column, `leases.md` §12.5.3) |
| Renewals in progress | leases with a draft renewal lease linked (`leases.previous_lease_id`, Stage 6) not yet signed |
| Month-to-month | active leases with no `end_date` |
| Open faults | `rental_fault_reports` not in a terminal outcome state, this agency |
| Open work orders | `rental_work_orders` not `completed`/`cancelled` |
| Inspections due | leases with an in-inspection or out-inspection due per their lifecycle state (`leases.md` §12.2 lifecycle-strip logic, reused here) |

### 3.2 Needs-action queue
One row per property/lease needing a human action, one action button per row. Rules (each stated so
the table is a deterministic derivation, never a stored flag):
| Trigger | Action button | Fires when |
|---|---|---|
| Lease expiring, no notice/renewal yet | **Renew** | inside reminder window (tile 4 above) |
| Notice given, out-inspection not done | **Start inspection** | `notice_date` set, no completed out-inspection |
| Active lease, in-inspection not done | **Start inspection** | lease active ≥0 days, no completed in-inspection |
| Open fault with no work order yet | **New work order** | `rental_fault_reports` open, `rental_work_order_id` null |
| Unoccupied rental property, no active lease, no draft lease in progress | **Open property** (to begin letting it) | no active/draft lease on an otherwise-rental property |
| Lease ended, no out-inspection evidence | **Start inspection** | highest-priority duplicate of the two inspection rules above collapses to one row per lease, never two |

A property/lease with multiple triggers shows multiple action buttons on one row, not duplicate
rows. **Report a fault** and **Open lease**/**Open property** are also always available for any row
regardless of trigger state — the table in this subsection lists which action is additionally
surfaced, not the only ones ever shown.

### 3.3 Full table — every rental property, any state
Columns: address, status, tenant, lease end/term, open faults, open work orders, last inspection
date, row actions (Renew, Record notice, Start inspection, Report fault, New work order, Open lease,
Open property — only the ones applicable to that row's state are enabled, never a dead button).

---

## 4. Search / sort / filter / pagination / scope (BUILD_STANDARD §1b/§1c)

- **Search fields:** property address, tenant name, erf number.
- **Sort columns:** address (default), lease end date, status, open-faults count, open-work-orders
  count, last-inspection date.
- **Filters:** the tiles (§3.1) double as filters; additionally a plain status dropdown and a date
  range on lease end date.
- **Pagination:** 25/page default, agency-configurable page-size selector per existing pattern.
- **Empty state:** "No rental properties yet" (agency has none) vs. "Nothing matches this filter."
- **Own | Branch | All** switch, default per the user's `rental_command_centre.view` scope ceiling.
  Every row in the full table and every needs-action-queue row re-checks this at the query layer —
  this screen aggregates across properties/leases/faults/work-orders, so its own query must apply
  the SAME scope predicate consistently across all four joined/unioned sources, not just the
  properties table (the concrete risk BUILD_STANDARD §1c exists to prevent: a join that silently
  widens visibility past what the properties-level scope already restricted).

## 5. Print / API
- **Print** — the full table, current filter applied, in the existing rental-list print style.
- **API:** `GET /api/v1/rentals/command-centre` (JSON: tiles + queue + table, same scope guard) — the
  screen's own Alpine component consumes this; Andre's mobile app's rentals landing screen calls the
  same endpoint.

## 6. Permissions
New key: `rental_command_centre.view` (access). No create/edit/archive actions originate on this
screen itself — every action button navigates to (or opens a modal backed by) the owning entity's
own existing permission-gated action (Renew → `leases.renew`, Start inspection →
`rental_inspections.create`, New work order → `rental_work_orders.create`, etc.) — the Command
Centre does not duplicate or bypass those gates.

## 7. Agency settings / open questions for Johan
- **Reminder lead time** reuses the existing `LeaseSetting` value — no new setting here.
- **"Expiring in window" and needs-action thresholds** — this spec proposes the defaults in §3.1/§3.2
  above (tied to the existing reminder-lead-time setting rather than a second, separate threshold).
  Confirm this is the right single source, or whether the Command Centre should have its own,
  separately-configurable look-ahead window distinct from the renewal reminder itself.
- **"Unoccupied, no draft lease" row** — confirm this should appear even for a rental property that
  was only ever `draft`/never actually let (a brand-new rental mandate with no tenant yet) — as
  specced, yes, since that is exactly the state an agent most needs to act on; flagging in case
  Johan wants new-mandate-with-no-tenant-yet excluded until some other milestone (e.g. marketing
  live) is reached.

## 8. Acceptance criteria
- [ ] All ten tiles (§3.1) render correct live counts and each filters the table on click.
- [ ] Needs-action queue shows exactly one row per property/lease with ≥1 trigger, correct action
      button(s), no dead buttons.
- [ ] Full table search/sort/filter/pagination/empty-state all function per §4.
- [ ] Own/Branch/All scope is correctly and consistently applied across the joined
      properties/leases/faults/work-orders query — verified by direct comparison of row counts at
      each scope level against each underlying screen's own count.
- [ ] Print renders the current filtered view.
- [ ] `/api/v1/rentals/command-centre` is registered, named, scope-guarded, and appears in the
      `/admin/api` catalogue.
