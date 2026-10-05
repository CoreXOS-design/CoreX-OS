# List screens — the filter area folds away while scrolling

> Status: APPROVED (Johan, 2026-09-27 — picked "Option 1: Shrinks as you scroll" from the
> five mockups at https://claude.ai/artifact/1LTPAnL9MacZW37pJfyGCQ). Lane: QA2.
> Scope of this build: **Properties** (`/corex/properties`, and its Rentals → Properties and
> Imported Stock entry points, which share the view) and **Contacts** (`/corex/contacts`
> and Rentals → Contacts, which share the view).

## 1. Business requirement

Since AT-393 the page header, KPI tiles and filter card on Properties and Contacts are
frozen: only the list below them scrolls. On a 1366×768 laptop, once an agent applies
filters on Properties (the "More filters" panel auto-opens and the "Active:" chips row
appears), the frozen area takes roughly half the screen and an agent sees about one row of
property photos at a time.

Fix: the page opens exactly as today. As soon as the agent scrolls the list down, the
header, tiles and filter card fold away into one slim bar that shows:

- the page name and the result count (e.g. "Properties · 142 properties"),
- the active filters as chips (each chip removes that filter, exactly like the existing
  "Active:" chips),
- an **Edit filters** button.

Scrolling back to the top of the list, or clicking the bar's title / Edit filters,
opens the full filter area again. After opening it mid-list, scrolling on down by a short
distance folds it away again.

## 2. Pillars

Presentation only. Reads nothing new; writes nothing. Property (Properties list) and Contact
(Contacts list) screens are the surfaces.

## 3. Data model / migrations

None.

## 4. UI placement / navigation

No new page, no new nav entry. Same screens, same URLs.

## 5. Behaviour rules

1. Fold only when the list is long enough to stay scrolled once the top is gone
   (prevents flicker between the two states on short lists).
2. Fold after ~40px of downward scroll; unfold when the list is back at the top (≤4px).
3. After a manual unfold mid-list, re-fold once the agent scrolls ~80px further down.
4. While folded, the hidden controls are `inert` (not reachable by Tab / screen reader).
5. Animated (~0.22s height transition); no animation under `prefers-reduced-motion`.
6. While expanded, nothing in the top area is clipped (dropdowns such as Drafts, the
   agent picker modal and the Street & Complex help popover behave exactly as today).
7. Works identically at every width; the "Scroll up to see everything" hint hides on
   narrow screens.

## 6. Permissions / scoping

No change. Chips link to the same scoped index URL the existing chips / filters use; every
request still passes through the controllers' existing OWN / BRANCH / AGENCY scoping.

## 7. Settings / Setup Wizard

Not a setting — no Setup Wizard entry (Non-negotiable 10a does not apply).

## 8. Implementation

One shared, reusable piece so every other list screen can adopt it by markup alone:

- `resources/views/components/list-collapse-bar.blade.php` — renders the slim bar, and (once
  per page, via `@once`) the CSS and the plain-JS controller.
- Opt-in markup on a page:
  - `data-list-collapse` on the page's full-height flex-column wrapper,
  - `<div data-list-collapse-top><div class="lc-top__inner">…header, tiles, filters…</div></div>`,
  - `<x-list-collapse-bar title="…" :summary="…" :chips="[…]" />` directly after it,
  - `data-list-collapse-scroll` on the existing scroll region.

Files: the component above; `resources/views/corex/properties/index.blade.php`;
`resources/views/corex/contacts/index.blade.php`.

## 9. Acceptance criteria

- [ ] Properties, laptop height, filters applied: scroll down → top folds to one bar showing
      "Properties · N properties", the active filter chips and Edit filters; ≥3 rows of cards
      visible on a 1366×768 screen.
- [ ] Scroll back to top → full header, tiles and filter card return exactly as before.
- [ ] Edit filters mid-list → filters open; scroll on ~80px → fold again.
- [ ] Removing a chip from the bar removes that filter (same result as the existing chip).
- [ ] Short list (fits on screen) → never folds, no flicker.
- [ ] Drafts dropdown, agent picker and More filters work unchanged when expanded.
- [ ] Contacts: same behaviour; bar shows "Contacts · N contacts" plus type / agent /
      search chips.
- [ ] Rentals → Properties, Imported Stock and Rentals → Contacts entry points behave the same.
- [ ] No console errors; Alpine render gate clean on both pages.

## 10. Related (separate build)

Filtering without a full page reload across every list screen is investigated separately:
`.ai/investigations/2026-09-27-filter-no-reload.md`.
