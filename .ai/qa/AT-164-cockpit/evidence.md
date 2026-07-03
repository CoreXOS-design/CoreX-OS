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

Week view (1366×768): page does NOT scroll; the week frame is bounded and scrolls within
the cockpit. `weekScrollableX=false` at full width (7 days fit) — true week-to-week
horizontal windowing is a follow-up increment, not yet built.

Mobile: `CalendarMobileContractTest` 2/2 — frozen envelope + opt-in deck/layers intact.

## Screenshots
- `cockpit-month-1920x1080.png` — grid scrolled (Sep/Oct), **My Deck pinned + fully visible
  at the bottom**, no page scroll. (Dimming is the first-visit help tour for the temp user.)
- `cockpit-month-1366x768.png` — same on a laptop viewport.
- `cockpit-week-1366x768.png` — week bounded in the frame, no page scroll.
