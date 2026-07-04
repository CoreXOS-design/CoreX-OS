# AT-164 — Cockpit QA round (5 items) — closing evidence

**Deployed:** Staging `4deb41eb` (dev-3 pushed → `/corex-staging` git-pulled, php8.2-fpm reloaded). HELD (not live). No migrations (code-only).

Johan's morning re-QA raised five items; all fixed in ONE combined deploy and proven
headless at **1920×1080 AND 1366×768** (probe user `cockpit-qa@corex.local`).

## The five items + root causes

1. **Outer-scroll ghost** — the on-load month-anchor scroll used `scrollIntoView`,
   which by spec scrolls *every* scrollable ancestor, including the `overflow:hidden`
   page shell (`<div class="…h-full overflow-hidden -m-4…">` under `<main id="appScroll">`).
   `overflow:hidden` still scrolls *programmatically*, and the user can't wheel it back —
   hence stranded, and Reset (which only restores strip geometry) couldn't recover it.
   **Fix:** every programmatic scroll in month and week now targets ONLY the grid
   viewport via `scrollTop`/`scrollLeft` deltas — never `scrollIntoView`.

2. **Layers wired to the wrong surface** — layers are a CALENDAR lens only.
   - Deck tiles DECOUPLED: removed the layer filter from `upcomingEventsTile` +
     `deadlinesTile` (they emptied when the user hid grid layers).
   - Grid filters in EVERY view: `layer_key` annotated once at `applyFilters`; the
     week/day/agenda chips + panel agenda now carry `data-layer` + `cal-layerable`;
     `panelAgenda()` no longer server-filters (so toggling a layer back ON reveals it);
     the Deck no longer listens for `calendar:layers-changed`.

3. **Week Today button dead / label** — the toolbar Today button is now always a live
   in-page control in month AND week (`dispatchEvent('calendar:today')`);
   `continuousWeek` listens and snaps to today's column. Month sticky label now follows
   scroll (week `rangeLabel` already did).

4. **Month = ONE continuous week stream** — replaced month blocks with a single stream of
   `_week-row` partials (`weekRowsData` + `/calendar/week-rows` + `getRangeGrid`). Every
   week renders exactly once; boundary marked by a "Jul 1" first-cell label + seam accent;
   windowing/anchor/Today are WEEK-addressed; `?anchor` deep links still land. A `_ready`
   gate + preload (anchor−6 weeks ×20) stop init lazy-load from drifting off the anchor.

5. **True full-calendar mode** — Edit Deck can deselect ALL tiles → slim "Deck hidden /
   Show deck" restore bar, calendar full height. The right panel hides entirely (full
   width) with a slim floating "Agenda" reopen tab. Both persist per user + reset by
   Reset view. No page scrollbar in any combination.

## Headless proof matrix (both viewports identical)

| Check | 1920×1080 | 1366×768 |
|---|---|---|
| D4 month: week rows / month-blocks | 20 / **0** | 20 / **0** |
| D4 boundary week (29 Jun) count | **1** | **1** |
| D4 today highlighted count | **1** | **1** |
| D4 no duplicate weeks | ✅ | ✅ |
| D4 anchor label on load | July 2026 | July 2026 |
| D4 "Jul 1" first-cell labels present | ✅ | ✅ |
| D3 month label follows scroll (→Aug/Sep) | ✅ | ✅ |
| D3 Today snap returns to 2026-06-29 | ✅ | ✅ |
| D1 page shell scrollTop / overflow at load / after-scroll / after-Today | 0 / 0 | 0 / 0 |
| D1 toolbar + weekday visible throughout | ✅ | ✅ |
| D2 grid chips visible before / after "None" / after "All" | 8 / **0** / 8 | 8 / **0** / 8 |
| D2 week chips with layers persisted off | **0** | **0** |
| D2 deck tile item counts before vs after "None" | [0,3,2,6] == [0,3,2,6] | == |
| D3 week rangeLabel load / after-scroll / after-Today | 29Jun→06Jul→29Jun | same |
| D5 empty-deck persists (deckCards after reload) | **0** | **0** |
| D5 "Show deck" restore affordance present | ✅ | ✅ |
| D5 Reset view restores deck (cards) | 3 | 3 |
| D5 panel hide → floating tab, persists reload, reopen | ✅ | ✅ |
| D5 page-shell overflow in ALL combos (empty deck + panel hidden) | **0** | **0** |

Screenshots: `qa5-proof-month-1920.png`, `qa5-proof-month-1366.png`,
`qa5-proofD-empty-1920.png`, `qa5-proofD-panelhidden-1920.png`,
`qa5-smoke-month.png`, `qa5-smoke-week.png`.

## Tests (green)

- `CalendarLayerToggleTest` — `test_deck_tiles_never_respect_layer_toggles`,
  `test_grid_and_panel_agenda_carry_the_layer_for_client_side_hiding`
- `CalendarContinuousScrollTest` — `test_month_view_renders_the_continuous_week_stream`,
  `test_week_rows_endpoint_renders_the_same_partial_with_interactions`
- `CalendarDeckTest` — `test_deck_can_be_emptied_entirely_and_the_empty_layout_persists`

## Reversibility

Item 4 (week-stream) is reversible if Johan dislikes it in practice — the month-block
pipeline (`monthBlockData` / `/calendar/month-block` / `_month-block`) is retained.
