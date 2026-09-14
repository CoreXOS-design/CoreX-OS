# Outreach & Canvassing board — list search, agent picker, pagination, frozen top (AT-393)

> Status: BUILT 2026-09-14 on branch `AT-393-Sidebar-styling` at Andre's standing instruction
> ("every list page: frozen header, search/filter, pagination, sidebar scrollbar"). QA1 only.
> Page: `/corex/real-estate/outreach-canvassing` (route `corex.outreach-canvassing.index`).
> Parent: Part 4 unified board (`OutreachCanvassingController`, `OutreachActivityFeedService`);
> AT-380 own/branch/all row scope (`outreach_canvassing.view`).

## 1. Business requirement

The Activity Feed showed up to 500 rows on one screen with no search and no paging, and the
header, tab bar, subtotal tiles and filters all scrolled away with it. A manager looking for one
agent's pitches or one contact's outcome had to scroll the whole window. The existing
`?agent_id=` drill-down had no control on the screen.

## 2. Pillars

Reads: `AgentActivityEvent` (Agent pillar via `user_id`; Contact pillar via the send/contact
links resolved by the feed service). Writes: none.

## 3. Filters (Activity Feed tab; one GET form; all compose)

| Control | Key | Behaviour |
|---|---|---|
| Search | `q` | case-insensitive substring over the row's who / agent / action / outcome / channel / source label. Narrows the VISIBLE rows only — the three subtotal tiles and the total stay the honest window-wide, source-wide breakdown (never blended, never re-cut by search). |
| Agent | `agent_id` | the existing AT-380 drill-down, now with a picker. Rendered only for `branch` / `all` scope; the list is the branch's users for `branch`, the agency's for `all`. The controller still ignores an agent outside the caller's scope (falls back to the scope filter, never grants). |
| Window | `days` | unchanged (30 / 90 / 180 / 365) |
| Source | `source` | unchanged (MIC prospecting / Direct contact / Comms tile) |

Source tiles carry `q` and `agent_id` so clicking a tile never drops the search or agent.
"Clear" (only while q / source / agent is active) returns to the window-only view. A live
"N actions" count sits at the right of the bar.

## 4. Pagination

25 rows per page via `LengthAwarePaginator` over the feed's row array (rows are built in PHP by
the service after batch-resolving sends/contacts/users). Page links carry the query string, so
a searched / drilled-down page 2 stays searched / drilled-down. The service's 500-row window
cap and its "truncated" notice are unchanged.

## 5. Layout

Page wrapper is a full-height flex column. Frozen (`flex-shrink-0`): header (unchanged
styling), tab switcher, subtotal tiles, filter card. Each tab body is its own scroll region:
Activity Feed → `flex-1 min-h-0 overflow-y-auto corex-brand-scroll` holding the table +
pagination + notes; Consent Funnel → the same class on the embedded AT-91 board, unchanged
inside. Empty state distinguishes "no actions match these filters" from "no activity yet".

## 6. Permissions

Unchanged: `outreach.summary.view` gate on the route; `outreach_canvassing.view` scope for rows.
Filters only ever narrow what the scope already returns.

## 7. Tests

`tests/Feature/SellerOutreach/OutreachCanvassingBoardListTest.php`: search narrows rows but not
tiles; agent picker rendered for all-scope and narrows; 26 rows page 25 / 1 with the search
carried on the page-2 link.

## 8. Files

- `app/Http/Controllers/CoreX/OutreachCanvassingController.php` (index)
- `resources/views/corex/outreach-canvassing/index.blade.php`
- `tests/Feature/SellerOutreach/OutreachCanvassingBoardListTest.php`
