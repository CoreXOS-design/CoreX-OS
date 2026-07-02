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
