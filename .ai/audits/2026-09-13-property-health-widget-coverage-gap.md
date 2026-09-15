# Property Health — computed everywhere, seen almost nowhere

**Date:** 2026-09-13. **Found by:** cc5, while building a fixture for the conductor's
`contact_property` no-hard-delete verification. **Status:** confirmed with real numbers
against live QA1 data, not sampled or estimated. Not a bug in tonight's work — a
pre-existing product gap, surfaced by tonight's work.

## The one-sentence version

CoreX calculates a health score for every active property every night. There is exactly
**one** screen in the entire application that ever shows a property's score to a human,
and that screen only shows the worst 20 properties **per agent**. Tonight, agency-wide,
that means **1,165 of 1,492 properties currently needing attention — 78% of them — are
computed, stored, and never seen by anyone.**

## How this was found

Building a disposable fixture for tonight's `contact_property` verification, I needed a
property that currently scores "Owner linked" (no penalty) so an owner-unlink could be
shown flipping that one factor to critical. I discovered the cockpit widget
(`command-center/performance.blade.php`, "Properties Needing Attention") only lists the
worst 20 properties for the viewing agent, ordered by score ascending. Checking where a
clean fixture would rank surfaced how deep the backlog already is.

## The mechanism

- `App\Services\CommandCenter\PropertyHealthCalculator::calculateAll()` scores every
  active property and writes one row per property to `property_health_scores`
  (score, grade, per-factor JSON breakdown). Run by the console command
  `command-center:health` — nightly, not live, not triggered by any data change.
- `App\Http\Controllers\CommandCenter\DashboardController::performance()` is the
  **only** controller method that reads `property_health_scores` for display. It calls
  `PropertyHealthCalculator::getNeedingAttention($user->id, null, 20)` — agent-scoped,
  hard-capped at 20 rows, ordered by score ascending (worst first).
- `resources/views/command-center/performance.blade.php` is the **only** view in the
  whole codebase that renders a `property_health_scores` row. Confirmed by
  `grep -rl "PropertyHealthScore\|health_score\|healthScore\|propertyHealth"
  resources/views` — zero hits anywhere else. No drill-down. No per-property detail
  page. No modal. The property's own show page (`corex/properties/show.blade.php`)
  never mentions health at all.
- There is no live recompute path either — `getNeedingAttention()` and the two API/
  dashboard summary counts are the entire read surface, and all of them read the
  overnight snapshot. A change to a property's data (unlinking its owner, for example)
  is invisible everywhere, including in this widget, until the next
  `command-center:health` run.

## The numbers, queried live against `corex_qa1` tonight (2026-09-13 22:xx)

| Metric | Count |
|---|---|
| Properties with a stored health score | 1,570 |
| Needing attention agency-wide (grade critical or attention, i.e. score < 70) | 1,492 |
| Of those, grade **critical** (score < 50) | 1,032 |
| Distinct agents with at least one property needing attention | 21 |
| Sum of every agent's own top-20 widget (what CAN ever be seen, across all 21 widgets) | 327 |
| **Never surfaced to any human, on any screen, by this feature — ever** | **1,165** |

Johan's own agent id (22) alone carries 540 of the 1,492 — the single largest share,
and on its own already 27× the widget's 20-row cap.

## Why this matters

This isn't "the widget is a bit small." A feature whose entire purpose is flagging
properties that need an agent's attention is, for the overwhelming majority of the
properties it flags, doing the computation and then throwing the answer away. The
28 minutes it took to notice this happened by accident, while building an unrelated
fixture — which is itself a signal: nothing in the product today would ever surface
this gap on its own, because the gap is inside the one feature meant to surface gaps.

## What this is not

This is not a claim about what the fix should be — that's explicitly the conductor's
and Johan's call, not mine to make (CLAUDE.md non-negotiable #1/#8). Possibilities
that exist (not a recommendation, just naming the shape of the space): a real
paginated/searchable "Properties Needing Attention" list screen instead of a top-20
dashboard widget; a per-branch or per-admin rollup view; filters/sort on the existing
widget; a higher cap. Whichever direction is chosen, it is new build, not a bugfix —
the current code is doing exactly what it was written to do, just at a scale the
screen was never designed to represent.
