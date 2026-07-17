# AT-111 — Viewing Pack ↔ Calendar two-way link — READY TO LAND

_Build, 2026-07-17. QA1 only. Reverse direction of the AT-107 forward handoff._

## What Johan asked for (schedule-now-prep-later)
Agent books a viewing appointment now (no pack yet). The day before, from that
existing appointment they launch the pack, pick the now-available properties,
then "Update appointment" pushes the final list onto the event — and the event
itself carries the pack download buttons.

## What was actually in the code (forensics)
Despite an old "Done" status, **AT-111 was never built** (my AT-113 readiness doc,
2026-07-14). `viewing_packs.calendar_event_id` existed but **nothing ever wrote it**;
the only pack→calendar tie was a static prefill URL (forward handoff). The pack's
own scheduler (`schedule()` + `ViewingPackCalendarService`) had been **deliberately
removed** to avoid parallel scheduling — correctly; this build does **not** restore it.

## What I built (reverse direction only; forward handoff untouched)
1. **Event → Pack launch** — `POST corex.viewing-packs.from-event/{calendarEvent}`
   (`ViewingPackController::launchFromEvent`). Create-or-open: one event → one pack.
   Sets `calendar_event_id`, buyer (`contact_id`), `tour_at` from the event. Buyer
   resolved from `event.contact_id` (or first buyer/attendee link), **unscoped +
   agency-verified** (a linked buyer can sit outside the agent's branch — same way
   the calendar panel resolves linked contacts).
2. **Pack → Update appointment** — `POST corex.viewing-packs.{pack}/update-appointment`
   (`updateAppointment`). Pushes the pack's finalised properties (drag order,
   `viewing_pack_properties.sort_order`) onto the LINKED event **in place** via
   `CalendarEventService::update` (scalar `property_id`) + `syncManualEventLinks`
   (`subject_property` link set). Replace, not append; no new event.
3. **Download-on-event** — `CalendarController::show` payload gains
   `linked_viewing_pack {id,status,property_count,url,buyer_pack_url,agent_sheet_url}`
   + `viewing_pack_launch_url`. The event panel footer shows **Open pack** + (when
   prepared, property_count>0) **Download Buyer Pack** / **Download Agent Sheet**;
   with no pack it shows **Prepare viewing pack** (editable events only).
4. **Two-way discoverability** — pack `show.blade` shows an "Update appointment"
   action + linked-event date when `calendar_event_id` is set (else the existing
   Schedule Viewing prefill for unlinked packs).

## Files
- `app/Http/Controllers/CommandCenter/ViewingPackController.php` (+launchFromEvent, +updateAppointment, imports)
- `app/Http/Controllers/CommandCenter/CalendarController.php` (show payload: linked_viewing_pack + launch url)
- `routes/web.php` (viewing-packs: from-event, update-appointment)
- `resources/views/command-center/calendar/index.blade.php` (panel footer: open/downloads/prepare)
- `resources/views/command-center/viewing-packs/show.blade.php` (Update appointment branch)
- `tests/Feature/ViewingPack/ViewingPackCalendarLinkTest.php` (new)

## Verification (tinker, rolled back — lane PHPUnit bootstrap hangs)
1. launchFromEvent → pack #, calendar_event_id OK, contact OK, tour_at set. ✓
2. second launch → 1 pack (create-or-open). ✓
4. update-appointment → subject_property links `[p2,p1]` in drag order; event.property_id = first. ✓
5. show payload `linked_viewing_pack.property_count` = 2. ✓
6. re-push → 2 links (in-place replace, not append). ✓
Bug caught + fixed mid-build: `Contact::find` (BranchScope+ContactScope) over-restricted
a legitimately out-of-branch linked buyer → resolve unscoped + agency-verify.

## Deliberately NOT in this build
- **AT-112** (role/branch permission gating on viewing packs) — separate ticket, still
  unbuilt; the viewing-packs group remains agency-scoped only. Not scope-crept here.
- Agent-sheet/buyer-pack PDF content edits + Core Matches pagination — ticket's deferred list.

## Governance
QA1 only → Johan's eyeball → staging today on his word.
