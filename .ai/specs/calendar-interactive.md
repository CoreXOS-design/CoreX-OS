# CoreX Calendar — Interactive Calendar (AS-BUILT Spec)

> **Status:** AS-BUILT — documents the interactive calendar UI + behaviour actually shipped to **Staging (HELD from live)**. Authored 2026-07-02 under **AT-155** (spec remediation).
> **Verified against:** branch `spec-remediation-calendar-comms` @ origin/Staging `740b9c18`. Every statement below was checked in code; file/line anchors are given.
> **Owner:** Johan (product) · Build history: AT-154 + the `calendar-*` branch series.
> **Pillars:** Contact (attendees, buyers/sellers), Property (the appointment's subject), Agent (`User` — organizer, attendees, conflict target). Deal is read indirectly via property/contact links.
> **Relationship to `spec-calendar-module.md`:** that file is the original v1.0 **design** doc (auto-generated events, reminder engine, iCal, Gantt vision). This file is the **as-built interactive layer** — tiles, panels, private events, `occupies_time`, `event_nature`, conflict warnings, the recurring model, delete, and AT-154 attendee auto-fill. Where the two overlap, **this file is the current truth** (see §0 for the specific sections it supersedes).

---

## 0. What this supersedes in `spec-calendar-module.md`

`spec-calendar-module.md` described these as aspirational; the as-built shape differs and lives here:

| Design-spec section | As-built status | Where |
|---|---|---|
| "Manual Events" → "Custom recurring events" | Built — materialise-on-view model | §7 |
| "Week/Day View" drag + "Timeline/Gantt … event durations" | Built as absolute-overlay + lane-packing on week/day (no separate Gantt view) | §3 |
| Data model "Recurrence" (`is_recurring`/`recurrence_rule`/`parent_event_id`) — described as dormant columns | **Activated** | §2, §7 |
| Build "Phase 4 … Recurring events" | Shipped (this file) | §7 |

The reminder engine, iCal, auto-generation observers, and dashboard widget in the design spec are **not** re-specified here — they remain as that doc describes, except where `event_nature` changes overdue behaviour (§6).

---

## 1. Purpose

The interactive calendar is where an agent actually works the day: creating viewings/evaluations/meetings, seeing them as time-accurate tiles, catching double-bookings before they happen, and keeping private time private. The design-spec vision ("80% auto-generated") is the data feed; this layer is the **human surface** on top of it, judged by one question (CLAUDE.md): does it let an agent be an agent, or trap them behind a screen?

Everything below is server-authoritative — the Blade/Alpine client is a convenience skin over controller/service decisions, never the security boundary.

---

## 2. Data model (as-built)

Two tables carry the interactive behaviour. **No per-event columns were added for `event_nature`; the recurring columns were already present and are now active.**

### 2.1 `calendar_events` (existing)
- Recurrence columns (migration `2026_03_31_300001_create_calendar_events_table`, lines 40–42) — **now active, no longer dormant**: `is_recurring` (bool), `recurrence_rule` (string, RRULE subset), `parent_event_id` (self-FK, `nullOnDelete`).
- `metadata` (JSON) carries **per-event overrides**: `event_nature` (§6), recurrence-exception markers `recurrence_override_date` / `recurrence_cancelled` (§7).
- `source_type` distinguishes `manual` / `manual:demo` (user-created, editable/draggable) from source-driven rows (auto-generated, guarded from edit/drag/delete). `end_date` nullable (duration).
- Model `App\Models\CommandCenter\CalendarEvent`: `parent()` / `children()` relations; SoftDeletes.

### 2.2 `calendar_event_class_settings` (per-class config; agency rows override `agency_id = NULL` globals)
Three flags were added here — all **class-level**, resolved via `CalendarEventClassSetting::forAgencyAndClass($agencyId, $class)` (agency row first, else global):

| Flag | Migration | Meaning |
|---|---|---|
| `occupies_time` (bool, default false) | `2026_07_02_000001_add_occupies_time_to_calendar_event_class_settings` | `true` = a real appointment that occupies time and can conflict; `false` = a marker/reminder that never conflicts. Backfilled `= (actor_role <> 'neither')`. §5 |
| `event_nature` (string, default `actionable`) | `2026_05_05_000010_add_event_nature_to_calendar_event_class_settings` | class default for "requires feedback" — `actionable` vs `informational`. §6 |
| `autofill_buyers` (bool, default false) | `2026_07_02_000003_add_autofill_buyers_to_calendar_event_class_settings` | whether buyers are auto-added as attendees for this class. Backfilled `true` where `actor_role = 'buyer_action'`. §8 |

**Design decision (recorded):** each of these is a **dedicated column**, not an overload of `actor_role` or the activity-points `buyer_facing` flag. `actor_role` still exists but no longer implicitly decides marker-vs-appointment (that is `occupies_time`) or buyer auto-fill (that is `autofill_buyers`). Behaviour was proven identical to the old `actor_role`-derived logic by backfilling from `actor_role` (0 mismatches on staging).

Seeder `CalendarEventClassSeeder` sets all three **by `actor_role`, never a hardcoded class list**. Global class defaults: viewing / listing_presentation / property_evaluation = `actionable`; meeting / other / private = `informational`; markers/expiries = `actor_role = 'neither'` (never conflict).

---

## 3. Tile rendering — times + duration spanning

Primary view: `resources/views/command-center/calendar/index.blade.php`. Duration geometry is **server-computed in Blade** (not JS), so it is correct on first paint.

- **Time-on-tile:** the `$timeRange($e)` Blade closure (index.blade.php:50–57) renders `HH:MM–HH:MM` (en-dash) when the event has a same-day end after start, else just `HH:MM`; all-day events render no time. Used on month/agenda tiles and the week/day overlays.
- **Duration spanning (week/day):** timed tiles are an **absolute-position overlay**, not flow chips. `$layoutDayColumn($events, $gridStart, $gridCount)` (index.blade.php:63–101) maps each event to grid-minutes and greedily **lane-packs** overlapping clusters (a whole cluster shares a lane count so widths align; a 30-minute floor keeps short events clickable). Each tile gets `top: <startPct>%; height: calc(<heightPct>% - 2px)` off the now-line grid geometry, and a left/width computed from `laneIndex / (days × lanes)` past a 56px time-gutter.
- **Z-INDEX GOTCHA (do not regress) —** timed tiles set **inline `style="z-index: 3"`**, *not* a Tailwind arbitrary class `z-[3]`. CoreX deploys via `git pull` + `view:clear` with **no `npm run build`**, so a newly-introduced arbitrary Tailwind class is absent from the compiled CSS and the tile would compute `z-index: auto`, falling **below** the `z-[1]` drag-capture layers that then swallow the click. Empty-space clicks still hit the drag layer (create/drag intact); now-line markers sit at `z-[5]`. **Rule: any new critical layering on the calendar uses inline `z-index`, never a fresh Tailwind arbitrary class.**

---

## 4. Panel stacking (mutual exclusion)

Two side panels exist: the **event-detail** panel (`panelOpen`) and the **create-event** panel (`showCreateEvent`). They are mutually exclusive via two reciprocal Alpine `$watch`es on the root `calendarPage()` component (index.blade.php:104): opening either closes the other. `openEventPanel(eventId)` sets `panelOpen = true` (which auto-closes create); Escape closes whichever is open. This is the "two side-panels stacked" fix — a user can never have both open fighting for the same screen space.

---

## 5. `occupies_time` — marker vs appointment (decoupled from `actor_role`)

Marker-vs-appointment is now an explicit per-class boolean (`occupies_time`, §2.2), not an implicit read of `actor_role = 'neither'`.

- **Conflict detection** — `ConflictDetectionService` builds `$nonOccupyingClasses = CalendarEventClassSetting::where('occupies_time', false)->pluck('event_class')` (`withoutGlobalScopes`, because class settings are `agency_id = NULL` globals) and excludes those categories from overlap checks. A category with no settings row is treated as an appointment (safe default — it can conflict).
- **Server-render marker logic** — `CalendarController::applyFilters` uses the same `occupies_time = false` set to decide which events participate in the O(n²) sorted-overlap `has_conflict` sweep.
- **Settings carry-through** — `SettingsController::update` validates `occupies_time => sometimes|boolean` and, on save, defaults it from the global row so editing a class never silently resets it.

Behaviour is identical to the previous `actor_role`-derived logic; the decouple exists so an agency can, in future, mark a class as time-occupying independently of who acts on it.

---

## 6. `event_nature` / "requires feedback" (per-event override, no new column)

Exposes the existing actionable-vs-informational distinction as a **per-event choice** on the create/edit form, defaulting from the class.

- **Resolution** — `CalendarEvent::effectiveEventNature()`: returns `metadata['event_nature']` if it is `actionable`|`informational`, else the class default (`CalendarEventClassSetting::forAgencyAndClass(...)->event_nature`), else `actionable`. `isInformational()` = nature is `informational`. **No column on `calendar_events`** — the per-event override lives in `metadata` JSON.
- **What keys off it:**
  - The **feedback CTA / completion gate** (`show()` returns `is_actionable => !isInformational()` and `event_nature` so the edit form pre-selects).
  - The **OVERDUE marker** — `ProcessReminders` only sweeps to `status = 'overdue'` events whose effective nature is `actionable` (metadata override, else class default). **Informational events never go overdue** — they are time-blocks that need no feedback.
- **Form** — a `<select name="event_nature">` with `actionable` ("Requires feedback — can go overdue") / `informational` ("No feedback needed — time-block only"), defaulting from the `classConfigMap` on category change, and prefilled on edit.

---

## 7. Recurring events (materialise-on-view)

Recurrence is **materialise-on-view**: parents are stored once; occurrences are generated as in-memory virtual clones for the requested range and are **never persisted**. This keeps storage flat and makes every occurrence inherit the parent's colour, conflict, privacy, and nature for free.

### 7.1 Rule — `RecurrenceRule` (hand-rolled RFC-5545 subset)
`app/Services/CommandCenter/Calendar/RecurrenceRule.php`. Supports **FREQ ∈ {DAILY, WEEKLY, MONTHLY}**, `INTERVAL` (≥1), and an end of `never` / `UNTIL` (accepts `YYYYMMDD` or `YYYYMMDDTHHMMSSZ`, `endOfDay`) / `COUNT` (≥1). **No `BYDAY`/`BYMONTHDAY`** — weekly lands on DTSTART's weekday; monthly uses `addMonthsNoOverflow` (31 Jan → 28/29 Feb, never skips a month). Anything outside the subset → `parse()` returns null. Helpers: `build()`, `advance(Carbon)`, `humanLabel()` (e.g. "Every 2 weeks, 10 times").

### 7.2 Expansion — `RecurrenceExpander`
`app/Services/CommandCenter/Calendar/RecurrenceExpander.php` · `expand($parent, $rangeStart, $rangeEnd): Collection`.
- Walks a cursor from `event_date` (DTSTART) via `rule->advance()`, emitting occurrences in range that are **not** overridden. `COUNT` counts from DTSTART including pre-range occurrences (RFC semantics).
- **Agency caps** from `AgencyContactSettings` (migration `2026_07_02_100001_add_calendar_recurrence_limits_to_agency_contact_settings`, both columns nullable with code defaults): `calendar_max_occurrences` (**default 200**) and `calendar_max_expansion_days` (**default 400**). A runaway guard caps iterations at `maxOccurrences + 10000`.
- **Virtual occurrence** — `replicate()` of the parent excluding recurrence fields, shifted by the parent's duration, with `is_recurring = false`, `source_type = 'recurring'` (keeps drag-reschedule off), `exists = true` but **never saved**, plus dynamic markers `is_occurrence`, `occurrence_date`, `recurrence_parent_id`.
- **Inheritance is automatic** — because occurrences are `CalendarEvent` clones, they flow through the same `CalendarEventService::getEventsForRange` → `CalendarController::applyFilters` path as real rows, inheriting colour, conflict markers, private redaction (§4 of privacy), and `effectiveEventNature`. In `getEventsForRange`, recurring parents are pulled separately (`is_recurring = true`, `event_date <= rangeEnd`, subject to `visibleTo` + type/property/status filters), expanded, then merged and sorted with the base rows.

### 7.3 Synthetic occurrence id — keeps tile-click sites unchanged
- Encoding: `syntheticId = parentId * 1e8 + YYYYMMDD` (`OCC_ID_BASE = 100000000`). Any id ≥ 1e8 is an occurrence; real ids stay below. `decodeId()` returns `{parent_id, date}`; a client mirror `decodeOccurrenceId(id)` exists in the Blade.
- **Why:** every tile fires `openEventPanel(eventId)` with the raw (possibly synthetic) id. `openEventPanel` decodes it once; if synthetic it fetches the **parent** with `?occurrence=<date>`. So the ~12 tile-click / restore / panel call sites are **unchanged** — the synthetic→parent resolution happens in one chokepoint. `CalendarController::show` reads `?occurrence=` and substitutes the occurrence date/time onto the in-memory parent, exposing `is_occurrence` / `occurrence_date` / `recurrence_label`. The panel reconstructs the synthetic id (`recurrence_parent_id * 1e8 + date`) when opening the edit form.

### 7.4 Edit / delete scope — `RecurrenceEditService` (this / this-and-future / all)
`app/Services/CommandCenter/Calendar/RecurrenceEditService.php`. Scope values are the string literals **`'this'` / `'future'` / `'all'`** (validated in the controller). **Every operation is soft — there are no hard deletes anywhere on this path.**

**EDIT:**
- **this** → `editOccurrence()` creates/updates an **exception child** row (`parent_event_id = parent.id`, `is_recurring = false`, `recurrence_rule = null`, `source_type = 'manual'`, `status = 'pending'`) carrying `metadata['recurrence_override_date'] = <date>`; idempotent via `findException()`. The parent series is untouched.
- **future** → `editFuture()` truncates the parent's rule to `UNTIL = splitDay − 1` and **creates a new recurring series** from the split date carrying the remaining COUNT/UNTIL. (Splitting at the first occurrence delegates to `editAll`.)
- **all** → `editAll()` updates the parent in place; the whole series follows.

**DELETE:**
- **this** → `deleteOccurrence()` writes a **dismissed tombstone child**: `metadata['recurrence_override_date']` + `metadata['recurrence_cancelled'] = true`, `status = 'dismissed'`. No row removed; the expander skips that slot (tombstones are rejected from the feed).
- **future** → `deleteFuture()` truncates the parent rule to `UNTIL = splitDay − 1` (split-at-first delegates to `deleteAll`).
- **all** → `deleteAll()` soft-deletes the exception children then the parent (Eloquent `->delete()` on a SoftDeletes model → `deleted_at`); expansion stops. `withTrashed()` still returns the rows.

`overrideDates()` collects children's `recurrence_override_date`s so the expander skips edited/cancelled slots; `countOccurrencesBefore()` drives the future-split math.

### 7.5 Tests
`tests/Feature/CommandCenter/CalendarRecurrenceTest.php` — **14 tests** (real feed + destroy routes, `RefreshDatabase`): weekly/COUNT/UNTIL bounds; edit-this = single exception child (series intact); edit-all = whole series; edit-future = split; delete-this = dismissed tombstone (no hard delete); delete-all = soft-deleted parent, expansion stops; informational recurring occurrence never actionable; occurrences get distinct synthetic ids and inherit category/end for conflicts; one-off soft-delete audited; HTTP `recur_scope=this` leaves series; HTTP `recur_scope=all` removes series; source-driven/system event → 422, not trashed.

---

## 8. AT-154 — attendee auto-fill by appointment type (server-authoritative)

**The bug it fixed:** creating a `listing_presentation` on a property auto-pulled the property's **buyer** as an attendee, and `viewing` (a buyer-driven class) auto-filled nobody. **The rule (two-dimensional — type × entry-point):**

- **Sellers auto-fill for ALL property appointments** (viewing, property_evaluation, listing_presentation, meeting, other).
- **Buyers auto-fill ONLY for buyer-driven classes** (`autofill_buyers = true`, i.e. viewing).
- **Context override unaffected:** scheduling *from* a buyer (Buyer Pipeline / a buyer Contact) still prefills that buyer explicitly via `prefill_attendees`, regardless of type. Manual buyer-add still works everywhere.

**Server enforcement** — `CalendarController::propertyOwners(Request, int $propertyId)` (the single endpoint web + mobile both call — `command-center.calendar.property-owners` on both `web.php` and `api.php`):
- Builds `$owners` from the `contact_property` pivot (seller/owner/landlord/lessor → `seller_contact`; buyer/tenant/lessee → `buyer_contact`; else `attendee`).
- Reads `?category=<class>`; if the class does **not** `autofill_buyers` for the property's agency, **rejects the `buyer_contact` rows server-side**. No category param → returns everyone (back-compat). `classAutofillsBuyers()` resolves agency row first, then global, unknown class → false.

**Client** — `autoPopulateOwners(propertyId)` early-returns for `actor_role = 'neither'` (markers never auto-fill), reads the chosen category, and passes `?category=` so the **server** gates buyers. The client filter is convenience only — the server is authoritative, so the mobile client is gated identically.

**Verified on staging** (property 5946, 1 seller + 1 buyer): listing_presentation / property_evaluation / meeting / other → seller only; viewing → seller + buyer; no category → both. Test `CalendarAttendeeAutofillTest` 5/5.

### 8.1 Known limitation (flagged per BUILD_STANDARD, not a defect to hide)
`SettingsController::update` carries `occupies_time` and `event_nature` through the class-settings form but does **not** carry `autofill_buyers` — that flag is currently set only by migration/seeder (by `actor_role`), not editable through the settings UI. This is acceptable today (the seeded defaults match the intended rule) but should be surfaced in the settings form when an agency needs to change which classes auto-fill buyers. Recorded here so it is a deliberate choice, not silent drift.

---

## 9. Double-booking / self-conflict warnings

A **non-blocking** soft warning when the organizer (or an invited agent) already has an appointment in the chosen slot.

- **Endpoint** — `GET …/calendar/check-conflicts` (declared before the `/calendar/{calendarEvent}` wildcard). Returns **`{ has_conflict, conflicts }`** and delegates to `ConflictDetectionService::checkUserConflicts(user_id, start, end, exclude_event_id)`. **Latent bug fixed here:** the endpoint previously returned a raw array, so the client's `has_conflict` read was `undefined` and the attendee ⚠ never fired.
- **Organizer self-check (client)** — a 200ms-debounced `checkSelfConflict()` watches the start/end/all-day form fields; it early-returns for all-day events or when there is no current user (markers make no time claim), passes `exclude_event_id` when editing, and reads `data.has_conflict ? data.conflicts : []`. Markers are excluded server-side (non-occupying classes, §5). The UI is an amber "you already have an appointment at this time… you can still save" — **never blocks the save**.
- **Attendee check** reuses the same endpoint with the invited agent's `user_id`.

---

## 10. Private events + privacy redaction chokepoint

A configurable **`private`** event class (seeded: `actor_role = 'both'`, `event_nature = 'informational'`, in `MANUAL_CREATABLE_CLASSES`). Private events show as a busy time-block to everyone but reveal their details only to their owner.

- **Model** — `isPrivateClass()` (`category === 'private'`), `privateOwnerId()` (`created_by_id ?: user_id`), `isPrivateHiddenFrom(?User $viewer)` (**role-blind** — admins/owners/BMs are "someone else"; there is no override role).
- **Single chokepoint** — `CalendarEvent::applyPrivacyFor(?User $viewer)`: if hidden, it redacts the in-memory model to `title = 'Private'`, nulls description/metadata/property/contact and all linked relations, sets `isPrivacyRedacted = true`. Called as the **last step** of `CalendarController::applyFilters` (after conflicts/colour are computed on the real data), so **every** server-rendered view and the events() JSON is covered by one call. Redaction is display-only, never persisted.
- **Redacted `show()`** — returns a placeholder JSON (`title:'Private'`, `is_editable:false`, `is_actionable:false`, `is_draggable:false`, `is_private:true`, empty attendees/records) that still keeps the time/colour busy block.
- **Creator-only edit guards** — `update()` and the feedback endpoints `abort(403)` when `isPrivateHiddenFrom($request->user())`. Meets STANDARDS "No Silent Locks" — a non-owner sees a busy block they cannot open, and the private owner retains full control.

---

## 11. Delete action (all panels, soft only)

A Delete action is available from the event-detail panel for every user-editable event.

- **Controller** — `CalendarController::destroy` guards non-manual/system rows (`source_type` not in `{manual, manual:demo}` → 422, so auto-generated events cannot be hand-deleted). For a recurring event with `recur_scope ∈ {this, future, all}` it dispatches to `RecurrenceEditService` (§7.4) and writes a `CalendarEventAuditEntry` action `deleted` recording scope + occurrence_date. A single (non-recurring) event falls through to invitation-cancel cascade + soft-delete.
- **Client** — the panel `deleteEvent()` opens the this/future/all scope modal for a recurring event, or a plain confirm for a one-off, then issues `DELETE …/calendar/{parentId}` with `{recur_scope, occurrence_date}`.
- **No hard deletes anywhere** — every removal is a SoftDeletes `->delete()`, a `status = 'dismissed'` tombstone, or a rule truncation (CLAUDE.md non-negotiable #1 / STANDARDS "No Hard Deletes"). Confirmed by tests asserting `deleted_at` set and `withTrashed()` recovery.

---

## 12. Navigation & permissions

- **Route** — `GET /corex/command-center/calendar` · name **`command-center.calendar`** · middleware `permission:command_center.calendar.view`. Sibling routes: `.events`, `.show`, `.store`, `.update`, `.destroy`, `.complete`, `.dismiss`, `.reschedule`, `.feedback.show/store`, `.search.attendees`, `.property-owners`, `.check-conflicts`, `.invitations*`.
- **Sidebar** — under the DASHBOARD group: a "Calendar" sub-item (`route('command-center.calendar')`, active-state bound via `request()->routeIs('command-center.calendar')`) with a sibling "Invitations" sub-item carrying a pending-invite badge (`resources/views/layouts/corex-sidebar.blade.php`). Satisfies non-negotiable #2 (no orphaned page).
- **Permission** — `command_center.calendar.view` gates the page; edit/delete additionally gated by ownership (`source_type` guard + private creator-only guard). Mobile hits the same permission-checked controller methods.

---

## 13. Cross-cutting invariants (do not regress)

1. **Server is authoritative.** Attendee auto-fill, conflict data, and privacy redaction are decided in the controller/services; the client cannot widen them. (§8, §9, §10)
2. **One privacy chokepoint** — `applyPrivacyFor` in `applyFilters`. New views must render through `applyFilters`, not bypass it. (§10)
3. **Occurrences are virtual** — never saved; edits/deletes go through `RecurrenceEditService`, producing exception children / tombstones / rule truncations, never a saved occurrence row and never a hard delete. (§7)
4. **Inline `z-index` for calendar layering** — never a fresh Tailwind arbitrary class (no `npm run build` on deploy). (§3)
5. **`{this,future,all}` scope** is the contract for every recurring edit/delete surface. (§7.4, §11)
6. **Class flags are by `actor_role`, never a hardcoded class list**, in every seeder/backfill. (§2.2)

---

## 14. Deploy status

All of the above is on **Staging (HELD from live)** except where a specific item is noted otherwise. AT-154 shipped via `AT-154-calendar-attendee-autofill` → Staging; the tile/panel/private/occupies_time/event_nature/conflict/recurring work shipped via the `calendar-*` branch series and was reconciled into origin/Staging `740b9c18`. **Promotion to live is gated on Johan's explicit authorization.**

---

# 15. Calendar Noise Redesign (AT-164) — AS-BUILT (Staging, HELD from live)

> **Status:** AS-BUILT on **Staging (HELD from live)** — all 8 build gates (§15.10) shipped 2026-07-03 on
> branch `AT-164-calendar-redesign`. Gates 1–2 (species split + server aggregation + aggregate-chip popover +
> new-tab deep links) banked earlier; Gates 3–8 (Tile Library `<x-tile>` · Deck + launch tiles · continuous
> vertical month scroll + JSON range/month-block endpoints · layer toggles · live-RAG focus/poll loop · frozen
> mobile contract) this pass. Each gate carries a passing feature test (`tests/Feature/CommandCenter/Calendar*Test.php`).
> My Deals tile ships FLAGGED HIDDEN behind `calendar.tile.my_deals` (seeded OFF; owner-bypass noted). Promotion
> to live remains gated on Johan's explicit authorization. Original spec (below) authored before build; the shape
> here is what shipped.
>
> Johan-directed from live use: the month view drowns in
> system-deadline bars (6+ "Portal listing expiring" red bars/day bury the 3 real appointments). Design
> settled Johan+Claude, patterned on Fantastical / Notion Calendar / Monday "My Work" / Outlook-web scroll.
> **Investigation basis:** four parallel subagent audits (2026-07-03) of the render pipeline, the 8 feed
> sources' deadline taxonomy, the dashboard tiles (see `.ai/audits/2026-07-03-dashboard-tile-audit.md`), and
> the DR2 RAG / Tasks / client-freshness patterns. DR2 specifics are the Staging truth (WS0–WS3), not the
> pre-WS0 working-tree state one agent saw.

## 15.0 The problem, precisely
The interactive render reads **only persisted `calendar_events` rows** (§14 note: feed/source events are
**pre-materialised** into `calendar_events` by the nightly `corex:calendar:reconcile`; the registry is a
write-side concept invisible to render — see the render audit). So "noise" is not a feed problem; it is a
**render/aggregation** problem on `calendar_events`. Today every row becomes its own tile/bar (day cell
capped at `$chipCap = 6` with a `+N` badge, `index.blade.php:417`), so a day with 8 portal-listing expiries
shows 6 red bars + "+2" and the real viewing is pushed out of sight. The fix is to **render deadlines as
aggregates, not bars**, and to give appointments the grid to themselves.

## 15.1 Event species split (the backbone)
Split every `calendar_events` row at render time into two species. The signals already exist — **no feed
change, no new event data**:

- **APPOINTMENT** (renders as a time bar, as today): the manual classes (`viewing`, `property_evaluation`,
  `listing_presentation`, `meeting`, `other`, `task`) — i.e. `occupies_time = true` (§5) / `actor_role <>
  'neither'` — PLUS the single source-emitted timed class `property_showday`. These have a real clock time
  (parsed without `->startOfDay()`), a duration, and can conflict.
- **SYSTEM DEADLINE** (stops rendering as an individual bar → becomes an aggregate chip, §15.2): everything
  the 8 sources emit as a point-in-time all-day marker (`->startOfDay()`, `occupies_time = false`). Full
  enumeration (from the feed-taxonomy audit) — grouped by `event_type` (the layer-toggle axis, §15.6):

  | event_type (layer) | Deadline categories (class keys) |
  |---|---|
  | **deal** | `deal_step_deadline`, `deal_registration_target` |
  | **document** | `signature_expiry`, `sales_doc_expiry` |
  | **lease / rent** | `lease_expiry`, `commercial_lease_expiry`, `rent_escalation`, `rent_due` |
  | **property / listings** | `mandate_expiry`, `portal_listing_expiry`, `filed_document_expiry` (+`property_showday` is the appointment exception, stays a bar) |
  | **people** | `employment_anniversary`, `employee_termination`, `leave_cycle_end`, `rmcp_ack_expiry` (+ `agent_birthday`/`contact_birthday`/`leave_*` are informational, §15.1.1) |
  | **compliance** | `ffc_expiry`, `pi_insurance_expiry`, `tax_clearance_expiry`, `fica_renewal_due`, `rmcp_review_due`, `screening_due`, `training_expiry`, `compliance_provision_expiry`, `compliance_override_expiry`, `agent_document_expiry` |
  | **payroll** | `payroll_run`, `sars_emp201`, `uif_declaration`, `sdl_submission`, `sars_emp501`, `tax_year_end`, `irp5_deadline` |
  | **recurring (system)** | `ppra_trust_audit`, `salary_review` |

  The authoritative classifier is `occupies_time` (§2.2/§5) — a class with `occupies_time = true` is an
  appointment; `false` is a deadline. `all_day`-from-midnight corroborates but `occupies_time` is the single
  source of truth (already used by conflict detection), keeping this consistent with the existing invariant
  (§13.6: flags by `actor_role`/class config, never a hardcoded list).

### 15.1.1 Informational markers
`agent_birthday`, `contact_birthday`, `leave_annual`, `leave_sick`, `office_closure` are `event_nature =
informational` (§6) → never RAG-escalate, currently `HIDDEN_BY_DEFAULT_CATEGORIES`. They join the **Personal**
layer (§15.6), off by default, and when on render as a single neutral "🎂 2 birthdays" chip — never a bar.

## 15.2 Aggregate deadline chips
On the grid, all deadline rows for a given **(day × category-group)** collapse to **one compact chip**:
`⚠ 6 listings expiring`, `Rent due ×3`, `SARS EMP201`. Rules:
- **Grouping key** = day + a display **category group** (a small agency-configurable map from class → group;
  default groups mirror the `event_type` layers: Listings/Portal, Compliance, Rent, Deals, People, Payroll).
- **Colour** = the worst RAG in the group for that day, via the existing `CalendarThresholdResolver`
  (`resolveForEvent`, overdue→red; per-step `deal_step_deadline` overrides preserved) — computed server-side,
  never client. Count badge shows the group size.
- **Click → popover** listing the individual items (title + RAG dot + due), each a **new-tab** link to its
  record (§15.7A) via `CalendarController::resolveSourceLink()` (extend its route map — today only 6 model
  types resolve; the rest carry `source_type`+`source_id` but no deep link, a gap to close per group).
- **Grid-cell max rows configurable** (`AgencyContactSettings.calendar_grid_max_rows`, default 4): appointments
  fill first; deadline chips occupy the remainder; overflow = one "＋N more" chip opening the day popover.
- **Aggregation is server-side** — extend `CalendarEventService::getMonthGrid()` to return, per cell, a
  `deadlineGroups[]` structure (group → count, worstColour, itemIds) alongside the appointment `byDate[]`.
  The browser never receives 200 deadline rows; it receives ≤ a handful of grouped chips per cell
  (kills the DOM-bar explosion — §15.8 performance).

## 15.3 Continuous-scroll grid (per-view)
Replace month pagination with continuous scrolling; **the scroll axis is per-view** (Amendment: month
vertical, week horizontal, day single):
- **MONTH** — weeks flow **vertically** and continuously (Outlook-web). Sticky month/year label while
  scrolling; a **Today** anchor button; **jump-to-date** via the existing date picker; **URL/scroll-state**
  so refresh returns to the same position (`?anchor=YYYY-MM-DD` + restore).
- **WEEK** — days flow **horizontally** (scroll left/right through weeks); the absolute-overlay timed-tile
  geometry (§3) is unchanged within a week column.
- **DAY** — a single day (unchanged).
- **Windowed/virtualised render with server-side aggregation per visible range.** Never render months of DOM:
  fetch a window (e.g. current ± N weeks) and lazy-append as the user scrolls, each window served pre-aggregated
  (§15.2). This requires a **new JSON range endpoint** returning the same `{byDate, spanningBars, deadlineGroups}`
  shape `getMonthGrid` builds (the current page is full-reload HTML only — see render audit §5), consumed by an
  Alpine windowing controller. The existing **right slide-over for create/edit is UNCHANGED** and must coexist
  (it opens via `openEventPanel(id)` → `GET /calendar/{id}`, decoupled from the grid — §2 render audit).
- **Invariant kept:** new critical layering uses **inline `z-index`**, never a fresh Tailwind arbitrary class
  (§3 / §13.4 — no `npm run build` on deploy).

## 15.4 The Tile Deck + unified Tile Library
Below the grid sits a **Deck** of tiles. **One Tile Library, two surfaces** (Dashboard + Calendar) — see the
audit `.ai/audits/2026-07-03-dashboard-tile-audit.md`. The dashboard is already a data-driven tile system
(`CommandCentreService` card array `card_id/title/icon/urgency/count/items[]/view_all_url` → `today.blade.php`);
extract that into a single `<x-tile>` component and add the four missing deltas:

- **Tile contract** (single, both surfaces consume it): header (icon+title), **count badge**, **RAG accent**
  support, **independent scroll area** (body scrolls, not capped-and-hidden), **per-row new-tab click-through**
  (§15.7A), **collapse**, **empty state** and **degraded state** (a tile whose data source errors renders a
  quiet "couldn't load" body — **never 500**, mirroring the Backups-page robustness doctrine).
- **The Deck** = **X slots** below the grid (default **3–4** on desktop; slot count `AgencyContactSettings.
  calendar_deck_slots`, agency-configurable). Per-user: **tile picker per slot**, **drag-reorder**, **saved
  layout**, **one-click reset-to-default**. **Role-based default layouts** are agency-configurable.
- Dashboard's 22 "clean" tiles migrate into the component in place (no data change); the 7 "needs-refactor"
  tiles keep a bespoke body slot within the same shell (audit tally).

## 15.5 Launch tiles
1. **Upcoming Events** — agenda of the user's next appointments (today first, then next days). Source:
   `CalendarEventService` (the existing `today_appointments` builder pattern), appointment species only.
2. **Notifications / Deadlines** — the RAG-ranked deadline groups (§15.2 data), ranked by urgency
   (overdue → red → amber → green), each group expandable to items with new-tab links. This is the "coming up"
   intelligence, now a tile.
3. **To-dos** — surfaces the existing **`CommandTask`** module (audit §4): `CommandTask::visibleTo($user,$scope)
   ->open()->thisWeek()`, rows via `task-card.blade.php`, `view_all_url = command-center.tasks`.
4. **My Deals** — DR2 pipeline attention: `DealV2::visibleTo($user)->whereIn('overall_rag',['amber','red',
   'overdue'])` (the enum + `visibleTo` scope + `DealV2Controller ?rag` filter exist on Staging WS0). **Spec now,
   ship FLAGGED HIDDEN behind the DR2 programme hold** — no `deals_v2` rows live, no DR2 UI. Gate the tile on a
   new `calendar.tile.my_deals` capability (there is NO feature-flag config today — gating is permission-based;
   add the capability, default OFF) so it lights up when DR2 goes live without a rebuild.
5. **Repurposed dashboard tiles** — any of the 22 clean tiles a user picks into a Calendar slot (same component).

## 15.6 Layer toggles
A **category-visibility control** beside the existing All/Branch/Mine scope radios (`index.blade.php:202-219`).
Layers derive from `event_type`: **Appointments · Deals · Compliance · Listings/Portal · Rent · People ·
Personal**. Each toggle hides/shows that species/group on the grid AND filters the Notifications tile.
**Per-user persisted** (localStorage + a server `CalendarUserPreference` row so it survives devices — a model
already exists for notification prefs, extend it); **agency-configurable defaults** (which layers start on;
Personal off by default). Server still authoritative — toggles never widen the visibility/RAG-role gate (§13.1).

## 15.7 Click-through (new tab) + live RAG freshness loop
**A. Click-through = NEW TAB.** Every tile row and every deadline-popover item opens its record in a **new tab**
(`target="_blank" rel="noopener"`), consistent with dashboard behaviour — deal step, contact, property, listing,
task, compliance item. Appointment bars keep opening the in-page slide-over (unchanged). Extend
`resolveSourceLink()` so each deadline category resolves a deep link (the audit found only 6 of ~30 do today).

**B. Live RAG loop.** When an action completes elsewhere (a DR2 step ticked in another tab), the grid + tiles
reflect the new RAG **without a manual full reload**. This is a **client-freshness** question, not a data one:
the WS0 `deals:process-rag` / observer already repaint the `calendar_events` row + `overall_rag` server-side.
- **Minimum (spec'd):** (i) **refetch-on-window-focus / `visibilitychange`** — no such data-refresh exists
  today (must add; borrow the listener shape from `presentations/public/show.blade.php:1470`), plus (ii) a
  **light poll** at a **configurable interval** (`AgencyContactSettings.calendar_poll_seconds`, default 60 —
  reuse the exact `today.blade.php:237-248` `setInterval`+`fetch`+reactive-reassign primitive, currently a
  hardcoded 60000). Both hit the new JSON range endpoint (§15.3) and re-render in place (no page reload).
- **Demo moment to satisfy:** red deal chip → click (new tab) → complete step → return to calendar tab →
  focus refetch → chip is green. Caveat recorded: server-side RAG currency depends on the completion writing
  through `DealPipelineService`/the observer (there is no separate batch on the DR1 path); the client loop only
  refetches — it cannot compute RAG the server hasn't.

## 15.8 Doctrine (binding)
- **All thresholds/defaults agency-configurable:** grid max rows, deck slot count, poll interval, category→group
  map, default layers, role-based deck layouts, deadline RAG thresholds (already per-class in
  `calendar_event_class_settings`). Never hardcoded.
- **Mobile keeps the cockpit contract:** the `MobileCalendarController` JSON envelope (snake_case
  `id/title/event_type/category/event_date/end_date/all_day/colour/...`) is **frozen** — the redesign adds an
  optional aggregated shape behind a version/param, never mutates the existing fields. The Deck becomes
  **swipeable cards / tabs / a bottom sheet** on small screens; layer toggles become a filter sheet.
- **No behaviour change to event CRUD** — create/edit/delete/reschedule/complete/dismiss, the slide-over, the
  recurring `{this,future,all}` contract (§7.4), attendee auto-fill (§8), conflict warnings (§9), and private
  redaction (§10) are all untouched. This is a render + surface redesign only.
- **Performance:** aggregation is **server-side** (extend `getMonthGrid`), windowed/virtualised — never 200 DOM
  bars. Tiles/lists usable at **half-width** (truncation + count badge + "view all" affordance).
- **Robustness:** every tile and popover degrades gracefully (empty/error → quiet state, never 500).

## 15.9 Data model / persistence
- **No new event columns.** Species split reads existing `occupies_time`; grouping uses `category`/`event_type`.
- **Per-user layout memory:** extend `CalendarUserPreference` (or add `calendar_deck_layouts`) — per user:
  deck slot→tile map, collapsed states, active layers, grid/deck proportions. localStorage mirrors for instant
  paint; the server row is the cross-device source.
- **Agency config:** new nullable columns on `AgencyContactSettings` (code defaults): `calendar_grid_max_rows`
  (4), `calendar_deck_slots` (4), `calendar_poll_seconds` (60), plus a `calendar_category_groups` JSON map and
  `calendar_default_layers` — all with sensible code defaults so an agency with no row behaves correctly.

## 15.10 Build sequencing (one continuous build — no deferral framing)
1. **Species split + server aggregation** — classify `calendar_events` by `occupies_time`; extend
   `getMonthGrid`/`getEventsForRange` to emit `deadlineGroups[]`; render deadlines as chips (grid unchanged
   otherwise). *Immediate noise relief.*
2. **Aggregate chip popover + `resolveSourceLink` deep-link coverage + new-tab click-through.**
3. **Tile Library** — extract `<x-tile>` from `today.blade.php`; dashboard migrates in place; contract deltas
   (scroll/RAG-accent/new-tab/collapse/degraded).
4. **The Deck** — slots, per-user picker/drag/layout/reset, role defaults; launch tiles 1–3; My Deals tile
   built + flagged hidden.
5. **Continuous-scroll grid** — JSON range endpoint + windowed Alpine controller; per-view scroll axes; sticky
   label, Today anchor, jump-to-date, URL/scroll-state; slide-over coexistence.
6. **Layer toggles** — the visibility control + per-user/agency persistence.
7. **Live RAG loop** — focus/visibility refetch + configurable poll against the range endpoint.
8. **Mobile** — Deck→sheet/tabs, layer filter sheet, contract-compatible.

## 15.11 Acceptance criteria
- A day with 8 portal-listing expiries + 1 viewing shows **the viewing as a bar + one "⚠ 8 listings expiring"
  chip**, not 6 red bars + "+2".
- Month scrolls vertically continuously; week scrolls horizontally; refresh returns to scroll position; Today
  anchor + jump-to-date work; no month-pagination controls remain.
- The Deck shows 3–4 tiles; a user can pick/reorder tiles per slot, layout persists across sessions and devices,
  reset-to-default works; My Deals tile is absent until its capability is enabled.
- Every deadline/tile item opens its record in a new tab; the DR2 demo (red→complete→green on focus) works.
- Layer toggles hide/show species and persist; agency defaults apply to new users.
- Mobile: Deck is a sheet/tabs; `MobileCalendarController` fields unchanged; event CRUD identical on both.
- Server-side aggregation proven: a busy month issues a bounded number of grouped chips, not hundreds of bars.

---

# 16. Calendar Versatility Round — EXPLICIT-SAVE arrangement model (AT-164 amendment)

> **Status:** AS-BUILT on the QA1 lane (branch `AT-164-calendar-versatility`, off `origin/Staging`),
> deployed to **qatesting1** for Johan's first QA. **NOT on Staging, NOT live** until his pass is
> relayed. Authored 2026-07-06. This section AMENDS §15.9's persistence model: the debounced
> auto-persist is **retired** and replaced by an explicit-save default with a session transient.

## 16.1 Why (Johan-directed)
The v2 cockpit auto-persisted every tweak straight to the per-user DB default (debounced 400–450ms).
Two problems: (a) the "popping panels" bug — hiding My Deck / the right panel then navigating away
within the debounce window abandoned the pending write, so the hide was lost and the panel returned
on the next load; (b) there was no separation between "how I've arranged the calendar right now" and
"my saved default" — every accidental drag became permanent. Johan's ruling: **on reload it loads the
saved default; in-session changes persist across navigation but a fresh reload returns to the default;
an explicit control promotes the current arrangement to the default.**

## 16.2 Three tiers of arrangement state
- **SAVED DEFAULT** — `calendar_user_preferences` (`calendar_cockpit` JSON + `calendar_deck_layout`
  + `calendar_layers` + `default_view`). Rendered by the server on **every fresh page load**.
  Written by **exactly one path**: "Save as my default" → `POST /calendar/cockpit`
  (`CalendarController::saveCockpit`, repurposed from the old debounced endpoint into a single atomic
  full-arrangement write). Existing per-user rows become the initial saved default for free — nobody
  loses their arrangement on deploy (no data migration).
- **TRANSIENT** — client `sessionStorage['corex.calendar.arrangement']`. Every in-session change writes
  here **synchronously** (no debounce, no DB). Survives navigate-away-and-return (same tab). A hard
  reload discards it. Owned by `window.CoreXCal` (index.blade.php).
- **FACTORY / ROLE** — the fallback when a user never saved (null pref columns / `resolveCockpit`
  code defaults / agency `calendar_default_deck_layouts` per role).

## 16.3 Reload vs navigate — the distinction that makes it work
`window.CoreXCal` classifies the load via `performance.getEntriesByType('navigation')[0].type`:
- **reload** → `boot()` clears the transient at script-parse time (before any Alpine component reads
  it) → the SAVED DEFAULT renders.
- **navigate / back_forward** → each component applies the transient over the server-rendered default
  in its `init()` (panel-collapse, strip height/collapse, tile ratios, deck layout, layers).

Client-reactive tiers apply instantly with no server round-trip. The **structural** tiers (view mode,
scroll mode) are server-rendered shells; they are carried across navigation by a single **guarded**
`?view=&scroll=` redirect (`reconcileStructural`) that fires only on a non-reload load with no explicit
param and a differing transient — it cannot loop and never fires on a reload.

## 16.4 Controls
- **"Save default" + "Reset"** live in the **persistent top toolbar** (beside the Stream/Pages
  toggle), NOT in the deck header — so they stay reachable in EVERY arrangement state, including
  deck-hidden and both-panels-hidden (Johan QA1 finding: a deck-header button vanished with the deck,
  so you couldn't save a "deck hidden" default). They render in all views. Verified visible+clickable
  across deck-hidden / panel-hidden / both-hidden / month·week·day / Stream·Paged at 1920×1080 +
  1366×768 (`proof-savebtn-visibility.js`).
- **"Save default"** → `window.CoreXCal.save()` reads the live arrangement across the
  registered Alpine components + current view/scroll and POSTs it to `saveCockpit`; on success it
  clears the transient (current == default) and toasts. THE only write path to the default.
- **"Reset"** → `window.CoreXCal.reset()` clears the transient and reloads a clean URL
  (strips `?view/scroll/anchor/date`) → the server renders the SAVED default. It does **not** erase
  the saved default. (`POST /calendar/cockpit/reset` remains as a harder "reset to factory" and is
  retained for completeness, no longer wired to the button.)
- Auto-persist writers removed: `default_view` no longer saved on `?view=` (controller `index()`);
  `persistCockpit`, `togglePanelCollapse`, the deck add/remove/reorder, and the layer `persist()` all
  now write the transient only. Deck add uses a new non-persisting single-tile endpoint
  `GET /calendar/tile/{tileId}` (`CalendarController::tile` → `CalendarTileService::buildOne`).

## 16.5 Calendar scrolling preference (continuous vs paged)
New per-user arrangement key `scroll_mode ∈ {continuous, paged}` (lives in the `calendar_cockpit` JSON,
**no migration**; default `continuous`). Context: Andre dislikes the continuous stream, Johan loves it.
- **CONTINUOUS** — the as-built week-stream (§15.3), unchanged.
- **PAGED** — a classic single-month grid with prev/next month paging (week = a single week with
  prev/next; day unchanged). **One rendering truth, two navigation shells:** the paged month reuses the
  SAME `_week-row.blade.php` partial (scoped to the anchor month's weeks via `renderMonthAgenda`'s new
  `pagedWeekRows`); the paged week reuses `_day-column.blade.php` (`pagedDayColumns`). Today + `?date`
  deep-links work in both. Toolbar toggle (Stream / Pages) beside the view switcher; server honours
  `?scroll=` for the request without persisting (transient), promoted to the default only via Save.

## 16.6 Continuous-mode month boundary tint
Each week row carries an alternating faint month wash so a month change reads as a glanceable
full-width colour shift — **in addition to** (never covering) the existing 2px seam accent + "Jul 1"
first-cell label + sticky header label. Implementation: `_week-row.blade.php` computes the week's
owning month (its Thursday, ISO) → parity class `cal-month-tint-{0,1}`; the CSS colours it only under
the continuous container (`.cal-scroll-continuous`) so the paged month (one month) stays untinted.
Strength is a single tunable CSS variable `--cal-month-tint-alpha` (default `0.045`, slate) so Johan
can dial it on QA1. (Complementary option, not shipped: a slim full-width month band — the tint is the
primary treatment.)

## 16.7 Invariants preserved
The cockpit hardening (locked frame, per-panel scroll, pinned month/day-of-week headers, viewport
strip-height clamp + self-heal) is untouched and re-asserted. The `MobileCalendarController` frozen
envelope is untouched (2/2 green). Event CRUD, recurring `{this,future,all}`, attendee auto-fill,
conflict warnings, private redaction — all unchanged (render + persistence-model change only).

## 16.8 Tests
`tests/Feature/CommandCenter/CalendarExplicitSaveTest.php` (12 tests): save promotes the whole
arrangement; unknown tiles/layers sanitised; no-param load renders the saved default view; a `?view=`
/ `?scroll=` request renders the shell but NEVER auto-persists (the regression proof); reset nulls the
factory default; scroll defaults to continuous; paged month + week render with paging; continuous month
carries alternating tint classes; the single-tile endpoint builds without persisting + 404s an unknown
tile. Headless proof (`proof-explicit-save.js`, 1920×1080 + 1366×768): popping-panels
reproduced→dead, transient-survives-navigation, reload-renders-default, save-promotes-current,
reset-discards-transients, continuous alternating tints, paged month paging.

# 16.9 View-save trap — "Save default" only promotes a DELIBERATELY chosen view (2026-07-07)

> **Status:** AS-BUILT, HFC2402 (2026-07-07). Amends §16.4's "Save default reads current
> view/scroll". Trigger: Kym Pollard reported "my calendar is empty / most entries gone."

## 16.9.1 The incident
No data was lost — Kym's 431 events were all present. Her `default_view` had silently
flipped to `day`, so every calendar open landed on a single day (~4 entries) instead of her
month. **Five of six agents** with a saved preference (Kym, Shalan & Shawn Du Bois, Dru De
Bruyn, Gerda Baard) were locked into `day` within two days of the cockpit shipping — none had
deliberately chosen it. Remediation: their `default_view` was reset to `month` (the factory
default they had before the cockpit).

## 16.9.2 Root cause
`index.blade.php` routes **every event-click to `?view=day`** (`CalendarController` event
deep-links, L1355). §16.4's Save read `body.view = $currentView` — the *server-rendered*
view. So an agent who clicked an event (→ forced day view) and then clicked **"Save default"**
to persist their *tile/strip arrangement* silently promoted `day` to their default view. The
arrangement and the landing view were conflated; a navigation masqueraded as a preference.
This is exactly the "does this trap the agent behind a screen" failure the Operating Principle
forbids.

## 16.9.3 The rule (Johan's ruling — "keep, but only user-chosen view")
"Save default" still captures the view, **but only when the user DELIBERATELY chose it this
session.** A view reached by an event-click / `?view=` deep-link is navigation, never a saved
preference, and is excluded.

Mechanism — no new state needed, because the transient already encodes deliberate choice:
- The **view switcher** and **Stream·Pages toggle** are the ONLY writers of the transient
  `view` / `scroll_mode` keys (`window.CoreXCal.patch(...)`, toolbar links). A deliberate
  click on either is the ONLY thing that sets them.
- Event-clicks / deep-links land on `?view=day` via a plain navigation — they do **not**
  patch the transient.
- Therefore `save()` now promotes `view` / `scroll_mode` **only when present in the transient**
  (`window.CoreXCal.get()`), instead of grabbing the server-rendered `$currentView`. Absent →
  the field is omitted from the POST → `saveCockpit`'s existing
  `array_key_exists('view',$data) && !== null` guard leaves the saved default untouched.
- Same fix applied to `scroll_mode` (identical bug class — fix the class, not the instance).

On reload the transient is cleared (§16.3), so an in-session view choice does not survive a
hard reload — consistent with the three-tier model. To change a saved default view an agent
clicks the switcher, then "Save default".

## 16.9.4 Tests
`CalendarExplicitSaveTest::test_save_without_view_leaves_the_existing_default_view_untouched` —
an arrangement-only save (no `view` / `scroll_mode`, the shape the browser POSTs after a tweak
with no deliberate view choice) leaves `default_view` and saved `scroll_mode` intact while the
sent arrangement fields still persist. The client-side "only send a deliberately chosen view"
behaviour belongs in the `proof-explicit-save.js` headless harness (event-click → day → Save
default → default_view stays month).

# 17. Inactive class must NEVER erase existing events (colour resolver hardening) — 2026-07-07

> **Status:** AS-BUILT, HFC2402 (2026-07-07). Triggered by a production incident (below).
> Amends the `CalendarThresholdResolver` contract. Ships to main + Staging + live.

## 17.1 The incident (root cause)
Kym Pollard (and, it turned out, **all 22 HFC agents**) reported empty calendars. No data was
lost — every event was present. **Cause (identified 2026-07-07): the AT-197 Part A "turn-off".**
A developer/agent session deliberately turned OFF all 49 event classes for agency 1 (HFC) via the
settings screen's own mechanism — an agency-1 override row per class (`updateOrCreate` on
`agency_id`+`event_class`, a faithful copy of the global with `is_active=false`; 48 rows, the
duplicate `manual` collapses). It was a documented, planned operation "for the midweek setup
session" with a captured restoration baseline (`.ai/audits/2026-07-06-event-classes-snapshot.md`).
The two timestamps (rows created 18:19 on 07-06, updated 05:10 on 07-07) are its two passes — the
first pass 500'd on a JSON-cast bug (copied `getAttributes()` raw strings into array-cast columns)
and was re-run from the model's cast accessors; that re-run is the 05:10 stamp. Agency 1 is the
ONLY agency with override rows; every other agency uses the (still-active) globals — which is why
only HFC was hit.

**Why a documented "turn-off" blanked every calendar — the impact-statement gap.** AT-197's own
impact statement assumed an inactive class would leave events *"materialised but inert"* (still
visible, no RAG/notifications) and that *"agents keep working the calendar normally."* The real
code did NOT behave that way: `CalendarThresholdResolver::resolveForEvent()` returned **null** for
an inactive class, and `CalendarController::applyFilters()` + `CalendarTileService` **drop every
null-colour event** → the whole book vanished. `forAgencyAndClass()` returns the agency row when
one exists (even inactive), so the dead overrides shadowed the healthy globals. The fix below
makes reality match the impact statement: inactive = inert-but-visible, never erased.

## 17.2 The rule
A class-config *state* must NEVER erase an event already on the calendar. Deactivating a class
stops **new-event generation, RAG urgency, and notifications** — it does **not** hide events
already scheduled. So a missing or inactive class config now resolves to **`neutral`** (visible,
no RAG urgency), never `null`. The ONLY `null` case is an event with no `event_date` to place on
the grid. Worst case is now "no colour", never "no calendar".

Implementation (`CalendarThresholdResolver`): both `resolve()` and `resolveForEvent()` return
`'neutral'` where they previously returned `null` for `!$config || !$config->is_active`. `'neutral'`
was already a first-class resolved colour (the "beyond green threshold" case), so the entire
render/tile/reconcile stack already handles it — no downstream change. `ReconcileCalendarEvents`
already treats `neutral` transitions correctly; event owners always pass `canSee` via the creator
rule, so their own events render regardless of colour.

## 17.3 What was deliberately NOT done
A blanket "fall back to the active global when the agency override is inactive" in
`forAgencyAndClass()` was rejected: it would delete an agency's ability to deactivate a class at
all (an inactive override would always be overridden by the active global). Neutral-render is the
correct expression of the intent — deactivation is non-destructive, not non-existent.

## 17.4 Data remediation (live)
Agency 1's 48 override rows were reactivated to restore RAG immediately, then reset to the global
default (the audit's own prescribed restore: delete the agency-1 overrides → inherit globals).
They are faithful copies of the globals — carry no real customization and are a maintenance trap
(they shadow future global threshold/visibility changes). The one apparent `manual` "difference"
was a false positive: `[]` (empty array) vs `{}` (empty object) in the notification columns — a
`json_encode` artifact, not a customization. Reset via the sanctioned `resetEventClass` semantics
(hard delete of the thin override layer — these config rows are not SoftDeletes-protected
records), backed up to `storage/app/hfc-class-settings-backup-2026-07-07.json` first. After
removal HFC tracks the active globals. NOTE: the turn-off was AT-197's deliberate setup-session
prep; if that setup still needs HFC's classes quieted it can now be re-done safely (§17.2 keeps
events visible) — coordinate with the AT-197 owner before re-applying.

## 17.5 Tests
`tests/Feature/CommandCenter/InactiveClassStillRendersTest.php`: active class → RAG; inactive
class → neutral (not null); the incident shape (inactive agency override shadowing an active
global) → neutral; `resolve()` direct inactive → neutral, and null ONLY when there is no date.
