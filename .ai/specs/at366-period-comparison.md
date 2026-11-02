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

## 8. Deal-status ticks drive the whole page (AT-535, 2026-11-02, Johan — QA1 only)

**Bug.** The Deal-status ticks (All / Pending / Granted / Registered / Declined) only recomputed the four tiles. The
headline sentence and the 13 metric cards (and their deltas) were built server-side over ALL deals including Declined,
so declined deals were counted as commission "earned", and the comparison showed only the delta, never the previous
period's actual figure. The company deal numbers also counted a joint deal once per agent on it, so the page said 15
deals where the Deal Register lists 11.

**Rulings (all Johan's).**

- **A. The ticks drive the whole page.** Tiles, headline, and every deal-derived figure (Deals created, Deals
  registered, Commission gross/agent/company, the branch and agent columns, the drilldown lists, Print) count only
  deals in the ticked statuses, for the main AND the comparison period, so every delta compares like with like.
  Non-deal metrics (contacts, properties, presentations, portal views, buyers, viewings, appointments, outreach, FICA,
  MIC claims) are untouched.
- **B. Declined is out by default.** First load = Pending + Granted + Registered ticked. Ticking All ticks all four.
  Same ticks, same tiles, same cards as before; nothing rebuilt.
- **C. Every comparison shows the previous period's actual figure** beside the delta:
  `R 490,397.53 · ▲ +R 270,682.92 (+123.2%) · 2026-09-01 → 2026-09-30: R 219,714.61`. Headline sentence and all 13
  cards; the Commission card shows Gross, Agent share and Company share each with their own previous figure
  (the card's big number is Gross, so its delta is on Gross). Money to the cent when a previous figure is shown.
- **D. Print and a copied link show the same figures as the screen.** The selection travels in the URL
  (`?status[]=pending&status[]=granted…`; absent = the default in B; `status[]=none` = nothing ticked; `status[]=all`).
  The server is the single source: **a tick reloads the page at the same address with the new selection** (no client-side
  recompute; that is the choice made, so the screen, a pasted link and Print cannot disagree). Print also carries the
  custom dates, the comparison, and shows the counting line, headline and the same comparison lines.
- **E. "Unequal-length ranges" warning unchanged.**
- **F. Scoping unchanged.** OWN / BRANCH / AGENCY is resolved first (cohort + ceiling); the ticks only filter rows inside
  it. Proven by tests (an agent asking for All statuses plus a colleague / another branch still sees only their own).
- **G. Deal date, not captured date.** Every deal-derived figure goes by the DEAL DATE so the report agrees with the
  Deal Register for the same month on the same ticks:
  - DR1 (`deals`): `deal_date` (NOT NULL, so there is no fallback); DR2 unlinked (`deals_v2`): `offer_date` (NOT NULL; DR2's
    deal date). `created_at` / the `period` month tag are never used. Deals registered is the one exception by design:
    it has its own date, `registration_date` (DR2 `actual_registration`), and counts deals registered in the period.
  - **A joint deal counts once** in a branch's and in the company's deal numbers (qty and value), however many agents are on
    it. An agent's own row still shows every deal they are on. Commission is each agent's own money line, summed, so it is
    unaffected. Consequence: agent rows can add up to more than the company deal count when deals are joint.

**Status buckets** (unchanged rule, `DealStatusBreakdownService`): Declined > Registered (registration date or R) >
Granted (granted date or G) > Pending. DR2 `completed` / `active` fall into Pending unless they carry an actual
registration date (reported to Johan; not changed here).

**Files.** `DealStatusSelection` (new; parsing, default, URL form), `AgencyPerformanceReportService::build(…, $statuses)`,
`Providers/{DealStatusAware,CountsDistinctDeals}` (new), `AbstractDealMetricProvider`, `CommissionGrossProvider`,
`DealStatusBreakdownService` (exposes the deals behind each agent's row), `ReportPeriodComparator` (Gross + Company share
comparisons), `PerformanceDrilldownService`, `AgencyPerformanceReportController` (index/print/drilldown),
`components/performance-delta`, `agency-report/index` + `print-company`.
Tests: `tests/Feature/Performance/AgencyReportDealStatusTicksTest.php`.

**Follow-on (same day, same bug):** the branch page, the agent page and the agent print now follow the same `?status[]` selection
(same default, same "Counting …" line), for both the period and the preceding period they compare to; links from the company
report (branch / agent rows) carry the period, custom dates and the ticks, and so do Back, the agent Print link, the agent
links on the branch page and the period selector (hidden `status[]` fields). The Buyer-activity tiles on the company report
show the previous period's actual figure beside the delta (ruling C, same component). The commission column / agent
card on the branch, agent and company tables is headed **"Commission (agent share, ex-VAT)"** because the number it holds is
the agent's own money line (`CommissionGrossProvider::forUsers`); the card keeps "Commission (gross ex-VAT)" because its
big number is Gross. The basis itself is NOT changed (open with Johan).

**Not changed (reported, with Johan):** the headline "the agency earned" uses the agent share of commission while the
Commission card's big number is Gross (agent + company); DR2 `completed`/`active` fall into Pending; agent rows can add up
to more than the company deal count on joint deals.

### Build log

- 2026-11-02 — AT-535: rulings A–G built on QA1. See above.
- 2026-11-02 — AT-535 follow-on: branch / agent pages + agent print follow the ticks; buyer-activity tiles show the previous figure; commission column headed "agent share" (basis unchanged); previous-period labels ("Previous: …", "Same period last year: …") now name that window's own dates (they repeated the current period's label).
