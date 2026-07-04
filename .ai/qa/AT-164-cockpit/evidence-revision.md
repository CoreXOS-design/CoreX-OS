# AT-164 Cockpit layout REVISION — human-mode verification (2026-07-03)

Johan's wireframe (hardcoded 3-region cockpit) built and verified on the staging host
`/corex-staging` @ Staging `f71afc4f`. Real headless Chromium, logged in via `/login`.
Desktop-only per Johan's amendment (mobile-web layout dropped; the MobileCalendarController
API contract + Gate 8 tests stay green — that's Andre's app data contract).

## Layout (fixed regions, configurable content)
- Compact HEADER strip: toolbar (Today, month picker, view switcher, All/Branch/Mine, Layers,
  Add Event). Branded band removed for density.
- MAIN ROW: CALENDAR BLOCK (~73%, the scroll frame — month vertical / week horizontal / day)
  + fixed RIGHT CONTEXT PANEL (~27%).
- BOTTOM TILE STRIP: N EQUAL columns (`grid-template-columns: repeat(N, 1fr)`), never scrolls
  horizontally, never grows; each tile scrolls its own list.

## Measured (puppeteer, both sizes)
| Check | 1920×1080 | 1366×768 |
|---|---|---|
| (a) Calendar block height ≥ 60% of viewport | **72.1%** | **60.7%** |
| (e) Page scrollbar (want none) | none | none |
| main #appScroll scrolls | no | no |
| (d) Month wheel advances the grid | yes | yes |
| (d) Week horizontal scroll advances | yes | yes |
| (c) Event click → right panel shows "Event" | yes | yes |
| (c) "+ New" → right panel back to "New event" | yes | yes |

## (b) Tile strip — N equal columns, zero horizontal scroll
Deck set to 2, 3, 4, 5 tiles in turn; every case: `horizontal-scroll = false`, no page scroll,
`grid-template-columns` = one track per tile (single row, never grows).

## Per-panel scroll doctrine (locked frame, live instruments)
Forced 3000px of content into the right panel and 2000px into a tile body:
- right panel `overflow-y = auto`, its own scrollbar engaged, **page did NOT scroll**;
- tile body scrollbar engaged, **page did NOT scroll**.
So overflow is always contained by the panel/tile — the screen frame stays locked.

## Right context panel
Default = compact quick-create (title/type/date/time/all-day → Create; "Full form" opens the
full slide-over overlay). Empty day/slot click pre-fills the date. On event click = that event's
details + actions (Full details/Edit → slide-over overlay, Complete, Open source ↗ new tab).

## Layout memory
Last view (month/week/day) persisted per user (`CalendarUserPreference.default_view`); a bare
`/calendar` opens the user's last view.

Screenshots: `cockpit2-month-1920x1080.png`, `cockpit2-month-1366x768.png`, `cockpit2-week-1366x768.png`.
Mobile Gate 8 (`CalendarMobileContractTest`): green (app API contract untouched).

## Defect pair (02:30 smoke test) — fixed + verified
1. **Tiles were all chrome.** `<x-tile>` gained a compact mode: one thin header line (small icon,
   name, count badge, View-all) so the CONTENT LIST gets the height. Headless (staging data):
   Notifications tile shows 10 rows, To-dos 14, Upcoming 2 — each data-bearing tile shows its
   first lines with internal scroll (empty tiles like My Deals correctly show their empty state).
2. **New Event form floated over the page.** Root cause: create/detail/color-by panels were
   `position:fixed` overlays (from the region restructure) — one matched a hidden helper div so a
   prior move corrupted the DOM. Now re-parented (via `<aside>`-depth scan) as `absolute inset-0`
   children of the relative, overflow-hidden right panel. Bounding-box proofs both sizes:
   - New Event form: `asideWithinPanel = true` (form rect ⊆ panel rect), **Create button reachable
     via panel-internal scroll**, no page scroll, nothing over header/calendar/tiles.
   - Event detail: renders inside the panel (`withinPanel = true`), actions visible.
Screenshots: `defect-month-1920x1080.png`, `defect-month-1366x768.png`.
