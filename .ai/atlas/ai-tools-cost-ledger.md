# Atlas — Ellie / AI features + AI Cost Ledger

> **Status: DONE** · Last verified: 2026-06-22
> Cross-cutting: AI is invoked from MIC, Presentations, DocuPerfect import, property images, mobile voice,
> marketing copy. Companion specs: `.ai/specs/ellie.md`, `.ai/specs/ai-cost-ledger.md` (marked DRAFT but the
> ledger is **fully built** — §9.10).

---

## 1. WHAT IT DOES

Ellie is CoreX's embedded SA real-estate AI assistant (advises, never auto-writes). Beyond Ellie, AI is
invoked across the system for narratives, summaries, document field-detection, image recognition, and voice.
The **AI Cost Ledger** (`ai_usage_events`) meters Anthropic calls (tokens in/out, ZAR cost, model, feature,
agency) and enforces per-agency monthly budgets.

---

## 2. ENTRY POINTS

- **Ellie web chat** `routes/web.php:329-344` (`permission:access_ellie`): `ellie.index` `:330`,
  `ellie/send` `:333`, rename/archive `:337-344` → `EllieController` (index `:18`, send `:78`). View
  `resources/views/ellie/index.blade.php`; widget `layouts/partials/ellie-widget.blade.php`. Nav
  `corex-sidebar.blade.php:1182-1189`.
- **Ellie Voice (mobile)** `routes/api.php:352-355`: `mobile/ellie/voice` → `MobileEllieVoiceController@process`
  (gated `use_ellie_voice` + `agency->ai_voice_enabled` `:33-36`).
- **AI Usage admin** `routes/web.php:266-275` (`permission:mic.view_ai_costs`): `admin.ai-usage.index` `:266`,
  agency drill-down `:269`, `updateBudget` `:273` (super_admin only `:213`) → `Admin\AiUsageController`. Nav
  `corex-sidebar.blade.php:1519-1527`.

---

## 3. ELLIE — how it works

`EllieController@send:78`: stores message `:108` → **deterministic shortcuts bypass the LLM** (prime/interest
from `performance_settings` `:116-155`, agent target/points from `AgentPerformanceService` `:224-270`,
transfer/bond cost via `PropertyCostService` `:272-295`) → builds context (performance, pipeline, listings,
last-10 history `:297-419`) → KB search (`KnowledgeSearchService::search` `:425-444`) → **the model call goes
to the self-hosted Python service** `Http::post('http://127.0.0.1:3100/chat')` `:446-460`, NOT Anthropic →
stores assistant `AiMessage` `:498`. **No dedicated EllieService** — orchestration is in the controller; LLM
lives behind port 3100 (`/opt/hf-ai/app.py`). Voice path: `SpeechToTextService` (local Whisper, port 3100,
on-shore for POPIA) → `IntentExtractionService` (Claude Haiku) → `ScheduleEventIntentHandler` (creates a
calendar event, 30s undo) or chat fallback.

---

## 4. EVERYWHERE AI IS INVOKED

API keys in `config/services.php`: Anthropic `:62-91`, OpenAI `:42-44`, HF self-hosted `:93-96`.

**A. Anthropic via the gateway (`AnthropicGateway::generate` `:62`) — metered as `MIC_NARRATIVE`:** MIC
narratives (`MarketIntelligenceController.php:1052,1115,1311`), strategic brief
(`StrategicBriefService.php:38`, Sonnet), This-Week tile (`ThisWeekTileBuilder.php:52`, Haiku), report
spot-check (`SpotCheckMarketReportJob.php:49`, Haiku), presentation AI summary (`AiSummaryService.php:71`),
DocuPerfect PDF vision (`ImporterAiService.php:402`).

**B. Anthropic DIRECT calls — each self-meters to the ledger:**
| Service | call / record | model | ledger source |
|---------|---------------|-------|---------------|
| `IntentExtractionService` (voice) | `:45` / `:66` | Haiku 4.5 | MOBILE_VOICE |
| `VisionRecognitionService` (property image) | `:67` / `:101` | Haiku 4.5 | IMAGE_ANALYSIS |
| `Docuperfect\ImporterAiService` (Claude) | `:83` / `:121` | Sonnet 4.6 | DOCUPERFECT_IMPORT |
| `Docuperfect\AiFieldMapperService` | `:95` / `:123` | Sonnet | DOCUPERFECT_FIELD_MAP |
| `Docuperfect\ClaudeVisionParserService` | `:80` / `:117` | Sonnet | DOCUPERFECT_VISION |
| `MarketingCopyService` | `:109` / `:139` | Haiku | MARKETING_COPY |
| `Presentations\Evidence\AIExtractionService` | `:136` / `:156` | Haiku | PRESENTATION_EVIDENCE |

**C. OpenAI — NOT metered:** KB embeddings (`EmbeddingService.php:50`), DocuPerfect fallback engine
(`ImporterAiService.php:148`, gpt-4o-mini). **D. Self-hosted (port 3100) — NOT metered:** Ellie chat,
Whisper STT. *(Note: AT-22 is a CMA report-quality overhaul — there is no OCR/LLM image content-gate.)*

---

## 5. THE AI COST LEDGER

**Recorder** `AiUsageRecorder::record:45` — computes cost via `AiCostCalculator`, forces 0 on cache-hit/
fallback `:59`, resolves `agency_id` from `effectiveAgencyId()` when null `:64,96`, inserts one
`ai_usage_events` row, **never throws** (try/catch → warning `:76-82`). **Calculator**
`AiCostCalculator::zar:30` (USD/M × `usd_to_zar` 16.50 default; `resolvePricingKey:63-77` maps dated/family
model ids → pricing; **unknown model → 0** `:33`). **Gateway** records success/cache-hit/fallback at
`AnthropicGateway.php:367,512,483`. **Readers:** `AICostAggregator` (monthly cost/by-source/cache-hit),
`AiUsageController@index:36`, `Agency::aiBudgetUsedZar:370-380` (powers the budget cap).

---

## 6. DATA READ / WRITTEN — `ai_usage_events`

Model `AI/AiUsageEvent.php` (`UPDATED_AT=null`, append-only `:42`; source constants `:45-52`). Migration
`2026_06_23_000000`: `agency_id`/`user_id` nullable FK nullOnDelete, `source` str(40), `surface_ref`,
`model`, `input_tokens`/`output_tokens`, `cost_zar` decimal(10,4), `cache_hit`, `fallback`, `occurred_at`,
**no `updated_at`**, **no SoftDeletes** (by design). Retention via hard delete `PurgeOldAiUsageEventsJob`
(13-month window), scheduled `routes/console.php:188-196`. Each caller writes model/tokens/cost/source/
surface_ref/agency_id/user_id.

---

## 7. AGENCY SETTINGS / AI CONFIG

`app/Models/Agency.php`: feature flags `ai_image_recognition_enabled` `:66`, `ai_voice_enabled`; budget
`ai_monthly_budget_zar` `:113`, `ai_budget_warning_pct`/`ai_budget_hard_cap_pct` `:115`,
`ai_budget_overage_allowed` `:116` (migration `2026_05_21_160001`). Budget logic: `aiBudgetUsedPct:385`,
`aiBudgetStatus:407` (healthy/warning/critical/capped), `canMakeAiCall:429`, enforced in
`AnthropicGateway::loadCappedAgency:552-569` → `emitFallback('agency_budget_capped')`. `config/services.php`:
`ANTHROPIC_ENABLED` kill-switch `:79`, model aliases fast/quality `:73-76`, pricing `:86-90`. **No per-agency
model selection** — model tier is global config.

---

## 8. AFFECTS / AFFECTED BY

The ledger reads from every metered AI call site (above) and feeds the admin AI-usage dashboard + the
budget cap that gates further MIC calls. Ellie reads performance/pipeline/listings/KB across pillars.

---

## 9. KNOWN FRAGILITIES

1. **OpenAI spend is entirely unmetered** — KB embeddings (`EmbeddingService.php:50`) and the DocuPerfect
   OpenAI fallback (`ImporterAiService.php:148`) write nothing; the calculator only knows Anthropic pricing.
   The same `ImporterAiService` meters its Claude branch but not its OpenAI branch (split-attribution bug).
2. **Ellie web chat (port 3100) and Whisper STT are unmetered** — the primary Ellie surface and all voice
   transcription compute are excluded from "total AI cost".
3. **Python AI service is a hard single-point dependency** — `127.0.0.1:3100` hardcoded
   (`EllieController.php:449`, `AiChatProxyController.php:28`), not in git, restarted manually
   (`ellie.md:48`); if `hf-ai.service` is down, Ellie returns "AI service error".
4. **Image-analysis cost under-attributed at user level** — `AnalysePropertyImageJob.php:54` passes
   `agency_id` but not `userId`; in a queued job `Auth::id()` is null → every `image_analysis` row has
   `user_id=null`.
5. **Model-version drift prices at ZERO silently** — direct callers hardcode dated model ids;
   `resolvePricingKey` maps known families, but any future model without "haiku/sonnet/opus" in the name
   returns 0 (`zar():33`) — tokens log, cost reads 0, undercounting with no error.
6. **`usd_to_zar` is forward-only** — historical rows are not re-priced; stale rates skew the cap.
7. **Tenancy enforced at write-time only** (deliberate, no `AgencyScope` on the ledger) — isolation depends
   on the recorder stamping the right `agency_id`; the migration comment wrongly claims AgencyScope is on
   the model.
8. **Budget cap covers only the gateway path** — the 7 direct callers (§4.B) record cost *after* the call
   but are **never checked against `canMakeAiCall()` before calling**. A capped agency is blocked on MIC
   narratives but can still spend via voice/image/DocuPerfect/marketing/presentation-evidence.
9. **Recorder swallows all failures silently** (`:76-82`) — a persistently failing ledger write produces
   only a log warning; the dashboard under-reports with no alert.
10. **Spec/code divergence** — `ai-cost-ledger.md` is "DRAFT — not on main" while the implementation is
    complete; anyone reading the spec would wrongly believe the ledger isn't live.

---

## Key file:line index
- `app/Http/Controllers/EllieController.php:78-513`; `app/Http/Controllers/Api/MobileEllieVoiceController.php:30-156`.
- `app/Services/AI/AiUsageRecorder.php:45-100`, `AiCostCalculator.php:30-77`, `AnthropicGateway.php:62-589`, `AICostAggregator.php`.
- `app/Models/AI/AiUsageEvent.php`; `app/Models/Agency.php:370-431` (budget).
- `app/Http/Controllers/Admin/AiUsageController.php:36-222`; `app/Jobs/AI/PurgeOldAiUsageEventsJob.php`.
- `config/services.php:42-96`; specs `.ai/specs/ellie.md`, `.ai/specs/ai-cost-ledger.md`.
