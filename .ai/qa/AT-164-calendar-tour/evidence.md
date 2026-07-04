# AT-164 — Calendar guided tour — headless proof

**Deployed:** Staging `a1b9f7b8` (dev-2 HEAD == staging host HEAD).
**Tour:** `calendar` (route `command-center.calendar`) — expanded from the stale
2-step version to 8 steps covering the cockpit redesign (cockpit-at-a-glance,
month/week continuous scroll + Today + following label, Layers-vs-independent-tiles,
the right agenda/detail/add panel, Add Event + reminders, the tile deck, Save/Reset).

## Critical technical check (brief): does the tour displace the hardened cockpit?

The cockpit's outer frame is structurally locked (cc3 hardening: banner/toolbar/weekday
header pinned OUTSIDE the inner scrollers; page shell `overflow:hidden`, scrollTop 0).
The tour engine (driver.js 1.3.6) `scrollIntoView`s a step's target — BUT only when the
target is **not already in the viewport** (driver.js `ue()` in-view guard). Every tour
anchor is a cockpit-level element that is already on-screen (the cockpit is a fixed
viewport-height frame), so driver.js never scrolls → the locked frame is never touched.
No scroll-scoping workaround was needed; the hardening + in-view guard already hold.

## Proof — `tour-proof.cjs` (real login, staging, hardened cockpit)

Ran the tour start-to-finish on `/corex/command-center/calendar?view=month&tour=calendar`
(forced auto-start). At EVERY step, measured: the toolbar (`[data-tour="cal-views"]`)
top, the MON–SUN weekday row (`div.grid.grid-cols-7.flex-shrink-0`) top, and the page
shell scrollTop. "Frame intact" = toolbar & weekday visible and not pushed above the
viewport top AND page shell scrollTop == 0.

| Viewport | Steps frame-intact | Toolbar top (every step) | Weekday top (every step) | Page scroll |
|---|---|---|---|---|
| 1920×1080 | 8/8 ✅ | 17px (never moves) | 138px (never moves) | 0 |
| 1366×768  | 8/8 ✅ | 17px (never moves) | 138px (never moves) | 0 |

The toolbar and weekday header sit at a **constant** top offset across all 8 steps in both
viewports, and the page shell never scrolls — i.e. the tour causes **zero displacement** of
the locked cockpit. The 8 popover titles (Your calendar cockpit → Month, Week or Day →
Back to Today → Layers → The right panel → Add an event — and get reminded → Your tile
deck → Your view, saved) confirm the tour ran to completion.

Screenshots: `tour-1920x1080-step{1,8}.png`, `tour-1366x768-step{1,8}.png` (first + last
step per viewport; toolbar + MON–SUN row pinned identically in every frame).

## Notes
- Proof used a throwaway super_admin QA user (`calendar-tour-qa@example.invalid`),
  soft-deleted after the run (0 active).
- Pre-existing unrelated red: `TourRegistryIntegrityTest` fails on the `docs-shared-drive`
  tour (4 anchors missing from its view) — collateral from an earlier docs change, NOT this
  diff; the calendar tour's 8 anchors all resolve.
