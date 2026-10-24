## 12. Lease Hub (AT-440, added 2026-10-04) — lease detail becomes the tenancy file

**Status:** SPEC ONLY. Approved design, Johan, 4 Oct 2026. Master spec: `.ai/specs/rentals-rebuild.md`
(read its §1 shared standards first — list standard and context bar are not restated here).
**Closes the §11 "property-status-flip" open item above** — see §12.5: it resurfaced exactly as this
section predicted, and is resolved here using the Property model's own existing status governance,
not a new mechanism.

### 12.1 What changes and why
Today a lease's `show()` page is a CRUD detail view — rent, dates, tenant, not much else (§7 of this
spec). Johan's instruction: the lease detail becomes **the tenancy file** — the one screen that
answers "what has happened on this tenancy, what needs to happen next, and is there anything open
right now" without an agent having to check four other screens. Nothing about the existing CRUD
(§2-§7 above) changes; this section adds to the `show()` page, it does not replace it.

### 12.2 Layout (full-width, not a narrow centre column — Screen rule from the task brief)
- **Header:** address, status (live, reflecting §12.5), tenant name(s), rent, term (start–end or
  "month-to-month"). **Actions:** Print tenancy report, Edit, Report a fault.
- **Rental context bar** (master spec §1.2) — Lease chip highlighted.
- **Lifecycle strip:** `Application → Approved → Lease signed → In-inspection → Tenancy →
  Renewal/notice → Out-inspection`, each node's done/current/pending state **derived from data**, not
  a stored field:
  | Node | Done when | Current when | Pending when |
  |---|---|---|---|
  | Application | `rental_applications` row linked via `leases.rental_application_id` has `status=approved` | that application exists and is not yet approved | no linked application (lease created directly) |
  | Approved | application approved | — (instantaneous with Application done) | — |
  | Lease signed | a signed e-sign document exists OR lease was manually marked active with terms captured | lease is `draft` | — |
  | In-inspection | an in-type `rental_inspections` row with `status=completed` exists for this lease | lease is `active` with no completed in-inspection yet | — |
  | Tenancy | lease `status=active` and in-inspection completed | — | — |
  | Renewal/notice | a renewal lease (`leases.previous_lease_id` — new column, §12.5.5) exists, OR notice recorded (§12.5.3) | lease `end_date` within the agency's reminder-lead-time setting (`LeaseSetting::expiryNoticeWindowDaysFor()`, already live, `LeaseSetting.php:58-67`) | lease active, outside that window |
  | Out-inspection | an out-type `rental_inspections` row with `status=completed` exists | lease `status` in (`notice_given`,`ended`) with no completed out-inspection yet | — |
- **Tenancy log:** one chronological, searchable, type-filterable list of everything on the tenancy —
  application events, lease create/edit/escalation, inspections, faults, work orders, job cards
  (Stage 4), documents, notices (Stage 7). This is the evidence trail for the out-inspection. Search:
  free text over each entry's description. Filter: by type (the list above) and date range. No
  pagination cap below 500 rows (a full tenancy's log is expected to be small; if this assumption
  breaks on real data, paginate — flagged, not built defensively here per BUILD_STANDARD §0's "don't
  over-engineer for a case that doesn't exist yet").
- **Next-step card:** single next action derived from lifecycle-strip state, e.g. no completed
  in-inspection on an active lease → "Start in-inspection" (links straight into the inspection flow,
  pre-filled with this lease). One action, not a list — if two are equally due, the earliest-dated
  one wins; tie-break on lifecycle-strip order above.
- **Lease terms card:** rent, deposit, landlord spend limit (`properties.rental_no_approval_spend_threshold`,
  already live), notice window opens (derived: `end_date` minus the agency's tenant-notice-period
  setting, Stage 6), landlord(s) (via `Property::sellerOwnerContact()`/`contactsForRole('landlord')` —
  audit Part 3 item G, the existing derive-don't-duplicate mechanism, unchanged here), lease document
  view/print.
- **Open items card:** open fault reports + open work orders for this lease, count + direct links.
- **Property's Rental tab gains occupancy history** (§12.6).

### 12.3 Search / sort / filter / pagination on the Tenancy log (per BUILD_STANDARD §1b)
- **Search fields:** entry description, actor name.
- **Sort:** date (default: newest first).
- **Filter:** entry type (multi-select: application / lease / inspection / fault / work order / job
  card / document / notice), date range.
- **Pagination:** 50/page if the log exceeds 500 rows (see note in §12.2).
- **Empty state:** "Nothing recorded yet on this tenancy" — a brand-new lease has a genuinely empty
  log, distinct from a filtered-to-nothing result ("No {type} entries in this date range").

### 12.4 Scoping, permissions, API
- Own/branch/agency scoping: unchanged from §7/§8 above — the Lease Hub is the existing `show()`
  route with more content, not a new route, so it inherits the per-record guard added in
  `rentals-foundation-at439.md` §4.1.
- No new permission key — reuses `leases.view` to see the hub, existing action keys
  (`leases.create`/`.renew`/`.cancel`) continue to gate the actions shown.
- **API:** `GET /api/v1/leases/{lease}/tenancy-log` (JSON, same scope guard, paginated) — the screen's
  own tenancy-log panel consumes this; Andre's mobile app calls the same endpoint for a tenant/agent
  mobile tenancy view. Named per CLAUDE.md non-negotiable #7 (versioned, `->name()`'d, appears in the
  `/admin/api` catalogue automatically).

### 12.5 Property status follows the lease (Johan's ruling, 4 Oct 2026)

**Builds on the EXISTING, already-settled status mechanism — nothing new is designed here:**
- Agency-configurable status list: `PropertySettingItem` (`group='property_status'`,
  `app/Models/PropertySettingItem.php:29`) — agencies add/rename/deactivate their own status options;
  CoreX does not hardcode a status enum.
- Vocabulary guard + audit + domain event, already wired: `PropertyObserver::updated()` validates any
  dirty `status` against `Property::isAllowedStatus()` (AT-307 guard, `app/Observers/PropertyObserver.php:139-175`),
  then calls `PropertyAuditService::logStatusChange($property, $old, $new)` (line 480-481) and fires
  the already-catalogued `Property\PropertyStatusChanged` domain event
  (`corex-domain-events-spec.md:305`, consumed today by `FlagPropertyAsOnBooks` and
  `NotifyExpiredMandateBranchManager`).
- The "leased out" status already exists and is already live: **`let_out`** (confirmed real,
  currently-live `property_status` option — `rentals-shared-screens.md` §5.2, 70 real agency-1
  properties carry it today). **No new status is introduced for the base leased-out case.**

**What this section adds:** the code paths that *drive* a status change automatically, firing through
the exact mechanism above (never bypassing it, never writing `properties.status` directly without
going through the model save that triggers the observer).

1. **Lease becomes active → property status set to the agency's configured "leased out" status**
   (default `let_out`, but see §12.5.4 — agency-configurable which status value this is, since a
   second agency may have renamed or not have `let_out` in its own `PropertySettingItem` list).
   Fires from `LeaseActivationService::activate()` (the existing activation service, audit Part 3) —
   after the existing overlap-prevention guard succeeds, it additionally calls
   `$property->update(['status' => $agency->leasedOutStatusValue()])` (new settings accessor, §12.5.4),
   which runs through the normal `Property::save()` path and therefore through the observer/audit/
   event chain above unchanged.

2. **A lease CAN be created/activated on a Withdrawn property.** Real scenario: owner withdrew, a
   qualifying tenant appears, owner agrees to let again. `LeaseActivationService::activate()` does
   **not** block this. The UI, at the moment of creating/activating a lease on a property whose
   current status is `withdrawn` (or any other non-`let_out` status), shows a confirmation dialog:
   > "This property is withdrawn. Are you sure you want to use it for this lease?"
   On confirm, activation proceeds exactly as in point 1 — the status change to "leased out" is
   automatic, there is no separate manual re-activation step the agent must also perform. On cancel,
   activation does not proceed (lease stays `draft`).

3. **Status keeps following what CoreX learns during the lease — the full transition table:**

   | Event | New property status (agency-configured value) | Advertise? | Availability-from date |
   |---|---|---|---|
   | Lease created, not yet active (`draft`) | unchanged (whatever it was) | unchanged | — |
   | Lease activated | "leased out" (default `let_out`) | **No** — must not stay advertised (§12.5.6) | — |
   | Tenant gives notice / landlord gives notice, move-out date known (new `leases.notice_date`/`notice_given_by` columns) | "notice given" (new, agency-configured status, default label "Notice Given") | **Yes** — advertising starts now, ahead of vacancy | = day after lease `end_date` (e.g. lease ends 31 Oct → available-from 1 Nov) |
   | Renewal signed (Stage 6) — new lease term linked, old term closed | "leased out" (stays, or re-set if it had drifted) | No | — |
   | Lease becomes month-to-month (no fixed end date, explicit agent action) | "leased out" (stays) | No | — |
   | Lease end date passes with no renewal/notice recorded and no out-inspection yet | unchanged automatically (flagged in the Command Centre as overdue, Stage 3) — **no silent automatic flip to vacant without evidence the tenant actually left** | No change | — |
   | Lease ended (out-inspection completed, property confirmed vacant) | "vacant"/agency's normal pre-let status (e.g. `draft`/`to_let` — agency-configured, §12.5.4) | Yes, if the agency wants it back on market immediately | today's date (available now) |
   | Lease cancelled (early termination, any cause) | same as "lease ended" row | Yes | the recorded cancellation/vacate date |

   Every automatic change in this table writes to the property audit trail via the existing
   `PropertyAuditService::logStatusChange()` call, with a cause string identifying it as lease-driven
   (e.g. `"Lease #{id} activated"`, `"Tenant notice recorded on Lease #{id}"`, `"Lease #{id} ended —
   out-inspection completed"`) — distinguishing it from a manual agent-initiated status change in the
   same audit log, per CLAUDE.md non-negotiable #1's spirit of a legible record, not per any new
   requirement on `PropertyAuditService` itself (it already accepts a free-text reason).

4. **Agency settings (new, every one surfaced in the Setup Wizard per CLAUDE.md non-negotiable #10a):**
   - `leased_out_status_value` — which of the agency's own `property_status` items means "leased
     out." Default: `let_out` if present in that agency's list, else the agency must pick one during
     onboarding (no silent fallback to a status that doesn't exist for them).
   - `notice_given_status_value` — which status value means "notice given, re-advertising." New
     `PropertySettingItem` row seeded per agency (not hardcoded), default label "Notice Given."
   - `post_tenancy_status_value` — which status a property reverts to once a lease ends/cancels with
     no renewal. Default: whatever the agency's normal pre-let status already is (commonly `draft` or
     `to_let` — read from the agency's existing list, not assumed).
   - Per-transition **on/off toggles**, each defaulting ON: `auto_status_on_lease_active`,
     `auto_status_on_notice_given`, `auto_status_on_lease_ended`. An agency that wants to keep full
     manual control over property status can turn any of these off individually; CoreX then performs
     every other part of the lease lifecycle (lifecycle strip, tenancy log, next-step card) unchanged
     — only the automatic property-status write is skipped, and the next-step card surfaces "Update
     property status" as a manual action instead.

5. **Portal syndication consequences, stated explicitly (per instruction):**
   - **"Leased out" must not stay advertised.** The existing portal-sync machinery already reads
     `Property::isOnMarket()`/the status-driven on-market predicate (`Property.php:1943-1965`) to
     decide what stays live on P24/PP/the website — a status change to the agency's configured
     "leased out" value is already sufficient to pull the listing, through the existing mechanism,
     with no new portal-sync code. This spec does not add a second, parallel "should this be
     advertised" check.
   - **"Notice given" re-advertises, with the availability date carried through.** The existing
     `advertise` boolean + availability-date fields already added to the Rental tab
     (`property_rental_details_custom_fields` migration, 2026-09-21 — confirmed existing column) are
     the vehicle: setting status to "notice given" sets `advertise=true` and the availability-from
     date per the table in point 3. The portal payload for a "notice given" property states the
     availability date explicitly (existing field on the rental advert block per `rental-property-tab.md`)
     — a prospective tenant sees "available from 1 Nov," not a bare live listing with no date.
   - **New lease on a withdrawn property (point 2)**: the property leaves "withdrawn" and its
     withdrawn-mandate implications (not separately advertised while withdrawn, per existing
     withdrawn-status behaviour) the instant the lease activates — same mechanism, no special case.

### 12.6 Property's Rental tab gains occupancy history
New read-only section on the existing Rental tab: every **past** lease on this property (status
`ended`/`cancelled`), newest first — tenant name(s), term, end reason (ended/cancelled/non-renewed),
link to that lease's own hub (§12.2). This is the "who stayed when" record the audit found missing
(audit Part 1 item 3, Part 5 top-gap #7) — the active-lease partial planned but never built is
superseded by linking straight to the Lease Hub for the current lease, rather than duplicating its
summary on the Property tab a second time (Screen rule: no fact shown twice).

### 12.7 Acceptance criteria (additive to §7's existing CRUD acceptance criteria)
- [ ] Lease `show()` renders the full Lease Hub layout (§12.2) for every lease, including one with
      no application, no inspections, and no faults/work-orders yet (the brand-new-lease empty case).
- [ ] Lifecycle strip's done/current/pending states are derived live, never a stored enum that can
      drift from reality.
- [ ] Tenancy log search/sort/filter/pagination/empty-state all work per §12.3.
- [ ] Activating a lease on a `withdrawn` property shows the confirmation dialog and proceeds
      correctly on confirm; status changes automatically, no separate manual step required.
- [ ] Every automatic status transition in §12.5.3's table fires through `PropertyObserver`/
      `PropertyAuditService`/`PropertyStatusChanged` — never a direct `DB::table('properties')->update()`.
- [ ] Each of the three new agency settings (§12.5.4) is in the Setup Wizard with `explain` +
      `affects` copy, and each per-transition toggle defaults ON and is independently switchable.
- [ ] Turning a toggle OFF stops that one automatic write and surfaces the manual next-step action
      instead, without breaking any other part of the Lease Hub.
- [ ] A property moved to "leased out" drops off P24/PP/website through the existing on-market
      predicate, with no new portal-sync code required.
- [ ] A property moved to "notice given" re-advertises with the correct availability-from date
      visible in the portal payload.
- [ ] Property's Rental tab shows occupancy history for every past lease, linking to each one's hub.

### 12.8 Open questions for Johan
- **§12.5.4 default status mapping**: this spec proposes `let_out` as the default "leased out" value
  and leaves "notice given"/"post-tenancy" to be set per-agency with no universal default beyond
  "whatever the agency's existing pre-let status is" — confirm this is acceptable, or state a
  universal default label for agencies that have never configured either.

### 12.9 Built, 2026-10-04 (AT-440, cc3) — what landed, what was scoped down, and corrections found

**Built, verified in Tinker + PHPUnit against real/throwaway data (never Johan's real properties):**
§12.2 Lease Hub layout (header/actions/lifecycle strip/next-step card/tenancy log/lease terms/open
items/escalation history, full-width), §12.3 tenancy-log search/filter/pagination/empty-state, §12.4
scoping/API (`GET /api/v1/leases/{lease}/tenancy-log`, scope-guarded), §12.6 occupancy history on the
property Rental tab, the Print Tenancy Report PDF, and a narrowed §12.5 (below). New files:
`app/Services/Rentals/LeaseTimelineService.php`, `app/Services/Rentals/LeaseHubService.php`,
`app/Http/Controllers/Concerns/AuthorizesLeaseAccess.php` (superseded and removed at the AT-439 merge,
2026-10-04 — see §13 below: AT-439's `AuthorizesRentalRecordScope::guardRentalRecordScope()` is
byte-for-byte the same own/branch/all/audit-log logic, generalised across Lease/RentalFaultReport/
RentalWorkOrder/RentalInspection; `LeaseController` now uses that trait exclusively, per this trait's
own original docblock: "should be consolidated with that one at merge time rather than two
near-identical guards living side by side"),
`resources/views/components/rental-context-bar.blade.php`,
`resources/views/corex/leases/pdf/tenancy-report.blade.php`, `tests/Feature/Leases/LeaseHubTest.php`.

**Context bar — built here, not in AT-439.** §12.4/master-spec §1.2/rentals-foundation-at439.md §5 all
describe ONE shared context-bar component; §5 there frames it as an AT-439 (Stage 1) deliverable. As
of this build, it did not exist in AT-439's own worktree (checked directly) and this ticket's own task
brief explicitly assigns the context bar to AT-440. Built once, here —
`resources/views/components/rental-context-bar.blade.php` — exactly to the master spec's §1.2 chip
list and order. Included on the Lease Hub and the property Rental tab in this build; the one-line
`<x-rental-context-bar :lease="$x->lease" current="..." />` include for the Inspection/Fault
Report/Work Order show screens is handed to AT-439/AT-442 rather than added here, to avoid editing
files those lanes have open concurrently. **Do not build a second copy of this component** — adopt
this one.

**§12.5 property-status-follows-lease — built narrower than specced, by design, for the 15 Oct
deadline.** Built: point 1 (lease activates → property status flips to `let_out`, through the
property's normal `save()` so `PropertyObserver`/`PropertyAuditService::log()` fire exactly as a
manual change would), point 2 (confirm dialog on Activate when the property is currently
`withdrawn`, proceeding automatically on confirm — client-side speed-bump only, `activate()` itself
never blocks on it), the audit-trail-with-cause requirement (`metadata.cause = "Lease #{id}
activated"`), and the portal consequence (verified directly: a property with real on-market status
flips `isOnMarket()` true→false purely from this status write — zero new portal-sync code, exactly as
specced). **NOT built, deliberately** — the §12.5.3 full transition table's "notice given" row
(`leases.notice_date`/`notice_given_by`, the re-advertise-with-availability-date behaviour) and the
"lease ended → reverts to pre-let status" row: both depend on the notice/renewal and out-inspection-
completion workflows that are explicitly Stage 6/7 in the master spec, outside AT-440's own task
brief (which named only "lease active → leased out," "withdrawn confirm," "audit trail," and "portal
consequence stated" for this ticket). Revisit when Stage 6/7 land.

**§12.5.4 settings — not built; `let_out` used directly instead, and this is a deliberate scope
cut, not an oversight.** Rather than a new `leased_out_status_value` agency setting (which the
generic Setup Wizard control renderer can't express as a per-agency dynamic select without new
plumbing — it only supports static `options` arrays), this build reuses the `let_out` slug directly.
This is NOT a new multi-agency hardcode: `let_out` is already a system-wide status slug baked into
`Property::OFF_MARKET_STATUSES`/`CONCLUDED_STATUSES` (`Property.php:68,1726`) for every agency, not
an HFC-specific value introduced by this ticket. Prevent-or-absorb: if an agency's own
`property_status` vocabulary doesn't include `let_out`, `LeaseActivationService` skips the automatic
write entirely (`Property::isAllowedStatus()` check) rather than writing an unconfigured status —
confirmed in Tinker. `notice_given_status_value`/`post_tenancy_status_value` are moot until Stage 6/7
are built. **Flagged for Johan**: if a second agency needs `let_out` renamed/different before Stage 6
lands, the real setting from §12.5.4 should be built then, not deferred indefinitely.

**Correction to §12.5's own text, found during this build**: §12.5 states
`Property\PropertyStatusChanged` is "already catalogued... consumed today by `FlagPropertyAsOnBooks`
and `NotifyExpiredMandateBranchManager`." Checked directly — neither listener file exists anywhere in
the codebase, and `app/Events/Property/` has no `PropertyStatusChanged.php`. The domain-events
catalogue entry is aspirational, not built — a pre-existing gap, not something this ticket introduces
or was asked to fix (non-negotiable #2: reported, not touched). Because of this, the status-flip in
`LeaseActivationService::flipPropertyToLeasedOut()` goes through the property's normal `save()` +
`PropertyAuditService::log()` directly (the mechanism that genuinely exists today), not through a new
`PropertyStatusChanged` listener — consistent with non-negotiable #9's intent (no ad-hoc side-channel;
this is the SAME aggregate `LeaseActivationService` already locks and saves in the same transaction,
not a new cross-pillar hook) but flagged here in case Johan wants the full domain-event catalogue
entry actually built as separate follow-up work.

**Tests**: `tests/Feature/Leases/LeaseHubTest.php` — 14 cases: lease-isolation (a previous tenant's
fault never appears on the next lease's log — the task's own named concern, confirmed both via direct
service call and over real HTTP), timeline ordering (newest-first), timeline search/type filter,
lifecycle derivation (both an active-no-inspection lease and a draft lease), next-step priority rule
and its null case, landlord derivation from the contact pivot (not a column), lease-scoped open-item
counts, the tenancy-log API's and the PDF's cross-agency 403/404 guard, an authorised fetch of each,
and a brand-new lease with nothing attached rendering its genuinely-empty tenancy log. 13 passed on
first write; one (`lease hub show renders for a brand new lease`) initially failed because
`LeaseTimelineService` was logging a "Lease created" entry even for an untouched draft — fixed by
removing that entry (§12.3's own "a brand-new lease has a genuinely empty log" wording was the
correct spec; the implementation was wrong, not the acceptance criterion) — then passed.

### 12.10 Built, 2026-10-04 (AT-444) — §12.5.3's `notice_date`/`notice_given_by` columns now real

`leases.notice_date`/`notice_given_by`/`notice_note`/`move_out_date` (§12.5.3's row 2 cites these as
"new" — they now exist, migration `2026_10_04_220000`, `rental-renewals.md` §2/§7). Written by the
new one-click "Tenant gave notice"/"Landlord not renewing" outcomes
(`App\Services\Rentals\LeaseRenewalService::recordNotice()`), reversible, with history kept in a new
append-only `lease_events` table (not on the lease row itself, so a reversal never erases that the
event happened — see `rental-renewals.md` §14 for the full reasoning).

**§12.5.3's property-status side effects (the "Advertise? Yes" column, the re-advertise-with-
availability-date behaviour) are now built too**, under GATE 2 (approved by the conductor 2026-10-04,
same day), WITH one change from this section's original wording: no new "Notice Given" property
status is introduced. Notice is a fact about the LEASE only. The existing status mechanism is driven
directly (`Property::isAllowedStatus()` / `isOnMarket()`) — the notice dialog's "put this property
back on the market" tick always uses the agency's configured on-market rental status (a setting, never
`status_before_letting` directly), while a lease ending or being cancelled restores the property's own
captured pre-let status (`properties.status_before_letting`, captured once at lease activation),
falling back to that same on-market setting only if nothing was ever captured — two genuinely
different rules, not one shared expression. See `rental-renewals.md` §15 for the full as-built
transition table, the three agency settings, and the portal-syndication findings (including a real
pre-existing gap found in both portal mappers' own availability-date handling, reported not fixed).

`LeaseHubService::nextStep()`'s "Review renewal"/"Record outcome" now link to the real renewal screen
(`corex.leases.renewal.create`) instead of AT-440's own lease-edit placeholder, and are suppressed once
`Lease::hasActiveNotice()` or `renewed_lease_id` is set — nothing left to "review" until reversed.

### 12.11 Built, 2026-10-05 (AT-444/AT-441 follow-up) — "Lease actions" menu items open as dialogs

**Root cause fixed:** every item in the header's "Lease actions ▾" menu (Renew lease, Goes
month-to-month, Tenant gave notice, Landlord not renewing, Reverse notice, Reverse month-to-month,
Cancel lease) toggled visibility of a plain `<div>`/hidden `<form>` sitting in the right-hand column,
below "Open items" — on a normal screen the agent clicked the menu item and the result was off-screen,
with no visible feedback. Each item now opens the EXACT same form (same routes, same field names — no
behaviour change in what gets submitted) inside `<x-modal>` (the app's existing Breeze-style modal
component, `resources/views/components/modal.blade.php`), with focus landing on the first field
(`focusable` attribute) and Escape/backdrop-click/Cancel all closing it (the component's existing
mechanism). "Renew lease…" and the two "Reverse …" items had no confirmation UI at all before (Renew
navigated away immediately; Reverse submitted a hidden form with zero feedback) — they now open a
lightweight confirm dialog too, for the same "something visibly happens on click" reason.

**`?action=renew|month-to-month|tenant-notice|landlord-notice`** on the Lease Hub URL opens the
matching dialog on page load — the mechanism the Command Centre (`rental-command-centre.md` §3.2/§3.3)
uses to deep-link an agent straight into the right dialog instead of landing them on the hub with one
more click still needed. An action not valid for the lease's CURRENT state (e.g. `?action=renew` on a
lease that is not active, or `?action=month-to-month` on a lease already month-to-month) is silently
ignored — never force-opened. `reverse-notice`/`reverse-month-to-month`/`cancel` are deliberately NOT
URL-triggerable (reachable only from the menu itself).

**New, pure-logic service:** `App\Services\Rentals\LeaseActionDialogResolver` — `validActionsFor(Lease)`
mirrors the EXACT conditions the menu itself uses to decide which items to render, and `resolve()`
decides which dialog (if any) opens: a just-failed submission (identified by a hidden `_lease_action`
field each dialog's form carries, echoed back via `old('_lease_action')`) always wins over `?action=`,
so the agent sees their own mistake corrected, not a different dialog. The hidden field exists because
Tenant-gave-notice and Landlord-not-renewing post the SAME field names (`move_out_date`, `note`) through
`LeaseRenewalController::recordNotice()` — without it, Laravel's single default error bag can't tell
which of the two actually failed, and `old()` would leak one form's entered values into the other's
same-named field.

Tests: `tests/Unit/Services/Rentals/LeaseActionDialogResolverTest.php` (pure logic — validity per
state, URL-action-wins vs reopen-wins, invalid-for-state is ignored either way) and
`tests/Feature/Leases/LeaseActionDialogsTest.php` (HTTP — `?action=` opens the right dialog and is
ignored when invalid; a failed tenant-notice/landlord-notice/cancel submission reopens the SAME dialog
with the entered values; the shared-field-name leak between tenant-notice and landlord-notice is
proven absent).

---
