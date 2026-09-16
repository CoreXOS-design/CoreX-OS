# MIC — Stale claims review: list filters, pagination, frozen header (AT-393)

> Status: BUILT 2026-09-14 on branch `AT-393-Sidebar-styling` at Andre's instruction. QA1 only.
> Page: `/corex/market-intelligence/stale-review` (route `market-intelligence.stale-review`).
> Parent spec for the stale-claim state machine: `.ai/specs/2026-08-19-claim-timer.md`
> (Johan 2026-08-13 anti-poaching rule — agents never grab stale stock; the BM/admin moves or keeps).

## 1. Business requirement

The BM/admin review list grows with the agency: every warned or stale claim lands here.
Without a filter or paging a branch manager scrolls an unbounded table to find one
agent's claims, and the header + column headings scroll away with it. Andre's ask
(2026-09-14): a filter, pagination, the same frozen header treatment as Contacts /
Properties / Portal Leads / Presentations, and the sidebar must light only "Stale
claims review" on this page, not also "Market intelligence".

## 2. Pillars

Reads: `ProspectingClaim` (Agent pillar via `user_id`; Property pillar via
`prospecting_listing_id` → `prospecting_listings.address`). Writes: none on the list
itself (move-or-keep actions are unchanged).

## 3. Filters (all optional; one GET form; every control composes)

| Control | Query key | Behaviour |
|---|---|---|
| Address search | `q` | case-insensitive substring match on the resolved address (or the `Property #n` fallback) |
| Agent ("On it") | `agent_id` | claims whose `user_id` is that agent; `''` = all agents |
| State | `state` | `''` = warned + stale together (default oversight view); `warned` = at the warn line but not past release; `stale` = past release, ready for move-or-keep |

"Search" submits; "Clear" appears only when a filter is active and returns to the
unfiltered list. The agent list is the same agency agent list the Reassign control uses.

## 4. Pagination

25 rows per page. Staleness is a per-claim PHP computation (`staleAgeDays()`), so the
page is cut from the filtered collection with `LengthAwarePaginator`, not in SQL. Every
page link carries the active query string, so page 2 of a filtered list stays filtered.
A live count ("N claims") sits at the right of the filter bar.

## 5. Layout

Page wrapper is a full-height flex column: header (shared `x-mic-page-header`) and the
filter card are `flex-shrink-0`; everything below — flash, table, pagination — lives in a
`flex-1 min-h-0 overflow-y-auto corex-brand-scroll` scroll region (sidebar-matching
scrollbar). Same shape as the other AT-393 list pages; not `position:sticky`.

## 6. Sidebar

The "Market intelligence" sub-item's active matcher (`market-intelligence.*`) now
excludes `market-intelligence.stale-review*`, so only "Stale claims review" is lit on
this page. The Real Estate section-open matcher is unchanged (still opens for it).

## 7. Permissions

Unchanged: route middleware `permission:prospecting_setup.manage`.

## 8. Acceptance / tests

`tests/Feature/Prospecting/StaleClaimReviewFilterTest.php`: default view shows warned +
stale and hides fresh; address search narrows; agent filter narrows; state filter
separates stale from warned; 30 claims page 25 / 5 and the page-2 link carries the
agent filter.

## 9. Files

- `app/Http/Controllers/Prospecting/StaleClaimController.php` (index: filters + paginator)
- `resources/views/corex/market-intelligence/stale-review.blade.php`
- `resources/views/layouts/corex-sidebar.blade.php` (one matcher)
- `tests/Feature/Prospecting/StaleClaimReviewFilterTest.php`
