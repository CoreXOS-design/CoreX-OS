# AT-164 Cockpit — human-mode verification evidence (2026-07-03)

Deployed to the staging host `/corex-staging` at Staging `baa84e6e`, then exercised with a
real headless Chromium (puppeteer-core + system chromium 148) logged in as a super_admin
via the actual `/login` form (throwaway user, removed after). Screenshots in this folder.

## Root cause (why it was static / page-scrolled / deck off-screen)
The main calendar column was `overflow-y-auto` and the **Deck lived INSIDE it, after the
grid** — so reaching the Deck meant scrolling the whole column (Johan: "scroll off screen
to find them"). The month grid had `max-height: 74vh` while rendering a **single** ~500px
month — shorter than the cap, so its own overflow never engaged; the wheel bubbled to the
column, which read as "page scroll," and because the month container never scrolled its
`onScroll` never fired, so lazy-loading never started → "not scrolling at all."

## Fix
Main column is now a NON-scrolling flex-col cockpit: `[filter bar shrink-0]` +
`[grid frame flex-1 min-h-0 — the ONLY scroll container]` + `[deck row shrink-0, compact,
pinned]`. Every view fills the frame (vh caps removed). Prev+current+next months are
preloaded so the frame overflows on first paint; the client scrolls to the current month.

## Measured results (puppeteer)
| Check | 1920×1080 | 1366×768 |
|---|---|---|
| Page scrolls (want NO) | **false** | **false** |
| Main `#appScroll` scrolls (want NO) | **false** | **false** |
| Grid frame is the scroll container | **true** | **true** |
| Opens centered on current month (scrollTop) | 405px | 405px |
| Deck row within viewport | **true** (bottom 1032<1080) | **true** (bottom 720<768) |
| Wheel advances the grid (scrollTop↑) | 405→1441 | 405→1384 |
| Sticky month label changes on scroll | **July → September** | **July → September** |
| Lazy-load adds month blocks on scroll | 3 → 6 | 3 → 5 |

Mobile: `CalendarMobileContractTest` 2/2 — frozen envelope + opt-in deck/layers intact.

## Week — horizontal continuous scroll (built + verified)
Week now flows HORIZONTALLY as a windowed strip of day columns inside the bounded frame
(one `_day-column` partial, lazy prepend/append via `/calendar/day-columns`, sticky time
gutter, sticky date-range label, in-frame Today snap). Headless-measured:

| Check | 1920×1080 | 1366×768 |
|---|---|---|
| Page scrollbar (want none) | none | none |
| Horizontal scroll container exists | yes | yes |
| Opens on the current week | 29 Jun – 05 Jul | 29 Jun – 05 Jul |
| **Wheel** advances weeks (scrollLeft ↑) + label | 1232→4306, → 13 Jul–19 Jul | 1232→4084, → 13 Jul–19 Jul |
| **Drag** advances weeks (scrollLeft ↑) + label | 4306→5006, → 20 Jul–26 Jul | 4084→4784, → 20 Jul–26 Jul |
| Lazy-load adds day columns | 28 → 42 | 28 → 42 |
| Deck row within viewport | yes | yes |

Duplicate-day assertion after 25 appends: **0 duplicates** (unique 22 Jun → 6 Sep) — this
caught a UTC off-by-one in the day math that was duplicating the boundary column; fixed.

Day view: single day + toolbar prev/next continuity, fills the frame (per spec; no rebuild).

Screenshot: `cockpit-week-1920x1080.png` — day columns flowing across week boundaries with
the sticky "20 JUL – 26 JUL" range label and the pinned deck.

## Screenshots
- `cockpit-month-1920x1080.png` — grid scrolled (Sep/Oct), **My Deck pinned + fully visible
  at the bottom**, no page scroll. (Dimming is the first-visit help tour for the temp user.)
- `cockpit-month-1366x768.png` — same on a laptop viewport.
- `cockpit-week-1366x768.png` — week bounded in the frame, no page scroll.
