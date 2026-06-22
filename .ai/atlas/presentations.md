# Atlas — Presentations (incl. CMA generation)

> **Status: DONE** · Last verified: 2026-06-22 (post AT-78 + AT-79 deploy)
> Pillars: **Property** (subject), reads Contact (link), writes back to Property (sectional identity backfill).
> Companion specs: `.ai/specs/presentations.md`, `.ai/specs/at22-presentation-quality.md`,
> `.ai/specs/cma-property-pillar-binding.md`. Source audits cited inline: AT-78, AT-82.

---

## 1. WHAT IT DOES

The Presentation is HFC's **seller-facing CMA + listing pitch document**. An agent clicks one button on a
Property and CoreX assembles a full comparative market analysis: it runs CoreX's own CMA valuation engine
over the sold-comp pool, pulls the property's market-intelligence snapshot (active listings, competitor
stock, vicinity benchmarks), estimates holding costs, drafts an executive summary, and freezes the result
into a versioned, published PDF the seller sees.

It has a **two-stage lifecycle**: a **draft** (analysis screen — live, editable, recomputes against the
property's *current* state every time) and a **published** version (frozen snapshot — what the seller's
PDF serves; condition %, comp whitelist, and computed numbers are locked at publish time so later setting
or property drift can never silently change a document already sent).

It is the system's primary **write-back-to-the-pillar** feature: generation enriches the subject Property
(GPS, sectional identity, CMA propagation) — which is also the source of its sharpest fragilities (§9).

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
| Route | Handler | Name | What |
|-------|---------|------|------|
| `POST /corex/properties/{property}/generate-presentation` `:2138` | `PresentationGeneratorController::generate` | `corex.properties.generate-presentation` | **Primary "Generate Presentation" button** |
| `GET /corex/properties/{property}/presentation-coverage` `:2140` | `PresentationGeneratorController::coverage` | — | coverage badge |
| `POST /presentations/{presentation}/analysis/run` `:2612` | `PresentationController::runAnalysis` | `presentations.analysis.run` | recompute draft analysis |
| `POST /presentations/{presentation}/analysis/confirm` `:2615` | `PresentationController::confirmAndGenerate` | `presentations.analysis.confirm` | **PUBLISH / FREEZE point** |
| `POST /presentations/{presentation}/analysis/reopen` `:2619` | `PresentationController::reopenForEditing` | `presentations.analysis.reopen` | unlock confirmed → editable |
| `GET /presentations` … `:2504-2512` | `PresentationController` | `presentations.index` etc. | index/create/store/show/edit/update |
| review surface `:2518-2576` | `PresentationReviewController` | toggleComp/setComps/browseComps/setCondition (`:2559`)/toggleSection | live review interactions |
| `GET /presentations/{presentation}/versions/{version}/pdf` `:2705` | `PresentationPdfController::download` | — | PDF download |
| `.../complete-pack` `:2707` | `PresentationPdfController::downloadCompletePack` | — | full pack |
| `/p/{token}` family `:72-97` | `PublicPresentationController` | — | **public seller share link (no auth)** |
| settings `:1866-1868` | `CoreXSettingsController` | — | `POST /settings/presentations`, `.../sections` |

### Controllers
`app/Http/Controllers/Presentation/`: `PresentationGeneratorController`, `PresentationController`,
`PresentationReviewController`, `PresentationPdfController`, `PresentationVersionController`,
`PublicPresentationController`, + snapshot/delivery/outcome/analytics controllers.

### UI triggers (Blade)
- **Generate button:** `resources/views/corex/properties/show.blade.php:232`
  (`generateUrl: route('corex.properties.generate-presentation', $property)`); label at `:269`
  (`'Generating…' : 'Generate Presentation'`).
- **Confirm & Generate / Reopen:** `resources/views/presentations/analysis.blade.php:144-147` (confirm form
  → `presentations.analysis.confirm`), `:130` (reopen → `presentations.analysis.reopen`), confirmed-state
  gating `:110-113`.

### Nav
Sidebar `presentations.index`; plus analytics/outcomes/refresh-requests under
`corex.presentations.*` and the `tools.cma` tool entry.

---

## 3. THE FLOW — "Generate" → published PDF

Orchestrator: **`PresentationGeneratorService::generateForProperty`**
(`app/Services/Presentations/PresentationGeneratorService.php:56-352`, whole body wrapped in
`DB::transaction`). Constructor-injected collaborators at `:40-49`.

| # | Step | file:line | What happens |
|---|------|-----------|--------------|
| 1 | Guard + upsert Presentation | `:63-127` | Rejects blank `property_type` (`:72-76`); `hydrateFromProperty` (`:477-510`) copies subject fields onto the presentation; scope/radius override resolved presentation → agency default (`:135-140`) |
| 2 | **MarketAnalytics run** (persist) | `:129-182` | Builds `MarketAnalyticsInput` (`:145-162`); runs the MIC analytics engine, persists a run row |
| 3 | **SaleProbability run** (persist) | `:184-195` | Sale-probability model run |
| 4 | **GPS backfill (subject)** | `:197-213` | `PropertyGeoBackfillService::backfillProperty` — geocodes the subject if missing coords |
| 5 | **Comp-row GPS backfill** | `:215-247` | `SubjectReportResolver::resolveReportIds` (`:226`) + `CompRowGeocoder::backfillForSubject` |
| 6 | **Subject sectional-identity backfill** | `:249-267` → `backfillSubjectSectionalIdentity` `:367-470` | **WRITES `property.complex_name`/`unit_number`** from the matched market report (see §5, §9) |
| 7 | **MIC snapshot hydration** | `:269-275` | `MicSnapshotHydrator::hydrateForPresentation` populates `presentation_sold_comps` / `presentation_active_listings` / `presentation_fields` |
| 8 | **Holding-cost auto-fill** | `:277-282` | `HoldingCostEstimator::estimateAndPersist` |
| 9 | **AnalysisDataService::compile** | `:284-286` | Builds all display sections + runs CMA compute (see below) |
| 10 | **PresentationSnapshot::create** | `:288-299` | Stores `computed_json`, links BOTH engine run IDs |
| 11 | **PresentationCompilerService::compile → PresentationVersion** | `:301-323` | Stamps `review_status = REVIEW_AWAITING` (`:321-322`) |
| 12 | **Defer Executive Summary** | `:325-345` | Cleared on fresh draft; AI summary generated only at confirm |
| 13 | **`PresentationGenerated::dispatch`** | `:348` | Domain event (cross-pillar reactivity) |

### The CMA compute path (inside step 9)

`AnalysisDataService::compile` (`AnalysisDataService.php:28-138`):
1. Reads fields / soldComps / activeListings (`:30-33`), agent selections (`:36-38`).
2. **Resolves condition context** (`:44` → `resolveConditionContext` `:479-507`) — draft live vs published frozen (§ below).
3. Applies the comp whitelist `included_comp_ids_json` (`:81-87`).
4. Calls **`CmaComputeService::compute`** (`:89-91`) — CoreX's independent CMA engine
   (`CmaComputeService.php:75-180`): reads agency cleaning controls (`:96-113`), `cleanPool` (`:205-267`
   = recency cut → IQR fences `:290-329`, min-n fallback ladder), returns `pool_stats`,
   `method_median`/`method_mean`/`method_rm2_extent`, and `outlier_excluded_comp_ids` (`:161-179`).
5. `compileCmaValuation` (`:509-661`): **baseline = `method_median.raw`** (the un-condition-adjusted comp
   median, `:544`); **condition ×(1+pct/100) applied ONCE at `:574-578`**; band derived from the *adjusted*
   middle ± agency band pct (`:586-594`).
6. Assembles display sections: `compileComparableSales` (`:230-379`, same-scheme grouping; hides valuation
   outliers per agency toggle `:118-119`), `compileCompetitorStock` (`:920-1043`), `compileHoldingCost`
   (`:1045-1162`), `cma_info_benchmark` from parsed `presentation_fields` (`:516-518`).

### Draft vs Published — the freeze model

**`AnalysisDataService::resolveConditionContext` (`:479-507`)** is the switch:
- No version → baseline (`:481-483`).
- **Published + frozen pct** (`review_status === REVIEW_PUBLISHED && condition_adjustment_pct !== null`):
  returns the **version's frozen** `condition_adjustment_pct` (`source = version_snapshot`, `:486-493`).
  Defends already-sent PDFs against later property/setting drift.
- **Draft / live** (`:495-506`): delegates to `ConditionAdjustmentService::resolveLive`
  (`ConditionAdjustmentService.php:57-92`) — reads version-override `condition_level_id` (`:66-73`) →
  **property's current `condition_level_id`** (`:76-84`, `source = property_default`) → none.

**The freeze itself** — `PresentationController::confirmAndGenerate` (`:457-495`): `resolveLive` +
`ConditionAdjustmentService::snapshotOnVersion` freeze condition onto the version (`:471-473`); recompiles
and writes `snapshot_payload` + `snapshot_taken_at`; sets `review_status = REVIEW_PUBLISHED`, `published_at`
(`:478-483`); generates the Executive Summary from confirmed numbers (`:489-491`).

**Reopen** — `reopenForEditing` (`:508-520`): flips `review_status → REVIEW_IN_ANALYSIS` but **keeps**
`snapshot_payload`/`published_at`, so the seller keeps seeing the prior confirmed snapshot until the next
confirm overwrites it.

**PDF** — `PresentationPdfService::generate(version)` (`:27`) → `buildHtml` (`:495`) reads from
`snapshot_payload` with a live-fallback warning (`:590-594`). For a published version the recompile still
yields frozen numbers because `resolveConditionContext` feeds it the frozen pct.

---

## 4. DATA IT READS

### Property (`properties`) columns
`property_type`, `title`, `suburb`, `beds`, `baths`, `garages`, `erf_size_m2`, `size_m2`, `price`,
`rates_taxes`, `levy`, `branch_id`, `latitude`/`longitude`, `is_demo`, `complex_name`/`unit_number`
(`AnalysisDataService.php:178-185`), and critically **`condition_level_id`**
(`ConditionAdjustmentService.php:76`) and **`title_type`** (`AnalysisDataService.php:64`, `:1054`).
Hydrated in `PresentationGeneratorService::hydrateFromProperty` (`:483-509`).

### Comps / market-report tables
- `market_reports` — `subject_address`, `subject_scheme_name`, `subject_section_number`, `source_suburb`
  (via `SubjectReportResolver`, `PresentationGeneratorService.php:412-419`).
- `market_report_comp_rows` — source rows for the MIC hydrator.
- `presentation_sold_comps` (read `AnalysisDataService.php:31`; the CMA compute pool),
  `presentation_active_listings` (`:32`), `presentation_fields` (`:30`, incl. parsed `cma.lower/middle/upper_range`).
- `portal_captures` (parsed) — `AnalysisDataService.php:47-49`.
- `PropertySettingItem` — condition-level definitions/labels (`ConditionAdjustmentService.php:67-71`).

### Agency settings — see §8.

---

## 5. DATA IT WRITES

| Target | file:line | Notes |
|--------|-----------|-------|
| `presentations` (create/fill, scope override) | `PresentationGeneratorService.php:101-118`, `:122-127` | the presentation record |
| `presentation_snapshots` (`computed_json`, run IDs) | `:288-299` | analysis snapshot |
| `presentation_versions` | compiled `:302`, stamped `:308-323`, `:336-345`; frozen at confirm `PresentationController.php:478-483` | version + frozen `snapshot_payload`/`included_comp_ids_json`/`condition_adjustment_pct` |
| `presentation_sold_comps` / `presentation_active_listings` / `presentation_fields` | `MicSnapshotHydrator.php` — deletes prior `:74-79`, creates `:174`/`:439`/`:216`/`:1027` | MIC snapshot rows (regenerated each run) |
| **`properties.complex_name` / `properties.unit_number`** | **`PresentationGeneratorService.php:428` / `:432` / save `:438`** | **⚠ generator backfill — writes to the Property pillar (see §9).** Guarded: blank-only (`:369-373`), confident full-street match required (`:412-420`), never clobbers agent values. **Audited** via `PropertyAuditService::log` (`:443-461`, source `'presentation_generator'`) — this audit was added by AT-78; pre-AT-78 it was a silent `save()`. |

### Secondary write-back (not from the generator path)
`PropertyCmaPropagationService::propagateFromPresentation`
(`app/Services/Presentation/PropertyCmaPropagationService.php:53-103`) does
`DB::table('properties')->update($updates)` at `:103`. `buildUpdates` (`:374-424`) writes
`erf_number`, `municipal_valuation`, `municipal_valuation_year`, `cma_gps_lat/lng`, `title_deed_number`,
`last_cma_at` (`:99`) — **not** complex_name/unit_number. *(Trigger/caller — verify when documenting the
Property feature.)*

---

## 6. AFFECTS DOWNSTREAM

- **Writes to the Property pillar** — sectional identity (complex_name/unit_number) and, via the
  propagation service, erf/municipal-valuation/GPS/title-deed/last_cma_at. **Every feature that reads
  those Property columns inherits whatever the generator wrote.** Because the subject `display_address` is
  built from `complex_name`/`unit_number` (`AnalysisDataService.php:161-170` →
  `Property::buildDisplayAddress` `Property.php:398-452`), a wrong backfill changes the address shown
  everywhere the property appears — not just on this presentation (this is exactly the AT-78 symptom).
- **Freezes snapshots** — `confirmAndGenerate` writes a frozen `snapshot_payload`; the seller PDF and
  public share link (`/p/{token}`) serve that frozen copy. Downstream consumers of the published version
  see locked numbers until the next confirm.
- **Emits `PresentationGenerated`** (`:348`) — any listener in the domain-events catalogue reacts (see
  `.ai/specs/corex-domain-events-spec.md`).
- **MIC snapshot rows** (`presentation_sold_comps` etc.) are regenerated (prior rows deleted) on every run
  — anything pinning a specific comp row id across regenerations can go stale.

---

## 7. AFFECTED BY UPSTREAM

The presentation's output changes if any of these upstream inputs change:
- **Property address fields** (`street_number`/`street_name`/`suburb`, the legacy `address` column,
  `complex_name`/`unit_number`) — drive `SubjectReportResolver` matching and the displayed subject address.
  The empty legacy `address` column was the trigger for the AT-78 suburb-only borrow.
- **`property.condition_level_id`** — drives the live ×(1+pct/100) on a draft (e.g. `Excellent` = +20%).
  Change the condition and an unpublished draft revalues; a published version stays frozen.
- **Sold-comp data** (`presentation_sold_comps`, sourced from `market_report_comp_rows` via the MIC
  hydrator) — the CMA median, mean, IQR fences, and band all move with the pool.
- **Parsed CMA-Info benchmarks** (`presentation_fields` `cma.lower/middle/upper_range`) — surfaced as the
  vicinity range on the review screen (NOT the seller PDF); these are **parsed scalars from the uploaded
  source CMA**, so a provider-inflated source number flows straight through (AT-82 §D).
- **Suburb / market reports** (`market_reports`, `source_suburb`) — feed the resolver and comp geocoding.
- **Agency settings** (§8) — change a default and any *unpublished* draft recompiles with it; published
  versions are protected by the freeze.

---

## 8. AGENCY SETTINGS / CONFIG

All columns on `app/Models/Agency.php` (fillable + casts) unless noted.

| Setting | Default | Read at | Migration |
|---------|---------|---------|-----------|
| `cma_hide_display_outliers` | `true` | `AnalysisDataService.php:119` | `2026_06_22_090000_add_cma_hide_display_outliers_to_agencies.php` |
| `cma_compute_iqr_multiplier` | `1.50` | `CmaComputeService.php:98` (const fallback `DEFAULT_IQR_MULTIPLIER=1.5` `:59`) | `2026_06_17_160000_add_cma_compute_settings_to_agencies.php` |
| `cma_compute_recency_months` | `36` | `CmaComputeService.php:97` (const `DEFAULT_RECENCY_MONTHS=36` `:58`) | `2026_06_17_160000...` |
| `cma_band_lower_pct` / `cma_band_upper_pct` | `7%` | `CmaComputeService.php:112-113`; consumed `AnalysisDataService.php:586-594` | comp-selection migration |
| `range_lower_pct` / `range_upper_pct` | textbook quartiles | `CmaComputeService.php:103-104` | `2026_06_11_180000_add_comp_selection_settings_to_agencies.php` |
| `competitor_stock_default_display_count` | `10` | `AnalysisDataService.php:931` | `2026_06_19_120000_add_competitor_stock_settings_to_agencies.php` |
| `competitor_stock_default_price_tolerance_pct` | `20` | `CompetitorStockMatchService` | same migration |
| `competitor_stock_min_score` | `50` | — | same migration |
| `ss_show_complex_section` | `true` | `AnalysisDataService.php:110` | — |
| `presentations_default_comp_scope` / `_radius_m` | `radius_all` / `1000` | `PresentationGeneratorService.php:136-140` | — |
| `presentations_default_rates_per_million_zar` | `800` | `AnalysisDataService.php:1085,1093` | — |
| `presentations_default_insurance_per_million_zar` | `200` | `AnalysisDataService.php:1086,1094` | — |
| `presentations_default_utilities_zar` | `1200` | `AnalysisDataService.php:1087` | — |
| `presentations_default_garden/pool/security_zar` | `800/600/1500` | `AnalysisDataService.php:1088-1090` | — |
| `presentations_default_opportunity_cost_pct` | `8` | `AnalysisDataService.php:1083` | — |
| `presentations_default_show_*` (9 section toggles) | bools | section-enable gates | — |

### `mic_` is NOT a settings prefix
There are **no agency settings keyed `mic_`**. `mic_snapshot_v1` is the MIC hydrator's parser/source tag
(`MicSnapshotHydrator.php:51` `SOURCE_TAG`, `:225`, `:822-824`); `mic.regenerate_brief` is a **permission**
(`routes/web.php:3276`). (The MIC *matching/scoring* knobs — `mic_match_threshold`, `mic_price_band_pct`
— belong to the Market Intelligence Centre feature, AT-75, documented in `market-intelligence.md` TODO.)

### config files
- `config/features.php` — `presentations` (`:4`), `presentation_pdf_v1` (`:11`, gates PDF download in
  `PresentationPdfController.php:42,81`), `presentation_blueprint` (`:5`).
- `config/presentations.php` — pagination tuning only (`:19-33`). See the Presentation PDF pagination
  policy (one governing CSS policy R1-R6 in `PresentationPdfService` + seller `@media print`).
- PDF download gate: `PresentationPdfController.php:42,50` — 404 if `presentation_pdf_v1` off; redirects if
  `ai_summary_text` empty (not yet confirmed) — enforces draft→published at download time.

---

## 9. KNOWN FRAGILITIES

1. **Suburb-only `SubjectReportResolver` borrow (AT-78 — FIXED).** When the subject's legacy `address`
   column is empty, the resolver used to fall through to a **suburb-only OR match** and return *every*
   report in the suburb, then `backfillSubjectSectionalIdentity` stamped the most-recent sectional report's
   `complex_name`/`unit_number` onto an unrelated freehold (live case: property 557 got "Unit 14, NAUTILUS"
   from a neighbour's CMA). **Fix:** `SubjectReportResolver::resolveReportIds` now **requires** a street-needle
   match; suburb only confirms (`SubjectReportResolver.php:74-121`); backfill requires a confident full-street
   match and never clobbers (`PresentationGeneratorService.php:412-420`). See
   `.ai/audits/AT-78-presentation-comp-wrong-address-2026-06-21.md`.

2. **The silent backfill write (AT-78 — now AUDITED).** `backfillSubjectSectionalIdentity` writes to the
   **Property pillar** during a *presentation generate* — a surprising side effect (generating a document
   mutates the underlying property). Pre-AT-78 it was a bare `$property->save()` that bypassed the audit
   observer (left no `property_audit_log` row, no domain event). AT-78 routed it through
   `PropertyAuditService::log` (`:443-461`). **Still a fragility to know about:** generation is not
   read-only — it can change `complex_name`/`unit_number` on the property, which changes the address shown
   everywhere (§6). Treat any "property address changed and nobody edited it" report as a possible generate.

3. **Condition uplift on top of the comp median — doctrine question (AT-82 — OPEN, not a bug).** Condition
   is applied **once**, to the **raw sold-comp median** (`method_median.raw`), at
   `AnalysisDataService.php:574-578` — **not** double-applied to the asking price (Johan's "asking × 1.20"
   premise was false; the comp median merely equalled the asking to the rand for property 557). The real
   open question: applying +20% (`Excellent`, from `property.condition_level_id`) to an already
   top-of-market comp median yields a middle/band **above every recent comp** — i.e. is "+20% on the comp
   median" the right *doctrine* when the comps already reflect good-condition sales? This is a doctrine
   decision (e.g. condition relative to the comp set, or a cap at/above asking), **not** a
   remove-the-second-application fix — there is no second application. See
   `.ai/audits/AT-82-cma-condition-double-apply-investigation-2026-06-21.md` §B.

4. **The R13m vicinity benchmark is a PARSED scalar, not a CoreX computation (AT-82 §D — OPEN).** The
   review-screen vicinity range (`cma_info_benchmark`, e.g. lower 1.43m / mid 1.73m / upper 4.61m) is read
   verbatim from parsed `presentation_fields` `cma.lower/middle/upper_range`
   (`AnalysisDataService.php:516-518`) — i.e. parsed from the uploaded source CMA, which the provider had
   inflated with a R13,000,000 sale. **AT-78's outlier cut cannot touch it** (it acts only on CoreX's
   computed comp pool, not on parsed scalars). Mitigation in place: `cma_info_benchmark` is review-screen /
   internal only — **never rendered on the seller PDF** (`AnalysisDataService.php:643-647`; confirmed
   `PresentationPdfService` never reads it). Remediation options (separate spec): stop surfacing the parsed
   benchmark when known-inflated, or replace it with a CoreX-computed vicinity range that inherits the same
   IQR exclusion.

5. **Display-vs-valuation outlier parity (AT-78 FIX 3 — FIXED, agency-toggleable).** The valuation engine
   always IQR-excludes outliers (`CmaComputeService::cleanPool`), but the **rendered comps table** used to
   apply no cut, so a R13m row could sit in a R2.5m CMA table while being excluded from the valuation. Now
   gated by `cma_hide_display_outliers` (default true, `AnalysisDataService.php:118-119`); excluded count
   stays visible in `pool_stats.excluded_by_outlier` (not silent).

6. **Publishing a draft freezes whatever the property currently resolves (AT-82 §C — LIVE RISK).** The
   seller PDF is protected *only because* the latest published version froze its own condition. If an agent
   hits **Confirm & Generate** while `property.condition_level_id` resolves `Excellent`, publication freezes
   `condition_adjustment_pct = 20` and the seller then sees the higher middle/band. The over-valuation is
   "one Publish click away" — not structurally impossible. Watch when documenting the confirm UX.

7. **Regenerate creates a fresh draft; the published PDF still serves the last *published* version
   (AT-78 FIX 2).** `AnalysisDataService::compileSubjectProperty` now prefers the live `property_address`
   over the frozen extracted `subject.address` (so a corrected address shows on regenerate), but the agent
   must **re-confirm** the new draft for the seller PDF to update — intended AT-27 flow, easy to forget.

8. **MIC snapshot rows are deleted+recreated each generate** (`MicSnapshotHydrator.php:74-79`). Comp-row ids
   are not stable across regenerations; the published version's `included_comp_ids_json` whitelist is the
   durable record of what was in a sent document, not the live `presentation_sold_comps` rows.

---

## Key file:line index
- `app/Services/Presentations/PresentationGeneratorService.php` — `:56-352` orchestrator; `:367-470`
  `backfillSubjectSectionalIdentity` (writes `:428`/`:432`/`:438`, audit `:443-461`); `:477-510` hydrate.
- `app/Services/Presentations/AnalysisDataService.php` — `:28-138` compile; `:479-507` resolveConditionContext;
  `:509-661` compileCmaValuation (`:544` baseline, `:574-578` the ×condition, `:586-594` band, `:516-518`
  benchmark, `:643-647` "never on seller PDF"); `:230-379` compileComparableSales; `:118-119` display-outlier cut.
- `app/Services/Presentations/CmaComputeService.php` — `:75-180` compute; `:205-267` cleanPool; `:290-329`
  IQR; `:402-428` percentile; consts `:56-59`.
- `app/Support/Presentations/SubjectReportResolver.php` — `:74-121` resolveReportIds (street-needle required); `:46-67` needles.
- `app/Services/Presentations/ConditionAdjustmentService.php` — `:57-92` resolveLive; `:108`/`:145`
  applyToMiddle/applyToBand (**dormant**); `:192+` snapshotOnVersion.
- `app/Services/Presentations/PresentationPdfService.php` — `:27` generate; `:495` buildHtml; `:590-594`
  reads snapshot_payload w/ live fallback.
- `app/Http/Controllers/Presentation/PresentationController.php` — `:457-495` confirmAndGenerate (freeze);
  `:508-520` reopenForEditing.
- `app/Http/Controllers/Presentation/PresentationReviewController.php` — `:162-166` condition resolve, `:171-172` live compile.
- `app/Models/PresentationVersion.php` — `:79-87` status constants; casts `:48-50,70,74`.
- `resources/views/presentations/review.blade.php` — `:243` asking (separate), `:307` baseline=comp-median, `:309` adjusted.
- Audits: `.ai/audits/AT-78-presentation-comp-wrong-address-2026-06-21.md`, `.ai/audits/AT-82-cma-condition-double-apply-investigation-2026-06-21.md`.
