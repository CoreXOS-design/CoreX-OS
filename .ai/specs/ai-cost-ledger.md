# Unified AI Cost Ledger — Spec Delta

> **Status: DRAFT — awaiting review (Andre → Johan).** Not yet approved, not yet on `main`.
> Extends `.ai/specs/mic-complete-spec.md` §3.2.6, §3.2.8 (new), §4.8.
> Drafted: 2026-06-08 by Andre.

---

## 1. What this does and why

### The problem

`/admin/ai-usage` (MIC Phase B2 cost dashboard) and the per-agency AI budget caps
both compute spend from **one table — `ai_narrative_cache`**. That table is written
by exactly one class: `App\Services\AnthropicGateway`, which serves only the MIC
narrative surfaces (weekly brief, tile copy, listing tooltip, suburb pocket,
audit finding).

Every other Anthropic call in CoreX hits `https://api.anthropic.com/v1/messages`
**directly**, spends real money, and records **nothing** the dashboard or the budget
cap can see. Confirmed direct callers as of 2026-06-08:

| # | Service | Surface | Records cost today? |
|---|---|---|---|
| 1 | `App\Services\AI\IntentExtractionService` | **Mobile** Ellie voice → intent | No |
| 2 | `App\Services\AI\VisionRecognitionService` | Property image analysis (`AnalysePropertyImageJob`) | `cost_usd` on `property_image_analyses` only — invisible to dashboard |
| 3 | `App\Services\Docuperfect\ImporterAiService` | DocuPerfect template import | No |
| 4 | `App\Services\Docuperfect\AiFieldMapperService` | DocuPerfect field mapping | No |
| 5 | `App\Services\Docuperfect\ClaudeVisionParserService` | DocuPerfect vision parse | No |
| 6 | `App\Services\MarketingCopyService` | Listing marketing copy | No |
| 7 | `App\Services\Presentations\Evidence\AIExtractionService` | Presentation evidence extraction | No |

Consequences:
- **The dashboard lies.** Per-agency ZAR spend, daily burn, and "top consumers"
  undercount every agency that uses mobile voice, image AI, DocuPerfect AI, or
  marketing copy.
- **The budget cap is bypassable.** `Agency::aiBudgetUsedZar()` reads
  `ai_narrative_cache`, so `ai_budget_hard_cap_pct` can neither see nor stop spend
  on any of the seven surfaces above. An agency capped at R0 can still burn
  unlimited money through mobile voice.
- **`AnthropicGateway`'s own docblock claims** it is the "Single gateway for every
  AI call in CoreX." Reality contradicts the spec. This delta makes the claim true.

### The principle

Per CoreX Operating Principle #3 (*integration is the moat*): one ledger, every
call, no exceptions. Cost visibility and budget enforcement are only as honest as
their most-bypassed code path.

### Pillars

Reads from: **Agent** (`User` — who spent), **Agency** (budget owner).
Writes back: enriched spend attribution per agent/agency/surface — feeds the
existing MIC cost dashboard and budget-enforcement events.

---

## 2. Design decision — append-only ledger, not a column on the cache

We introduce a **new append-only table `ai_usage_events`** as the single cost sink,
rather than widening `ai_narrative_cache`. Reasons:

1. **`ai_narrative_cache` is a cache, not a ledger.** It is written with
   `updateOrCreate(['cache_key' => …])` — re-running a key **overwrites** the prior
   row. It holds "latest row per cache_key", not a complete history. A financial
   ledger must be append-only and lossless: one row per API call, forever.
2. **It fixes the cache-hit-rate heuristic.** `AICostAggregator::cacheHitRate()`
   today admits it cannot count hits vs misses and approximates from
   `agent_activity_events`. With an append-only ledger that logs `cache_hit`
   per call, hit rate becomes an exact ratio.
3. **Most new sources aren't cacheable narratives.** Voice intent, vision parse,
   and field-mapping are one-shot transactional calls with no `cache_key`,
   `narrative_type`, or `output_text` to cache. Forcing them through the cache
   table's shape is the wrong abstraction.

`ai_narrative_cache` stays exactly as-is for its caching job. It simply stops being
the source of truth for *cost*. The gateway writes **both**: the cache row (as now)
**and** a ledger row (new).

---

## 3. Data model

### 3.2.8 `ai_usage_events` (new, append-only)

Every successful or degraded Anthropic call writes exactly one row here.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, nullable, indexed | Null = global/system call (e.g. cross-agency brief). Tenant-scoped via `AgencyScope`. |
| `user_id` | FK → users, nullable, indexed | The agent who triggered it, when resolvable (voice, marketing copy). Null for queued/system jobs. |
| `source` | string(40), indexed | Stable enum-ish key — see §3.1 source registry. |
| `surface_ref` | string(120) nullable | Free-form correlation handle (`cache_key`, `analysis_id`, `template_id`, …) for drill-down. |
| `model` | string(60) | Resolved model id, e.g. `claude-haiku-4-5-20251001`. Suffix ` (fallback)` mirrors the cache convention. |
| `input_tokens` | int unsigned | 0 on fallback/cache-hit-with-no-call. |
| `output_tokens` | int unsigned | |
| `cost_zar` | decimal(10,4) | Computed by the shared cost calculator (USD pricing × `usd_to_zar`). 0 on fallback. |
| `cache_hit` | boolean, default false | True when served from cache (no API spend). Drives exact hit-rate. |
| `fallback` | boolean, default false | True when the call degraded to fallback text. |
| `occurred_at` | timestamp, indexed | When the call happened. |
| `created_at` | timestamp | No `updated_at` — append-only log (mirrors `agent_activity_events`). |

Indexes:
- `(agency_id, occurred_at)` — per-agency monthly rollups (dashboard + budget cap).
- `(source, occurred_at)` — per-surface breakdown.
- `(occurred_at)` — global daily burn + purge job.

**No `updated_at`, no soft delete.** A cost ledger is never edited or
soft-deleted (non-negotiable #1 concerns user-facing deletes; an append-only
financial log has no delete affordance). Retention: a `PurgeOldAiUsageEventsJob`
hard-deletes rows older than **13 months** (keeps a full rolling year + current
month for year-over-year), mirroring the existing `PurgeOldSoftDeletedCacheJob`
cadence. 13-month retention is a compliance/cost trade-off, not user data.

### 3.1 Source registry

`source` values (constants on the new `AiUsageSource` enum / `AiUsageEvent` model):

| Constant | Value | Origin |
|---|---|---|
| `MIC_NARRATIVE` | `mic_narrative` | `AnthropicGateway` (all 5 narrative types — keep `surface_ref = cache_key`; narrative subtype already in cache) |
| `MOBILE_VOICE` | `mobile_voice` | `IntentExtractionService` |
| `IMAGE_ANALYSIS` | `image_analysis` | `VisionRecognitionService` |
| `DOCUPERFECT_IMPORT` | `docuperfect_import` | `ImporterAiService` |
| `DOCUPERFECT_FIELD_MAP` | `docuperfect_field_map` | `AiFieldMapperService` |
| `DOCUPERFECT_VISION` | `docuperfect_vision` | `ClaudeVisionParserService` |
| `MARKETING_COPY` | `marketing_copy` | `MarketingCopyService` |
| `PRESENTATION_EVIDENCE` | `presentation_evidence` | `AIExtractionService` |

New sources append to this table — adding a surface = add a constant + one
`record()` call, nothing else.

---

## 4. The recording path

### 4.1 One sink: `AiUsageRecorder`

New service `App\Services\AI\AiUsageRecorder` with a single entry point:

```php
public function record(
    string $source,           // AiUsageSource constant
    string $model,
    int $inputTokens,
    int $outputTokens,
    ?int $agencyId = null,    // defaults to current tenant when null + resolvable
    ?int $userId   = null,    // defaults to auth()->id() when null + resolvable
    ?string $surfaceRef = null,
    bool $cacheHit = false,
    bool $fallback = false,
): void
```

Behaviour:
- Computes `cost_zar` via a **shared cost calculator** (see §4.2).
- Resolves `agency_id`/`user_id` from the authenticated context when the caller
  passes null (queued jobs pass them explicitly since there's no request user).
- Inserts one `ai_usage_events` row stamped `occurred_at = now()`.
- **Never throws into the caller.** The whole body is wrapped — a ledger write
  failure logs a warning and returns. Recording cost must never break the AI call
  that earns the money. (Same defensive posture the gateway already uses for
  budget-warning detection.)

### 4.2 Extract the cost calculator

`AnthropicGateway::computeCostZar()` is currently private. Extract the USD→ZAR
pricing math into a shared `App\Services\AI\AiCostCalculator::zar(model, in, out)`
(reads `config('services.anthropic.pricing.*')` + `usd_to_zar`, unchanged formula).
Both the gateway and the recorder call it — one pricing source of truth. The
gateway keeps a thin private wrapper so its existing tests don't move.

### 4.3 Wire each caller

| Caller | Change |
|---|---|
| `AnthropicGateway::handleApiSuccess()` | After the cache write, `record(MIC_NARRATIVE, …, cacheHit: false)`. |
| `AnthropicGateway::buildResponseFromCache()` | `record(MIC_NARRATIVE, …, cacheHit: true)` with the cached token counts (spend = 0 but the hit is logged for the hit-rate metric). |
| `AnthropicGateway::emitFallback()` | `record(MIC_NARRATIVE, …, fallback: true)` (cost 0). |
| `IntentExtractionService::extract()` | On `$response->successful()`, read `usage.input_tokens` / `usage.output_tokens` from the body and `record(MOBILE_VOICE, …, userId: auth user)`. |
| `VisionRecognitionService` | After analysis, `record(IMAGE_ANALYSIS, …)`. Job passes `agencyId`/`userId` from the analysis row (no request context in queue). Keep the `cost_usd` column too — it's a per-image display field; the ledger is the rollup. |
| `ImporterAiService`, `AiFieldMapperService`, `ClaudeVisionParserService` | `record(DOCUPERFECT_*, …)` after each successful call. |
| `MarketingCopyService` | `record(MARKETING_COPY, …, userId: auth user)`. |
| `AIExtractionService` | `record(PRESENTATION_EVIDENCE, …)`. |

Each direct caller already parses the Anthropic response — they gain ~3 lines:
pull the two `usage` counts, call `record(...)`. No behavioural change to the AI
calls themselves.

### 4.4 Repoint the readers

| Reader | Change |
|---|---|
| `AICostAggregator::monthlyCostZar()` / `monthlyCostByNarrativeType()` / `totalTokensThisMonth()` | Query `ai_usage_events` instead of `ai_narrative_cache`. Group "by narrative type" becomes "by `source`" (richer — now shows mobile/vision/docuperfect lines). |
| `AICostAggregator::cacheHitRate()` | Exact: `count(cache_hit=true) / count(*)` over the window. Delete the `agent_activity_events` heuristic + its docblock caveat. |
| `AiUsageController::index()` | `dailyBurn` and `topAgencies` raw queries point at `ai_usage_events`. Add a "by source" panel replacing/augmenting "by narrative type". |
| `Agency::aiBudgetUsedZar()` | Sum `cost_zar` from `ai_usage_events` for the agency+month. **This is the line that makes the budget cap honest** — once repointed, `canMakeAiCall()` sees all seven surfaces. |

The dashboard Blade gains one column (source breakdown); otherwise its shape is
unchanged — same hero, same daily burn, same top-consumers, same budget form.

---

## 5. Multi-tenancy

`ai_usage_events` is tenant-owned → `agency_id` column + `BelongsToAgency` /
`AgencyScope` from day one (non-negotiable #7 / `.ai/specs/multi-tenancy.md`).
Global/system calls write `agency_id = null` and are visible only to super-admin
(the dashboard already special-cases the global bucket as "(global)").

---

## 6. Permissions

No new keys. The dashboard stays gated on `mic.view_ai_costs`; budget edits stay
`super_admin`. The ledger is internal infrastructure with no standalone UI.

---

## 7. Domain events

No new events required. Existing `AgencyAiBudgetWarning` / `AgencyAiBudgetCapped`
fire unchanged — they just become *accurate*, because `aiBudgetUsedZar()` now sums
true spend. (Optional future: emit `AiCallRecorded` for real-time dashboards —
out of scope here.)

---

## 8. Files

**Create**
- `database/migrations/<ts>_create_ai_usage_events_table.php`
- `app/Models/AI/AiUsageEvent.php` (+ `BelongsToAgency`, `AiUsageSource` constants)
- `app/Services/AI/AiUsageRecorder.php`
- `app/Services/AI/AiCostCalculator.php`
- `app/Jobs/AI/PurgeOldAiUsageEventsJob.php` (+ schedule in `routes/console.php` / kernel)
- Tests: `tests/Feature/AI/AiUsageRecorderTest.php`,
  `tests/Feature/AI/AiUsageLedgerIntegrationTest.php` (one per source proves the
  row lands), `tests/Feature/Admin/AiUsageDashboardLedgerTest.php`.

**Modify**
- `app/Services/AI/AnthropicGateway.php` (record on success/cache/fallback; use calculator)
- `app/Services/AI/IntentExtractionService.php`
- `app/Services/AI/VisionRecognitionService.php`
- `app/Services/Docuperfect/ImporterAiService.php`
- `app/Services/Docuperfect/AiFieldMapperService.php`
- `app/Services/Docuperfect/ClaudeVisionParserService.php`
- `app/Services/MarketingCopyService.php`
- `app/Services/Presentations/Evidence/AIExtractionService.php`
- `app/Services/AI/AICostAggregator.php`
- `app/Http/Controllers/Admin/AiUsageController.php`
- `app/Models/Agency.php` (`aiBudgetUsedZar` → ledger)
- `resources/views/admin/ai-usage/index.blade.php` (source breakdown panel)
- `.ai/specs/mic-complete-spec.md` (§3.2.8 add table; §4.8 note the ledger is the
  cost source of truth; §4.8 dashboard line "by source")
- `database/schema/mysql-schema.sql` (re-dump after migration — non-negotiable #12a)

---

## 9. Acceptance criteria

1. A mobile Ellie voice command (`IntentExtractionService`) writes one
   `ai_usage_events` row with `source=mobile_voice`, the correct agency/user,
   non-zero tokens, and a ZAR cost — and that cost appears in the agency's total
   on `/admin/ai-usage`.
2. Each of the seven previously-invisible surfaces writes a ledger row on a live
   call (one integration test per source).
3. An agency at 100% of a hard cap is **blocked** from a mobile voice call (the
   cap now sees that spend) — proven by a feature test that fills the ledger then
   asserts `canMakeAiCall()` is false and the gateway/recorder path degrades.
4. `cacheHitRate()` returns an exact ratio computed from `cache_hit` rows; the
   `agent_activity_events` heuristic is gone.
5. `/admin/ai-usage` shows a per-source breakdown (mobile_voice, image_analysis,
   docuperfect_*, marketing_copy, presentation_evidence, mic_narrative) summing to
   the hero total.
6. A recorder write failure (simulated) logs a warning and does **not** throw —
   the underlying AI call still returns its result.
7. `ai_usage_events` is tenant-scoped: Agency A's dashboard never sees Agency B
   rows (AgencyScope test).
8. `scripts/dev-check.ps1` green; `php artisan schema:dump` re-run and committed.

---

## 10. Open questions for review (Johan)

1. **Backfill?** Should we seed `ai_usage_events` from existing
   `ai_narrative_cache` + `property_image_analyses` rows so history isn't a cliff,
   or start the ledger at go-live (cleaner, but the dashboard shows a step-change)?
   Recommendation: start clean, note the go-live date on the dashboard.
2. **Keep per-table cost columns?** `property_image_analyses.cost_usd` stays for
   per-image display. Any other per-surface cost column we should retire once the
   ledger is authoritative?
3. **13-month retention** acceptable, or does finance want longer for
   year-over-year reporting? (Cheap to keep — it's a narrow table.)
