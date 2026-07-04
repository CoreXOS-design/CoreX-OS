# AT-164 cockpit v2 (adjustable) — human-mode verification (2026-07-04)

Four features (Johan 05:18 QA) + a week-nav fix, on the staging host `/corex-staging`.
Headless Chromium, real `/login`, both 1920×1080 and 1366×768.

## Features
1. **Drag-resize** — top-edge handle resizes the calendar/strip split; per-tile column
   handles adjust widths. 2. **Collapse** — strip → slim bar (names + counts), right panel
   → thin rail; calendar flexes into the freed space. 3. **Agenda** — right panel resident
   default = chronological agenda (today + upcoming, scope + layer filtered); click → detail;
   Add-Event/empty-day → New Event form; close → agenda. 4. **Save/Reset** — debounced
   auto-persist to `calendar_user_preferences.calendar_cockpit` (DB, not localStorage);
   explicit **Reset view** restores the role default. Plus **week vertical scroll** fixed.

## Measured (both sizes)
| Check | Result |
|---|---|
| Split resize changes the calendar/strip ratio | yes (bbox before/after) |
| Tile column ratios change on drag | yes (`grid-template-columns` changes) |
| Strip collapses to a slim bar; calendar grows | yes (strip ≈43px; cal 85%/79%) |
| Right panel collapses to a rail (≈40px); calendar flexes | yes |
| Agenda renders real staging items; click → detail | yes (14+ items) |
| Arrangement survives a full page reload | **yes** (strip-collapsed persisted) |
| Reset view restores defaults | **yes** (strip expanded, cockpit row nulled) |
| **Calendar block ≥ 45% of viewport in EVERY arrangement** | **min = 45.0%** |
| **Page-level scrollbar in ANY arrangement** (incl. maximal-strip, collapsed-everything) | **none** |
| Week: vertical wheel (deltaY) NOT hijacked → scrolls hours; deltaX/drag advances days | **yes** |

Screenshots: `v2-month-1920x1080.png`, `v2-month-1366x768.png`. Mobile Gate 8 green.

## Root causes fixed mid-round
- `saveCockpit`/`resetCockpit` used an unqualified `CalendarUserPreference` (not imported)
  → 500 → persistence silently dead. Fully-qualified.
- `resetCockpit` set `default_view = null` (NOT NULL column) → 500. Sets `'month'`.
- Strip resize capped only the tile area, not the section chrome, and didn't re-clamp on
  render → calendar could drop below 45vh (and a height saved on a big screen violated the
  floor on a small one). Cap now section-aware and re-clamped to the current viewport.
- Week `onWheel` translated ALL wheel (deltaY) to horizontal → vertical hours unreachable.
  Now horizontal only on deltaX / shift+wheel / drag; deltaY scrolls hours natively.
