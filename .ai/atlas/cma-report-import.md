# Atlas — CMA / Suburb Report Import

> **Status: DONE** · Last verified: 2026-06-22
> Pillar: **Property** (subject + comps). The upstream that Presentations depends on for subject reports,
> sold comps, and vicinity benchmarks. Companion: `.ai/specs/cma-import-freeland-investigation.md`.
> Cited audits: AT-82 (parsed benchmark bypass), AT-78 (SubjectReportResolver street-needle), AT-58 (re-import soft-delete).

---

## 1. WHAT IT DOES

Agents upload CMA / suburb-analysis reports (PDFs, typically from CmaInfo). CoreX parses them into
structured `market_reports` (subject metadata) + `market_report_comp_rows` (sold comps, active listings,
scheme owners) + `market_data_points` (benchmark scalars). Presentations then resolve "this property's own
report" from `market_reports`, pull sold comps from `market_report_comp_rows`, and surface parsed
vicinity-range scalars as the internal `cma_info_benchmark`.

**Critical: two separate parse pipelines both produce `cma.lower/middle/upper_range` presentation fields,
sharing no code:**
- **Pipeline A — standalone MIC report importer** (`/corex/market-intelligence/reports`): PDF →
  `MarketReportParserRegistry` → `ParseMarketReportJob` → `market_reports` / `market_report_comp_rows` /
  `market_data_points`. The `cma.*` presentation scalars are written **later** by `MicSnapshotHydrator`
  from the `market_data_points` `cma_value_*` rows.
- **Pipeline B — in-presentation upload** (`UploadExtractionService` + `DocumentExtractor`): PDF →
  `DocumentExtractor::parseCma()` → writes `cma.lower/middle/upper_range` **directly** into
  `presentation_fields`. **This is the AT-82 parsed-scalar bypass.**

`AnalysisDataService` reads `cma.*` without knowing which pipeline wrote it.

---

## 2. ENTRY POINTS

### Standalone MIC report importer — group `market-intelligence.reports.*` (perm `mic.upload_reports`)
`routes/web.php:3291-3335` (prefix/name `:3300-3301`, route-model bind `withTrashed()` `:3303-3307`):
`index` `:3309`, `create` (upload UI) `:3310`, `store` (POST) `:3311`, `bulk-import` `:3314-3315`,
`parser-dashboard` `:3316`, `show` `:3317`, `destroy` (archive) `:3318`, `spot-check` `:3319`,
`discrepancies` `:3320`, `reparse` `:3327`, `restore` (perm `mic.restore_reports`) `:3332-3334`.
Controller: `app/Http/Controllers/CoreX/MarketReportController.php` (class `:32`).

**Second entry point — in-presentation generate-modal upload** shares Pipeline-A ingest via
`app/Services/MarketReports/MarketReportIngestService.php:40` (`ingest()`, single path backing both,
docblock `:14-28`).

**Not this flow:** `admin.importer.*` (`web.php:475-509`, `Admin\ImporterController`) is the agent/listing
CSV onboarding importer — ruled out.

Blade: `resources/views/corex/market-intelligence/reports/{create,bulk-import,index,show,discrepancies,parser-dashboard}.blade.php`.

---

## 3. THE FLOW

### Upload → store → parse (synchronous)
`MarketReportController::store` (`:95-175`): validate PDF ≤20MB `:95-101` → SHA-256 hash `:107` →
restore-on-rehash dedup (`UNIQUE(agency_id,file_hash)`, `withTrashed()`) `:115-133` → store file
`market-reports/{agency}/{y}/{m}/{uuid}.pdf` `:135-146` → parser auto-detect `:148-157` → row insert
`:159-173` → **`ParseMarketReportJob::dispatchSync()` `:175`** (synchronous). Bulk variant `:219-350`.

### Parser registry / detection
`app/Services/MarketReports/MarketReportParserRegistry.php` — `V1_PARSERS` `:31-41` (six CmaInfo parsers +
`GenericFallbackParser` last); `detect()` picks highest `canParse()->score` `:56-71`. Base
`AbstractCmaInfoParser.php`: `extractText()` (pdftotext `-layout`) `:44-80`, `parsePriceBounded()`
(50k–50M outlier guard) `:153-176`, `looksLikeCmaInfo()` signature gate `:211-234`.

### Parse orchestration — where data is persisted: `app/Jobs/MarketReports/ParseMarketReportJob.php`
1. Resolve parser (by `report_type_id` else detect, write back) `:78-90`.
2. `$parser->parse()` → DTO `:92`.
3. **Subject metadata write-back** (allow-list incl. `subject_address`, `subject_scheme_name`,
   `subject_section_number`, GPS, extent, `source_suburb/town`) `:98-122`.
4. `market_data_points` writes `:124-148`; `market_report_comp_rows` writes `:151-164`.
5. Suburb backfill on comp rows `:166-181`; scheme owners `:184-208`.
6. **Address back-propagation through `TrackedPropertyMatchOrCreateService`** (`source_type='cmainfo'`)
   `:211-230` (this is how report parsing feeds `tracked_properties` — see `prospecting-tracked-properties.md`).
7. Final status + `raw_extracted_json` + `MarketReportParsed` event `:232-240`; GPS backfill + spot-check
   dispatch (unless `auto_approve`) `:247-258`.

### Extraction detail (`CmaInfoPropertyValuationParser.php`)
- **Subject** `extractSubject()` `:226-293`: address `:231-233`, scheme name+suburb `:236-246`, scheme
  number→ss `:248-251`, **section number `:253-255`**, extent `:257-259`, GPS `:262-268`, municipal
  valuation `:271-279`, sale price/date `:282-290`.
- **Sold comps** `extractCmaCompRows()` `:319-441` (sale_price via `parsePriceBounded` `:382`),
  `extractSoldWithDistance()` `:448-501` (distance anchor `:462`), active listings `:509-552`; canonical
  payload `buildCompRow()` `:597-625`.
- **Vicinity benchmark scalars** (`CmaInfoVicinitySaleParser.php`): Lower/Middle/Upper Range extraction
  `:202-220` → written as `market_data_points` `metric_key` `cma_value_lower/middle/upper` `:207`,
  `cma_value_average` `:221-232`. *(The vicinity parser does NOT write `vicinity.*` presentation fields.)*

### Pipeline B scalar (AT-82 source) — `app/Support/Presentation/DocumentExtractor.php`
`parseCma()` `:115`, scalar matches `cma.lower/middle/upper_range` `:120-122`, vicinity equivalents
`:437-439`; invoked from `UploadExtractionService.php:51,271,410`; dispatched into `cma` at `:55`.

---

## 4. DATA IT WRITES

### `market_reports` (`app/Models/MarketReports/MarketReport.php`, `SoftDeletes` + `BelongsToAgency` `:26`)
Fillable `:42-56`: `file_path/name/hash`, `source_suburb/town`, `report_date`, `parse_status`,
`parser_version`, `raw_extracted_json`, `data_points_count`, `spot_check_status/results`, **`subject_address`,
`subject_scheme_name`, `subject_section_number`**, `subject_latitude/longitude`, `subject_extent_m2`,
`radius_metres`, `is_demo`. Status enums `:30-40`.

### `market_report_comp_rows` (migration `2026_05_23_120002_...`, model `MarketReportCompRow.php` `SoftDeletes` `:20`)
Key cols `:26-73`: `row_index`, `row_type` enum(subject/comp/listing/owner) `:32`, `scheme_name`,
`section_number`, **`address` `:42`**, `suburb_normalised`, `property_type`, **`extent_m2` `:47`**,
**`sale_date` `:50`**, **`sale_price` `:51`**, `estimated_value`, `r_per_m2`, `list_price`,
`municipal_valuation[_year]`, `condition`, **`distance_to_subject_m` `:66`**, `latitude/longitude`,
`raw_row_json`. **No `erf` column** — erf lives in `raw_row_json`.

### `presentation_fields` `cma.*` — two paths
- Pipeline A: `MicSnapshotHydrator::hydrateCmaMetrics()` (`MicSnapshotHydrator.php:953`) resolves
  same-subject report by address `:960-984`, reads `market_data_points` `cma_value_*` `:971-994`, upserts
  `cma.lower/middle/upper_range` `:996-1002`.
- Pipeline B: `UploadExtractionService::propagateFields()` `:332-390` upserts every `DocumentExtractor` key
  (incl. `cma.*`) at `confidence=0.90`, `final_value = override_value ?? extracted_value` `:356-372`.

### Auditing & re-import (AT-58)
**No audit-log trait/observer** on `MarketReport`/`MarketReportCompRow` — audit preservation is structural
via **soft-deletes + nullOnDelete FKs**. Re-import dedup restores archived rows + re-parses
(`MarketReportController.php:121-133`, bulk `:244-269`, `MarketReportIngestService.php:57-69`).
`resetReportForReparse()` **soft-deletes** prior comp rows/data points/discrepancies (never force-deletes)
`:433-456` (rationale `:419-432`). `reparse()` `:403-417`, `destroy()` soft-deletes `:352-366`, `restore()`
`:374-386`. FK cascade fix so archiving the parent preserves child audit rows (rows + discrepancies →
`nullable + nullOnDelete`) `2026_06_16_122000_fix_market_report_cascade_to_preserve_audit.php`. All readers
filter `whereNull('deleted_at')` (e.g. `MicSnapshotHydrator.php:578,972`).

---

## 5. DATA READ BY PRESENTATIONS (the consumption side)

- **`SubjectReportResolver` reads `market_reports.subject_address`** (+ `source_suburb`) —
  `app/Support/Presentations/SubjectReportResolver.php::resolveReportIds()` `:74-121`; required street-needle
  `LIKE` `:103-107`; suburb confirm-only `:111-117`; needle extraction `:46-67`.
- **`MicSnapshotHydrator` reads `market_report_comp_rows`** for sold comps — `collectMatchedRows()`
  `:571-608` (table `:577`, soft-delete + row_type + is_demo filters `:578-580`, sold filter
  `whereNotNull(sale_date/sale_price)` `:588`, same-subject `whereIn(market_report_id, subjectReportIds)`
  `:589-596`); calls `SubjectReportResolver::resolveReportIds()` `:507-511`.
- **`AnalysisDataService` reads `presentation_fields` `cma.lower/middle/upper_range`** — `:516-518` (also
  `:1190-1192`); surfaced as `cma_info_benchmark` `:648-653` — **internal/review-screen only, never on the
  seller PDF** (`:643-647`). Vicinity equivalents `:598-600`.
- Other `cma.*` readers: `PropertyIntelligenceService.php:405-407`, `AiSummaryService.php:108-110`,
  `CmaComputeService.php:85`, `PresentationPdfService.php:696`.

---

## 6. AGENCY SETTINGS / CONFIG

There is **no agency setting governing the parsing itself** — parser selection is fully automatic
(`MarketReportParserRegistry::detect()`). Parsing-adjacent config: the bounded-price sanity window
(50k–50M) hard-coded in `AbstractCmaInfoParser::parsePriceBounded()` `:153-159`; per-report-type
`auto_approve` (skips AI spot-check) consumed `ParseMarketReportJob.php:253`. The CoreX-computed band/IQR
settings (`cma_band_*`, `cma_compute_iqr_multiplier`) govern the *valuation*, not the import — see
`presentations.md` §8.

---

## 7. KNOWN FRAGILITIES

1. **Parsed vicinity/CMA benchmark bypasses outlier exclusion (AT-82 — OPEN).** `DocumentExtractor::parseCma()`
   extracts `cma.lower/middle/upper_range` (`:120-122`) and `vicinity.*` (`:437-439`) as **verbatim regex
   captures of the source PDF footer** — no IQR/recency cleaning, no comp-pool recomputation.
   `propagateFields()` stamps them at confidence 0.90 (`:356-372`); `AnalysisDataService` surfaces them as
   `cma_info_benchmark`. CoreX's outlier machinery (`MicSnapshotHydrator` OutlierGuard `:99,168,210`;
   `CmaComputeService` IQR `:121-152`) only touches the **comp-pool-derived** tile values — never these
   parsed scalars. So a provider-inflated R4.6m upper flows through untouched. Mitigation: the benchmark is
   internal/review-only, never on the seller PDF (`AnalysisDataService.php:643-647`). Remediation (separate
   spec): stop surfacing when known-inflated, or replace with a CoreX-computed vicinity range. See AT-82 §D.
2. **SubjectReportResolver requires a street-needle (post-AT-78).** `SubjectReportResolver.php:95-97`
   returns `[]` when no ≥8-char street fragment exists; suburb alone can never select (`:108-117`). A
   suburb-only / short-token `property_address` resolves **zero** same-subject reports → the hydrator falls
   back to the date-windowed suburb/Haversine branch (`MicSnapshotHydrator.php:589-596`) and analyst-vetted
   comps disappear. The `LIKE '%needle%'` (`:105`) is also OCR/casing-sensitive. **Divergence hazard:**
   `hydrateCmaMetrics` uses a *looser* bare `LIKE '%subjectAddr%'` (`:962-968`) with no needle requirement —
   so the comp-pool resolver and the cma-scalar resolver can pick **different** "subject" reports.
3. **Re-import / re-parse.** Re-parse soft-deletes prior rows then re-appends with new PKs (`:433-456`);
   readers' `whereNull('deleted_at')` governs visibility. Hazards: (a) re-parse **clears `report_type_id`**
   to force re-detection (`:438-445`) — an un-registered parser can re-detect as `GenericFallbackParser`
   and lose all facts; (b) re-parse hard-fails if the original PDF is missing (`:406-411`); (c) an archived
   report's comp rows keep `market_report_id` (FK `nullOnDelete`) — the comp-row's own `deleted_at`, not the
   parent's trashed state, governs hydrator visibility (`:578`).
4. **Secondary.** Parsing needs `pdftotext` on PATH (`AbstractCmaInfoParser.php:58-80`) — absence →
   `GenericFallbackParser` with 0 facts. Parse is **synchronous** (`dispatchSync` `:175`) → a large/slow PDF
   blocks the upload request. Comp-row writes are failure-isolated per row (`:151-164`) → a partial parse
   can silently land an incomplete comp set.

---

## Key file:line index
- `app/Http/Controllers/CoreX/MarketReportController.php` — `:95-175` store, `:219-350` bulk, `:352-456` destroy/restore/reparse/reset.
- `app/Services/MarketReports/MarketReportParserRegistry.php` `:31-71`; `MarketReportIngestService.php:14-101`;
  `AbstractCmaInfoParser.php:44-176,211-234`; `CmaInfoPropertyValuationParser.php:226-293,319-625`;
  `CmaInfoVicinitySaleParser.php:202-244`.
- `app/Jobs/MarketReports/ParseMarketReportJob.php` — `:78-258`.
- `app/Support/Presentation/DocumentExtractor.php:115,120-122,437-439`; `UploadExtractionService.php:51,332-390`.
- Consumption: `SubjectReportResolver.php:74-121`, `MicSnapshotHydrator.php:507-608,953-1002`,
  `AnalysisDataService.php:516-518,643-653`.
- Models/migrations: `MarketReport.php:26-56`, `MarketReportCompRow.php`, `2026_05_23_120002_create_market_report_comp_rows_table.php`, `2026_06_16_122000_fix_market_report_cascade_to_preserve_audit.php`.
- Audits: `.ai/audits/AT-82-cma-condition-double-apply-investigation-2026-06-21.md`, `.ai/audits/AT-78-presentation-comp-wrong-address-2026-06-21.md`.
