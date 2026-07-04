# AT-164 — Cockpit v2 header-loss defect (Johan 05:45) — closing evidence

**Deployed:** Staging `7bd215a1` (dev-2 HEAD == staging host HEAD).
**Defect:** Drag-resizing the calendar/deck split pushed the toolbar row (Today /
month-picker / view-switcher / Add Event) **and** the MON–SUN weekday header row
off the top of the month view. Once off-view the resize handle was unreachable,
and because the arrangement persists per-user, the user was stuck across reloads.

## Root cause (geometry)

The month view's own root element was the vertical **scroller**. The MON–SUN
weekday row lived *inside* that scroller as `sticky top-0`. When the deck strip
grew (drag) the main row's flex height shrank; the sticky row's sticky context
could scroll away with its scroller and the toolbar (a sibling higher up) had no
pinned floor. Result: headers scrolled out of the viewport and the handle went
with them.

## Fix

1. **Headers pinned OUTSIDE the scroll region.** Month view root is now
   `flex flex-col` (non-scrolling). The MON–SUN weekday row is a
   `flex-shrink-0` sibling *above* a NEW inner `<div x-ref="scroller"
   overflow-y-auto flex-1 min-h-0>` that wraps only the day-cell months. The
   toolbar row sits in the outer page shell, itself `flex-shrink-0`. Only the
   day cells scroll; toolbar + weekday row can never leave the viewport.
   (`_month-block.blade.php` month-label sticky offset moved `top:34px → top:0`
   since the weekday row is no longer inside the scroller.)
2. **Clamp + self-heal.** `resolveCockpit()` clamps `strip_height` at read
   (max 600 server-side; the client getter re-clamps to `min(40vh−66, …)` and a
   45vh calendar floor at render, per-viewport). On load, `calendarDeck.init()`
   validates the persisted value — if `strip_height` is non-finite / >450 / <100
   it rewrites it to the clamped value and **re-persists** (debounced POST), so
   anyone already stuck self-heals on next load and the correction sticks.

## Headless proofs — `header_check.js` (real login, month view)

Probe asserts bounding boxes for: toolbar (view-switcher's `.rounded-md`),
MON–SUN weekday row (`grid-cols-7 flex-shrink-0`), and hit-tests the resize
handle via `elementFromPoint`. Two states per viewport: **afterCorruptReload**
(POST `strip_height:700` → reload → self-heal) and **afterMaxDrag** (drag handle
up 15×50px to the top clamp).

| Viewport | State | toolbarVis | weekdayVis | handleHit |
|---|---|---|---|---|
| 1920×1080 | afterCorruptReload | ✅ | ✅ | ✅ |
| 1920×1080 | afterMaxDrag | ✅ | ✅ | ✅ |
| 1366×768 | afterCorruptReload | ✅ | ✅ | ✅ |
| 1366×768 | afterMaxDrag | ✅ | ✅ | ✅ |

Screenshots: `hdr-1920x1080.png`, `hdr-1366x768.png` (both show toolbar +
MON–SUN row pinned at top after self-heal + max-drag).

## Self-heal persistence proof — `selfheal_persist.js`

- POST `strip_height:700` → 200.
- Reload → server-rendered cockpit returns `strip_height:366` (healed).
- DB row `calendar_cockpit` now `{"strip_height":366,"strip_collapsed":false}` —
  the corrupt 700 is permanently corrected, not re-applied.

## Week vertical-scroll defect (folded in, `7bd215a1` lineage)

`continuousWeek.onWheel` no longer hijacks `deltaY` for horizontal paging.
Vertical wheel = native scroll through hours; horizontal advance only via
`deltaX`, `shift+wheel`, or drag.

## Regression gate

- Mobile Gate 8 `CalendarMobileContractTest` — **2 passed / 25 assertions** (green).

## Out-of-scope observation (flagged, not fixed here)

To-dos deck tile renders "…no activity in **-0.605…** days" — a negative
fractional-days display bug in tile-content math (not cockpit geometry). Left
for a separate ticket rather than widening this defect's scope.
