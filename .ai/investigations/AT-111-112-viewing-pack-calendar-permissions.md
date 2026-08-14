# AT-111 + AT-112 — viewing-pack ↔ calendar two-way link + permission gating

_INVESTIGATION (then BUILD). QA1 only. 2026-07-15. Builds on m3's [AT-113 readiness doc](AT-113-viewing-pack-tour-readiness.md)._

## The route-removal question (Johan's IMPORTANT: WHEN + WHY before rebuilding)

**The removal was DELIBERATE, by Johan, and it is exactly the architecture AT-111 mandates. It must NOT be rebuilt.**

- **Commit `b22653df`, 2026-06-28 22:02, author Johan Reichel** — _"schedule via the SAME calendar prefill handoff as Schedule Viewing … remove parallel pack scheduler + ViewingPackCalendarService"_. It deleted `ViewingPackCalendarService` (93 lines) and the pack-side `POST schedule` route, and replaced them with the shared calendar prefill handoff (pack → `command-center.calendar?prefill_class=viewing&…`).
- The prior commit `55fab607` (AT-107) had added a calendar tie-in; `b22653df` then consolidated it onto the ONE shared scheduler.

**So what was removed was a _parallel scheduler_ — precisely what AT-111 says not to build** ("Reuse existing CalendarEventService … do not build parallel scheduling"). AT-111 is not a restore. It builds the **reverse direction** on top of today's shared-handoff architecture:

1. forward pack→calendar prefill handoff — **already exists** (`show.blade` builds the prefill URL)
2. **event → pack**: launch/create a pack linked to an existing event (NEW)
3. **pack → event update-in-place**: push the pack's ordered properties onto the linked event via `CalendarEventService::syncManualEventLinks()` (NEW)
4. two-way link: `viewing_packs.calendar_event_id` — column exists; add `CalendarEvent::viewingPack()` back-relation (NEW)
5. **download buttons on the event panel** when a prepared pack is linked (NEW)

## Code truth (confirms m3)

| Piece | State | Evidence |
|---|---|---|
| `viewing_packs.calendar_event_id` + `calendarEvent()` relation | EXISTS | `app/Models/ViewingPack.php` |
| `CalendarEvent::viewingPack()` back-relation | MISSING | grep = 0 |
| Reverse direction (event→pack / update-in-place / download-on-event) | MISSING | the heart of AT-111 |
| Permission middleware on the `viewing-packs` route group | MISSING | route group comment: "Tenancy via AgencyScope on the model" — agency-level only |
| `viewing_packs.*` permission keys | MISSING | not in `config/corex-permissions.php` |
| ViewingPack branch scope / `scopeVisibleTo` | MISSING | model has `agent_id` owner but no `BelongsToBranch` |

## The patterns to mirror (no parallel schemes)

- **AT-111 update-in-place** → `CalendarEventService::syncManualEventLinks($event, ['property_ids' => …], $user)` already syncs `ROLE_SUBJECT_PROPERTY` links onto an event. Reuse verbatim.
- **AT-111 panel data** → `CalendarController::show()` returns the event panel JSON; inject a `viewing_pack` block (linked pack id/status/download URLs, or a launch URL when `category==='viewing'` and no pack yet). The panel blade renders buttons from `panelData`.
- **AT-112 gating** → `Presentation::scopeVisibleTo()` is the exemplar: `PermissionService::getDataScope($user, 'viewing_packs')` → `all` (no filter) / `branch` (`branch_id = effectiveBranchId`) / `own` (`agent_id = user->id`); `null` → `whereRaw('1=0')` (AT-265 fail-closed). Permission keys `viewing_packs.view|create|edit|archive` mirror the `presentations.*` block. `BelongsToBranch` trait auto-fills `branch_id`. Route group gets `permission:viewing_packs.view` etc.

## Build order

AT-112 foundation first (branch_id + keys + scopeVisible + middleware — AT-111's new surfaces must respect it), then AT-111 wiring on top. QA1 only.
