# Rental Money (AT-446)

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026, plus Addendum 2 (outstanding/debtors,
2026-10-04) in §9.
**Ticket:** AT-446. **Date:** 2026-10-04. **Pillar:** Property, Contact (tenant, landlord), Deal has
no connection (rental money is not a `Deal`), Agent (`User`, as the one who captures bills/allocates
payments).
**Master spec:** `.ai/specs/rentals-rebuild.md`. **Depends on:** `rental-work-orders.md` §14 (job-card
costs become charges, §6 below), `rental-renewals.md` (escalation updates the rent charge, §7),
`rental-portal-access.md` §8 (breach notices pull arrears, §9.1).
**Explicitly NOT in scope** (per instruction): trust accounting, owner payouts, deposit interest,
bank reconciliation.

---

## 1. What this stage does and why
Nothing in the current build models money at all — rent, deposits, fees exist as fields on `Property`/
`Lease` (`rental_amount`, `deposit_amount`, commission %, admin/marketing fee) but nothing turns them
into an invoice, a payment, or a statement. This stage builds the charge → invoice → payment →
allocation → statement chain, designed around the real three-way split: **tenant money = owner money
+ agency money.**

## 2. Charge types (agency-defined)
New `rental_charge_types` table (agency_id, name, **what the tenant pays**, **what the agency takes**,
**what the owner gets**, **where it is paid** — owner / agency trust / agency business account —
recurring or once, soft-deleted/restorable). Full CRUD under Rentals → Settings, standard list-screen
floor (search by name, sort by name, filter active/archived, pagination, empty state).

Seeded examples (agency-editable, never hardcoded as logic):
- **Rent** — recurs monthly for the lease term. Split example: R10,000 tenant → R9,000 owner / R1,000
  agency. The split is a property of the charge type (or an override on the specific lease — §2.1),
  never a hardcoded percentage in code.
- **Utilities** — recurs monthly, but **the line is created with no value** and sits in an "awaiting
  amount" queue until the agency captures the actual bill (e.g. R1,234 tenant → R1,234 owner, full
  pass-through, zero agency cut on this charge type by default — editable per agency).
- **Deposit** — once, paid to agency trust (recorded as a destination, not moved anywhere — per
  explicit out-of-scope, no trust-accounting engine is built).
- **Collection fee** — once (or recurring, agency's choice), paid to agency business account.

### 2.1 Lease-level override
A specific lease may override a charge type's split (e.g. a landlord negotiated a different
commission on one property) — `lease_charge_overrides` (lease_id, charge_type_id, override split
fields) — read in preference to the charge type's own default when generating that lease's charges.

## 3. Recurring charges
`rental_recurring_charges` (lease_id, charge_type_id, amount — nullable for "awaiting amount" items,
start_date, end_date = lease term, frequency). A scheduled job (new, `GenerateRentalChargesCommand`,
daily) creates the period's `rental_invoices`/`rental_invoice_lines` rows from every active recurring
charge whose period has arrived. A recurring charge with `amount=null` (the utilities case) still
generates its invoice line **each period**, visibly in the "awaiting amount" queue (§4.1), rather
than silently skipping periods until someone notices.

## 4. Invoices
`rental_invoices` (lease_id, period, status, total) + `rental_invoice_lines` (invoice_id,
charge_type_id, description, amount nullable). Invoices raise from charges (§3) automatically for
recurring ones, or manually for a once-off charge an agent adds directly.

### 4.1 Awaiting-amount queue
A dedicated filter/tile on the Invoices screen: every invoice line with `amount=null`, grouped by
property/charge type, with a one-click "capture bill" action that fills the amount and recalculates
the invoice total. This is the operational surface for the utilities case in §2 — an agency never
loses track of an unpriced recurring line because it's a real, visible queue item, not a silent null.

## 5. Payments and allocation
`rental_payments` (lease_id or landlord_id depending on direction, amount received, date, method —
free text, since "no bank link yet") + `rental_payment_allocations` (payment_id, invoice_id,
amount_allocated). An agent captures the **total received** (one real-world deposit into a bank
account may cover one or several invoices) and then **ticks which invoices it settles** — each tick
is one allocation row, and the sum of allocations for a payment may be less than the payment total
(a part-allocated/overpaid balance sits against that tenant/lease until the next payment, never
forced to balance to zero). **Designed so a future bank feed can supply "total received" instead of
an agent typing it** — the capture step itself doesn't change, only where the amount comes from; no
bank-specific field is added now (explicitly out of scope), the data shape simply doesn't preclude it
later.

## 6. Job-card/work-order costs become charges
A completed job card's priced labour/parts lines (`rental-work-orders.md` §14.5, already designed to
be the source record) and a completed outside-supplier work order's final invoiced amount both
generate a once-off `rental_invoice_line` against the relevant charge type (commonly "Maintenance
recovery," agency-configurable whether it's billed to the owner, the tenant, or split — same
mechanism as §2, not a special case). This is a one-click action from the completed work order/job
card screen ("Raise as a charge"), not automatic — an agency may choose to absorb a repair cost rather
than bill it, and the action must not force a charge to exist.

**VAT on job cards is already built** (`rental-work-orders.md` §14.17, 2026-10-05) — the single source
of truth this stage reads from, not re-derives. A completed job card's lines already carry a frozen
VAT snapshot per line (type name, rate, excl/VAT/incl — `RentalJobCardLine.vat_*_snapshot`) and the
card itself carries the agency's registration/capture-mode snapshot at the moment it was sent/
completed (`RentalJobCard.vat_registered_snapshot`/`vat_capture_mode_snapshot`). When this stage turns
a line into a `rental_invoice_line`, it MUST carry that same frozen excl/VAT/incl breakdown through
unchanged — never re-run VAT math against the agency's THEN-current settings, which may have since
changed. The agency's VAT registration (`agencies.vat_registered`), rate (`PerformanceSetting
'vat_rate'`), and capture mode (`agencies.vat_capture_mode`) are also the ones this stage's own
invoices/statements (§4, §8) must use for anything NOT sourced from an already-snapshotted job card
line (e.g. a manually-raised charge) — one VAT configuration per agency, read everywhere, never a
second rate/registration setting invented for rental money.

## 7. Escalation updates the rent charge
A signed renewal with an escalation (`rental-renewals.md` §7, reusing the already-built
`LeaseEscalation`) updates the lease's recurring rent charge (§3) with the new amount effective from
the new term's start — the existing recurring-charge row's `amount` is updated, not a second parallel
row created, so the invoice-generation job always reads one current value.

## 8. Statements
Three statement types, each a filtered view of the same invoice/payment/allocation data:
- **Tenant statement** — their own lease's invoices, payments, balance.
- **Owner statement** — every charge type routed "to owner" across their properties, minus nothing
  (no payout engine — explicitly out of scope) — a reporting view of what's owed/received, not a
  disbursement record.
- **Agency statement** — every charge type routed "to agency trust"/"to agency business account"
  across the whole agency.
Each: period picker, print/PDF/export, same shared-shell pattern as `rentals-reports.md` §3 (this
spec's statements are a Reports-screen entry, not a separate UI).

## 9. Outstanding indicator + Outstanding (Debtors) report — Johan's Addendum 2, 2026-10-04

### 9.1 Outstanding indicator (on the Lease Hub, Leases list, Command Centre)
**Never a stored figure that can drift** — always derived live as `SUM(unpaid/part-paid invoice
balances)` for the lease, where an invoice's balance = `invoice.total - SUM(allocations against it)`.
- **Lease Hub header** (`leases.md` §12.2): shows the outstanding total + the date of the OLDEST
  unpaid/part-paid invoice, e.g. *"R6,589.00 outstanding since 15 Sep."* Zero outstanding shows
  nothing extra in the header (Screen rule: no always-on badge for a non-event).
- **Leases list**: a sortable "Outstanding" column + a clickable "In arrears" status tile (master
  spec §1.1 tile standard) that filters the list to leases with outstanding > 0.
- **Command Centre** (`rental-command-centre.md` §3.3): same figure as a row-level column, reusing
  the identical query — not a second implementation.
- **Click-through**: opens a breakdown of each unpaid/part-paid invoice: charge type, invoice date,
  amount, paid, balance — per line, newest-or-oldest-first per the user's last sort choice, no
  default assumed beyond invoice date ascending (oldest-first, matching the "outstanding since" date
  shown in the header).

### 9.2 Outstanding (Debtors) report
**Specced in full in `rentals-reports.md` §8** — aging buckets, drill-down, own/branch/agency
scoping, owner-side view, search/sort/filter/totals, API endpoint. Not restated here; this section
exists only to confirm the data this report reads is exactly the invoice/allocation model in §4-§5
above, with no separate "debtors" table — the report is a query over real invoices and payments, not
a parallel ledger that could disagree with the Lease Hub's own indicator (§9.1). Default aging
buckets (Current/30/60/90/120+ days) are an agency setting, surfaced in the Setup Wizard alongside
this stage's other settings (§11).

## 10. Routes, nav, CRUD, permissions
- **New nav entries** under Rentals: Charges (settings), Invoices, Payments, Statements — each its
  own list screen, full CRUD/list-screen floor (search/sort/filter/pagination/empty-state/archive/
  restore) per BUILD_STANDARD §1a-§1b.
  - Invoices: search by property/tenant/invoice number; sort by date (default, newest first),
    amount, status; filter by status (awaiting-amount/open/paid/part-paid), charge type, date range.
  - Payments: search by payer name/reference; sort by date (default), amount; filter by
    method/date range.
- **Permissions (new):** `rental_charges.manage_settings`, `rental_invoices.view`, `.create`,
  `rental_payments.view`, `.create`, `rental_statements.view`.
- **Own | Branch | Agency scoping**, per-record guard on every invoice/payment/statement
  show/download — same floor as every other entity in this rebuild (BUILD_STANDARD §1c).

## 11. Agency settings (Setup Wizard, CLAUDE.md non-negotiable #10a)
- Default charge-type splits are themselves agency-configured data (§2), not a single toggle —
  the wizard surfaces "set up your charge types" as a step linking to the Charges settings screen,
  with at least Rent/Deposit/Collection Fee pre-offered as editable starting templates (neutral
  defaults, no HFC-specific split baked in).
- `aging_bucket_days` (§9.2) — default `[30, 60, 90, 120]`, agency-editable array.
- `utilities_awaiting_amount_reminder_days` — how long an unpriced utilities line sits before it's
  flagged overdue-to-capture on the Command Centre (default 7 days).

## 12. API
`GET/POST /api/v1/rentals/charge-types`, `/invoices`, `/payments`, `/payments/{payment}/allocations`,
`/statements/{type}`, `GET /api/v1/leases/{lease}/outstanding` (the §9.1 indicator, consumed by the
Lease Hub, the Leases list, and the Command Centre identically — one endpoint, three callers).

## 13. Multi-agency
No charge type, no split percentage, no statement wording may assume HFC's own commission structure
— every agency defines its own charge types and splits from the Setup Wizard's linked settings screen
(§11); the seeded examples in §2 are illustrative defaults, not hardcoded behaviour.

## 14. Acceptance criteria
- [ ] Charge-type CRUD meets the full list-screen floor.
- [ ] Rent recurs monthly for the lease term at the correct split; an escalation updates the SAME
      recurring-charge row, not a duplicate.
- [ ] A utilities charge with no amount generates each period into the awaiting-amount queue and is
      visibly flagged, never silently skipped.
- [ ] Deposit and collection-fee charges route to the correct destination (trust/business account) as
      configured.
- [ ] A payment can be captured once and allocated across multiple invoices; a part-allocated balance
      is preserved, not forced to zero.
- [ ] A completed job card/work order can be raised as a charge with one click, and is NOT
      automatically charged if the agency doesn't choose to.
- [ ] Tenant/owner/agency statements each render correctly for a lease/property/agency with real
      invoice and payment data.
- [ ] The Lease Hub outstanding indicator, the Leases list column, and the Command Centre row all
      show the identical figure for the same lease, proving one shared derivation, not three.
- [ ] The outstanding-indicator click-through and the Outstanding report's drill-down show the same
      invoice breakdown for the same lease.
- [ ] Every new settings (§11) appear in the Setup Wizard with `explain`/`affects` copy.

## 15. Open questions for Johan
- **Default aging buckets** (Current/30/60/90/120+) — proposed as the agency-editable default;
  confirm acceptable, or state a different default set.
- **Utilities-awaiting-amount reminder default (7 days)** — proposed; confirm or adjust.
