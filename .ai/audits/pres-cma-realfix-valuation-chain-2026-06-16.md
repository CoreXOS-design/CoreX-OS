# PRES-CMA-REALFIX — Valuation Chain Investigation (read-only)

> **Date:** 2026-06-15 (persisted 2026-06-16)
> **Branch:** `PRES-CMA-REALFIX`
> **Author:** Claude + Johan
> **Status:** read-only map; superseded in part by the 2026-06-16 build (band = middle ± config %).

## Pre-reads & branch
- Read: `CLAUDE.md`, `.ai/STANDARDS.md`, `.ai/BUILD_STANDARD.md`, `.ai/specs/presentation-data-lineage.md` (dated 2026-05-29 — **stale**: claims "no maths, bands read from `presentation_fields`"; the current tree computes bands from comp quartiles via `CmaComputeService`).
- Branch: **`PRES-CMA-REALFIX`** (HEAD `952e591e`).

## ⚠️ GATING FINDING — the R2,994,000–R3,000,000 band is NOT produced by this tree
That band was raw comp band (~R2.5M) × a ~1.2 condition factor (`ConditionAdjustmentService::applyToBand`), **removed from the render path** by commits `a84d8445`, `300f77ea` on this branch. The current tree produced, pre-2026-06-16-build, raw p25/median/p75 with no condition factor. `applyToBand`/`applyToMiddle` and `CmaComputeService.condition_adjusted` all exist but were **dormant/unread**.

## 1. CHAIN MAP (end-to-end, in order)

| # | Step | file:line |
|---|---|---|
| 1 | Sold comps loaded | `AnalysisDataService.php:31` (`$presentation->soldComps()`) |
| 2 | Comp whitelist applied | `AnalysisDataService.php:81-87` (`included_comp_ids_json`) |
| 3 | Compute engine called | `AnalysisDataService.php:89-91` → `CmaComputeService::compute:75` |
| 4 | Recency cut → IQR fences on R/m² | `CmaComputeService.php:115` → `cleanPool():193` → `applyIqrFences():263` |
| 5 | Distribution stats (min/p25/median/p75/max) | `CmaComputeService.php:131-132` → `poolStats()` (~:307) |
| 6 | Method medians (raw + dormant condition_adjusted) | `CmaComputeService.php:155-165` |
| 7 | Band assembled from pool_stats | `AnalysisDataService::compileCmaValuation:416-441` |
| 8 | asking-vs-value % (vs middle) + overpriced flag | `AnalysisDataService.php:479-482`, `:498` |
| 9 | Persisted to snapshot `computed_json` | `PresentationGeneratorService.php:236`; `PresentationController.php:433`; `PresentationCompFreshnessService.php:72` |
| 10 | **PDF recompiles LIVE (ignores computed_json)** | `PresentationPdfService.php:582` |
| 11 | Headline recommendation anchor = middle | `PresentationPdfService.php:143` |
| 12 | Exec bullet above-clause | `PresentationPdfService.php:144-145`, `:426-454`, `:317-368` |
| 13 | §5 Recommended Range card | `PresentationPdfService.php:2995-3007` |
| 14 | §6 Current Asking Price card (verdict colour) | `PresentationPdfService.php:3009`, `:3012` |
| 15 | "Why This Range?" evidence rows | `PresentationPdfService.php:687-689`, table `:3020+` |

## 2. CONDITION — every factor/multiplier site (all dormant pre-build)
- `CmaComputeService::methodResult` factor `(1+pct/100)` ~:557-567; `applyConditionBc` at `methodRm2Extent:482`, `methodMedian:420`, `methodMean:439`. Output `.condition_adjusted` **unread**.
- `ConditionAdjustmentService::applyToMiddle` `* (1+pct/100)` at :116.
- `ConditionAdjustmentService::applyToBand` → `scaleBc():172-177` — **not called from any render path**.
- Blanket-% source: per-tier `adjustment_pct`, migration `2026_06_17_120000_add_condition_levels_to_presentations.php:40`; resolved via `ConditionAdjustmentService::resolveLive:57` → `AnalysisDataService::resolveConditionContext:353-378`.

## 3. COMP DATA MODEL — `presentation_sold_comps`
Columns: `id, mic_comp_row_id, presentation_id, agency_id, source_upload_id, sold_date, sold_price_inc, suburb, property_type, beds, baths, size_m2, listed_date, raw_row_json, parser_version, created_at, deleted_at, is_demo`. Model `app/Models/PresentationSoldComp.php:15-39`.
- size = `size_m2` EXISTS; sale date = `sold_date` EXISTS.
- **Condition rating, position/view flag, per-comp adjustment columns DO NOT EXIST → migration required.**
- Source `market_report_comp_rows` carries `condition` (:65), `extent_m2` (:47), `sale_date` (:50); `mic_comp_row_id` FK links each frozen comp — but see the data-shape audit: that FK is empty in `corex_dev` and `condition` is 0% populated.

## 4. ACTIVE LISTINGS — leaks into verdict logic
Model `PresentationActiveListing`; loaded `AnalysisDataService.php:32`. CMA band + recommendation are sold-comp-only (no band leak). Verdict leaks: `price_position` (`:115` → `compilePricePosition:1318-1330`), `price_brackets` (`:116`), `competitor_stock` (`:111`/`:901`), `stock_absorption` (`:113`, render `:721-725`), `PricingSimulatorService.php:21,63`.

## 5. ASKING-VS-VALUE VERDICT
Compute `AnalysisDataService.php:479-482` (vs middle), `:498` (is_overpriced). §6 render `PresentationPdfService.php:3009`, `:3012`. Bullet `buildAboveClause:317-368` + `bulletRecommendationHtml:426-454`. Competition percentile `compilePricePosition:1314-1345`. Absorption `compileStockAbsorption` (render :721-725).

## 6. SETTINGS
Hardcoded: `CmaComputeService.php:56` MIN_VIABLE_N=5, `:58` DEFAULT_RECENCY_MONTHS=36, `:59` DEFAULT_IQR_MULTIPLIER=1.5; `CompPoolBuilder::DEF_RANGE_LOWER/UPPER_PCT`.
Agency-configurable: `cma_compute_recency_months`, `cma_compute_iqr_multiplier`, `range_lower_pct`/`range_upper_pct`, comp-selection + competitor-stock settings. Size tolerance: no setting (R/m² IQR only).

## 7. FREEZE
`PresentationSnapshot.computed_json` = full `compile()` array. Writers `PresentationGeneratorService.php:236`, `PresentationController.php:433`, `PresentationCompFreshnessService.php:72`. **Seller PDF recomputes live (`:582`)** — does NOT read computed_json; no frozen band to migrate.

## 8. TESTS
`tests/Feature/Presentation/`: CmaComputeServiceTest, CmaComputeCleaningTest, CmaTickWiringTest, ConditionAdjustmentTest, PriceBandTest, SellerVoiceAnchorTest, Build8dDealCompsTest, CompCurationToolkitTest, ReviewCompTableAddressTest, CompileSnapshotWiringTest, CompLabelTest. `tests/Unit/Presentations/CompPoolBuilderTest.php`.

## 9. READ — per-comp grid rebuild touch-map
Compute core `CmaComputeService.php` + `CompPoolBuilder.php`; band assembly `AnalysisDataService::compileCmaValuation:383-471`; new per-comp adjustment storage (migration on `presentation_sold_comps` or new `presentation_comp_adjustments`); render `PresentationPdfService.php:2995-3014`, `:687-689`; settings constants + Agency columns; verdict de-leak of active-listing-driven signals.
