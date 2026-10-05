# Rentals Foundation (AT-439) — Single Lease Store, Legacy Retirement, List Standard, Context Bar

**Status:** SPEC ONLY. Code for "AT-439 part 1" is being built concurrently by another lane in its
own worktree (`at439-rentals-foundation-2026-10-04`) — this spec does not assume or depend on that
lane's in-progress code; it specs the full Stage 1 scope so the two converge, not diverge.
**Ticket:** AT-439. **Date:** 2026-10-04. **Pillar:** Property (every retired/retained table ultimately
describes a property), Contact (tenant/landlord), Agent (`User`).
**Master spec:** `.ai/specs/rentals-rebuild.md` — read its §1 (shared standards) before this file;
not repeated here.
**Johan's rulings folded in (2026-10-04, see master spec Addendum 1):** no legacy-data migration/
matching/review-list; retire (not merge) the legacy Rentals menu group once its dependencies move.

---

## 1. What this stage does and why

Four lease-shaped data stores exist today (audit Part 2): `rentals` (58-59 rows, legacy, still
writable), `lease_records` (2 rows, legacy, still writable), `rental_properties` (2 rows, legacy,
still writable), `leases` (35 rows, the intended source of truth, the only one with a real enforced
property+tenant FK chain). Two Rentals nav groups render simultaneously (Stage-1 investigation item
B) with no cross-link. A user cannot tell from the UI that "Rentals" and "Leases" are different,
disconnected stores for the same real-world facts. This stage makes `leases` the **only** lease
store any live code path reads or writes, retires the legacy nav group and its dead-end
controllers, and ships the two shared UI primitives (list standard, context bar) every later stage
depends on.

---

## 2. Single lease store — `leases` only, everything else retired from live code paths

### 2.1 What "retired" means here (per Johan's ruling, not a data migration)
No row in `rentals`, `lease_records`, or `rental_properties` is touched, moved, hard-deleted, or
migrated. Only **code paths** change: every controller/service/command that currently reads or
writes one of these three tables is repointed at `leases` (and its children — `lease_tenants`,
`LeaseEscalation`) or retired outright if its only purpose was serving the legacy table. The three
legacy tables and their rows stay in the database as historical record, reachable only via direct DB
access (Tinker/admin), never via application code, from the moment Stage 1 ships.

### 2.2 Every live reader/writer that must move or retire, by table (audit Stage-1 investigation item D)

**`rentals` (59 rows, STILL WRITABLE today):**
| Caller | File:line | Disposition |
|---|---|---|
| `RentalsController` full CRUD | `app/Http/Controllers/.../RentalsController.php` (`Rental::create()` at line 359) | **Retired** — the controller itself is removed once nothing else needs it (§3) |
| `RentalAgent` / `RentalAmountVersion` child relations | model relations on `Rental` | Retired with the controller |
| `RentalCalendarSource.php:105,180` | Command Center calendar feed | **Repointed** to read from `leases` (active leases with a `start_date`/`end_date` in the relevant window) — this is a live feature, not dead code, and must not silently lose calendar entries |
| `RentalWorksheetInclusionService.php:35,99` + `WorksheetController.php:943` | agent commission worksheets | **Repointed** to `leases` — same reasoning, a live commission-calculation path |
| Nav: `corex-sidebar.blade.php:2895` AND `navigation.blade.php:24` | two separate nav entries | **Retired**, both, in the same pass (§3) |

**`lease_records` (2 rows, STILL WRITABLE today):**
| Caller | File:line | Disposition |
|---|---|---|
| `Docuperfect\LeaseController::renew()` | creates new rows, line 78 | **Retired** — renewal now happens against `leases` (Stage 6 builds the real renewal flow; this stage only stops new `lease_records` writes) |
| `SignatureService::createLeaseRecord()` | writes legacy table, line 5382 | **Retired** — the e-sign cascade already also calls `createLeaseFromSignedDocument()` (writes `leases` directly, audit Part 3 item F) going forward; the legacy write is simply removed, the `leases` write path is untouched |
| `RentalDivisionController::activeLeases()`/`expiredLeases()` | lines 139-161, a second dashboard pair reading only `lease_records`' 2 rows | **Retired** with the controller (§3) |
| `CheckLeaseExpiry.php` | reads+writes status, queries `lease_records` exclusively | **Repointed to `leases`/`end_date`** — this is Stage 6 (Renewals) scope, not Stage 1; Stage 1 only ensures no NEW `lease_records` rows get created so Stage 6 isn't chasing a moving target (see audit Part 1 item E) |
| `LeaseExpirationAlert.php:14` | notification class tied to `lease_records` | Retired/repointed alongside `CheckLeaseExpiry` in Stage 6 |
| `SignatureTemplate.php:333` (`hasOne`) | model relation | Retired — no code calls it once `createLeaseRecord()` is removed |

**`rental_properties` (2 rows, STILL WRITABLE today):**
| Caller | File:line | Disposition |
|---|---|---|
| `RentalPropertyController` full CRUD | `RentalProperty::create()` line 47 | **Retired** with the legacy nav group (§3) |
| `RentalDivisionController.php:48,105` | legacy dashboard | Retired with the controller |
| `Docuperfect\Document.php:75` | `belongsTo(RentalProperty::class,'property_id')` — the documented ID-space collision where `docuperfect_documents.property_id` points at `RentalProperty`, not the real `Property` | **This is the one genuinely load-bearing dependency and the reason the legacy menu cannot simply be deleted on day one.** Every e-sign prefill path below reads through this relation. It must be repointed to resolve the real `Property` (via `LeasePropertyResolver::matchOneByAddress()`, already used elsewhere for exactly this purpose — audit Part 3 item F) BEFORE `RentalProperty`/its controller is retired, or every rental e-sign document's prefill breaks silently. |
| `SupportingBatchPrefillResolver.php:63` | e-sign prefill | Repointed alongside `Document.php:75` |
| `DocumentController.php:382` | e-sign prefill | Repointed alongside `Document.php:75` |
| `ESignWizardController.php:450,1203,4318` | e-sign prefill (three call sites) | Repointed alongside `Document.php:75` |

**Sequencing inside Stage 1 (not optional — order matters):** the `rental_properties`/`Document`
dependency chain must be repointed at real `Property` **before** `RentalPropertyController`/the
legacy nav entries are removed, or every rental e-sign document silently loses its property prefill.
`RentalCalendarSource` and the Worksheet commission path must be repointed at `leases` **before**
`RentalsController` is removed, for the same reason. The legacy nav group (§3) is the LAST thing
removed in this stage, once every dependency above has moved.

### 2.3 Multi-agency fix included in this retirement (audit Part 4, Finding reported there, fixed here)
`RentalsController.php` hardcodes two real HFC employees' names (`'Maggie Venter'`, `'Retha Kelly'`)
directly into commission-bucket calculation logic — a direct CLAUDE.md non-negotiable #9 violation.
Retiring the controller (§2.2, §3) removes this violation as a side-effect of the retirement, not as
a separate patch — there is no surviving code path that needs this logic preserved, since the
Worksheet commission path is repointed at `leases` (§2.2) independently of this controller.

---

## 3. Legacy Rentals menu group — exactly what is retired (Johan's ruling, Addendum 1B)

**Retired, once §2.2's dependency moves are complete — not before:**

| Item | File:line | Why safe to retire |
|---|---|---|
| Nav group, Alpine key `rentals`, hidden under System Developer → Hidden | `corex-sidebar.blade.php:2879-2909` | Confirmed (Stage-1 investigation item B) gated `@permission('view_rentals') @feature('rentals')`, default ON for every agency today — removing it removes the only remaining entry point into every controller below |
| `rentals.index` route + `RentalsController` | `routes/web.php:1778-1790` | Full CRUD retired per §2.2; no delete/archive capability existed on this controller at all (audit Part 4) — nothing to preserve |
| `rental.dashboard` route + `RentalDivisionController@dashboard` | nav line 2897 | Reads only `lease_records`/`rental_properties` (2+2 rows) — dead weight once those tables stop being written |
| `rental.signatures` route + `RentalDivisionController@signatures` | nav line 2900 | Same — reads `LeaseRecord`/`SignatureService` dashboard query, superseded by the new Lease Hub (Stage 2) |
| `rental.active-leases` / `rental.expired-leases` + `RentalDivisionController@activeLeases()`/`expiredLeases()` | nav lines 2903-2904, controller lines 139-161 | Shows only the 2 `lease_records` rows, blind to the 35 real `leases` rows — actively misleading, superseded by Leases list (already built) and the Command Centre (Stage 3) |
| Second duplicate nav entry for `rentals.index` | `navigation.blade.php:24` | Same route as above, different file — removed in the same pass |

**Confirm-nothing-else-calls-it check (per instruction):** every remaining caller found by the audit
against these specific routes/controllers is listed in §2.2's tables above and is repointed BEFORE
this section's removal, not left behind. No other caller of `RentalsController`, `RentalDivisionController`,
or `RentalPropertyController` was found by either investigation pass.

**What is explicitly NOT retired in this stage:** the Group-1 (new) nav entries
(`corex-sidebar.blade.php:1008-1205` — Rental Applications, Leases, Rental Inspections, Rental Fault
Types, Rental Fault Reports, Rental Work Orders, Contacts/Properties/Pipeline/Core-Matches rental
lenses) are untouched; they are the Stage-1 rebuild's actual nav home and are extended, not replaced,
by later stages (Stage 3 adds the Command Centre as a new first item in this same group — see
`rental-command-centre.md` §2).

### 3.1 Known, demonstrated, unrelated sidebar bug — fix in the same pass (same file, directly adjacent)
Stage-1 investigation item A: the `rental-applications` group's own active-state matcher
(`corex-sidebar.blade.php:207-212`) never lists the four route-name patterns for Leases/Inspections/
Fault Reports/Work Orders, so the Rentals panel never auto-expands on those four pages — confirmed by
running `Str::is()` against every branch, zero matches anywhere. **Smallest correct fix** (already
identified by the investigation, not re-derived here): add `'corex.leases.*'`,
`'corex.rental-inspections.*'`, `'corex.rental-fault-reports.*'`, `'corex.rental-work-orders.*'`,
`'corex.rental-fault-types.*'` to the `routeIs(...)` list at lines 207-208. Low risk — the chain is
`elseif`-ordered, confirmed no collision with any earlier branch. Ship in the same Stage 1 commit as
the menu retirement since both touch the same file and the same investigation surfaced both.

---

## 4. The shared list standard (built once in this stage, consumed by every later stage)

**Component:** one Blade partial (e.g. `resources/views/corex/rentals/partials/_rental-list-controls.blade.php`)
plus its Alpine controller, parameterised by: scope-permission-key, status-tile config (label, status
value(s), icon), search-field list, sort-column list + default, filter list. Leases, Inspections,
Fault Reports, and Work Orders adopt it in this stage (replacing each one's current bespoke "Showing:"-
less markup — Stage-1 investigation item C confirmed the query-layer scope already exists on all
four via `visibleTo($user, $scope)`, only the UI control and the per-record guard are missing).

### 4.1 Per-screen close-out of the Stage-1-investigation Finding C gap
For each of Leases, Fault Reports, Work Orders, Inspections, this stage:
1. Adds the Own|Branch|All UI switch (pattern already built on Rental Applications —
   `rental-applications/index.blade.php:153-164`) to that screen's index view.
2. Adds the per-record scope guard to `show()`/`pdf()`/`form()`/`report()`/`printForSignature()` on
   each controller (pattern already built — `Concerns\AuthorizesRentalApplicationAccess::guardRentalApplication()`,
   `app/Http/Controllers/Concerns/AuthorizesRentalApplicationAccess.php:20-48`) — closing the
   documented gap where `pdf()`'s own docblock admits "same query-layer scoping as show()" is false
   today (Stage-1 investigation item C, exact lines: `RentalFaultReportController.php` show 226-237/
   pdf 243-250; `RentalWorkOrderController.php` show 197-214/pdf 222-230; `RentalInspectionController.php`
   show 183-212/form 278-287/report 298-315/printForSignature 326+; `LeaseController.php` show 200-211).

This closes BUILD_STANDARD.md §1c's "direct-URL access by ID is blocked, not just unlinked" floor on
all four screens, not just Rental Applications.

### 4.2 Standard fields (this stage; later stages add entity-specific columns on top)
- **Search:** tenant name, property address, lease/reference number (named per entity in its own
  controller — this spec states the shared mechanism, not the per-entity field list, which already
  exists per-entity in `leases.md`/`rental-work-orders.md`/`rental-inspections.md`/`rental-inventory.md`).
- **Sort:** every sensible column + stated default (already stated per-entity in those same specs;
  unchanged by this stage — only the missing UI control is added).
- **Filter:** status (via the clickable tiles), date range, own/branch/all.
- **Pagination:** existing per-entity page sizes, unchanged.
- **Empty state:** "no results for this filter" vs. "nothing recorded yet" — distinct copy per
  entity, written in that entity's own spec.

---

## 5. The rental context bar (built once, included everywhere)

See master spec §1.2 for the full chip list and the FK chain it reads (already real end-to-end,
audit Part 3). Built as one Blade component
(`resources/views/corex/rentals/partials/_rental-context-bar.blade.php`) taking a `Lease` (or a
`Property` with no active lease, for the vacancy case) and rendering: Property · Lease · Application ·
Inspections (n) · Faults (n) · Work orders (n) · Inventory · Documents · Tenant · Landlord, current
one highlighted via a `$current` prop. Included on: Property's Rental tab, the Lease hub (Stage 2),
every Application/Inspection/Fault/Work-Order/Job-Card/Inventory detail screen. No per-screen
reimplementation in any later stage — they `@include` this component.

---

## 6. Routes, nav, CRUD, permissions — this stage's own additions

This stage adds no new entity (no new table, no new CRUD surface of its own) — it is a retirement +
shared-component stage. Accordingly:
- **No new permission keys.** The retired routes' permission keys (`view_rentals`, `manage_rentals`,
  `access_rental_signatures`) are themselves retired from `config/corex-permissions.php` once the
  routes are gone — do not leave orphaned permission rows a Role Manager screen still offers to grant
  for a feature that no longer exists.
- **No new settings**, hence nothing new to add to the Setup Wizard.
- **API:** no new endpoints. (`leases:migrate-legacy` artisan command: left as-is, unused, not
  removed — it is a one-time tool, not a live code path, and removing it is out of this stage's
  scope per CLAUDE.md non-negotiable #1's spirit of not deleting working tooling without being asked.)

---

## 7. Acceptance criteria

- [ ] `leases` is the only table any live controller/service/command reads or writes for lease data.
- [ ] Every caller in §2.2's tables is repointed or retired, in the stated order.
- [ ] The legacy nav group (§3) is gone from the sidebar and from `navigation.blade.php`.
- [ ] `RentalsController`, `RentalDivisionController`, `RentalPropertyController` and their routes
      are removed; `Rental`, `RentalProperty`, `Docuperfect\LeaseRecord` models' write paths are
      removed (models themselves may remain for read-only historical Tinker access — not required to
      delete the model class, only its write/controller paths).
- [ ] `rentals`, `lease_records`, `rental_properties` tables and rows are untouched in the database.
- [ ] The §3.1 sidebar active-state bug is fixed in the same commit.
- [ ] Leases/Fault Reports/Work Orders/Inspections all have the Own|Branch|All UI switch and the
      per-record scope guard on show/pdf/form/report/printForSignature.
- [ ] The HFC-employee-name hardcode (§2.3) no longer exists anywhere in the codebase.
- [ ] No orphaned permission keys remain in Role Manager for retired routes.
- [ ] The shared list-standard component and context-bar component exist and are adopted by at least
      the four screens named in §4.

---

## 8. Open questions for Johan

None remaining for this stage — both original items were closed by his 2026-10-04 ruling (master
spec §4, Addendum 1). One item surfaced during investigation and flagged, not decided, here:
sequencing of the rental-inspections mobile API gap (audit top gap #4) against this rebuild — not
blocking Stage 1, but worth Johan naming which stage (if any) in this eight-stage plan should pick it
up, since none of the eight currently do.

---

## 9. AT-439 follow-up (2026-10-05) — display price reads the active lease; landlord chip dead-end fixed

Two display-only follow-ups, per Johan's 2026-10-04 ruling folded into §1.3 of the master spec
(`rentals-rebuild.md`): "rent and dates live on the LEASE; the property only links to it."

### 9.1 Item A — `Property::effectivePrice()` never read the active lease

`effectivePrice()`/`formattedPrice()` read `properties.rental_amount` directly for a rental
listing and never looked at `leases`, so a property with an active lease showing a different
(renegotiated) rent displayed the stale original asking figure everywhere (confirmed on QA1:
property 5792 showed R7150 while its active lease #10 carries R8000). `effectivePrice()` and
`formattedPrice()` are themselves **unchanged** — syndication (P24/PP), matching, and
notifications all read them and must keep doing so exactly as before.

Added instead: `Property::activeLease()` (hasOne `Lease`, `status='active'`, most recent
`start_date`) and two new display-only methods, `displayRentalPrice()` /
`formattedDisplayPrice()` — active lease's `rental_amount` first, `properties.rental_amount`
only when no active lease exists; identical to `effectivePrice()`/`formattedPrice()` for a sale
listing. Wired into the four on-screen call sites that actually render a price outside
syndication/matching/notifications:

| Screen | File:line |
|---|---|
| Properties list — grid card, grid card (hero), "owned by other" compact row, table row | `resources/views/corex/properties/index.blade.php` (4 call sites) |
| Property header/overview | `resources/views/corex/properties/show.blade.php` (2 call sites) |
| Shared shell-header partial (property show + rental-inventory capture screen) | `resources/views/corex/properties/partials/_property-shell-header.blade.php` |

Eager-loaded (`->with([..., 'activeLease'])`) on `PropertyController::index()`'s list query and
`PropertyController::show()`'s `$property->load([...])` — no N+1, covered by a query-count test.

**Not touched, found and reported, not fixed (explicitly out of scope per instruction):**
- `resources/views/corex/properties/ad.blade.php` and `live-preview.blade.php` (public live
  listing page) still call `formattedPrice()`/`effectivePrice()` — these are marketing/public
  surfaces, not the four named screens; left exactly as they were.
- `resources/views/emails/rental-application-approved.blade.php` — same reasoning.
- Every matching/syndication/notification/AI-toolkit caller of `effectivePrice()` (P24/PP
  mappers, `MatchingService`, `RentalApplicationPropertyMatcher`, `BuyerIntelligenceService`,
  Ellie, etc.) — unchanged, as instructed.
- Rentals Command Centre (`rental-command-centre` views/controller/service) — audited directly;
  it does not display a rental price anywhere today, so there was nothing to fix there.
- The property's own Rental tab (`show.blade.php`, the existing-rental branch) already reads the
  active lease directly for its own "Active lease: ... R.../mo" block (a separate, pre-existing
  query, not `effectivePrice()`) — confirmed already correct, left untouched.
- 3 rental properties on QA1 are `withdrawn` while still carrying an active lease (ids 5294,
  5577, 5792) — reported per instruction, not changed; QA1 is demo data (STANDARDS.md
  Standard −1q), no status was altered.

### 9.2 Item B — shared `<x-rental-context-bar>` landlord chip no longer dead-ends

The landlord chip rendered a bare "Not linked" with no next step when a property had no
landlord/lessor contact tagged — every other dead-end case on this shared bar already had one
(the Lease Hub's own landlord line, `corex/leases/show.blade.php`, already shows "No landlord
linked" + a "Link landlord" link into the property's Contacts tab). One change, in the shared
component only (`resources/views/components/rental-context-bar.blade.php`): when the chip's
already-computed `$landlords` collection is empty, the chip renders "No landlord linked" + a
"Link landlord" link to `corex.properties.show` with `tab=contacts`, reusing the existing
`$landlords` variable — no second `Lease::landlordContacts()` implementation. Takes effect on
every screen that includes the bar (Lease Hub, Property Rental tab, Inspection/Fault
Report/Work Order show screens) with this one edit.
