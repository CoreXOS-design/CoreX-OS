# AT-111 — Viewing Pack ↔ Calendar two-way link — READY TO LAND (tandem-merged)

_Two-minion tandem, 2026-07-17. QA1 `fb6c8daf`. Reverse direction of the AT-107 forward handoff._

## Outcome (after reconciling both lanes on QA1)
Both lanes independently built the reverse `launchFromEvent` + `updateAppointment`
methods. The **other lane's pair is kept** — it is integrated with the AT-112
permission/visibility work already landed on QA1 (`guardVisible()`,
`CalendarVisibilityResolver::canSee()`, the `CalendarEvent::viewingPack()` inverse
relation, `branch_id` on the pack, and `permission:viewing_packs.*` middleware on
the route group). My duplicate pair was removed to kill the fatal redeclare.

**My unique contributions that survived the merge (the half the other lane did NOT build):**
1. **`POST corex.viewing-packs.from-event/{calendarEvent}`** route → their
   `launchFromEvent`. Their method had **no route** — without this it was
   unreachable. Gated `permission:viewing_packs.create` (launching creates a pack).
2. **Download-on-event** — `CalendarController::show` payload gains
   `linked_viewing_pack {id,status,property_count,url,buyer_pack_url,agent_sheet_url}`
   + `viewing_pack_launch_url`; the calendar event-panel footer renders **Open pack**
   + (when prepared) **Download Buyer Pack** / **Download Agent Sheet**, and
   **Prepare viewing pack** when no pack is linked (editable events). This is the
   "agent opens the appointment and downloads from there" requirement (#5) — only
   this lane built it.

The other lane also owns: the pack-side "Update appointment" blade branch, the
AT-112 gating, and the reverse-direction test (`ViewingPackCalendarPermissionTest`).

## Requirement coverage
1. Standalone scheduling — pre-existing. ✓
2. Event → pack launch — other lane's `launchFromEvent`, exposed by my `from-event` route. ✓
3. Pack → update appointment (in place) — other lane's `updateAppointment` (reuses
   `CalendarEventService::syncManualEventLinks`). ✓
4. Two-way link (`viewing_packs.calendar_event_id`) written both directions. ✓
5. **Download buttons on the event** — my payload + panel footer. ✓

## Verification
- Static (QA1): no conflict markers; `php -l` clean on ViewingPackController +
  CalendarController; `route:list` shows from-event / update-appointment /
  buyer-pack / agent-sheet; both edited blades compile.
- Reverse methods: verified by the other lane (their `ViewingPackCalendarPermissionTest`).
- My payload/panel: the `linked_viewing_pack` shape was tinker-verified on my
  original build; the payload code is unchanged by the merge.

## Two known bugs FIXED in the kept methods (QA1 `0ab243d3`)
- `updateAppointment` now updates the scalar `calendar_events.property_id` (first in
  drag order) **as well as** the `subject_property` link set — the event "primary"
  no longer goes stale on single-property surfaces. Verified: `event.property_id` =
  first ordered property.
- `launchFromEvent` resolves the buyer via `Contact::withoutGlobalScopes()->find()`
  + agency-verify (the id comes from the event itself; a linked buyer may sit outside
  the agent's branch — matches the calendar panel). Replaces the branch-scoped
  `Contact::findOrFail` that 404'd a cross-/null-branch buyer.

Re-verified end-to-end on QA1 (tinker, rolled back): launch (cross-branch buyer) →
create; re-launch → open (1 pack); update → `[p2,p1]` drag order + `property_id=p2`;
payload count=2.

## Deliberately NOT in scope
AT-112 permission gating is the other lane's ticket (landed). Agent-sheet/buyer-pack
PDF content edits + Core Matches pagination = the ticket's deferred list.

## Governance
QA1 only → Johan's eyeball → staging today on his word.
