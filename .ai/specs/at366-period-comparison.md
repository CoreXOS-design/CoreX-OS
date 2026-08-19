# AT-366 Period Comparison — Agency Performance & ROI Report

> Extends `.ai/specs/at366-report-frontend-contract.md`. Commissioned by Johan
> (relayed via conductor, 2026-08-19): "give me a compare periods option...
> lets compare what the figures are doing from lets say 2 custom date
> selections, or this month to last month." Phase 1 of a two-phase build —
> Phase 2 (charts / visual redesign) is a separate spec addendum once Phase 1
> is verified.

## 1. What this adds

A **comparison period**, independent of and additive to the existing primary
period selector (`PeriodResolver`). When on, every figure on
`/corex/performance/agency-report` — the 13 company tiles, the 6 buyer-activity
tiles, and every branch/agent table row — gains: the comparison-period value,
the absolute delta, and the percentage change. Comparison **off** renders
byte-identical to today; this is a pure addition, no existing behaviour changes.

## 2. Comparison modes (query param `compare`)

Two orthogonal controls: the existing **Period** selector (unchanged, plus one
new primary preset, `this_quarter`/quarter Carbon boundaries — needed so "this
quarter vs last quarter" is reachable) and a new **Compare to** selector:

| `compare` value | Meaning | Maps to |
|---|---|---|
| `off` (default) | No comparison | — |
| `previous` | The equal-length window immediately preceding the primary period | `Period::previous()` (already existed, previously only used by agent/branch drill-downs) |
| `same_last_year` | The primary period's start/end shifted back exactly one year | new `Period::sameLastYear()` |
| `custom` | A fully independent second range (`compare_start`, `compare_end`) | `PeriodResolver::custom()` (already existed, reused) |

Johan's four named presets compose from these two controls, not four separate
hardcoded pairs — one mechanism ("previous period") naturally produces "this
month vs last month" when the primary is `this_month`, "this quarter vs last
quarter" when primary is `this_quarter`, and "this year vs last year" when
primary is `this_year`. "Same period last year" and the two-custom-ranges case
are the other two `compare` modes. This is a deliberate simplification versus
four separate named preset pairs — flagged, not asked, since it's a technical
implementation choice with an identical observable outcome for all four named
cases, and is more composable (e.g. "this month vs same period last year" also
becomes reachable for free).

## 3. Direction-of-good — the trap

Every metric declares its own polarity; the UI colours/arrows follow the
declared polarity, never the raw sign of the delta.

- `higher_is_better` — all 13 company MetricProviders (activity/production:
  more claims, more contacts, more viewings, more deals, more commission — all
  good going up). Declared via a new `direction(): string` method on the
  `MetricProvider` interface, defaulted to `higher_is_better` in
  `AbstractCountMetricProvider`/`AbstractDealMetricProvider` so only the two
  providers with bespoke `forUsers()` (`PortalViewsProvider`,
  `CommissionGrossProvider`) need an explicit override (also
  `higher_is_better`).
- Buyer activity (`BuyerActivityService::METRICS`): `buyers`, `appointments`,
  `comms_email`, `comms_whatsapp` = `higher_is_better`. **`lost` and
  `lost_value` = `lower_is_better`** — Johan's explicit trap example.
- Deal status buckets (`DealStatusBreakdownService`): `granted`/`registered` =
  `higher_is_better`, **`declined` = `lower_is_better`** (Johan's other named
  example), `pending` = `neutral` (deliberately uncoloured — a pending-deals
  increase is not intrinsically good or bad; flagged as a judgement call, not
  a business decision Johan was asked to make, since it wasn't named).
  `all` = `higher_is_better` (aggregate production signal).

All delta/percent/direction math computed **once, server-side**, in a new pure
helper `App\Services\Performance\PeriodComparison::compute(current, previous,
direction)`, returning `{value, previous, delta, delta_pct, direction, good}`.
The view/Alpine layer never computes a percentage or picks a colour from a
raw number — it only reads `good` (bool|null) and renders. This is also why
the comparison payload is fully pre-computed server-side and embedded as JSON
into the existing `agencyReport(...)` Alpine config, matching this page's
existing convention (a named function referenced from `x-data`, not inline
literal logic) — no nontrivial business logic lives in Blade/JS.

## 4. Edge cases (explicit, not incidental)

- **Comparison value is zero, current isn't**: `delta_pct = null` (never
  `∞`/`100%`). View renders the absolute delta only, no percentage.
- **Both zero**: `delta = 0`, `delta_pct = null`. View renders `—`.
- **Unequal-length ranges** (e.g. comparing 3 days to 31): computed and
  surfaced as `comparison_meta.unequal_length` (bool) + both lengths in days;
  the header states this plainly ("comparing 3 days to 31 days") rather than
  silently proceeding as if the ranges matched.

## 5. Data flow

`AgencyPerformanceReportService::build()` is **untouched** — it's depended on
by the existing, working `agentJourney()`/`branchJourney()` drill-downs, and
this feature doesn't need to touch that method's contract. Instead:

- Controller calls `build()` twice (current period, then comparison period if
  requested) — same pattern `agentJourney()`/`branchJourney()` already use.
- A new `ReportPeriodComparator::merge($current, $previous)` (new class) folds
  the two raw `build()` outputs into one comparison-annotated structure:
  company metrics + deal_status wrapped with `PeriodComparison::compute()`;
  branches merged by the union of branch keys (an agent's point-in-time branch
  attribution can differ between the two periods); agents merged by user_id
  (the cohort is the same set in both builds — `HierarchyResolver::agents()`
  doesn't vary by period — so this is a lookup-merge, not a full union).
  `merge()` is a no-op passthrough when `$previous` is `null` (comparison off).
- `BuyerActivityService::rollup()` gets the identical two-call-then-merge
  treatment via the same `PeriodComparison` helper, inline in the controller
  (6 metrics, doesn't need its own merge class).

## 6. Files touched

- New: `app/Services/Performance/PeriodComparison.php`,
  `app/Services/Performance/ReportPeriodComparator.php`,
  `.ai/specs/at366-period-comparison.md` (this file).
- `app/Services/Performance/Period.php` — `sameLastYear()`, `lengthInDays()`.
- `app/Services/Performance/PeriodResolver.php` — `this_quarter` preset,
  `resolveComparison()`.
- `app/Services/Performance/Providers/MetricProvider.php` — `direction()`.
- `app/Services/Performance/Providers/AbstractCountMetricProvider.php`,
  `AbstractDealMetricProvider.php` — default `direction()`.
- `app/Services/Performance/Providers/PortalViewsProvider.php`,
  `CommissionGrossProvider.php` — explicit `direction()`.
- `app/Services/Performance/BuyerActivityService.php` — `direction` key per
  metric.
- Deal-status direction map lives as `ReportPeriodComparator::STATUS_DIRECTIONS`,
  not on `DealStatusBreakdownService` (which stays untouched) — it's the
  class that consumes it to merge `deal_status` buckets, so that's its
  natural home; noted here since it differs from the file list above.
- `app/Http/Controllers/Performance/AgencyPerformanceReportController.php` —
  read `compare`/`compare_start`/`compare_end`, orchestrate the second
  `build()` + merge.
- `resources/views/performance/agency-report/_period-selector.blade.php` —
  Compare-to selector.
- `resources/views/performance/agency-report/index.blade.php` — render
  delta/%/direction on every tile and table row.
- `resources/views/performance/agency-report/_buyer-summary.blade.php` —
  same, for the 6 buyer tiles.

## 7. Acceptance criteria

- Comparison off: page renders byte-identical to pre-change output.
- Comparison on: every company tile, buyer tile, branch row, agent row shows
  value / previous / Δ / Δ%.
- A metric with `lower_is_better` rising shows a red/bad indicator; the same
  numeric rise on a `higher_is_better` metric shows green/good.
- Comparison-zero and both-zero cases render per §4, never `∞`/`100%`/`0%`.
- Unequal-length ranges are stated plainly in the header.
- `php -l` clean, blade compiles, single most relevant test passes (or the
  pre-existing test-DB grant gap is stated plainly if blocked).
