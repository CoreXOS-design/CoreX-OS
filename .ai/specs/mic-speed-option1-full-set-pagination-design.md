# MIC speed — Option 1 design write-up: real SQL pagination for the work-screen listing query

**Status:** not built. Scoped for planning per Johan's explicit instruction (2026-08-22)
— this is the real cost centre, but it needs proper design work, not an evening edit.

## The problem, precisely

`MarketIntelligenceController::work()` resolves the agent's **entire** matching
listing set before it ever slices to the 50 rows a page actually shows:

```
$query = ProspectingListing::where('agency_id', $agencyId) ... (scope, filters, sort)
$allListings = $query->get();                          // EVERY matching row, no LIMIT
$grouped = $allListings->groupBy(property_group_id);    // in-memory grouping
$rows = $grouped->map(...);                             // primary + portals per group
$rows = $rows->sortBy(...)                              // in-memory sort (surface-stock rows)
$listings = new LengthAwarePaginator($rows->forPage($page, 50), $rows->count(), 50, $page, ...);
```

Measured live: this is the single biggest remaining cost after round 1 —
roughly 4.8s of pure PHP time in one full-page measurement, scaling directly
with the agency's row count (currently ~39,665, growing).

## Why it can't be a plain `LIMIT`/`OFFSET` swap

Two things happen on the in-memory set that a straight SQL `LIMIT` cannot
reproduce without rework:

1. **Property-group collapsing.** Rows sharing `property_group_id` (the same
   real property re-scraped under rotating portal refs across P24/PP) are
   grouped into ONE row with the others attached as `->portals`. Collapsing
   is a property of the *whole* matching set — you cannot know a row's
   final page position until you know how many OTHER rows it will absorb,
   because absorbed rows disappear from the list entirely. A naive
   `LIMIT 50` on the raw (ungrouped) table would return a wrong, inconsistent
   count of *visible* rows per page (sometimes fewer than 50 after collapsing,
   sometimes a partially-collapsed group split across two pages).

2. **Synthetic stock-row injection + stock-first sort.** When "include in
   stock" is off, the controller separately queries `properties` for the
   agency's own on-market stock not already represented, manufactures
   synthetic `ProspectingListing`-shaped rows for them, and floats every
   stock row (real or synthetic) to the top of the CURRENT page's view via
   `sortBy`. This assumes the full set is in hand to inject into and
   re-sort.

## What real pagination would require

The honest options, roughly ordered by how much they preserve current
behaviour vs. how much they change the query shape:

**A. Two-step ID pagination.** Run a lightweight query that resolves
`property_group_id` (or a synthesized fallback key for ungrouped rows) with
`GROUP BY`, ordered and `LIMIT`/`OFFSET` at the *group* level in SQL, to get
exactly the 50 group-keys for this page — then a second query to hydrate
only those groups' rows (typically 50-150 rows, not 39,665). Stock-row
injection would need to become a **per-page** operation: resolve which of
THIS page's 50 groups are stock, inject/float only within that already-small
set. This preserves grouping and stock-float behaviour with the least
semantic change, but requires the group-resolution query to be efficient at
scale (a `GROUP BY property_group_id` with the current filter/sort combination,
which itself needs to be indexed well — likely a new composite index on
`(agency_id, property_group_id)` plus whatever the active sort column is).

**B. Push grouping into SQL entirely** (window functions / a materialised
grouping column) — most performant long-term, most invasive: changes the
data model (a maintained "canonical representative row per group" flag or
view), touches every place that currently assumes `$grouped`/`$rows` shape,
and needs its own correctness proof for stock-float ordering. This is a
multi-day piece of work, not a follow-up round.

**C. Cache the full resolved+grouped+sorted set per (agency, filter-signature)
for a short TTL**, paginate the cache in-memory instead of on every request.
Explicitly the thing Johan told us not to reach for first — it hides the
real cost rather than fixing it, and this screen has ticks/claims/pitches
that mutate state constantly, so cache invalidation would be its own hazard
(a stale row after a claim is exactly the kind of "list says X, tile says Y"
bug the standing rule exists to prevent). Only worth considering as a
short-term band-aid ALONGSIDE A or B, never instead of them.

## What breaks if this is rushed

- Any of the several places that currently read `$listings->items()` and
  assume it's the fully-resolved, fully-grouped page (buyer tiers,
  suggested-action resolution, state enrichment, the fragment JSON payload)
  need to keep receiving the same shape — a partial rewrite that changes
  what a "row" is mid-migration risks silently breaking claim/pitch state
  correlation, which is exactly the class of bug that's expensive to notice
  (looks fine, quietly attributes the wrong buyer match to the wrong row).
- The property-grouping logic is currently a single, auditable in-memory
  pass. Splitting it into "resolve page of groups" + "hydrate this page's
  rows" doubles the surface area for a group to be mis-resolved, and this
  is the screen literally everyone in the agency uses every day — a
  regression here is maximally visible.

## Rough sizing

- **Design + review:** the grouping/stock-float interaction needs a written
  spec before code, per the pillars-first / spec-before-code standing rule
  — this isn't a "just try it" screen.
- **Build (Option A, the least invasive path):** new group-resolution query,
  new supporting index, rework of the stock-injection step to operate
  per-page instead of per-full-set, re-verification of every consumer of
  `$listings->items()`. Realistically multiple sessions, not one evening —
  this is the same order of magnitude as the round-1 double-resolution fix
  plus round-2 combined, with a materially higher regression risk given how
  many downstream features read the resolved row shape.
- **Verification:** needs the same reconciliation discipline as round 2's
  Option 2 (every tile/count/badge cross-checked against the list, in every
  scope state) PLUS a row-shape correctness check (grouping/portals/stock
  rows identical to today's output for a fixed filter set, page by page,
  not just aggregate counts).

## Recommendation

Worth doing — it's the actual ceiling on how fast this screen can get, and
the cost scales with the row count that's already growing. Not worth doing
under time pressure on the screen everyone uses. Scope it as its own spec
(`.ai/specs/`) with Johan's sign-off on the UX/behavioural tradeoffs (e.g.
whether "50 items per page" can stay exact once grouping happens purely
per-page) before any code is written.
