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

## Sketch of Option A, for whoever picks this up (Andre or otherwise)

Not a commitment to this exact shape — a starting point so the next person
isn't deriving the approach from zero.

**Step 1 — resolve this page's group keys in SQL**, not PHP:

```sql
SELECT COALESCE(property_group_id, CONCAT('single_', id)) AS group_key,
       MAX(<active sort column>) AS sort_val   -- representative value per group
FROM prospecting_listings
WHERE agency_id = ? AND <same filters as today>
GROUP BY group_key
ORDER BY sort_val <dir>
LIMIT 50 OFFSET ?
```

This needs a supporting composite index — `(agency_id, property_group_id,
<sort_col>)` at minimum; likely one such index per allowed sort column
(`last_seen_at`, `first_seen_at`, `price`, `suburb` — same set round 1 already
indexed on the ungrouped table), since `GROUP BY` + `ORDER BY` on a derived
column rarely uses a single index cleanly. Needs its own `EXPLAIN` pass
before trusting it at 39k+ rows, not assumed.

**Step 2 — hydrate only those groups' rows**:

```sql
SELECT * FROM prospecting_listings
WHERE agency_id = ? AND COALESCE(property_group_id, CONCAT('single_', id)) IN (<50 keys from step 1>)
```

Then run the EXISTING in-memory `groupBy`/`map`/portals-attachment logic on
this ~50-150 row result instead of the full 39,665 — unchanged code, just a
pre-filtered input. This is deliberately the smallest possible change to the
grouping logic itself, to keep the "what breaks if rushed" surface small.

**Step 3 — stock-row injection becomes page-scoped**: resolve which of
*this page's* resolved groups are company stock (existing `OnMarketStockService`
identity check, called against ~50 rows instead of the full set — likely fast
enough with no further work), inject/float only within that already-small set.
The one open UX question this surfaces: today, stock rows float to the top of
whichever page they land on. Per-page resolution means a stock row's floated
position is now relative to ITS page, not the whole list — worth confirming
with Johan whether that's the same behaviour agents expect, or whether stock
rows should always be forced onto page 1 regardless of natural sort order
(a deliberate product decision, not an implementation detail to guess at).

**Acceptance criteria** (in addition to the reconciliation/row-shape checks
already listed above):
- [ ] Every allowed sort (`last_seen_at`, `first_seen_at`, `price`, `suburb`)
      produces the IDENTICAL page-by-page row sequence as today's full-set
      approach, for at least 3 real agencies of varying size (small/medium/
      the largest), verified programmatically (not eyeballed), not just page 1.
- [ ] Total page count (`LengthAwarePaginator`'s reported total) matches
      today's grouped total exactly — the group-collapsing count, not the
      raw row count.
- [ ] Stock-row float behaviour explicitly signed off by Johan (see the open
      question above) before merge, not discovered as a surprise after.
- [ ] Controller time for the full page (agency 1, admin/all scope, no
      filters) measured under 2s on live — the actual target, not "faster
      than before."
- [ ] Every downstream consumer of `$listings->items()` (buyer tiers,
      suggested-action resolution, state enrichment, the `_fragments=1`
      JSON payload) verified unchanged in shape and content for a fixed
      filter/page, before/after diffed to zero — same discipline as round
      2's Option 2 reconciliation proof.
