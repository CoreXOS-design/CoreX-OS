# CMA / Market-Report Extraction Hardening Audit — 2026-07-23

Field-by-field map of what each market-report type **contains** vs what CoreX
currently **captures**, to maximise extraction. Triggered by the "1087 Harrison
Drive" incident where the vicinity report's 15 sales parsed as 0 (now fixed).

Scope: the comps-bearing / data-bearing CMA Info reports agents upload today.
Evidence: `market_report_types.expected_fields_json`, the `App\Services\MarketReports\Parsers\*`
family, and the live reports mr#205/206/207 (Shelly Beach).

Legend: ✅ captured · ⚠️ partial / layout-fragile · ❌ not captured · — n/a

---

## 1. cma_info_vicinity_sale  ("Sales in <Suburb>" / "<Street> Vicinity Report")
**Carries individual comps.** This is the primary comparable-sales source that
populates a presentation's comp list. **FIXED 2026-07-23** (commit 3da84a1a).

| Field | Status | Notes |
|---|---|---|
| subject_property.address | ⚠️ | Detected only when the subject line is number-prefixed; blank on estate subjects. Low impact (presentation carries its own subject). |
| subject_property.suburb_scope ("Limited to …") | ✅ | |
| subject_property_type (residential/vacant) | ✅ | |
| radius_meters ("within N m") | ✅ | |
| sales[].distance_m | ✅ | |
| sales[].erf_number | ✅ | Now incl. wrapped Erf No ("730-"/"11" → 730-11). In `raw_row_json` (no erf column on `market_report_comp_rows`). |
| sales[].address | ✅ | Blank / numberless / wrapped-estate addresses now handled; optional (never drops a row). |
| sales[].erf_usage | ✅ | Optional. |
| sales[].property_type / "Type" (e.g. "DS House") | ⚠️ | "Type" column not separately captured (folded into address tail); erf_usage → property_type default 'Residential'. |
| sales[].extent_m2 | ✅ | |
| sales[].sale_date | ✅ | |
| sales[].sale_price | ✅ | |
| sales[].r_per_m2 | ✅ | |
| summary.lower/middle/upper_range | ✅ | → cma_value_lower/middle/upper. |
| summary.average / average_r_per_m2 | ✅ | |

**Residual work:** (a) subject_address on estate subjects → feeds
`SubjectReportResolver` (§5); (b) capture the "Type" column (DS House / Sectional)
for better title-type discipline.

---

## 2. cma_info_property_valuation  (Lightstone-style property valuation)
**A SECOND comps-bearing layout.** Carries subject + a comparative-properties
table (Dist, Erf, Address, Usage, Erf Extent, Last Sale Date, Last Sale,
Estimated Value, R/m²) PLUS an Indexed Value + CMA value range and several rich
sub-tables. The parser HAS a ROW_COMP path — **but it extracted 0 comps from the
Harrison valuation report (mr#206)** → the same estate/numberless/wrapped layout
gap the vicinity parser just had. **HIGHEST-VALUE next target.**

| Field (expected) | Status | Notes |
|---|---|---|
| subject_property | ✅ | Emitted as ROW_SUBJECT. |
| municipal_valuation | ⚠️ | Verify per-report; column exists on comp rows. |
| sale_history[] (subject prior sales) | ⚠️ | Confirm extraction. |
| **comparative_properties[]** | ❌ (this report) | Parser has ROW_COMP logic but yielded 0 on mr#206 — estate-layout gap. **Fix like vicinity → a second comp source.** |
| cma_value_range (lower/mid/upper) | ⚠️ | Verify → could enrich the valuation number. |
| comparative_municipal_valuations[] | ❌ | Not mapped. |
| comparative_accommodation[] (beds/baths) | ❌ | Not captured → we have no bed/bath on comps. |
| scheme_recent_sales[] (sectional) | ⚠️ | Sectional variant only. |
| price_distribution | ❌ | |
| sold_properties_dom[] (days-on-market) | ❌ | Would feed DOM metrics. |
| for_sale_in_vicinity[] | ❌ | Active-listing competitors. |

**Recommended:** port the column/date-anchored row parse (extent · date · price ·
estimated_value · R/m², optional address/usage, wrapped-fragment merge) into
`CmaInfoPropertyValuationParser::extract*` so estate valuation reports yield their
~11 comps. Then let `MicSnapshotHydrator` treat valuation ROW_COMP as a comp
source (dedup vs vicinity by `CompFingerprint`).

---

## 3. cma_info_median_sales_analysis / lightstone_suburb_report  (suburb aggregate)
**No individual comps by design** — yearly counts, median price, index, change %,
DOM, demographics. Feeds the Market Overview / suburb metrics, not the comp list.

| Field | Status | Notes |
|---|---|---|
| suburb / year / title_type | ✅ | |
| no_of_sales | ⚠️ | Verify each yearly row is captured (per-year series). |
| median_price | ✅ | |
| annual_change_pct / index_value | ⚠️ | Confirm both persist as data points. |
| price_movement / sales_volume (Lightstone) | ⚠️ | |
| buyer_demographics / time_on_market | ❌ | Not captured → richer suburb narrative opportunity. |

---

## 4. cma_info_market_analysis
Carries subject + cma_valuation (lower/mid/upper) + suburb_stats
(median/total_sales/absorption) + `comparable_sales[]`. Also comps-bearing —
audit its comp extraction against the same estate-layout stress (not yet
verified on an estate report).

---

## Cross-cutting findings & priorities

1. **P1 — Valuation report comps (§2).** Same class of bug as vicinity, on a
   report agents already upload. Fixing it adds a second comp source for exactly
   the estate suburbs where the vicinity report is thinnest. Reuse the vicinity
   column-parse approach + fixture test.
2. **P1 — 0-comp guard coverage.** The `zero_comp_with_summary_guard` +
   `NotifyOnMarketReportParseFailure` (built 2026-07-23) covers vicinity; extend
   the guard to valuation / market_analysis so a silent 0-comp there also flags.
3. **P2 — Accommodation (beds/baths) + DOM.** Not captured on any comp today;
   valuation & DOM tables carry them → better comparability + "days on market".
4. **P2 — subject_address on estate reports (§1)** → makes `SubjectReportResolver`
   link the vicinity/valuation report to the presentation as a same-subject
   source (bypasses the 12-month window for analyst-vetted comps).
5. **P3 — "Type" column (DS House / Sectional) + price_distribution / active
   listings** for richer narrative.

**Regression discipline:** every parser fix ships with a real estate-layout PDF
fixture in `tests/Fixtures/market_reports/` + a count+spot-check test (the
pattern established by `cma_info_vicinity_sale_estate.pdf`).
