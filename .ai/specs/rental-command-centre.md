# Rental Command Centre (AT-441)

**Status:** BUILT. Landed on QA1, 2026-10-04. Conductor browser-verification fix round landed same
day — see §10.
**Ticket:** AT-441. **Date:** 2026-10-04. **Pillar:** Property (primary — every row is a property),
Contact (tenant, read-only context via the active lease), Agent (`User`, who acts from the queue).
**Master spec:** `.ai/specs/rentals-rebuild.md` — read its §1 (shared standards) first.
**Builds on:** `leases.md` (Lease model, `LeaseSetting`), `rental-work-orders.md` (fault/work-order
models + `RentalWorkOrderSetting`), `rental-inspections.md` (RentalInspection model) — all read-only
consumers; this build added NO columns, relations, or methods to any of those files.

This revision replaces the original design draft with what was actually built, per Johan's own
build brief for AT-441 (which took precedence over the draft on every point where the two
differed — each is called out below). The draft's original wording is preserved in git history
(`.ai/specs/rental-command-centre.md` at commit `2e3256362`) for reference.

---

## 1. What this screen does and why

Unchanged from the draft: today an agent has no single place to see every rental property's
current state across the whole module. The Command Centre is the first item in the Rentals menu —
the "walk in, see everything that needs attention, act" screen the module has been missing.

---

## 2. Navigation

Built exactly as specced. First entry in the existing Group-1 Rentals nav panel
(`corex-sidebar.blade.php`, inside the `rental-applications` Alpine group, immediately before
"Rental Applications"). Route: `corex.rentals.command-centre.index`. Permission key (new):
`rental_command_centre.view` — access-only, no create/edit/archive keys (§6).

The sidebar's `$activeGroup` route-pattern matcher was ALSO widened to recognise
`corex.rentals.command-centre.*` (its own nav entry would otherwise never auto-open the panel).
The Stage-1 investigation (`rentals-stage1-investigation.md` item A) found the SAME matcher
missing `leases.*`/`rental-inspections.*`/`rental-fault-reports.*`/`rental-work-orders.*` — that is
a pre-existing gap on OTHER lanes' routes, reported but **not fixed here** (out of this ticket's
scope; AT-439/cc1's own fix).

---

## 3. Layout (as built)

### 3.1 Tiles
Ten tiles, `pstat-v2` style (Properties-screen pattern), each a real link that both counts AND
filters the table below from the SAME scoped base query (`RentalCommandCentreService::
derivedPropertyQuery()` → `tileCounts()` / `applyTile()`).

| Tile | Definition as built |
|---|---|
| Rental properties | `properties.listing_type` in the rental vocabulary (`Property::isRental()`'s own list — rental/to_let/to-let/lease), this agency, not soft-deleted. Every status (including `withdrawn`) is included deliberately — see §7. |
| Occupied | has an active lease (`leases.status = 'active'`) today |
| Unoccupied | no active lease today |
| Expiring in window | active lease, `end_date` set, inside `LeaseSetting::expiryNoticeWindowDaysFor()` (existing setting, reused, no new setting added) |
| Notice given | **hardcoded 0** — `leases` has no `notice_date` column. **AT-444 must add `leases.notice_date` (nullable date)** before this tile can be anything but 0. Per Johan's brief: do not add lease fields in this ticket. |
| Renewals in progress | active lease has a **DRAFT** lease chained to it via `leases.previous_lease_id` (column already exists, written by nothing today — `LeaseController` has no `renew()` action yet). Reads 0 today, starts counting real rows the day AT-444 ships a Renew action — no code change needed here when it does. Deliberately NOT "active lease has previous_lease_id set" — `LeaseActivationService::activate()` leaves that set permanently on every renewed-in lease, so that check would count every past renewal forever, not just ones still pending signature. |
| Month-to-month | active lease, no `end_date`, `is_month_to_month = true` |
| Open faults | **Fixed 2026-10-04 (§10.1).** TOTAL count of `rental_fault_reports` rows in scope, status NOT IN (`resolved`, `cancelled`, `declined`) — `RentalCommandCentreService::FAULT_OPEN_STATUSES_EXCLUDED`, matching `RentalFaultReportController`'s own "open_no_work_order" status set exactly. NOT the count of properties with ≥1 (that was the bug) — clicking the tile still filters the table to properties with ≥1 open fault, a deliberately different, still-correct number. |
| Open work orders | **Fixed 2026-10-04 (§10.1).** TOTAL count of `rental_work_orders` rows in scope, status NOT IN (`completed`, `cancelled`) — `RentalCommandCentreService::WORK_ORDER_OPEN_STATUSES_EXCLUDED`. Same property-count-vs-total fix as faults above. |
| Inspections due | a UNION (property counted once, never summed): (a) has an open (not completed/cancelled) `rental_inspections` row, OR (b) has an active lease with zero completed `type=in` inspections ever. Built as a union deliberately — a literal sum of the two buckets double-counts the common case of one lease matching both. |

### 3.2 Needs-action queue (built per Johan's brief, NOT the draft's per-property-collapsed design)
**One row per ITEM, one action button each** — a flat list, never collapsed per property/lease.
Five independent rules, each a standalone query against the owning model (Lease / RentalFaultReport
/ RentalWorkOrder), merged and sorted urgency-then-age in PHP:

| Rule | Fires when | Action | Links to |
|---|---|---|---|
| Review renewal | active lease, `end_date` set, inside the reminder window | **Review renewal** | `corex.leases.show` |
| Record outcome | active lease, `end_date` set, in the past | **Record outcome** | `corex.leases.show` |
| Fault awaiting approval | `rental_fault_reports.status = 'awaiting_approval'` | **Open** | `corex.rental-fault-reports.show` |
| Work order overdue | **Fixed 2026-10-04 (§10.1).** Reuses `RentalWorkOrder::scopeOverdue()` directly — status IN (`ordered`,`in_progress`) AND `updated_at` older than `RentalWorkOrderSetting::overdueReminderDaysFor()`. Previously a hand-rolled check against `reported_at` that also wrongly treated a merely-`reported` (not yet ordered) work order as eligible — disagreed with `RentalWorkOrderController`'s own "Overdue" tile/filter. | **Open** | `corex.rental-work-orders.show` |
| Start inspection | active lease, zero completed `type=in` inspections | **Start inspection** | `corex.rental-inspections.create` |

Every row's `detail` now names its own record (§10.2): the fault/work-order's own `title`, or
`"Tenant: " . Lease::tenantNames()` for the three lease-based rules — never the old generic
"Fault awaiting owner approval" / "Work order overdue" copy, which made two rows on the same
property indistinguishable. Each row's one age indicator (`age_days`, shown only when > 0) is a
clean non-negative whole number — see §10.3 for the Carbon 3 bug this fixes.

"No renewal outcome" / "no outcome recorded" (the draft's and the brief's own wording) is vacuously
true for every match today — `leases` has no outcome-recording field yet (same gap as "Notice
given" above), so every lease that reaches the window/past-end-date state genuinely needs a human
look, which is the correct behaviour for a needs-action queue even before that field exists.

**Known gap, reported not fixed:** the "Start inspection" link passes `?property_id=` to
`corex.rental-inspections.create`, but `RentalInspectionController::create()` (cc1-owned, AT-439)
does not read that parameter today — it always renders its own full property picker. The param is
harmless (ignored) and forward-compatible the day that controller is extended to read it.

### 3.3 Full table — every rental property, any state
Built exactly per the brief: address, status, tenant(s), lease end/month-to-month, open faults
count, open work orders count, last inspection date, agent, row actions. Row actions use ONLY
existing routes (`corex.leases.show`, `corex.properties.show`, `corex.rental-fault-reports.create`,
`corex.rental-work-orders.create`, `corex.rental-inspections.create`) — "Renew" and "Record notice"
do not exist as routes yet (AT-444), so they are omitted from the row actions entirely, per the
brief's own instruction, rather than rendered as dead buttons.

---

## 4. Search / sort / filter / pagination / scope (as built)

- **Search fields:** property address (`Property::scopeSearchAddress()`), tenant name (via
  `lease_tenants`/`contacts` on the active lease), erf number, **landlord (added 2026-10-04, §10.5)**
  — via the `contact_property` pivot, role IN (`seller`,`owner`,`landlord`,`lessor`), the same
  source `Property::sellerOwnerContact()` uses (`Lease` has no landlord accessor of its own yet).
- **Sort columns:** address, **lease end date (default, ascending, empty last — fixed 2026-10-04,
  §10.4)**, status, open-faults count, open-work-orders count, last-inspection date.
- **Filters:** status (data-driven — distinct values actually present on this agency's rental book,
  never a hardcoded list, per CLAUDE.md non-negotiable #9), agent, branch, lease-end date range —
  all four, per the brief (the draft listed only status + date range; the brief's richer list won).
- **Pagination:** 25/50/100/10 per-page selector, 25 default.
- **Empty state:** "No rental properties yet…" vs "Nothing matches this filter."
- **Own | Branch | All switch**, default = the user's `rental_command_centre.view` scope ceiling
  (`PermissionService::getDataScope()` + `clampScope()`, the DeedsCaptureController reference
  pattern every other rentals screen already uses).

**Scoping design decision, stated explicitly because it differs from calling each child model's own
`scopeVisibleTo()`:** this screen resolves ONE own/branch/all predicate against the
`rental_command_centre` permission module, applied directly to `properties.agent_id`/`branch_id`
(matching `Property`'s own existing 'own'/'branch' scope semantics). It deliberately does NOT call
`Lease::scopeVisibleTo()` / `RentalFaultReport::scopeVisibleTo()` / `RentalWorkOrder::
scopeVisibleTo()` — those resolve 'own' as "the record's `created_by_user_id`", a different concept
that would produce an inconsistent, confusing result on a screen whose every row is a PROPERTY (an
agent's queue could show another agent's property just because they happened to be the one who
logged a fault on it). Scoping at the property level first and deriving every child count/queue
item through `property_id` membership in that same scoped set guarantees one consistent boundary
across all four sources — exactly what BUILD_STANDARD §1c requires, implemented by construction
rather than by re-checking four different scope methods that could disagree.

---

## 5. Print / API

- **Print** — `GET /corex/rentals/command-centre/print` — standalone print HTML (same pattern as
  `market-intelligence/suburb-report-print.blade.php`), tiles + the current filtered table, up to
  1000 rows.
- **API** — `GET /api/v1/rentals/command-centre`, named `v1.rentals.command-centre`, gated
  `permission:rental_command_centre.view`, registered in the canonical `v1` route group (auto-listed
  on `/admin/api` per non-negotiable #7). Returns the same tiles/queue/table JSON the web screen
  renders, built from the same `RentalCommandCentreService` — cannot drift from the web screen.
  (The draft said the web screen's "own Alpine component" consumes this API — the built screen has
  no Alpine at all, it is plain server-rendered Blade; the API exists for Andre's mobile app only,
  per the brief.)

---

## 6. Permissions

`rental_command_centre.view` (access-only) — added to `config/corex-permissions.php`, module
`rental_command_centre`. **Not** added to any role's `role_defaults` (matching the existing pattern
for `leases.view`/`rental_fault_reports.view`/`rental_work_orders.view`/`rental_inspections.view` —
none of those are pre-granted either); assigned per role via Role Manager, Johan/Andre's own call.
Every action button navigates to the owning screen's own existing permission-gated route — this
screen creates, edits, and archives nothing itself, so it needs no action keys of its own.

---

## 7. Agency settings / decisions made during build

- **Reminder lead time** reuses `LeaseSetting::expiryNoticeWindowDaysFor()` — no new setting.
- **Work-order overdue threshold** reuses `RentalWorkOrderSetting::overdueReminderDaysFor()` — no
  new setting. (Not named in the original draft; needed for the "work order overdue" queue rule the
  brief specified, and an agency-configurable value already existed for exactly this purpose.)
- **"Rental properties" tile includes every status, including `withdrawn`/archived mandates** — a
  deliberate choice, not an oversight: the Stage-1 investigation (item I) found a real property
  (5792) marked `withdrawn` while an active, signed lease ran through 2027-05-31 against it — a
  genuine business-data contradiction. Excluding withdrawn properties from this screen would hide
  exactly the kind of row an agent most needs to see here. Flagged for Johan: should a future
  "Withdrawn" status change ever be blocked while an active lease exists? (Stage-1 item I's own
  open question — not decided or built here.)
- **No new wizard entry** — this build adds no new agency SETTING (it reuses two existing ones), so
  CLAUDE.md non-negotiable #10a does not apply.

---

## 8. Acceptance criteria (verified at build time)

- [x] All ten tiles render correct live counts and each filters the table on click — verified via
      Tinker against real QA1 data (read-only) by comparing every tile's count to an independently
      written direct query; also covered by `RentalCommandCentreServiceTest`.
- [x] Needs-action queue — one row per ITEM (not per property, per the brief), correct single action
      button, each of the five rules covered by its own passing test.
- [x] Full table search/sort/filter/pagination/empty-state all function per §4 — covered by tests
      (search, status filter, sort) plus manual Tinker verification of the underlying query.
- [x] Scope is correctly and consistently applied — verified both via Tinker (two real agencies on
      QA1's live data, `Auth::login()`'d, confirmed different correct counts matching an independent
      direct query) and via `RentalCommandCentreServiceTest`'s own/branch/all/agency-isolation tests.
- [x] Print renders the current filtered view.
- [x] `/api/v1/rentals/command-centre` is registered, named, scope-guarded.
- [ ] `/admin/api` catalogue listing — not independently re-verified by opening that admin screen in
      this build (the route registration itself follows the exact pattern every other `v1.*` route
      uses, which the catalogue already auto-lists); flagged so this isn't silently assumed.

## 9. What AT-444 (Renewals) must add for two tiles to stop reading 0/placeholder

1. `leases.notice_date` (nullable date) — "Notice given" tile.
2. A `Renew` action on `LeaseController` that creates a DRAFT lease with `previous_lease_id` set to
   the current active lease — "Renewals in progress" tile already queries for exactly this shape
   and needs no further change once that action exists.

---

## 10. Conductor browser-verification fix round (2026-10-04)

Johan walked the deployed screen as `johan@hfcoastal.co.za`, scope All, on qatesting1 and found
five real defects. All five fixed same day, same branch-off-QA1-worktree discipline, 25/25 tests
(was 17), re-verified against real QA1 data.

### 10.1 Count mismatch — one definition of "open", now shared everywhere
Root cause: the "Open faults"/"Open work orders" tiles counted the number of PROPERTIES with ≥1
open item, not the TOTAL number of open items — so a property with 2 open work orders (1 Kenmuir
Road / property 5792: one Reported, one Ordered) still showed tile=1. Separately, the per-row
`open_faults_count` column excluded only `resolved`/`cancelled`, not `declined`, disagreeing with
`RentalFaultReportController`'s own "open_no_work_order" tile (§39) which excludes all three.

Fix: `RentalCommandCentreService::FAULT_OPEN_STATUSES_EXCLUDED` / `::WORK_ORDER_OPEN_STATUSES_EXCLUDED`
are now the ONE definition, used by the row column, the tile TOTAL (a direct count, not a property
count), and the tile's table filter (still property-level — "show me the properties", a
deliberately different, still-correct number from the tile itself). Verified against real QA1 data
(property 5792, agency 1): tile open_faults=3 (2 awaiting_approval + 1 approved elsewhere in the
agency), open_work_orders=2 — both matching an independent direct count exactly.
Tests: `test_open_faults_tile_equals_sum_of_per_row_column_not_property_count`,
`test_open_work_orders_tile_equals_sum_of_per_row_column_not_property_count`.

### 10.2 Needs-action rows now name their own record
Two faults awaiting approval on the same property rendered as two identical "Fault awaiting owner
approval" rows — correct routing (each button already opened the right fault by id) but visually
indistinguishable. `detail` is now the fault/work-order's own `title`, or `"Tenant: " .
Lease::tenantNames()` for the three lease-based rules, never generic copy. Each row shows exactly
one age value (no separate embedded date text duplicating the age column).
Test: `test_queue_rows_name_their_own_record`.

**Found and fixed in the same pass:** `age_days` was silently broken for every rule except A —
Carbon 3 changed `diffInDays()`'s default from an absolute int to a SIGNED FLOAT, so every
past-dated "how old" value came out as a negative decimal (e.g. `-11.06`), which the blade's
`age_days > 0` display guard then hid entirely. Every call site now wraps `(int) abs(...)`.
Test: `test_queue_age_days_are_non_negative_whole_numbers_except_future_deadlines`.

### 10.3 Work order "overdue" now reuses the real scope
The queue's "work order overdue" rule used a hand-rolled `reported_at` check that also wrongly
treated a merely-`reported` (never ordered) work order as eligible. It now calls
`RentalWorkOrder::scopeOverdue()` directly — the exact scope `RentalWorkOrderController::index()`'s
own "Overdue" tile and `?overdue=1` filter already use (status IN `ordered`/`in_progress`,
`updated_at` threshold). Test: `test_queue_work_order_overdue_rule_matches_the_real_overdue_scope`.

### 10.4 Layout — queue beside the table, not above it
Approved mockup: on wide screens (`lg:` breakpoint) the needs-action queue (~40%, `lg:col-span-2`)
sits LEFT of the property table (~60%, `lg:col-span-3`) in a single grid row, so the table is never
pushed off screen. Queue per-page dropped from 20 to 8 to fit the narrower column. On narrow
screens the two stack; the queue shows its header count (unchanged markup, no new helper text) plus
only its first 5 rows (`hidden lg:flex` on rows index ≥ 5 — CSS-only, no second query).

### 10.5 Default sort — lease end ascending, vacant last
Previously defaulted to "address" (alphabetical), which put every vacant/no-lease property FIRST
under plain string sort. Now defaults to `lease_end` ascending; `applySort()` adds
`orderByRaw('active_end_date IS NULL')` before the main `orderBy` so a NULL end date (vacant or
month-to-month) always sorts LAST regardless of direction — MySQL's own ascending-NULLs-first
default was the root cause. Test: `test_default_sort_is_lease_end_ascending_with_vacant_last`.

### 10.6 Landlord added to search
Per §4 above — `contact_property` pivot, seller-side roles, same source `Property::
sellerOwnerContact()` uses. Test: `test_search_matches_landlord_via_contact_property_pivot`.

### Still reported, not fixed (unchanged from §3.2/§2)
"Start inspection" still cannot pre-select the property — `RentalInspectionController::create()`
(cc1-owned, AT-439) doesn't read a `property_id` param. Passed on to Johan per his own instruction
this round, not re-litigated here.
