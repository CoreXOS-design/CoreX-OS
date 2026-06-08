# Market Intelligence Centre — Complete Specification

**Spec ID:** `mic-complete-spec`
**Date:** 2026-05-21
**Owner:** Johan Reichel (HFC / CoreX)
**Author:** Claude (spec) + Johan (review and approval)
**Status:** Approved for build — single source of truth from this point forward.
**Target file path:** `.ai/specs/mic-complete-spec.md`

---

## 0. How to Use This Document

This is the **single source of truth** for the Market Intelligence Centre. Every build prompt that touches MIC, Tracked Properties, P24 Alerts, buyer matching, claims, or related surfaces must reference this document at the top of the prompt.

The hard rules apply (CLAUDE.md):
- Read `CLAUDE.md`, `.ai/STANDARDS.md`, and this spec before any work.
- Investigate exact files/lines before writing any prompt.
- Every prompt ends with `php -l`, `php artisan view:clear`, `scripts/dev-check.ps1`, Tinker verification.
- No hard deletes anywhere — soft delete / archive only.
- Multi-tenancy via `AgencyScope` / `BelongsToAgency` on every new table that holds agency-specific data.

**This spec supersedes:**
- `.ai/specs/build-f-market-intelligence-redesign-spec.md`
- `.ai/specs/prospecting-intelligence-spec.md`
- Any earlier scattered MIC notes

Earlier specs remain in place as historical context but are no longer authoritative.

---

## 1. Vision

**The translated data principle:**

An accountant explaining accounting to a layperson is incomprehensible — not because the data is wrong, but because the language is the engine room, not the dashboard. The Market Intelligence Centre is built on the inverse principle: ferocious complexity behind the glass, almost insulting simplicity on the glass.

Every agent on Monday morning has one fundamental question:

> *"What should I do this week to make money?"*

The MIC answers that question in one screen and three taps. Everything else — the demand-supply heatmaps, the suburb ratios, the cross-listing analysis, the per-bedroom inventory curves — exists in the engine room for when the agent (or the sophisticated seller, or the listed-company rep, or the branch manager) wants to know *why*.

**Three audiences, one screen, three levels of depth:**

| Audience | What they see | How they get there |
|---|---|---|
| Agent (default) | One English sentence + one number + one action button | Default view |
| Sophisticated seller | The chart behind the sentence | One tap on the tile |
| Analyst / BM / dev | The full data, methodology, source confidence | "Analyse" tab |

**Five principles that govern every MIC decision:**

1. **Translated, not simple.** "Simple" strips data out. "Translated" runs all the analysis and shows the conclusion in English.
2. **One number, one verb, one action.** Per tile. Per row. Per call to action.
3. **AI narrates everything narratable.** Ellie writes every tile sentence, every brief, every suburb tooltip. No templated text pretending to be insight.
4. **The address is helpful, not required.** Agents prospect addressless properties every day. The system supports that.
5. **Every action emits a domain event.** Today nothing listens. Tomorrow auto-activity-tracking, exclusive auto-notification, and a dozen other compounding features hook in without rewrites.

---

## 2. Architecture Overview

### 2.1 The Tracked Property graph is the spine

Every property the system has ever heard of — from any source — lives in `tracked_properties`. One row per real-world property, regardless of how many sources have told us about it.

The graph has been working in production since the F-series build. 4,912 nodes on local, 5,526 source refs. The 5-strategy match-or-create logic in `TrackedPropertyMatchOrCreateService` is the canonical front door (CLAUDE.md HARD RULE #10).

This spec does not rebuild the graph. It builds **on top of** it.

### 2.2 The MIC module is one unified surface

One sidebar entry: **Market Intelligence**.

Four tabs across the top:

| Tab | Purpose | Audience |
|---|---|---|
| **Work** | "What should I do today?" — the agent's default landing | Agent |
| **Opportunities** | The full Tracked Property inventory with filters | Agent + BM |
| **Analyse** | Demand-supply heatmaps, demand pockets, competitive landscape | Power user |
| **Market Pulse** | The P24 firehose — raw market data, suburb stats, price changes | Power user / curious |

Behind those four tabs, every surface reads from the same canonical data — `tracked_properties`, `prospecting_listings`, `prospecting_buyer_matches`, `contact_matches`, `p24_listings`, `market_data_points` (new).

The legacy `/admin/p24` page (currently a parallel mini-MIC) is migrated into the **Market Pulse** tab. The legacy `/corex/tracked-properties` page is migrated into the **Opportunities** tab. The legacy `ProspectingController` is retired entirely.

### 2.3 AI is wired throughout, from day one

No "AI hook reserved for later." Ellie / Claude writes:

1. The **Strategic Weekly Brief** (replaces templated text)
2. The **"This Week" tile copy** (nightly per agent)
3. Per-listing **"why this matters to your buyers"** tooltips
4. Per-suburb **demand-pocket narratives** on click
5. **CMA report spot-check audits** (background quality control)
6. **Address fuzzy-match suggestions** when normal matching fails
7. (Phase 4) Photo → feature list → heading → description for own stock

Cost ceiling: well under R200/agency/month at current Anthropic pricing for the entire MIC AI surface. Budget is not the constraint. Quality is.

### 2.4 Every action emits a domain event

This isn't optional. Every meaningful action in MIC — claim, release, pitch, WhatsApp send, feedback, address edit, promotion to stock — emits a domain event. Today, nothing listens. Tomorrow, two of the highest-ROI features in CoreX hook in without a single rewrite:

- **Auto activity tracking** (agent earns daily-activity points from CoreX actions, not manual capture)
- **Exclusive auto-notification** (mandate signed → address matches portal listing → portal emailed for takedown)

Both are deferred to Phase 5 (after MIC ships). But the event scaffolding lands now, in this spec.

---

## 3. Data Model

### 3.1 Tables already in place (no schema changes)

- `tracked_properties` — the spine (audit §2.1)
- `tracked_property_external_refs` — source tagging (audit §2.2)
- `prospecting_listings` — per-portal listings linked to TPs
- `prospecting_buyer_matches` — wishlist × prospecting_listing scoring
- `property_buyer_matches` — wishlist × own-stock scoring
- `contact_matches` — buyer wishlists
- `contact_match_feedback` — buyer reactions
- `contact_match_notifications` — buyer notification log
- `prospecting_claims` — agent claims
- `prospecting_pitch_locks` — temp locks
- `p24_listings` — P24 email-derived listings
- `p24_import_log` — P24 import audit
- `presentation_*` — CMA presentation parsing
- `properties` — own stock (the canonical pillar)

### 3.2 New tables — added by this spec

#### 3.2.1 `tracked_property_addresses`

The address-with-history table. Solves the silent-killer "P24 publishes wrong address forever" problem.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, cascade | Multi-tenancy via BelongsToAgency |
| `tracked_property_id` | FK → tracked_properties, cascade | |
| `street_number` | string(50) nullable | |
| `street_name` | string(200) nullable | Normalised on write |
| `unit_number` | string(50) nullable | |
| `complex_name` | string(200) nullable | |
| `suburb` | string(100) nullable | |
| `suburb_normalised` | string(100) nullable | Lowercase + strip punct |
| `town` | string(100) nullable | |
| `province` | string(100) nullable | |
| `postal_code` | string(20) nullable | |
| `latitude` | decimal(10,7) nullable | |
| `longitude` | decimal(10,7) nullable | |
| `source_type` | string(50) | `p24`, `pp`, `chrome_capture`, `cmainfo`, `manual_agent`, `manual_admin`, `deeds_office` |
| `source_ref` | string(200) nullable | The originating record ID |
| `confidence` | enum('low','medium','high','verified') | `verified` = agent confirmed |
| `is_primary` | boolean | Exactly one row per TP is `true` |
| `verified_by_user_id` | FK → users nullable | Who marked verified |
| `verified_at` | timestamp nullable | |
| `notes` | text nullable | Agent's note explaining the correction |
| `first_seen_at` | timestamp | |
| `last_seen_at` | timestamp | |
| `created_at` / `updated_at` / `deleted_at` | Soft deletes |

Indexes:
- `(agency_id, tracked_property_id, is_primary)`
- `(agency_id, suburb_normalised, street_name)` — for matching incoming captures
- `(agency_id, latitude, longitude)` — for proximity matching

**Promotion logic:** When a new address arrives:
1. If `source_type = manual_agent` or `manual_admin` → mark `confidence = verified`, demote current primary to history, set new row as `is_primary = true`.
2. Otherwise → insert as history with appropriate confidence, *do not* demote primary.

**The primary address on `tracked_properties` becomes a denormalised cache** — the `is_primary = true` row's fields are mirrored to the parent for fast queries. A model observer keeps the cache in sync.

#### 3.2.2 `market_reports`

The upload record for any CMA / market report file.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | |
| `uploaded_by_user_id` | FK → users | |
| `report_type_id` | FK → market_report_types | |
| `file_path` | string | Storage path |
| `file_name` | string | Original filename |
| `file_hash` | string(64) | sha256, dedup |
| `source_suburb` | string nullable | Auto-detected or agent-supplied |
| `source_town` | string nullable | |
| `report_date` | date | Date the *report* was generated, not uploaded |
| `parse_status` | enum('pending','parsing','parsed','failed','manual_review') | |
| `parse_started_at` / `parse_completed_at` | timestamp | |
| `parser_version` | string nullable | Track parser revisions for accuracy metrics |
| `raw_extracted_json` | json nullable | Everything the parser pulled, before normalisation |
| `data_points_count` | usmallint | Cached count of extracted market_data_points |
| `spot_check_status` | enum('pending','running','passed','flagged','manual') | |
| `spot_check_results` | json nullable | Audit results |
| `notes` | text nullable | |
| `created_at` / `updated_at` / `deleted_at` | Soft deletes |

#### 3.2.3 `market_report_types`

Seeded enum of supported report types.

| Column | Type | Notes |
|---|---|---|
| `id` | smallint PK | |
| `key` | string UNIQUE | e.g. `cma_info_market_analysis` |
| `display_name` | string | "CMA Info Market Analysis" |
| `parser_class` | string | FQCN, e.g. `App\Services\MarketReports\Parsers\CmaInfoMarketAnalysisParser` |
| `expected_fields_json` | json | What the parser yields, for validation |
| `auto_approve` | boolean | If true, skip manual review when spot-check passes |
| `sample_file_path` | string nullable | For parser regression tests |

Seeded report types (V1):
- `cma_info_market_analysis` — the big one (the "5 Sue Casa" / "25 Collison" reports)
- `cma_info_median_sales_analysis` — the multi-page suburb median trends report
- `cma_info_property_valuation` — the full subject + comps document
- `cma_info_sectional_title_sales` — the 300m-radius ST sales report
- `lightstone_avm` — Lightstone automated valuation
- `lightstone_suburb_report` — Lightstone suburb summary
- `agent_built_cma` — hand-built CMA in Word/PDF — high parse difficulty, always manual review
- `deeds_office_print` — raw Deeds Office output
- `ooba_bond_report` / `betterbond_report` — bond originator outputs
- `other` — fallback, files PDF but doesn't parse

#### 3.2.4 `market_data_points`

The normalised data warehouse. The gold.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | But shared across CoreX per §13 |
| `report_id` | FK → market_reports nullable | Nullable because data can also come from API integrations (Lightstone direct, future) |
| `tracked_property_id` | FK → tracked_properties nullable | If the data point pertains to a specific TP |
| `suburb_normalised` | string(100) nullable | For suburb-level data points |
| `town` | string(100) nullable | |
| `metric_key` | string(100) | e.g. `median_price_3bed_house`, `total_sales_yoy`, `municipal_valuation`, `last_sale_price` |
| `metric_value_numeric` | decimal(15,2) nullable | |
| `metric_value_date` | date nullable | |
| `metric_value_string` | text nullable | For non-numeric data |
| `metric_date` | date | The date the metric applies to |
| `confidence` | enum('low','medium','high','verified') | |
| `source_type` | string(50) | Mirrors market_reports.source_type but allows API origins |
| `source_ref` | string(200) nullable | |
| `is_superseded` | boolean default false | When a newer report invalidates this point |
| `superseded_by_id` | FK self | |
| `created_at` / `updated_at` / `deleted_at` | Soft deletes |

Indexes:
- `(agency_id, tracked_property_id, metric_key, metric_date)`
- `(agency_id, suburb_normalised, metric_key, metric_date)`
- `(metric_key, metric_date)` — global queries

**The shared pool play:** `agency_id` is on every row for audit, but read queries against `market_data_points` for the shared CoreX market database **do not filter by agency**. Per §13.

#### 3.2.5 `market_data_discrepancies`

The AI spot-check output.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `report_id` | FK → market_reports | |
| `data_point_id` | FK → market_data_points | |
| `parsed_value` | text | What the parser said |
| `audit_value` | text | What the AI re-extraction said |
| `discrepancy_type` | enum('value_mismatch','date_mismatch','address_mismatch','missing','extra') | |
| `severity` | enum('low','medium','high') | |
| `resolved` | boolean default false | |
| `resolved_by_user_id` | FK → users nullable | |
| `resolved_at` | timestamp nullable | |
| `resolution_notes` | text nullable | |
| `created_at` / `updated_at` | |

Notifications fire to super admin dashboard (not email) when severity ≥ medium.

#### 3.2.6 `ai_narrative_cache`

Caches Ellie-generated narratives so we don't burn tokens regenerating identical text.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies nullable | Nullable for global narratives (e.g. shared market briefs) |
| `narrative_type` | string(50) | `weekly_brief`, `tile_copy`, `listing_tooltip`, `suburb_pocket`, `audit_finding` |
| `cache_key` | string(255) | Composed: e.g. `weekly_brief:agency:1:week:2026-21` |
| `input_hash` | string(64) | sha256 of the input data — invalidates cache when inputs change |
| `prompt_version` | string(20) | Track prompt evolution for A/B comparison |
| `model` | string(50) | e.g. `claude-haiku-4-5`, `claude-sonnet-4-6` |
| `input_tokens` | int | Cost tracking |
| `output_tokens` | int | Cost tracking |
| `cost_zar` | decimal(10,4) | Cost tracking |
| `output_text` | text | The narrative |
| `output_json` | json nullable | When structured output required |
| `generated_at` | timestamp | |
| `expires_at` | timestamp | TTL varies by narrative_type |
| `created_at` / `updated_at` | |

Indexes:
- `(cache_key)` UNIQUE
- `(narrative_type, expires_at)` — for sweep-and-regenerate jobs

#### 3.2.7 `agent_activity_events` (foundation for Phase 5)

The catchall event log that future auto-activity-tracking listens to. Lands now, populated by domain events, but nothing reads it yet for points calculation.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | |
| `user_id` | FK → users | |
| `event_type` | string(100) | `claim.created`, `pitch.sent`, `whatsapp.sent`, `feedback.recorded`, `property.created`, `mandate.signed`, etc. |
| `subject_type` | string(100) nullable | Morphable: `TrackedProperty`, `Property`, `Contact`, `Deal`, etc. |
| `subject_id` | bigint nullable | Morphable ID |
| `payload` | json | Event-specific data |
| `occurred_at` | timestamp | |
| `created_at` | |

No `updated_at` — append-only log.

### 3.3 Schema fixes to existing tables

These are flagged in the audit. They land in this spec because they're prerequisites for clean MIC operation.

1. **`p24_listings.agency_id`** — add column, backfill to HFC's agency_id, add to `AgencyScope`. Currently global; risks cross-tenant leakage on agency #2 onboarding.

2. **`presentations.agency_id`** — add column, backfill via `branch.agency_id`. Same risk.

3. **`properties` identifier columns:**
   - Add `erf_number` string(100) nullable
   - Add `title_deed_number` string(100) nullable
   - Add `municipal_valuation` decimal(15,2) nullable
   - Add `municipal_valuation_year` smallint nullable
   - Backfill from linked `tracked_property` where promoted.

4. **`prospecting_listings` — remove `address = 'Address not available'` placeholder** — replace with proper null. Update default search behaviour to handle null gracefully.

5. **`tracked_properties` spatial index** — convert `idx_tracked_props_geo` to a spatial index if MySQL version supports `SPATIAL INDEX` on lat/lng. (Verify MySQL version on Hetzner during build.)

---

## 4. AI Surfaces

Every AI surface defined here. The prompt structure, the model choice, the cache TTL, the failure mode.

**Model selection principle:** Haiku 4.5 for high-frequency / structural tasks. Sonnet 4.6 for anything customer-facing or requiring strong language quality. Opus 4.7 nowhere — we don't need it for MIC.

### 4.1 Strategic Weekly Brief

**Replaces:** Templated text in `StrategicBriefService`.

**Trigger:** Nightly cron at 02:00 Africa/Johannesburg, per agency. Plus on-demand "Regenerate" button (admin only, rate-limited to 1 per hour per agency).

**Model:** Sonnet 4.6

**Input:** Structured JSON facts assembled by `StrategicBriefService`:
- Top demand pocket (suburb × beds with strongest demand-to-supply ratio)
- 30-day stock inflow leader
- Top competitor in leading suburb
- Stale-mandate count
- Notable price movements

**Output:** 2-3 sentences of natural-language brief + 2-3 action button labels.

**Cache:** 24h in `ai_narrative_cache`. Invalidated by hash of input JSON.

**Cost ceiling:** ~R5/agency/month at one regeneration per night.

**Failure mode:** If Anthropic API down, fall back to templated text (the existing implementation stays as fallback, not as primary).

### 4.2 "This Week" tile copy

**Trigger:** Nightly cron at 02:30 per agency, plus on-demand refresh on Work tab visit if cache older than 12h.

**Model:** Haiku 4.5

**Input per agent:** Their match counts, expiring claims, new listings in their area since Friday, sellers without feedback >14 days, demand-pocket alerts.

**Output:** Structured JSON — one tile per actionable item, each with `sentence`, `number`, `action_label`, `action_route`, `urgency_color`.

**Cache:** Per-agent, 12h TTL.

**Cost ceiling:** ~R3/agent/month.

**Failure mode:** Fall back to deterministic tile generation from the same input JSON.

### 4.3 Per-listing "Why this matters to your buyers" tooltips

**Trigger:** Lazy — on hover/tap over a listing row's match indicator. Cached aggressively.

**Model:** Haiku 4.5

**Input:** Listing fields + the top 3 matched buyers' anonymised requirements (no PII to the model).

**Output:** One sentence — "3 of your buyers want this. Strongest match: a couple looking for a 3-bed in Margate under R2M, who's been searching 6 weeks."

**Cache:** 7 days, invalidated on buyer-match recompute.

**Cost ceiling:** ~R1/agent/month.

### 4.4 Demand pocket narratives

**Trigger:** On click of a demand-pocket cell in the Analyse heatmap.

**Model:** Sonnet 4.6 (quality matters here — agent might quote this to a seller)

**Input:** Suburb, bedroom count, buyer count, listing count, average price band, recent sales in pocket.

**Output:** 3-4 sentences explaining why this pocket is hot, who's buying, what's available.

**Cache:** 24h per cell.

**Cost ceiling:** ~R10/agency/month.

### 4.5 CMA report spot-check audits

**Trigger:** Background job after every successful parse — picks 20% of extracted data points (minimum 3) for re-verification.

**Model:** Haiku 4.5 (with vision capability)

**Input:** The relevant page of the PDF as image + the parser's extracted value + the metric_key.

**Output:** JSON — `{agrees: bool, confidence: low|medium|high, audit_value: any, notes: string}`.

**Tolerance bands:**
- Prices: ±2%
- Counts (sales, bedrooms): exact
- Dates: exact
- Square meters: ±3%
- Addresses: normalised match

**Cache:** Permanent (we don't re-audit unless parser changes).

**Cost ceiling:** ~R0.30/month at HFC's current report volume.

**Action on flag:** Insert `market_data_discrepancy` row, notify super admin dashboard (not email).

**Parser accuracy tracking:** Every spot-check that agrees increments parser's confidence score. Parsers above 98% over 500 checks → promote to "trusted" → drop spot-check rate to 5%. Parsers below 90% → flag for rework.

### 4.6 Address fuzzy-match suggestions

**Trigger:** When `TrackedPropertyMatchOrCreateService` falls through all 5 deterministic strategies without finding a match.

**Model:** Haiku 4.5

**Input:** The incoming address fields + top 10 candidate TPs in the same suburb by token overlap.

**Output:** JSON — `{best_match_id: int|null, confidence: low|medium|high, reason: string}`.

**Action:** If confidence high → match. If medium → match but flag for review. If low or null → create new TP.

**Cache:** None (one-shot per ingestion).

**Cost ceiling:** ~R2/agency/month at HFC's ingestion volume.

### 4.7 (Phase 4) Photo → feature list → heading → description

Specced separately in §15. Out of scope for MIC live launch. Architecture leaves the door open.

### 4.8 Anthropic API integration layer

All AI calls go through a single service: `App\Services\AI\AnthropicGateway`.

Responsibilities:
- API key management (config/services.php → `anthropic.api_key`)
- Model selection (alias: `fast` = Haiku 4.5, `quality` = Sonnet 4.6)
- Cost tracking — every call logs to `ai_narrative_cache` with input/output tokens and ZAR cost
- Retry logic — exponential backoff on 5xx, fail fast on 4xx
- Cache lookup — every call checks `ai_narrative_cache` by `cache_key` and `input_hash` before hitting API
- Batch API support — for non-real-time work (briefs, tile copy nightly cron), use Anthropic Batch API for 50% discount
- Prompt caching — system prompts cached at API level for additional 90% input cost reduction

**Monthly cost dashboard:** New admin page at `/admin/ai-usage` showing per-agency token consumption, ZAR spend, per-source breakdown, cache hit rate.

> **Cost source of truth — see `.ai/specs/ai-cost-ledger.md`.** Spend is no
> longer read from `ai_narrative_cache` (which only the MIC gateway writes).
> All Anthropic calls across CoreX — MIC narratives, mobile voice, image
> analysis, DocuPerfect, marketing copy, presentation evidence — record to the
> append-only `ai_usage_events` ledger via `AiUsageRecorder`. The dashboard and
> `Agency::aiBudgetUsedZar()` read the ledger, so spend visibility and the
> per-agency budget cap cover every surface, not just narratives.

---

## 5. MIC Module — Full UX

### 5.1 Sidebar entry

Single entry: **Market Intelligence**.

Replaces in `resources/views/layouts/corex-sidebar.blade.php`:
- Existing "Market intelligence" link (kept)
- Existing "Tracked Properties" link (removed — folded in)
- Existing "Portal Leads" link (kept — separate concern)
- "Market Intelligence" under Command Centre Settings (renamed to "Market Intelligence Settings")
- `/admin/p24` (sidebar link added inside MIC → Market Pulse tab, removes need for direct URL)

Permission gate: `@permission('access_prospecting')`. Same as today.

Sidebar badge: count of "things needing my attention" (sum of expiring claims + new buyer matches today + flagged discrepancies if super admin). 60s cache.

### 5.2 Tab structure

Four tabs across the top of MIC, sticky on scroll:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  Market Intelligence                                                          │
│  [ Work ] [ Opportunities ] [ Analyse ] [ Market Pulse ]      ⚙ Settings     │
└──────────────────────────────────────────────────────────────────────────────┘
```

Each tab is a separate route and a separate controller method, but visually feels like a single module.

### 5.3 Tab 1: Work — the default landing

**Route:** `GET /corex/market-intelligence` → `MarketIntelligenceController::work()`

**Structure (top to bottom):**

#### 5.3.1 "This Week" hero block

Above everything else. The translated-data centrepiece.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Hi Johan. Here's your week.                                                 │
│                                                                              │
│  🔥  47 properties match your buyers right now.                              │
│      [ Prospect today →  ]                                                   │
│                                                                              │
│  ⏰  4 of your claims expire in the next 24 hours.                           │
│      [ Action now →  ]                                                       │
│                                                                              │
│  💬  6 of your sellers haven't heard from you in 14 days.                    │
│      [ Send updates →  ]                                                     │
│                                                                              │
│  🎯  Manaba Beach 5-bed: 5 buyers chasing 1 listing.                         │
│      [ Canvass this pocket →  ]                                              │
│                                                                              │
│  📈  18 new listings on P24 since Friday in your area.                       │
│      [ Review →  ]                                                           │
│                                                                              │
│  Generated by Ellie · 2 hours ago · [ Refresh ]                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

Each tile:
- One emoji icon (semantic — 🔥 hot opportunity, ⏰ time-sensitive own work, 💬 relationship, 🎯 strategic, 📈 informational)
- One number prominent
- One English sentence — Ellie-generated
- One action button — links to filtered view inside Work tab or another tab
- Tiles render only if the count > 0 (don't show "0 buyers haven't heard from you" — that's noise)

**Hidden by default:** an "Analyse" link at the bottom — "Show me the data behind this →" — takes you to the Analyse tab with the same filters applied.

#### 5.3.2 Stats strip (simplified)

Replaces the current 10-tile cockpit row. Five tiles, all clickable filter shortcuts:

| Tile | Source | Action on click |
|---|---|---|
| **BUYER MATCHED** (979) | Count of canvass-pool with ≥1 strong-tier buyer | Filter list to buyer-matched only |
| **PITCH NOW · HIGH** (664) | High-value strong-tier matches | Apply preset filter |
| **MY CLAIMS** (4) | Owner-scoped active claims | Filter to my claims |
| **EXPIRING** (4) | My claims past 24h without feedback | Filter to expiring |
| **NEW TODAY** (n) | First-seen today | Filter to new today |

Dropped from the current cockpit (still accessible via Analyse / Settings if needed):
- LOG OUTCOMES (zero today, dead surface)
- CROSS-LISTED (moved to row detail tooltip, not headline)
- IN STOCK (moved to row badge, not headline)

#### 5.3.3 Filter rail + listing list

Same as current Work mode (it works). Left rail: suburbs / types / beds with counts. Main pane: listing rows with suggested-action chips and one-click claim / pitch / WhatsApp.

**Enhancement:** Each row now shows a small "Why match?" pill on hover (the AI tooltip §4.3).

#### 5.3.4 Detail slide-over

Same as current. Five tabs inside the slide-over: Overview / Buyers / Activity / Market / Source. Address-history viewable in Source tab.

#### 5.3.5 Action: WhatsApp prospect

The full flow specced in §9.

### 5.4 Tab 2: Opportunities

**Route:** `GET /corex/market-intelligence/opportunities` → `MarketIntelligenceController::opportunities()`

**Replaces:** `/corex/tracked-properties`.

**Structure:**

#### 5.4.1 Top filters (quick-pick chips)

```
[ All ] [ With address ] [ Without address ] [ Company stock ] [ Recently enriched ]
```

Default: **All**, sorted by buyer-match strength descending.

Plus a secondary filter row:
- Suburb dropdown
- Source dropdown (chrome_capture, p24, pp, cmainfo, manual_prospect_entry)
- Status dropdown (active, promoted, archived)
- Search box (address, erf, deed, external_id)

#### 5.4.2 Stats strip

| Tile | Source |
|---|---|
| **TOTAL TRACKED** (4,910) | Count |
| **MATCHING BUYERS** (n) | Count of TPs whose linked prospecting_listing has ≥1 strong match |
| **UNCLAIMED** (n) | Count without active claim |
| **WITH ADDRESS** (327) | Filterable indicator |
| **PROMOTED TO STOCK** (1) | Won mandates |

#### 5.4.3 Listing rows

Each row:
- Primary address (or "Address pending — click to add")
- Suburb · type · beds (when known)
- Sources badge cluster (P24, PP, Chrome, CMAInfo)
- Buyer match indicator (e.g. "3 strong matches")
- Status badge (Active / Promoted / Archived)
- Last enriched timestamp
- Hover actions: View · Edit Address · Claim · Prospect

#### 5.4.4 Detail page

`GET /corex/market-intelligence/opportunities/{tp}` → enhanced TP detail.

Sections:
- Primary address with [Edit] and [Add corrected address] buttons
- Address history (collapsible)
- Source chain (parsed timeline, not raw JSON)
- Linked prospecting_listings table
- Linked Property (if promoted)
- Buyer matches panel
- Market data panel — pulled from `market_data_points` where `tracked_property_id` matches (CMA data, deeds-office data)
- Action bar: Claim · Prospect · Promote to Stock · Edit Address · Merge Duplicate

#### 5.4.5 Edit Address flow

Modal form. Agent enters corrected address. On submit:
- Row written to `tracked_property_addresses` with `source_type = manual_agent`, `confidence = verified`, `verified_by_user_id = current user`, `is_primary = true`
- Previous primary demoted to history (kept, not deleted)
- `tracked_properties` primary-address cache refreshed
- Domain event fired: `TrackedPropertyAddressVerified`
- Toast: "Address updated. Future captures matching this address will auto-link."

#### 5.4.6 Merge Duplicate flow

Available to BMs and admins (`prospecting_setup.manage` permission).

Agent identifies two TPs that are the same real-world property. UI: "Merge this property into [search box for other TP]". On confirm:
- The "from" TP is marked `status = duplicate`, `duplicate_of_tracked_property_id = <to TP id>`
- All linked `tracked_property_external_refs`, `prospecting_listings`, `tracked_property_addresses` re-pointed to "to" TP
- Domain event fired: `TrackedPropertyMerged`

### 5.5 Tab 3: Analyse

**Route:** `GET /corex/market-intelligence/analyse` → `MarketIntelligenceController::analyse()`

Existing implementation kept (the demand-supply heatmap, opportunity pockets, market velocity, competitive landscape are all good).

**Enhancements:**

#### 5.5.1 Strategic Weekly Brief becomes real AI

Replace templated text with the AI surface in §4.1. Show "Generated by Ellie · 2h ago · [Regenerate]" footer.

#### 5.5.2 Demand pocket cells get AI narratives on click

Click a heatmap cell → side panel opens with the AI-generated narrative (§4.4) + the underlying data.

#### 5.5.3 Suburb deep-dive

New: click a suburb name anywhere in Analyse → suburb deep-dive panel. Shows everything `market_data_points` has for that suburb, AI-narrated, with charts (price distribution, median over time, days-on-market trends).

This is where the CMA Info data warehouse pays off. Three years of CMA reports give us suburb-level history that competitors don't have.

### 5.6 Tab 4: Market Pulse

**Route:** `GET /corex/market-intelligence/market-pulse` → `MarketIntelligenceController::marketPulse()`

**Replaces:** `/admin/p24`.

The raw P24 firehose. For the curious / the BM / the agent who wants to see "what's the market doing this minute."

Same content as current `/admin/p24`:
- KPI tiles (last import, emails 30d, active listings, new this month, avg price, IMAP status)
- Listings by suburb table (121 suburbs)
- Recent listings (last 200)
- Price changes (last 200)
- Import log

**Enhancements:**
- "Run Import" button stays, admin-only
- Sort suburbs by absorption rate (sales/month vs active listings), not just count
- Click suburb → suburb deep-dive (same panel as §5.5.3)
- Add filter: "Listings my agency could service" (in our coverage area)

### 5.7 Settings

`GET /corex/market-intelligence/settings` → `MarketIntelligenceController::settings()`

Permission: `prospecting_setup.manage`

Tabs inside settings:
- **Suburbs** — manage which suburbs MIC tracks for this agency
- **Property types** — manage type taxonomy
- **Bedroom segments** — manage bed-range buckets
- **Price bands** — manage price brackets for opportunity-pocket detection
- **Suggested action thresholds** — tune the pitch-now / expiring thresholds
- **AI** — view current cost spend, regenerate brief, model preferences
- **Report types** — view registered parsers, accuracy stats, recent flags

---

## 6. The "This Week" Hero Block — Deep Detail

Specced in §5.3.1 at the UX level. Here's the full data and generation pipeline.

### 6.1 Data assembly

Per agent, nightly at 02:30:

1. **Match count** — count of prospecting_listings with ≥1 strong-tier buyer match for this agent's buyer wishlists.

2. **Expiring claims** — count of agent's active claims with `claimed_at < now - 24h` and `feedback_at IS NULL`, status not in (lost, not_interested).

3. **Stale seller relationships** — count of agent's `properties` where last activity in audit log > 14 days ago and status in (active, under_offer).

4. **Demand pockets relevant to agent** — pockets where this agent's suburbs match and demand-to-supply ratio ≥ 2x.

5. **New listings in agent's area since Friday** — count of `tracked_properties` first_seen_at since last Friday in agent's suburbs.

### 6.2 Tile generation prompt (Haiku 4.5)

```
SYSTEM: You write daily action tiles for South African real estate agents.
Each tile is exactly one sentence, conversational, written in plain English.
No emojis in the sentence (the UI provides those separately).
Each sentence must mention the number and motivate action.
Output strict JSON: an array of tiles. Each tile has:
  - id (one of: matches, expiring, stale, pocket, new_listings)
  - sentence (≤ 16 words)
  - urgency (red, orange, blue, green, neutral)
  - action_label (≤ 4 words)
  - action_route (provided as input)

USER: Generate tiles for agent {{agent_name}} based on:
{{json_input}}

Only include tiles where the underlying count > 0.
If pocket data is present, ALWAYS include the pocket tile — it's the highest-value action.
```

### 6.3 Cache + refresh

`ai_narrative_cache` row:
- `cache_key`: `tiles:user:{user_id}:date:{date}`
- `input_hash`: sha256 of the JSON input
- TTL: 12 hours

If user visits Work tab and cache is older than 12h, regenerate on demand. If younger, serve from cache.

### 6.4 Fallback

If Anthropic API fails, generate tiles deterministically from a fixed template:
- "🔥 {n} properties match your buyers. Prospect today."
- "⏰ {n} of your claims expire in the next 24 hours. Action now."
- etc.

Functional but less natural. Indicated to user with a small "(template fallback)" tag.

---

## 7. Address Management — Full Detail

Specced at the model level in §3.2.1. Here's the full flow.

### 7.1 Address sources and confidence

| Source | Default confidence | Can overwrite primary? |
|---|---|---|
| `manual_agent` | verified | Yes |
| `manual_admin` | verified | Yes |
| `deeds_office` | high | Yes — replaces unverified primaries |
| `cmainfo` | high | Only if no verified address exists |
| `chrome_capture` (full page) | medium | Only if no medium-or-higher exists |
| `p24` (email) | low | Never overwrites primary |
| `pp` (capture) | medium | Only if no medium-or-higher exists |

### 7.2 Match enhancement

When a new ingestion event arrives, the match-or-create service consults `tracked_property_addresses` *in addition to* the existing 5 strategies:

**New Strategy 0 (highest priority):** check incoming address against `tracked_property_addresses.is_primary = true` AND all history rows for that TP. If incoming address normalises to any historical address, match. This is the silent-killer fix.

### 7.3 Address edit UI

In the Opportunities tab detail page:

```
┌────────────────────────────────────────────────────────────────┐
│  PRIMARY ADDRESS                                                │
│  55 Marine Drive, Margate                          [ Edit ]    │
│  Verified by Johan Reichel · 12 Mar 2026                       │
│                                                                 │
│  ADDRESS HISTORY (3)                                            │
│  ▶ 55 Marine Crescent, Margate                                 │
│    Source: P24 email · low confidence · 15 Feb 2026            │
│  ▶ 55 Marine Drive (no suburb)                                 │
│    Source: Chrome capture · medium · 20 Feb 2026               │
│  ▶ 55 Marine Drive, Margate                                    │
│    Source: P24 email · low confidence · 1 Mar 2026             │
└────────────────────────────────────────────────────────────────┘
```

### 7.4 Add corrected address (without removing existing)

For cases where the agent doesn't want to overwrite — e.g. the P24 address is wrong but they want to keep it visible so they remember which listing matched.

Same modal as Edit, but with an "Add as alternative, keep current as primary" checkbox.

### 7.5 Domain events

- `TrackedPropertyAddressAdded`
- `TrackedPropertyAddressVerified` (when an agent marks any address as verified)
- `TrackedPropertyAddressPrimaryChanged` (when primary swaps)

---

## 8. CMA Report Import — Full Pipeline

### 8.1 Upload UI

New top-level surface: `GET /corex/market-intelligence/settings/reports` (admin/BM only) and a quick-upload widget on the MIC Work tab ("Got a report? Drop it here →").

Two upload modes:

**Mode A — Single file with auto-detect:**

Agent drops a PDF. Backend runs each registered parser's `canParse()` method. Strongest match wins. UI confirms: "Detected: CMA Info Market Analysis. Correct?" with override dropdown.

**Mode B — Single file with explicit type:**

Agent picks file + selects report type from dropdown. Skips auto-detect.

**Mode C — Bulk drop (email inbox):**

Future enhancement (Phase 5) — staff forwards reports to `reports@corexos.co.za`, system auto-parses. Spec'd here, not built V1.

### 8.2 Parser interface

```php
namespace App\Services\MarketReports\Contracts;

interface MarketReportParser
{
    public function canParse(string $filePath): ParserConfidence;

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult;

    public function getVersion(): string;

    public function getReportTypeKey(): string;
}

class ParserConfidence
{
    public function __construct(
        public bool $canParse,
        public float $confidence, // 0.0 - 1.0
        public array $reasons,
    ) {}
}

class MarketReportParseResult
{
    public function __construct(
        public array $dataPoints, // array of arrays matching market_data_points schema
        public array $extractedAddresses, // for cross-referencing TPs
        public array $rawJson, // everything pulled, for archival
        public ?string $errorMessage = null,
    ) {}
}
```

### 8.3 Parsers shipped V1

`App\Services\MarketReports\Parsers\`:

- `CmaInfoMarketAnalysisParser` — handles the 10-page "25 Collison Street" style report
- `CmaInfoMedianSalesAnalysisParser` — the 4-page median trends report
- `CmaInfoPropertyValuationParser` — the full 11-page subject+comps report
- `CmaInfoSectionalTitleSalesParser` — the 300m radius ST sales report
- `GenericFallbackParser` — files the PDF, extracts text, no structured data

Each parser uses a layered approach:
1. Text extraction via `TextExtractionService` (pdftotext)
2. Regex extraction for known field patterns
3. AI fallback (Haiku 4.5 with vision) for ambiguous values

### 8.4 Spot-check audit pipeline

Defined in §4.5. Critical detail: runs as a queued job after `parse_completed_at` is set.

```php
namespace App\Jobs\MarketReports;

class SpotCheckMarketReport implements ShouldQueue
{
    public function __construct(public MarketReport $report) {}

    public function handle(SpotCheckService $spotChecker): void
    {
        $points = $this->report->dataPoints()
            ->inRandomOrder()
            ->limit(max(3, intval($this->report->data_points_count * 0.2)))
            ->get();

        foreach ($points as $point) {
            $result = $spotChecker->verify($this->report, $point);
            // Insert discrepancy if disagreement, etc.
        }

        $this->report->update([
            'spot_check_status' => $spotChecker->hasFlagged($this->report) ? 'flagged' : 'passed',
        ]);
    }
}
```

### 8.5 Parser accuracy dashboard

In Settings → Report Types:

| Parser | Version | Reports parsed | Avg points/report | Spot-check pass % | Status |
|---|---|---|---|---|---|
| CmaInfoMarketAnalysisParser | v1.2 | 47 | 28 | 96.4% | Trusted |
| CmaInfoMedianSalesAnalysisParser | v1.0 | 12 | 9 | 91.7% | Active |
| GenericFallbackParser | v1.0 | 6 | 0 | N/A | Fallback |

---

## 9. WhatsApp Action Flow

The killer interaction. From `This Week` tile → WhatsApp sent → property + contact created → claim made → feedback expected.

### 9.1 Trigger surfaces

1. **From a "This Week" tile** — "47 properties match your buyers. Prospect today." → opens filtered list
2. **From a listing row in Work or Opportunities** — WhatsApp icon button
3. **From a buyer's match panel** — "Send to this buyer via WhatsApp"
4. **From the slide-over Buyers tab** — same

### 9.2 The flow

Step 1: Agent taps WhatsApp icon on a listing row.

Step 2: Modal opens. Pre-populated:
- Property summary (auto-detected best contact details from `prospecting_listings` and TP graph)
- WhatsApp draft message (AI-generated using property facts + agent's house tone)
- Action checkboxes (all default checked):
  - ☑ Create / update contact for property owner
  - ☑ Link contact to property
  - ☑ Auto-claim this property (permanent claim)
  - ☑ Log WhatsApp send to activity timeline
  - ☑ Schedule 7-day follow-up if no response

Step 3: Agent edits message if desired, taps "Send via WhatsApp".

Step 4: Backend executes (transactional):
- Resolve or create `Contact`
- Resolve or create `Property` (or link to existing if already in stock)
- Convert any temp_pitch_lock to permanent `prospecting_claim` for this agent
- Insert `WhatsappMessage` record (audit trail)
- Open WhatsApp URL with pre-filled message in new window (whapi or wa.me link)
- Fire domain events: `WhatsAppMessageSent`, `ClaimCreated` (or `ClaimConverted`), `ContactCreated` (if new)
- Schedule follow-up reminder in agent's calendar

Step 5: Toast: "Sent. Property and contact created. Claimed for 14 days. Follow up scheduled."

### 9.3 The "feedback obligation" loop

Once a permanent claim exists, the system expects feedback. Per existing claim system enhanced:

- 24h after claim, if no `feedback_at`: status `expiring` (in agent's expiring tile)
- 48h: scheduled job notifies BM (new — currently missing)
- 7 days: claim auto-released back to pool unless agent has explicit "still working" feedback

This is what gives BMs visibility ("Johan claimed 47 properties last month, gave feedback on 41 — 87% follow-through. Marie claimed 31, fed back on 12 — 39%. Coaching opportunity.")

### 9.4 WhatsApp draft prompt (Haiku 4.5)

```
SYSTEM: You write WhatsApp prospecting messages for South African real estate
agents. Friendly, professional, brief (≤ 4 short sentences). Use the agent's
first name in the sign-off. Mention one specific buyer detail to show this
isn't spam. No emojis. No "Hi" — start with the address or the buyer fact.

USER: Generate a WhatsApp message for:
Agent: {{agent.first_name}} ({{agency.name}})
Property: {{property.address_or_description}}
Buyer match: {{strongest_match_summary}}
Tone: warm but business-like.
```

### 9.5 Domain events emitted

Each event lands a row in `agent_activity_events`:
- `whatsapp.draft_opened`
- `whatsapp.sent`
- `claim.created` (or `claim.converted_from_lock`)
- `contact.created` (if applicable)
- `property.created` (if applicable)
- `follow_up.scheduled`

These power Phase 5 auto-activity-tracking. Today nothing reads them. They land anyway.

---

## 10. Claim System Enhancements

### 10.1 Scheduled BM flag for stale claims

New job: `App\Jobs\Prospecting\FlagStaleClaimsJob`.

Schedule: every hour.

Logic: find all claims where `claimed_at < now - 48h` AND `feedback_at IS NULL` AND `flagged_at IS NULL` AND status NOT IN (lost, not_interested, listing).

For each: set `flagged_at`, fire `ClaimFlaggedAsStale` domain event, post notification to agent's BM dashboard, notify the agent.

### 10.2 Branch manager dashboard

New surface: `GET /corex/market-intelligence/team` → `MarketIntelligenceController::team()`

Permission: `prospecting_setup.manage` OR role in (manager, admin, super_admin).

Shows:
- Per-agent: active claims count, feedback rate %, claims expiring 24h, stale flagged claims
- Drill-down: click an agent → their full claim list
- Coaching: "Marie has 5 stale claims. Send her a nudge?"

### 10.3 Feedback templates

Quick-pick feedback options when an agent ticks "Add feedback" on a claim:
- Spoke to owner — interested, follow up [date]
- Spoke to owner — not interested
- Couldn't reach — left message
- Wrong number / address
- Already listed with [agency]
- Custom note (free text)

Each writes to the claim's `notes` (prepended timeline) and updates `feedback_at`.

### 10.4 Auto-release after 7 days no contact

Claims with no feedback and no status change after 7 days auto-release back to the canvass pool. Notification to agent: "Your claim on [property] expired due to no feedback. The property is back in the pool."

---

## 11. Legacy Retirement

### 11.1 Routes to remove

From `routes/web.php:2551-2580`:

```php
// All prospecting.* legacy routes — REMOVE
// All ProspectingController references — REMOVE
```

Replace with redirect group:

```php
Route::redirect('/prospecting', '/corex/market-intelligence', 301);
Route::redirect('/prospecting/{any}', '/corex/market-intelligence', 301)
    ->where('any', '.*');
```

### 11.2 Controller to remove

`App\Http\Controllers\ProspectingController` — delete after confirming no `route('prospecting.*')` calls remain.

Check all blade templates for `route('prospecting.` references; replace with `route('market-intelligence.')` equivalents.

### 11.3 Sidebar cleanup

Remove `/evaluation/index#tab=prospecting` reference. The Prospecting tab inside Evaluation is the third place the word lives — consolidate.

### 11.4 `/admin/p24` redirect

Add: `Route::redirect('/admin/p24', '/corex/market-intelligence/market-pulse', 301);`

Keep the controller methods (they back the new tab). Just no longer mounted at `/admin/p24`.

### 11.5 `/corex/tracked-properties` redirect

Add: `Route::redirect('/corex/tracked-properties', '/corex/market-intelligence/opportunities', 301);`

Keep the controller (`TrackedPropertyController`). Methods are now invoked by `MarketIntelligenceController::opportunities()`.

---

## 12. Permissions Matrix

### 12.1 Existing permissions (kept)

- `access_prospecting` — gates the entire MIC module
- `prospecting_setup.manage` — gates settings, team dashboard, merge actions
- `manage_p24` — gates Market Pulse "Run Import" button only
- `p24.view` — implicit from access_prospecting
- `access_portal_leads` / `portal_leads.view` — unchanged (separate concern)

### 12.2 New permissions

- `mic.edit_address` — gates address edit/add UI. Granted to: agent, manager, admin, super_admin.
- `mic.merge_duplicates` — gates duplicate merge. Granted to: manager, admin, super_admin.
- `mic.upload_reports` — gates CMA report upload. Granted to: agent, manager, admin, super_admin.
- `mic.view_team` — gates BM team dashboard. Granted to: manager, admin, super_admin.
- `mic.regenerate_brief` — gates manual brief regen. Granted to: admin, super_admin.
- `mic.view_ai_costs` — gates AI cost dashboard. Granted to: admin, super_admin.

All new permissions registered in `config/corex-permissions.php` and seeded via a new migration `2026_05_22_*_seed_mic_permissions.php`.

### 12.3 Role assignments

| Permission | agent | manager | admin | super_admin |
|---|---|---|---|---|
| access_prospecting | ✓ | ✓ | ✓ | ✓ |
| prospecting_setup.manage | – | ✓ | ✓ | ✓ |
| manage_p24 | – | ✓ | ✓ | ✓ |
| mic.edit_address | ✓ | ✓ | ✓ | ✓ |
| mic.merge_duplicates | – | ✓ | ✓ | ✓ |
| mic.upload_reports | ✓ | ✓ | ✓ | ✓ |
| mic.view_team | – | ✓ | ✓ | ✓ |
| mic.regenerate_brief | – | – | ✓ | ✓ |
| mic.view_ai_costs | – | – | ✓ | ✓ |

---

## 13. Multi-tenancy and the Shared Data Pool

### 13.1 Per-agency scoping (default)

Every new table in this spec carries `agency_id` with the `BelongsToAgency` trait and `AgencyScope`. Reads default to current agency only. Writes always set `agency_id` from the user's session.

Tables:
- `tracked_property_addresses` — agency-scoped reads and writes
- `market_reports` — agency-scoped (which agency uploaded it)
- `ai_narrative_cache` — agency-scoped where applicable
- `market_data_discrepancies` — agency-scoped
- `agent_activity_events` — agency-scoped

### 13.2 The shared market data pool (`market_data_points`)

Per the Johan-decision: data is decoupled from origin. Once parsed, market data points are anonymised and pooled.

**Schema-wise:** `market_data_points.agency_id` is the agency that *uploaded* the source report. Audit-only.

**Read-wise:** queries against `market_data_points` for any market intelligence purpose **do not filter by agency_id**. Every CoreX agency benefits from the aggregated pool.

**Read scopes:**
- `MarketDataPoint::query()` — returns all data points (shared pool default)
- `MarketDataPoint::auditScope()` — filters by agency_id (super_admin only, for audit purposes)

**Display-wise:** the source of any data point is NEVER displayed as an agency name. Only as a report type (CMAInfo, Lightstone, Deeds Office) and a date.

### 13.3 Legal / commercial framing

By uploading reports, an agency warrants they have rights to do so (per their existing data provider agreements with CMA Info, Lightstone, etc.). CoreX user agreement clause:

> By uploading market reports, you grant CoreX a non-exclusive right to extract, normalise, store, aggregate, and re-distribute the data contained therein within CoreX's market intelligence database. You warrant that you have the necessary rights from your data providers to authorise this use.

Standard SaaS pattern. Same model as Knowledge Factory → Lightstone → end users.

### 13.4 POPIA notice

Required before agency #2 onboarding. Covers:
- CoreX as responsible party for the aggregated market database
- Purpose limitation (market intelligence only — no resale of PII)
- Data subject rights (correction, deletion on request)
- Security controls
- Breach notification

Drafted by Elize ahead of agency onboarding. Not blocking MIC live for HFC (single tenant today).

### 13.5 Schema fixes — multi-tenancy debt

- `p24_listings.agency_id` — add column, backfill, add to AgencyScope
- `presentations.agency_id` — add column, backfill, add to AgencyScope

These are prerequisites to onboarding agency #2 — not blocking HFC live but must land in this build cycle.

---

## 14. Domain Events Catalogue

Every event below is fired by code in this build. Today, only `agent_activity_events` listens (logging the event). Tomorrow, Phase 5 listeners hook in without rewrites.

### 14.1 Tracked Property events

- `TrackedPropertyCreated` (existing, kept)
- `TrackedPropertyEnriched` (existing, kept)
- `TrackedPropertyPromotedToStock` (existing, kept)
- `TrackedPropertyAddressAdded` (NEW)
- `TrackedPropertyAddressVerified` (NEW)
- `TrackedPropertyAddressPrimaryChanged` (NEW)
- `TrackedPropertyMerged` (NEW)

### 14.2 Claim events

- `ClaimCreated` (NEW — or fired from existing service)
- `ClaimConvertedFromLock` (NEW)
- `ClaimFeedbackRecorded` (NEW)
- `ClaimFlaggedAsStale` (NEW)
- `ClaimReleased` (NEW)
- `ClaimAutoReleased` (NEW — 7 day timeout)

### 14.3 Communication events

- `WhatsAppDraftOpened` (NEW)
- `WhatsAppMessageSent` (NEW)
- `EmailDraftOpened` (NEW — for parity)
- `EmailMessageSent` (NEW)
- `CallLogged` (NEW)

### 14.4 Market Report events

- `MarketReportUploaded` (NEW)
- `MarketReportParsed` (NEW)
- `MarketReportSpotCheckFlagged` (NEW)
- `MarketDataPointSuperseded` (NEW)

### 14.5 AI events

- `AINarrativeGenerated` (NEW — for cost tracking)
- `AINarrativeFailedFallback` (NEW — for SLO tracking)

### 14.6 Single listener for now

`App\Listeners\Activity\LogAgentActivity` — listens to all events above, writes to `agent_activity_events`.

Future Phase 5: additional listeners for points calculation, auto-portal-notification, etc.

---

## 15. Future Enhancements (Architectural Constraints, Not Build Scope)

### 15.1 Exclusive auto-notification to portals

**Trigger:** Mandate signed against a property (existing event from e-sign module).

**Logic:**
1. On mandate signing, check `Property.tracked_property_id`. If null, no-op (property isn't linked to TP graph).
2. From the TP, walk `tracked_property_external_refs` to find all portal listing IDs (P24, PP).
3. For each portal listing, present agent with confirmation: "We found this property advertised on P24 (ref P24-12345). Send takedown notice with your exclusive mandate?"
4. On confirm: auto-compose email to portal listings@property24.com (or relevant portal address) with attached signed mandate (already filed via e-sign system).
5. Log domain event `PortalTakedownRequested`.

**Architecture requirements met by this spec:**
- TP→portal listing linkage exists (`tracked_property_external_refs`)
- Address history allows match even if P24 publishes wrong address
- E-sign system already files signed mandates against properties

**Not built V1. Architecture preserves the path.**

### 15.2 Auto-activity tracking and points

**Trigger:** Domain events from §14.

**Logic:**
1. New listener: `App\Listeners\Activity\AwardActivityPoints` listens to all activity events
2. Points table seeded with per-event-type weights (claim.created = 1, whatsapp.sent = 2, mandate.signed = 50, etc.)
3. Daily rollup job aggregates points per agent per day
4. Replaces / augments existing manual daily activity capture

**Architecture requirements met by this spec:**
- Every action fires a domain event
- `agent_activity_events` is the canonical event log
- `LogAgentActivity` listener is already wired

**Not built V1. Architecture preserves the path.**

### 15.3 AI photo → feature list → heading → description (own stock)

Per earlier discussion (50 photos × 50 properties / month). Two-stage pipeline:
1. Haiku 4.5 vision: per-photo → structured feature JSON
2. Sonnet 4.6: aggregated features + property facts + agency style guide → heading + description

Cost ~R5-15/month per agency.

**Architecture requirements:** `properties` already has photo storage. `AnthropicGateway` already in place. New service `App\Services\AI\PropertyContentGenerator` slots in.

**Phase 4 build. Not V1.**

### 15.4 TVA / Lightstone API integration

Replaces manual report upload for agencies on a CoreX data subscription.

**Architecture requirements:** `market_data_points.source_type` enum extended to include `lightstone_api`, `tva_api`. New ingestion services slot in alongside parsers.

**Not built V1. Architecture preserves the path.**

### 15.5 Bridge mode for new agencies

Agency signs up to CoreX. They keep their existing CMA Info / VA / Lightstone subscription. CoreX accesses via their credentials.

**Architecture requirements:** Per-agency API credential storage (encrypted). Existing `branch_*` integration credential storage pattern extends.

**Not built V1.**

---

## 16. Build Sequence

Ordered list of VS Code prompts. Each is single-purpose. Each one ends with the verification chain (php -l, view:clear, dev-check.ps1, Tinker verification per CLAUDE.md hard rules).

### 16.1 Phase A — Schema and foundations (1 day, ~5 prompts)

**Prompt A1:** Create migrations for new tables.
- `tracked_property_addresses`
- `market_reports`
- `market_report_types`
- `market_data_points`
- `market_data_discrepancies`
- `ai_narrative_cache`
- `agent_activity_events`

Plus schema fixes:
- Add `agency_id` to `p24_listings`
- Add `agency_id` to `presentations`
- Add identifier columns to `properties`

Run migrations. Verify with Tinker that each table exists and has expected columns.

**Prompt A2:** Seed `market_report_types` (the V1 list from §3.2.3).

**Prompt A3:** Models for new tables + relationships.
- `TrackedPropertyAddress` (BelongsToAgency, BelongsTo TrackedProperty)
- `MarketReport`, `MarketReportType`, `MarketDataPoint`, `MarketDataDiscrepancy`
- `AINarrativeCache`
- `AgentActivityEvent`

Verify in Tinker: create, scope, retrieve.

**Prompt A4:** Domain events catalogue from §14. All new event classes. Single listener `LogAgentActivity`.

**Prompt A5:** Permission seeding from §12.

### 16.2 Phase B — AI infrastructure (1 day, ~3 prompts)

**Prompt B1:** `AnthropicGateway` service. API key config. Model selection. Cost logging. Retry / fallback. Cache integration.

Verify with Tinker: send a test prompt to Haiku, get response, see row in `ai_narrative_cache`.

**Prompt B2:** Cache invalidation logic. Sweep job for expired narratives. Admin dashboard at `/admin/ai-usage`.

**Prompt B3:** Address fuzzy-match AI integration into `TrackedPropertyMatchOrCreateService`. New Strategy 6 (after the 5 deterministic) consults Haiku for ambiguous cases.

### 16.3 Phase C — Address management (1 day, ~3 prompts)

**Prompt C1:** Backfill `tracked_property_addresses` from current `tracked_properties` primary fields. Every TP gets one row with `source_type` derived from the existing source_chain, `is_primary = true`.

**Prompt C2:** Match-or-create service enhancement — consult `tracked_property_addresses` history before falling through to fuzzy match. Address normalisation cache.

**Prompt C3:** Edit Address UI on Opportunities detail page. Add Address (alternative) UI. Address history display.

### 16.4 Phase D — MIC module restructure (2 days, ~6 prompts)

**Prompt D1:** New top-level controller `MarketIntelligenceController` (refactor existing). Routes for four tabs: work, opportunities, analyse, market-pulse. Sidebar single entry.

**Prompt D2:** Work tab — "This Week" hero block UI (deterministic fallback first, before AI lands).

**Prompt D3:** Work tab — stats strip simplification, filter rail, listing list (refactor from existing).

**Prompt D4:** Opportunities tab — folded-in TrackedProperty index, four filter chips, address-aware display, detail page with action bar.

**Prompt D5:** Analyse tab — keep existing services, swap StrategicBriefService to use AnthropicGateway for narrative.

**Prompt D6:** Market Pulse tab — migrate `/admin/p24` content into MIC. Redirect old URL.

### 16.5 Phase E — AI narratives wired (1 day, ~4 prompts)

**Prompt E1:** "This Week" tile generation — Haiku 4.5 service, nightly cron, on-demand refresh.

**Prompt E2:** Strategic Weekly Brief — Sonnet 4.6 generation, daily cron.

**Prompt E3:** Per-listing "why match" tooltip — Haiku 4.5, lazy load on hover.

**Prompt E4:** Demand pocket narratives — Sonnet 4.6, lazy on click.

### 16.6 Phase F — CMA report import (2 days, ~5 prompts)

**Prompt F1:** Upload UI (Settings → Reports + Work tab quick widget). File storage. `MarketReport` creation.

**Prompt F2:** Parser interface + `CmaInfoMarketAnalysisParser`. Register in `market_report_types`. Tested against `/mnt/user-data/uploads/Market_Analysis___25_COLLISON_STREET.pdf` sample.

**Prompt F3:** Remaining parsers — `CmaInfoMedianSalesAnalysisParser`, `CmaInfoPropertyValuationParser`, `CmaInfoSectionalTitleSalesParser`, `GenericFallbackParser`.

**Prompt F4:** Spot-check audit job — Haiku 4.5 vision per data point sample. `MarketDataDiscrepancy` creation. Notification to super admin dashboard.

**Prompt F5:** Parser accuracy dashboard in Settings.

### 16.7 Phase G — Claim system enhancements (1 day, ~3 prompts)

**Prompt G1:** `FlagStaleClaimsJob` + scheduler. `ClaimFlaggedAsStale` event.

**Prompt G2:** Branch manager team dashboard at `/corex/market-intelligence/team`.

**Prompt G3:** Feedback templates UI + auto-release after 7 days job.

### 16.8 Phase H — WhatsApp action flow (1 day, ~3 prompts)

**Prompt H1:** WhatsApp draft modal + AI prompt service + send button.

**Prompt H2:** Transactional handler — contact resolve/create, property resolve/create, claim convert, follow-up schedule.

**Prompt H3:** WhatsApp message log + activity timeline integration.

### 16.9 Phase I — Legacy retirement (half day, ~2 prompts)

**Prompt I1:** Remove `ProspectingController` and all `prospecting.*` routes. Redirects. Blade template route reference replacements.

**Prompt I2:** Sidebar cleanup. Remove `/evaluation` prospecting tab.

### 16.10 Phase J — Verification and demo prep (half day, ~2 prompts)

**Prompt J1:** End-to-end smoke test — agent walkthrough of all four tabs, address edit, report upload, WhatsApp send.

**Prompt J2:** Demo data seeder enhancement — ensure local has realistic counts for each tile in "This Week". Ensure at least 5 reports parsed for AI narratives to have grist.

**Total estimated prompts: ~36. Estimated build time: 10-12 working days.** Calendar-wise, with two devs working in parallel: 5-6 days. Realistically: 7-8 days given testing and inevitable surprises.

**Target ship date:** Friday 29 May or Sunday 31 May for live. Soft Monday 26 May was aspirational; quality wins over date.

---

## 17. Verification Checklist

What "done and working" looks like, by surface:

### 17.1 MIC top-level

- [ ] `/corex/market-intelligence` loads default to Work tab
- [ ] Four tabs render and route correctly
- [ ] Sidebar has one MIC entry, badge updates
- [ ] Old URLs (`/corex/tracked-properties`, `/admin/p24`, `/prospecting`) redirect

### 17.2 Work tab

- [ ] "This Week" block renders with AI-generated tiles
- [ ] Tiles only show when count > 0
- [ ] Each tile's action button leads to correct filtered view
- [ ] Stats strip shows 5 tiles, all clickable
- [ ] Listing list loads with suggested actions

### 17.3 Opportunities tab

- [ ] Filter chips work (All / With address / Without address / Company stock / Recently enriched)
- [ ] Address-less rows show "Address pending — click to add"
- [ ] Edit Address modal works, creates history row, demotes previous primary
- [ ] Merge Duplicate flow works (BM only)
- [ ] Detail page shows address history, source chain timeline, linked data

### 17.4 Analyse tab

- [ ] Strategic Brief is AI-generated (Ellie attribution visible)
- [ ] Demand pocket cells open narrative panel with AI text
- [ ] Suburb deep-dive panel pulls from market_data_points

### 17.5 Market Pulse tab

- [ ] All `/admin/p24` content present
- [ ] Run Import button (admin only) still works
- [ ] Suburb click opens deep-dive

### 17.6 CMA report import

- [ ] Upload from Settings works (auto-detect + explicit type)
- [ ] Quick-upload widget on Work tab works
- [ ] Each V1 parser succeeds on its sample file
- [ ] Spot-check audit runs in background, flags discrepancies
- [ ] Parser accuracy dashboard renders

### 17.7 WhatsApp flow

- [ ] WhatsApp icon on listing rows opens modal
- [ ] AI draft message generates
- [ ] All checkboxes default checked, can be unticked
- [ ] On send: contact, property, claim created transactionally
- [ ] WhatsApp URL opens in new window with pre-filled message
- [ ] Activity log shows the WhatsApp send
- [ ] Follow-up scheduled in calendar

### 17.8 Claim enhancements

- [ ] Stale-claim flag job runs hourly
- [ ] BM gets notification on stale flag
- [ ] Team dashboard shows per-agent stats
- [ ] Feedback templates work

### 17.9 AI infrastructure

- [ ] `/admin/ai-usage` shows token spend per agency
- [ ] Cache hit rate visible
- [ ] Fallback works when Anthropic API mocked-down

### 17.10 Multi-tenancy fixes

- [ ] `p24_listings` carries `agency_id` and is scoped
- [ ] `presentations` carries `agency_id` and is scoped
- [ ] HFC data unchanged after backfill

### 17.11 Legacy retirement

- [ ] `ProspectingController` removed
- [ ] No blade references to old route names
- [ ] All `/prospecting/*` URLs redirect

### 17.12 Domain events

- [ ] Each event in §14 fires from its trigger
- [ ] `LogAgentActivity` listener writes to `agent_activity_events`
- [ ] Event payload contains the data Phase 5 will need

---

## Appendix A: CMA Sample Data Reference

The PDFs uploaded with this spec for parser development:

- `Market_Analysis___25_COLLISON_STREET.pdf` (corex-templated, 10pp)
- `01_Market_Analysis_5_Sue_Casa_Lilliecrona_boulevard.pdf` (corex-templated)
- `5_Sue_Casa_Presentation.pdf` (presentation-style)
- `Median_Sales_Analysis_MANABA_BEACH.pdf` (CMA Info native, 4pp)
- `Property_Valuation_25_COLLISON_STREET_MANABA_BEACH.pdf` (CMA Info native, 11pp)
- `Sectional_Title_sales_in_300m_of_SUE_CASA_25_COLLISON_STREET_MANABA_BEACH.pdf` (CMA Info native, 3pp)

These constitute the test corpus for V1 parsers. Each parser's `tests/Feature/MarketReports/<ParserName>Test.php` asserts known data points are extracted correctly.

---

## Appendix B: Anthropic API Cost Model

At HFC's current volume (single agency, ~50 properties/month workflow):

| Surface | Model | Frequency | Monthly tokens | Monthly cost (USD) | Monthly cost (ZAR) |
|---|---|---|---|---|---|
| Strategic Weekly Brief | Sonnet 4.6 | 1/day | 30k in + 5k out | $0.165 | R3.10 |
| "This Week" tiles | Haiku 4.5 | 1/day/agent × 8 agents | 80k in + 16k out | $0.16 | R3.00 |
| Listing tooltips | Haiku 4.5 | ~500 lazy/month | 50k in + 10k out | $0.10 | R1.90 |
| Demand pocket narratives | Sonnet 4.6 | ~100 lazy/month | 30k in + 12k out | $0.27 | R5.10 |
| Spot-check audits | Haiku 4.5 vision | 200 checks/month | 300k in + 4k out | $0.32 | R6.00 |
| Address fuzzy match | Haiku 4.5 | ~100 calls/month | 30k in + 2k out | $0.04 | R0.80 |
| **Total** | | | | **$1.06** | **R20.00** |

With Batch API (50% off non-realtime) and prompt caching (90% input savings on system prompts):
**Effective monthly cost: ~R10/agency for the full AI surface.**

Genuinely a rounding error at agency scale. Quality is the constraint, not cost.

---

## Appendix C: Future Enhancements Quick Reference

For easy retrieval when planning future builds — these are decisions already made architecturally but not yet built:

1. **Exclusive auto-notification to portals** — §15.1
2. **Auto-activity tracking and points** — §15.2
3. **Photo → feature list → heading → description (own stock AI)** — §15.3
4. **TVA / Lightstone API integration** — §15.4
5. **Bridge mode for new agencies on existing data subscriptions** — §15.5

---

**End of specification.**

**Approval:** Johan Reichel — [signature pending in chat]
**Implementation start:** Upon Johan's approval in chat.
**First build prompt:** Phase A1 (schema migrations).