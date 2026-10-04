# Rentals Rebuild — Master Spec (AT-439..AT-446)

**Status:** SPEC ONLY — no code, no migration, no branch touching application files. Written per
Johan's explicit instruction, 2026-10-04: investigate, then write specs only. Another lane is
building AT-439 Part 1 (foundation code) concurrently in its own worktree
(`at439-rentals-foundation-2026-10-04`) — this document and its children do not touch rental
controllers/views and were written without reference to that lane's in-progress code.
**Design approved by Johan, 2026-10-04.** Motto: *"we do complicated so the user can do simple."*
**Sources:** `/tmp/rentals-audit-2026-10-04.md` (full forensic audit, read-only) and
`/tmp/rentals-stage1-investigation.md` (Stage-1-specific forensic investigation, read-only). Every
file:line citation below traces to one of those two reports unless marked otherwise.

---

## 0. What this document is

One master index + shared standards + build order for the eight-stage rentals rebuild
(AT-439..AT-446). Each stage gets its own spec file (new, or an appended section on an existing
settled spec where one already substantially covers the ground — listed in §3). This document does
not repeat what those files say; it tells you which file to open for which stage, what every stage
spec must share, and which decisions are Johan's to make before a stage can build.

**Pillars:** Property (every rental fact ultimately anchors to a `Property`), Contact (tenant,
landlord, contractor), Deal has no direct connection (rentals is a parallel transaction type, not a
`Deal` row), Agent (`User`) is who acts. This rebuild adds no new pillar — it completes the rentals
module's connection to the existing four.

**Multi-agency:** every item below is designed against a second real agency starting October 2026
(Cape Town, mainly rentals) running this same code for the first time. Nothing in any stage spec may
assume HFC, its branches, its staff names, or its current data shape. Where the audit found an
existing HFC-specific violation (§4 below), it is reported, not fixed, in this document — fixing it
is Stage 1 build work, not a spec change.

---

## 1. Shared standards (every stage spec inherits these — not restated per file)

### 1.1 Rental list standard
Identical on Leases, Inspections, Fault Reports, Work Orders, Job Cards, and the new Reports screen's
own result grids:
- **Own | Branch | All** scope switch, default = the widest the user's `*.view` permission's data
  scope ceiling permits (per `PermissionService::getDataScope()` / `clampScope()` — the exact pattern
  already built for Rental Applications, `app/Http/Controllers/CoreX/RentalApplicationController.php:58,74-75,84-88,133`,
  and already present at the query layer but missing its UI switch on Leases/Faults/Work
  Orders/Inspections — see §4 Finding C below).
- **Clickable status tiles** in the Properties-screen tile style (`pstat-v2` — the same visual/
  interaction pattern already shipping on `corex.rentals.properties.index`), each tile filters the
  table beneath it; no tile is decorative.
- **Search** (named fields per stage spec), **sort** (named columns + stated default per stage spec),
  **filters** (status + date range minimum, per BUILD_STANDARD.md §1b), **per-page** selector, **show
  archived** toggle, **Print list**, **Export**.
- **A real empty state** distinguishing "nothing yet" from "no results for this filter."
- **Every list, detail, export, and download action re-checks own/branch/agency scope at the query
  layer, independent of how the request arrived** (direct-URL-by-ID blocked, not just unlinked) —
  this is the one floor item the audit found genuinely missing today on four of five rental list
  screens (Leases, Fault Reports, Work Orders, Inspections — audit Part 1 item C, every stage spec
  below that touches these screens must close it, not just note it).

### 1.2 Rental context bar
One shared Blade component, rendered on every rental record screen (Property's Rental tab, Lease
hub, Application, Inspection, Fault Report, Work Order, Job Card, Inventory): a chip row —
**Property · Lease · Application · Inspections (n) · Faults (n) · Work orders (n) · Inventory ·
Documents · Tenant · Landlord** — the current record's own chip highlighted, every other chip a real
link using the FK chain the audit already confirmed is intact end-to-end (audit Part 3: lease →
application, lease → tenant, lease → inspection/inventory/fault/work-order are all real FKs today).
Built once, included everywhere — no per-screen reimplementation.

### 1.3 Ownership rule (confirmed already correct, carried forward unchanged)
Faults, work orders, and inspections belong to the **lease** first, then the **property**
(`rental_fault_reports.lease_id` / `rental_work_orders.lease_id` both nullable, vacancy-period
rationale written into both migrations — audit Part 4 "Faults / work orders with no lease" + Stage-1
investigation item H, both confirmed already correctly guarded end to end, `?->`/`??` everywhere, no
unguarded access found). With no tenant in the property an owner/agent can still log a fault/work
order against the property alone — already supported, unchanged. Rent and dates live on the **lease**,
linked to the property; past leases stay on the **property** as occupancy history (who stayed when),
visible to the landlord (new — Stage 2, §5 below, Property Rental tab gains this).

---

## 2. Build order

| Stage | Ticket | Spec file | New or updating |
|---|---|---|---|
| 1 | AT-439 | `.ai/specs/rentals-foundation-at439.md` | **New.** Replaces the two Johan-ruling placeholders with his actual 2026-10-04 rulings (§4 below). |
| 2 | AT-440 | `.ai/specs/leases.md` §12 (new section, appended) | **Updating.** `leases.md` is the settled, authoritative lease spec — the Lease Hub is the next chapter of that same document, not a fork. |
| 3 | AT-441 | `.ai/specs/rental-command-centre.md` | **New.** No existing spec covers a cross-entity command centre. |
| 4 | AT-442 | `.ai/specs/rental-work-orders.md` §14 (new section, appended) | **Updating.** `rental-work-orders.md` is BUILT through Stage 7 and is the authoritative work-order spec; Job Cards are the supplier-vs-own-team split this spec's own §3.4 already gestures at but never specced. |
| 5 | AT-443 | `.ai/specs/rentals-reports.md` | **New.** |
| 6 | AT-444 | `.ai/specs/rental-renewals.md` | **New.** No existing AT-428 spec file was found in `.ai/specs/` to tie this to directly; see Open Questions. |
| 7 | AT-445 | `.ai/specs/rental-portal-access.md` | **New**, absorbs and supersedes the secure-access half of `.ai/specs/rentals-faults-work-orders.md` §4 (tenant/owner/contractor web access) by reference rather than duplicating it — that section is not re-argued, only linked and extended to leases/inspections/notices. |
| 8 | AT-446 | `.ai/specs/rental-money.md` | **New.** Includes Johan's Addendum 2 (outstanding/debtors, 2026-10-04) in the same file, §9. |

Every stage spec below states its own routes, CRUD, search/sort/filter/pagination/empty-state,
scoping, permission keys, settings, API endpoints, print/PDF, and build-on citations per
BUILD_STANDARD.md §1d — none of that is repeated here.

---

## 3. Where an existing spec already covers part of a stage

| Stage touches | Existing spec | What it already covers | What the new section adds |
|---|---|---|---|
| 1 | `rentals-shared-screens.md` | Properties/Core Matches/Rental Pipeline rental lenses (BUILT), the tenant-"won" trigger on `let_out` status (BUILT) | Nothing duplicated — Stage 1 spec only links it |
| 2 | `leases.md` | Lease CRUD, lease_tenants, lease escalations, landlord-derivation design intent (BUILT through its own stages) | §12: Lease Hub screen, lifecycle strip, tenancy log, next-step card, property-status integration |
| 2 | `rental-property-tab.md` | Rental-tab fields, advert block, lease-type list (BUILT/in progress) | Cross-referenced for occupancy history placement, not edited |
| 4 | `rental-work-orders.md` | Fault report + work order lifecycle, spend threshold, quotes (BUILT) | §14: Job Cards, labour/parts catalogue, sign-off, printable job card |
| 4 | `dr2-supplier-work-orders.md` | DR2/COC supplier work orders — confirmed **zero shared code** with rentals work orders (audit Part 1 item 9) | Cross-referenced only to state explicitly this is NOT the same system |
| 7 | `rentals-faults-work-orders.md` §4 | Secure-link contractor access design, `ClientUser` extension design for tenant/owner (SPEC ONLY, unbuilt) | Extended to leases, inspections, documents, notices; not re-argued |
| 7 | `rental-documents-spec.md` | The 6 rental document templates (lease, mandate, disclosure, etc.) | Cross-referenced for what a tenant/owner portal surfaces as "Documents" |
| 6 | `rental-inspection-form.md`, `rental-inspections.md` | Out-inspection flow, signing (BUILT) | Cross-referenced — renewal's out-inspection trigger reads this, doesn't change it |

---

## 4. Johan's rulings, 2026-10-04 (replace the original Stage 1 "awaiting ruling" items)

### Addendum 1 — the two original Stage 1 open items are now CLOSED, not open questions

**A. Legacy lease data — NO migration, NO matching, NO review list.**
Everything in the rental tables on QA1 is demo data; no agency uses rentals on live yet. Stage 1
spec (`rentals-foundation-at439.md` §2) states plainly: `leases` becomes the single lease store by
retiring every other write/read path (§2.2); legacy tables and rows are left untouched in the
database (no hard deletes, per CLAUDE.md non-negotiable #1) but are no longer reachable through any
live code path once Stage 1 ships. The 27 same-complex-ambiguous and 24 bad-address legacy rows (audit
Stage-1 investigation item D) are **not** matched, not reviewed, not migrated — there is no real data
behind them worth the engineering cost (STANDARDS.md Standard −1q: ask whether the data is real before
designing a backfill — it isn't).

**B. Legacy Rentals menu group — RETIRE, not merge.**
Stage 1 spec (`rentals-foundation-at439.md` §3) lists, by file:line, exactly what is retired: the
hidden developer-only nav group (`corex-sidebar.blade.php:2879-2909`, Alpine key `rentals`), its five
routes, and the controllers/views they point to — **only once every live caller that currently
depends on the underlying legacy models is repointed at `leases`** (the dependency list is itself
large — see `rentals-foundation-at439.md` §2.2 — and retiring the menu before those dependencies move
would silently orphan live functionality, not just a stale link).

### Addendum 2 — Stage 8 (AT-446) outstanding/debtors addendum
Folded directly into `rental-money.md` §9 (Outstanding indicator + Outstanding/Debtors report) —
not a separate document. See that file for the full spec; summarised in the per-stage table at §2
above only.

### Addendum 3 (new this spec round) — Stage 2 (AT-440) property-status-follows-lease
Folded into `leases.md` §12.5. Builds on the Property model's existing, already-settled status
governance — the agency-configurable `PropertySettingItem` list (`group='property_status'`,
`app/Models/PropertySettingItem.php:29`), the already-live **"Let Out"** status (confirmed real and
currently in production use, `rentals-shared-screens.md` §5.2), `PropertyObserver::updated()`'s
existing AT-307 status-vocabulary guard and its existing `PropertyAuditService::logStatusChange()`
call (`app/Observers/PropertyObserver.php:139-175,480-481`), and the already-catalogued
`Property\PropertyStatusChanged` domain event (`corex-domain-events-spec.md:305`, already fired by
the observer, already consumed by `FlagPropertyAsOnBooks`/`NotifyExpiredMandateBranchManager`/audit).
**No new status governance mechanism is introduced; no existing one is redesigned.** See `leases.md`
§12.5 for the full transition table, the confirmation-dialog copy for activating a lease on a
withdrawn property, and the agency settings that make every automatic transition itself an on/off
agency setting with a sensible default.

---

## 5. Top gaps the audit found, carried forward as spec inputs (not fixed here)

Per CLAUDE.md non-negotiable #2 ("report-only outside scope"), the following confirmed findings from
the audit are **not fixed by any spec in this round** — they are cited in the relevant stage spec as
either (a) something that stage's build must fix as part of its own scope, or (b) flagged to Johan as
its own decision:

1. Three legacy lease-shaped tables (`rentals` 59 rows, `lease_records` 2 rows, `rental_properties` 2
   rows) remain live and unreconciled alongside `leases` (35 rows) — addressed by Addendum 1A/Stage 1.
2. `CheckLeaseExpiry` queries the wrong table (`lease_records`, not `leases`) — Stage 6 (Renewals)
   must repoint this; see `rental-renewals.md` §3.
3. No tenant/owner/contractor access exists for faults/work orders — Stage 7 builds this.
4. No rental inspection mobile API exists — out of scope for this spec round; flagged in
   `rentals-foundation-at439.md` Open Questions for Johan to sequence.
5. Breach notices / notice-to-vacate do not exist, not even as a spec — Stage 7 builds this.
6. **Multi-agency violation**: `RentalsController.php` hardcodes two real HFC employees' names into
   commission-bucket logic — reported per CLAUDE.md non-negotiable #2, fixed as a side-effect of
   Stage 1's legacy-table retirement (the controller is retired, not patched) — see
   `rentals-foundation-at439.md` §3.
7. Property's Rental tab shows no current tenant/landlord/lease info — Stage 2 (Lease Hub) fixes
   this via the occupancy-history addition to the Rental tab, `leases.md` §12.6.
8. Two agency-configurable work-order settings missing from the onboarding wizard with no recorded
   exclusion decision — flagged in `rental-work-orders.md` §14 Open Questions for Johan's call per
   CLAUDE.md non-negotiable #10a.
9. `Docuperfect\LeaseRecord` has no structural agency isolation (query-layer patch only) — moot once
   Stage 1 retires every live write path into it (Addendum 1A); confirmed in `rentals-foundation-at439.md` §2.2.
10. Legacy `RentalsController` has no search/sort/filter/pagination/archive — moot once retired
    (Addendum 1B).

---

## 6. Open questions needing Johan's ruling, consolidated

Each stage spec lists its own open questions in full; this is the index of items that are genuinely
business decisions, not engineering ones:

- **Stage 1**: nothing left — both original open items are closed by Addendum 1 above.
- **Stage 2**: which specific statuses map to "leased out" for a given agency (default list proposed,
  agency-editable) — `leases.md` §12.5.4.
- **Stage 3**: exact thresholds for "expiring in window" tile and "needs-action" queue rules (defaults
  proposed) — `rental-command-centre.md` §7.
- **Stage 4**: the two orphaned work-order settings' wizard placement (item 8 above) —
  `rental-work-orders.md` §14 Open Questions.
- **Stage 5**: none structural — reports read existing data, no new business rule.
- **Stage 6**: no AT-428 spec file exists to confirm this ties to — Johan to confirm the ticket
  reference or point at the right existing document — `rental-renewals.md` §1.
- **Stage 7**: email-only vs. a future SMS/WhatsApp channel for notices (spec assumes email-only per
  instruction; flagged for confirmation) — `rental-portal-access.md` §8.
- **Stage 8**: default aging buckets (Current/30/60/90/120+) proposed as agency-editable defaults —
  confirm acceptable — `rental-money.md` §9.2.

---

## 7. Finish-line summary (filled in on landing — see commit)

See the landing commit message and the conductor report for: every spec file created/updated with a
2-line summary, the full open-questions list, branch name, commit SHA, and where this landed.
