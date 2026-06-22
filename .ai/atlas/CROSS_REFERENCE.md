# CoreX System Atlas — Cross-Reference (reverse lookup)

> **Purpose.** "If I change value Y, what breaks?" Pick a table / column / setting below and see
> every documented feature that **READS** it and every feature that **WRITES** it.
>
> This index grows as each feature doc is added. **It is only as complete as the feature docs that
> feed it** — a feature not yet in `ATLAS_INDEX.md` as DONE may also touch these values without
> appearing here yet. Treat absence as "not yet documented", not "nothing else touches it".
>
> Last updated: 2026-06-22 · Seeded from: **Presentations**.

Legend: **R** = reads · **W** = writes · file:line points to the canonical access site.

---

## Property (`properties`) columns

| Column | READERS | WRITERS |
|--------|---------|---------|
| `complex_name` | Presentations — display address (`AnalysisDataService.php:178-185` → `Property::buildDisplayAddress`) | **Presentations — generator backfill** (`PresentationGeneratorService.php:428`, audited `:443-461`); manual edit (`PropertyController.php:401,404`) ⚠ |
| `unit_number` | Presentations — display address (`AnalysisDataService.php:178-185`) | **Presentations — generator backfill** (`PresentationGeneratorService.php:432`); manual edit (`PropertyController.php`) ⚠ |
| `condition_level_id` | Presentations — live condition uplift (`ConditionAdjustmentService.php:76`) | *(property edit — TODO)* |
| `title_type` | Presentations — sectional grouping / holding-cost (`AnalysisDataService.php:64,1054`) | *(property edit — TODO)* |
| `latitude` / `longitude` | Presentations — comp geocoding / map (`PresentationGeneratorService.php:142-143`) | Presentations — GPS backfill (`PropertyGeoBackfillService` via `:197-213`) |
| `cma_gps_lat` / `cma_gps_lng` | *(Match-or-Create GPS strategy — TODO)* | Presentations — `PropertyCmaPropagationService::buildUpdates` (`:374-424`) |
| `erf_number` | *(TODO)* | Presentations — `PropertyCmaPropagationService` (`:374-424`) |
| `municipal_valuation` / `_year` | *(TODO)* | Presentations — `PropertyCmaPropagationService` (`:374-424`) |
| `title_deed_number` | *(TODO)* | Presentations — `PropertyCmaPropagationService` (`:374-424`) |
| `last_cma_at` | *(TODO)* | Presentations — `PropertyCmaPropagationService` (`:99`) |
| `property_type`, `beds`, `baths`, `garages`, `erf_size_m2`, `size_m2`, `price`, `rates_taxes`, `levy`, `branch_id`, `is_demo` | Presentations — `hydrateFromProperty` (`:483-509`) | *(property edit — TODO)* |
| `street_number` / `street_name` / `suburb` / legacy `address` | Presentations — subject address + `SubjectReportResolver` matching | *(property edit — TODO)* |

---

## Presentation tables

| Table | READERS | WRITERS |
|-------|---------|---------|
| `presentations` | Presentations (lifecycle) | Presentations — `PresentationGeneratorService.php:101-127` |
| `presentation_versions` | Presentations — PDF/public link (frozen `snapshot_payload`) | Presentations — compile `:302`; freeze at confirm (`PresentationController.php:478-483`) |
| `presentation_snapshots` | Presentations — analysis display (`computed_json`) | Presentations — `PresentationGeneratorService.php:288-299` |
| `presentation_sold_comps` | Presentations — CMA compute pool (`AnalysisDataService.php:31`, `CmaComputeService`) | Presentations — `MicSnapshotHydrator.php:74-79,174,439` (delete+recreate per run) |
| `presentation_active_listings` | Presentations — active-listings section (`AnalysisDataService.php:32`) | Presentations — `MicSnapshotHydrator.php:216` |
| `presentation_fields` | Presentations — parsed CMA-Info benchmark (`AnalysisDataService.php:30,516-518`) | Presentations — `MicSnapshotHydrator.php:1027` |

---

## Market-report / intelligence tables

| Table / column | READERS | WRITERS |
|----------------|---------|---------|
| `market_reports` (`subject_address`, `subject_scheme_name`, `subject_section_number`, `source_suburb`) | Presentations — `SubjectReportResolver` (`:74-121`), backfill (`PresentationGeneratorService.php:412-419`) | *(CMA import — TODO)* |
| `market_report_comp_rows` | Presentations — MIC hydrator source for sold comps | *(CMA import — TODO)* |
| `portal_captures` | Presentations — parsed data (`AnalysisDataService.php:47-49`) | *(Prospecting / capture — TODO)* |
| `PropertySettingItem` (condition levels) | Presentations — condition labels (`ConditionAdjustmentService.php:67-71`) | *(settings — TODO)* |

---

## Agency settings (`agencies` columns)

| Setting | Default | READERS | WRITERS |
|---------|---------|---------|---------|
| `cma_hide_display_outliers` | `true` | Presentations (`AnalysisDataService.php:119`) | Settings UI (`CoreXSettingsController`) |
| `cma_compute_iqr_multiplier` | `1.50` | Presentations (`CmaComputeService.php:98`) | Settings UI |
| `cma_compute_recency_months` | `36` | Presentations (`CmaComputeService.php:97`) | Settings UI |
| `cma_band_lower_pct` / `cma_band_upper_pct` | `7%` | Presentations (`CmaComputeService.php:112-113`) | Settings UI |
| `range_lower_pct` / `range_upper_pct` | textbook quartiles | Presentations (`CmaComputeService.php:103-104`) | Settings UI |
| `competitor_stock_default_display_count` | `10` | Presentations (`AnalysisDataService.php:931`) | Settings UI |
| `competitor_stock_default_price_tolerance_pct` | `20` | Presentations (`CompetitorStockMatchService`) | Settings UI |
| `competitor_stock_min_score` | `50` | Presentations | Settings UI |
| `ss_show_complex_section` | `true` | Presentations (`AnalysisDataService.php:110`) | Settings UI |
| `presentations_default_comp_scope` / `_radius_m` | `radius_all` / `1000` | Presentations (`PresentationGeneratorService.php:136-140`) | Settings UI |
| `presentations_default_rates/insurance/utilities/garden/pool/security_zar` | `800/200/1200/800/600/1500` | Presentations holding cost (`AnalysisDataService.php:1083-1094`) | Settings UI |
| `presentations_default_opportunity_cost_pct` | `8` | Presentations (`AnalysisDataService.php:1083`) | Settings UI |
| `presentations_default_show_*` (9 toggles) | bools | Presentations section gates | Settings UI |
| `mic_match_threshold` / `mic_price_band_pct` | *(AT-75)* | *(Market Intelligence Centre — TODO)* | Settings UI |

> Note: **no agency setting is keyed `mic_`** in the Presentations path. `mic_snapshot_v1` is a parser
> source-tag; `mic.regenerate_brief` is a permission. The `mic_match_threshold`/`mic_price_band_pct` knobs
> belong to the MIC matching feature (documented separately).

---

## config / feature flags

| Flag | READERS | Notes |
|------|---------|-------|
| `config/features.php` `presentations` (`:4`) | Presentations | master feature gate |
| `config/features.php` `presentation_pdf_v1` (`:11`) | Presentations PDF (`PresentationPdfController.php:42,81`) | gates PDF download |
| `config/features.php` `presentation_blueprint` (`:5`) | Presentations | — |
| `config/presentations.php` (`:19-33`) | `PresentationPdfService` (pagination) | pagination tuning only |

---

## Domain events

| Event | EMITTED BY | LISTENERS |
|-------|-----------|-----------|
| `PresentationGenerated` | Presentations — `PresentationGeneratorService.php:348` | *(see `.ai/specs/corex-domain-events-spec.md` — TODO to enumerate)* |
