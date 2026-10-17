# AT-456 — AI Template Builder, Article Library, Post Scheduler & AI Spend Safety

> Status: **Part A BUILT on QA2 2026-10-17 (Johan: "save spec and build") — awaiting Johan's test on QA2.** **Part B BUILT 2026-10-17 (QA2) — awaiting Johan's test.** **Part C BUILT 2026-10-17 (QA2) — awaiting Johan's test.** **Part D BUILT 2026-10-17 (QA2) — awaiting Johan's test.** All four parts built.
> Drafted 2026-10-14 on QA2 from the AT-456 investigation conversation.
> Every business decision below is Johan's, quoted or plainly paraphrased from that conversation.
> Pillars: **Property** (read), **Agent** (read/write own drafts + posts), **Agency** (scope + AI safety).
> Related specs: `ad-manager.md` (§12 render kernel, §18 variants, §19 Template Manager),
> `marketing-ai-copy.md`, `ai-cost-ledger.md`, `corex-domain-events-spec.md`.

Build order (one lane at a time, Sonnet): **A** AI Spend Safety → **B** AI Template Builder → **C** Article Library → **D** Post Scheduler.
A comes first because B and C both spend money and A is the only thing standing between them and a runaway.

---

## 0. Decisions already made (do not re-ask)

| # | Decision | Who |
|---|----------|-----|
| 1 | Remove the per-agency monthly rand AI budget and its hard stop, for ALL features. | Johan |
| 2 | Replace it with: **email alert at R30** to `andre@corexos.co.za`, **automatic cut-out at R50**, both over a **24-hour window**. | Johan |
| 3 | AI usage page gets a **"turn AI back on"** control plus usage visibility (spend, templates made, etc.). | Johan |
| 4 | AI template generation capped at **20 per agency per month**, adjustable per agency by Johan. | Johan |
| 5 | AI builds templates **inside the existing Template Manager**; input = an idea box + optional reference image. | Johan |
| 6 | Articles never auto-post. They sit in a library; an agency uses one only if it wants to. | Johan |
| 7 | Article sources are a **link list in a system-dev section** (PPRA not required). Articles live in the Ad Manager area for agencies. | Johan |
| 8 | Post scheduling happens **inside CoreX** (a CoreX timer publishes), not via Facebook's scheduler. | Johan |
| 9 | Article Library briefs are **short (150–250 words)** in v1 — no fetching of full third-party pages. Accepted. | Johan |
| 10 | Alert recipients: `andre@corexos.co.za` (default). The list is a platform setting, so adding another address needs no code change. | Johan |
| 11 | The R30 alert is measured over the **same rolling 24 hours** as the R50 cut-out. (Confirmed 2026-10-17.) | Johan |
| 12 | The R30 / R50 / 24h figures and the alert address are **editable platform settings with those defaults**, not hard-coded — and they live in a **new section in Dev Settings** (see A.4). (Confirmed 2026-10-17.) | Johan |
| 13 | The cut-out applies to **every** AI call (Ellie chat — including mid-conversation — photo analysis, voice, ad copy, briefs, DocuPerfect, presentations, and the new features). Today only 3 of them were ever checked against the old budget. (Confirmed 2026-10-17.) | Johan |

---

## A. AI Spend Safety (replaces the agency rand budget)

### A.1 What is removed
- `canMakeAiCall()` gating in `MarketingCopyService`, `AnthropicGateway`, `WarmThisWeekTilesJob`.
- `AgencyAiBudgetWarning` **and** `AgencyAiBudgetCapped` events (both only ever fired by the budget code) and the gateway's budget-warning detection. `Agency::canMakeAiCall()` / `aiBudgetStatus()` / `aiBudgetUsedPct()` / `aiBudgetRemaining()` and the `AI_BUDGET_STATUS_*` constants removed; `aiBudgetUsedZar()` kept (it feeds "Spend — this month").
- The budget form/columns on `admin/ai-usage` (`ai_monthly_budget_zar`, warning %, hard-cap %, overage). Route `admin.ai-usage.budget.update` is removed.
- DB columns `ai_monthly_budget_zar`, `ai_budget_*` are **left in place, unused** (no destructive migration now; drop in a later cleanup once nothing reads them). Removed from `Agency::$fillable` / `$casts` so nothing writes them.
- `tests/Feature/AI/AiUsageDashboardTest.php` and `AiUsageLedgerIntegrationTest.php` updated for the removal.
- **Kept:** `ai_usage_events` ledger, `AiUsageRecorder`, `AiCostCalculator`, the dashboard's spend/daily-burn views.

### A.2 New behaviour
- **Window:** rolling 24 hours of `ai_usage_events.cost_zar` per `agency_id` (index `(agency_id, occurred_at)`).
- **Alert (R30):** when an agency's 24h spend first reaches the alert level, email the alert recipients: agency name, spend, top 3 sources, link to its AI usage page. Sent **at most once per agency per 24h** (`agencies.ai_alert_last_sent_at`).
- **Cut-out (R50):** when 24h spend reaches the cut-out level, set `agencies.ai_paused_at`, `ai_paused_reason`, `ai_paused_spend_zar`. While paused every AI call for that agency is refused with a plain message ("AI is paused for your agency — please contact CoreX support."). Email recipients the cut-out notice too.
- **Resume:** owner-only button on the agency's AI usage page sets `agencies.ai_resumed_at` and clears the pause. The guard counts spend **since `max(now − 24h, ai_resumed_at)`** — without this an agency would re-trip instantly, because the last 24h still contains the R50. Resume also resets the alert state and writes an audit entry (who, when, spend at the time).
- **System calls** (`agency_id` null — e.g. Article Library): same two thresholds evaluated across null-agency events platform-wide; state stored as `DevSetting`s (`ai_system_paused_at`, `ai_system_paused_spend_zar`, `ai_system_resumed_at`, `ai_system_alert_last_sent_at`). Shown as a "Platform AI" strip above the agency table with its own **Turn AI back on**. My decision, not discussed.
- **Bucket resolution** is identical to `AiUsageRecorder` (explicit agency → signed-in user's agency → system), so the guard always checks the bucket the recorder charges.
- **History / audit:** every alert, cut-out, resume and settings change is a row in `ai_spend_guard_events` (agency nullable, type, spend at the time, threshold, user, details). Append-only; owner-only readers; deliberately not `BelongsToAgency` (same documented deviation as `ai_usage_events`).
- **A cut-out supersedes the alert:** if one call jumps straight past R50, only the cut-out email is sent.
- **Emails** are sent synchronously and best-effort (a failed send is logged and never undoes a pause); every alert/cut-out also writes `AI SPEND ALERT` (warning) / `AI SPEND CUT-OUT` (critical) to the log.
- **Known limit:** spend is recorded after a call, so one in-flight call can overshoot R50 by its own cost. Acceptable; stated so nobody is surprised.

### A.3 One choke point — fix the class, not the instance
- New `App\Services\AI\AiSpendGuard`: `assertAllowed(?int $agencyId): void` (throws `AiPausedException` with the friendly message) and `evaluate(?int $agencyId): void` (called by `AiUsageRecorder::record()` after each write; trips alert/cut-out).
- **Every** direct Anthropic caller checks the guard first: `AnthropicGateway`, `MarketingCopyService`, `IntentExtractionService`, `VisionRecognitionService`, `ImporterAiService`, `AiFieldMapperService`, `ClaudeVisionParserService`, `AIExtractionService`, `EllieAgentService`, plus the new B and C services. (`AiSummaryService`, `StrategicBriefService`, `ThisWeekTileBuilder`, `SpotCheckMarketReportJob` and the MIC narrative endpoints go through `AnthropicGateway`, so the gateway check covers them.)
- **What the user sees when paused, per surface (as built):**
  | Surface | Paused behaviour |
  |---|---|
  | Gateway surfaces with a templated fallback (MIC briefs/narratives, This Week tiles, market-report spot check) | Templated text, no spend — exactly as before for a budget-capped agency. The fallback's reason is the plain "AI is paused…" sentence. |
  | Gateway surfaces without a fallback (presentation AI summary, DocuPerfect PDF import) | `AiPausedException` → the surface's existing error line shows "AI is paused for your agency — please contact CoreX support." |
  | Ad copy (`MarketingCopyService`) | Mapped onto `AiCopyUnavailableException` with the same sentence — the existing friendly path. |
  | Ellie chat | Checked before **every** round-trip, so a cut-out stops Ellie mid-conversation; the reply is the sentence. |
  | Mobile voice | `{"error": "<sentence>"}`, HTTP 503 — same shape as a transcribe failure. |
  | Photo analysis (queued) | Analysis marked failed with the sentence; not retried (a retry cannot help while paused). |
  | DocuPerfect .docx / PDF import | Import refused with the sentence as its error line (previously AI failure fell back to regex silently — a pause must not be silent). |
  | Presentation evidence (listing/article extraction) | Already degrades to "no AI result" on any failure; a pause means no spend and no AI summary, ingestion continues. |
- `AiPausedException` extends `RuntimeException`, so every pre-existing catch still handles it.
- **Guard test:** `tests/Unit/AiSpendGuardCoverageTest.php` scans `app/` for `v1/messages` / `api.anthropic.com` and fails if a file lacks `AiSpendGuard`, so a future service cannot bypass it silently. (Callers that use `AnthropicGateway` are covered by the gateway itself.)

### A.3b Hidden-spend gap fix (as built, 2026-10-17)
Two spend paths the ledger/guard could not see were checked after Part A:
- **hf-ai `/chat` (legacy AI Buddy proxy) — was unguarded paid spend.** `/opt/hf-ai/app.py` `chat()` (`@app.post("/chat")`, ~line 320) calls Anthropic (`client.messages.create`, `ANTHROPIC_MODEL`, default `claude-haiku-4-5`, `max_tokens=1024`) — it is **not** a local model. Its reply is `{reply, mode, sources}` with **no token usage**. `AiChatProxyController` now: checks `AiSpendGuard::assertAllowed()` (the signed-in user's agency bucket) and, when paused, replies `{reply: <paused sentence>, mode: "paused"}` without calling the service; otherwise records each non-error call as `ellie_chat_proxy` with **estimated** tokens (see `ai-cost-ledger.md` §3.1; knobs in `config/services.php` → `hf_ai.chat_*`). Only caller today: nginx `location = /ai/chat` on `performance.hfcoastal.co.za` → the old `ai/buddy` page (the in-app `/ai-buddy` route already redirects to Ellie). Exact usage needs the Python service to return Anthropic's `usage` block — not changed here (shared service).
- **Embeddings — no spend exists.** The brief assumed OpenAI; OpenAI was removed 2026-07-26 and `EmbeddingService` calls the self-hosted hf-ai `/embed` (local BGE ONNX model, free). No pricing config, ledger row or guard was added — a paused agency's knowledge search correctly keeps working (Ellie itself refuses when paused). No other OpenAI caller exists in `app/`.
- **Guard test extended:** `AiSpendGuardCoverageTest` now also flags `api.openai.com`, `v1/embeddings` and `3100/chat` in `app/` without `AiSpendGuard`, and a unit test exercises the scanner on fixtures. Tests: `tests/Feature/AI/AiChatProxyTest.php`.

### A.4 Platform settings — new Dev Settings section "AI Spend Safety" (decision 12)
- **Settings** (`DevSetting` store): `ai_alert_threshold_zar` (30), `ai_cutout_threshold_zar` (50), `ai_guard_window_hours` (24), `ai_alert_recipients` (`andre@corexos.co.za`; stored as a JSON list, same as the other Dev Settings alert lists). Defaults live in `AiSpendGuard::DEFAULT_*` and apply until the first save.
- **Where (as built):** a new **section** of the existing Dev Settings hub — left rail group **AI → AI Spend Safety**, deep link `/admin/dev-settings?s=ai_spend` — saved by the hub's own `admin.dev-settings.update` (`owner_only`). This is how Dev Settings already holds its sections (Queue worker emails, Mobile app releases…), so it needs no new sidebar item: the existing **Dev Settings** sidebar entry reaches it. A "Change thresholds" link on the AI usage page and its agency page goes straight to the section.
- The section is saved only when it was rendered (`ai_spend_present` marker, same guard as Mobile app releases); nothing is written unless every field validates.
- **Validation:** alert > 0; cut-out > alert; window 1–168 hours; each recipient a valid email, at least one required. Every save writes an audit entry (who, old → new).
- Changes take effect on the next AI call — no deploy, no cache to clear.
- These are platform-owner controls, not agency settings → **not** in the Agency Setup Wizard (recorded in the "NOT in wizard" list below).

### A.5 AI usage page (`/admin/ai-usage`, owner-only — existing gate `owner_only` + `mic.view_ai_costs`)
Per-agency table, one row per agency:

| Column | Notes |
|--------|-------|
| Agency | |
| Spend — last 24h | live, vs R30 / R50 |
| Spend — this month | |
| Status | Active · Alerted · **Paused** |
| Template generations | `used / limit` this month — **built with Part B** (sortable) |
| AI templates saved | count of `property_ad_templates.ai_generated` per agency, archived ones included — **built with Part B** (sortable) |
| Library articles used | count of `agent_articles.library_article_id` per agency (copies the agency's people later deleted still count) — **built with Part C** (sortable) |
| Actions | **Turn AI back on** (only when Paused), View detail |

- **Search:** agency name. **Sort:** every column; default = 24h spend, highest first. **Filters:** status (All · Active · Alerted · Paused), month. **Pagination:** 25. **Empty states:** "No agencies yet." (every agency is listed, usage or not, so a usage-based message would never be true); "No agencies match these filters." + Clear filters.
- "Spend — last 24h" is the spend the guard is counting: for an agency turned back on inside the window it is the spend since the resume, labelled "since turned back on <time>".
- Agency detail page: per-source breakdown (existing), AI Spend Safety status + **Turn AI back on**, the alert/pause/resume history (who, when, spend at the time). The per-agency **template generation limit** input (default 20) is **built with Part B** (owner-only POST, whole number 0–1000, audited as a `template_limit` row in `ai_spend_guard_events`: who, old → new).
- Scoping: owner-only at the route; no agency user can reach it.

### A.6 Acceptance
Automated proof: `tests/Feature/AI/AiSpendGuardTest.php` (16 tests), `tests/Unit/AiSpendGuardCoverageTest.php`, updated `AiUsageDashboardTest` / `AiUsageLedgerIntegrationTest`.
- [x] No AI call anywhere checks `ai_monthly_budget_zar`; the budget form is gone.
- [x] 24h spend ≥ R30 → exactly one email to the configured recipients; second event inside 24h sends none.
- [x] 24h spend ≥ R50 → agency paused; gateway, ad copy and Ellie proven to refuse without calling Anthropic; other agencies unaffected. (Per-surface behaviour table in §A.3.)
- [x] Resume works and the agency is not re-paused by old spend; resume is audited; non-owner refused.
- [x] Guard-coverage test passes.
- [x] Changing a threshold or recipient in Dev Settings → AI Spend Safety changes behaviour without a deploy; settings changes are audited; invalid levels / empty list refused.
- [ ] Johan tests on QA2.

---

## B. AI Template Builder (inside Template Manager)

### B.1 What it does
On `tools/ad-manager/templates` a **"Create with AI"** button opens a panel: **idea box** (text, required) + **reference image** (optional) + **ad size** (Facebook / Instagram / Story / WhatsApp) + **property type** (optional, drives §18 variants). Generate → **exactly three previews** (Johan 2026-10-17: "make it 3 templates to choose the best one") → agent saves any they like → each saved option becomes a normal `PropertyAdTemplate` in the agency library (editable in the Ad Builder, archivable, restorable).

### B.2 Pillars
Agency (READ: brand colours/logo — never HFC's; WRITE: templates), Agent (WRITE: creator), Property (none — templates are reusable, §3).

### B.3 How it works
- Model: `config('services.anthropic.models.quality')` (Sonnet). Output must be JSON matching the `layout_json` element schema in `ad-manager.md` §3; **validated server-side** (known fields only, numeric bounds, elements inside the canvas, min font size, contrast check against background). Invalid output → one automatic retry → friendly failure, **not counted** against the limit.
- Reference image: sent as an image to the model as *inspiration only* (layout, colour mood, hierarchy). JPG/PNG ≤ 5 MB. Stored privately, purged after 7 days. The AI is limited to the Ad Builder's own elements (text, shapes, gradients, photos, badges).
- Previews rendered with the existing render kernel (`public/js/corex-ad-render.js`) against a sample property — no new renderer.
- Ledger: new `AiUsageEvent::SOURCE_AD_TEMPLATE`; `AiSpendGuard::assertAllowed` first.
- Estimated cost: ~R0.90 per option set call with Sonnet; ~R2.70 for three options; image input adds pennies.

### B.4 Data model
- `property_ad_templates`: add `ai_generated` boolean default false, `ai_idea` text nullable.
- New `ai_template_generations`: `id, agency_id, user_id, idea (text), ad_size, property_type nullable, reference_image_path nullable, status (success|failed), cost_zar, created_at`. `BelongsToAgency`. Only `success` rows count toward the limit.
- `agencies.ai_template_monthly_limit` unsigned int, default **20**, owner-edited on the AI usage agency page (A.5). Counted per calendar month, per **idea submitted** (a refine counts as one). Agent sees "14 of 20 generations left this month"; at 0 the button explains the reset date.

### B.5 Template Manager changes (§19 stays otherwise unchanged)
AI badge on AI-made rows; new filter **Created: Everyone · Only mine · AI-made**. Search/sort/pagination/empty states per §19.3 untouched.

### B.6 Permissions
Reuse the template-create gate (`access_properties` + `access_ad_manager`); no new key. Server re-checks agency scope.

### B.7 Acceptance
Automated proof: `tests/Feature/Tools/AiTemplateBuilderTest.php` (39 cases incl. the validator's reject list), `tests/Feature/AI/AiTemplateUsageAdminTest.php` (11).
- [x] "Create with AI" yields three valid previews for a one-line idea; each saves as a normal template that opens in the Ad Builder. (Tests prove the validated, builder-shaped layout and the save; the *look* of real model output is Johan's judgement on QA2.)
- [x] Uploading a reference image sends it to the model as an image block; it is stored privately and purged after 7 days by `ai-templates:purge-reference-images` (scheduled daily 03:15, runnable by hand).
- [x] Invalid AI output never reaches the user and doesn't consume allowance (one automatic retry, then a friendly failure).
- [x] 21st generation in a month is refused with the reset date and no AI call; raising the agency limit takes effect immediately.
- [x] Second agency sees its own colours/logo and none of HFC's (brand block is read from the requesting agency's record; test asserts agency B's prompt carries B's colours only).
- ~~**Pre-build trial (before any UI):** run the prompt against 5 real listings and let Johan judge the ads.~~ **Struck — Johan 2026-10-17: no trial — build the screen; the user judges the 3 options in the real screen.**
- [ ] Johan tests on QA2.

### B.8 As built (2026-10-17) — decisions beyond the text above
- **One model call returns all three options** (cheaper and the options differ from each other). If some fail validation, ONE retry asks only for the missing ones and is told exactly what was wrong. If at least one option is valid after that, the user sees what is valid (success, counts once); if none is, friendly failure, not counted. Worst case in time is bounded: model call capped at 100s, retry skipped if the first attempt already took > 120s; nginx on QA2/Staging/live allows 300s+. The screen says "this usually takes about a minute".
- **Allowance counting:** a generation counts when it succeeds, plus any still genuinely in flight (a pending row younger than 10 minutes), so a double-click cannot slip two ideas past the cap. Failed and stale-pending rows never count. Calendar month in the app timezone; reset date = the 1st of next month.
- **The server keeps the options.** `ai_template_generations.options_json` holds the validated layouts; saving sends only the option number (+ optional name) — the browser can never hand the server a layout to store. Saving is idempotent (`saved_template_ids`). Only the user who made a generation may save from it (403 for a colleague); another agency's generation id is a 404 (AgencyScope).
- **Validation (`AdLayoutValidator`)**: element types limited to the Ad Builder's own (no custom image/video, no Agent 2); unknown properties dropped; every number range-checked; element fully inside the canvas (2px tolerance); text ≥ 16px; at least one property photo, 3–40 elements; colours must be `#hex`/`rgb()`/`rgba()` — anything else is refused because the render kernel writes colours straight into `style=""` (injection guard); literal text ≤ 80 chars, markup stripped. **Contrast is a heuristic (≥ 3:1)**: text is tested against its own pill, else the nearest covering colour block/shape/gradient beneath it (opaque stops), else the canvas; text straight on a photo with none of those is refused unless it has a text shadow. It does not sample real pixels. The model's sparse answer is completed server-side to the full element shape `makeElement()` produces, so a saved template edits like any other.
- **Canvas sizes** are the four from §B.1 (Facebook 1200×628, Instagram 1080×1080, Story 1080×1920, WhatsApp 900×900); a test fails if they drift from `CANVAS_PRESETS` in the kernel. Property type is sent to the model as context only (no automatic §18 variants are generated).
- **Previews** are drawn by the existing `corex-ad-render.js` kernel against a made-up sample listing (neutral generated photos; the signed-in user as agent; the agency's own name, website and logo).
- **Ledger:** new `AiUsageEvent::SOURCE_AD_TEMPLATE`. `NarrativeRequest` gained optional `source` and `timeoutSeconds` (defaults leave every existing caller unchanged); `AnthropicGateway` records under `$request->source` and honours the timeout.
- **Limit audit:** new `ai_spend_guard_events.type = template_limit` (details `{old, new}`) — no new table.
- **Reference image purge** deletes the FILE after 7 days and stamps `reference_image_purged_at`; the generation row stays (it is the usage record). `ai_template_generations` has no delete or archive action anywhere (append-only log, same posture as `ai_usage_events`).
- **Setup Wizard:** nothing added — the per-agency limit is owner-set, listed in the "NOT in the wizard" table below.
- **Template Manager filter** label changed from "Created by" to "Created" (Everyone · Only mine · AI-made).
- Route throttle `8/min` on generation as a belt-and-braces guard on top of the monthly allowance.

---

---

## C. Article Library (system-dev section → Ad Manager tab)

### C.1 What it does
A platform-owner section maintains a list of **source links** (RSS feeds). A scheduled job finds new items, an AI keeps the relevant ones and writes a **short original summary with credit and a "read original" link**. Agencies see these in a new **Article Library** tab in the Ad Manager and click **Use this** to copy one into their own article drafts. Nothing is ever auto-published.

### C.2 Data model
- `article_sources`: `id, name, url, type (rss), is_active, last_run_at, last_status, last_error, created_by, timestamps, softDeletes`. Seeded with the 5 feeds now hard-coded in `ArticleScraperService`; the service reads this table instead.
- `library_articles` (**global — deliberately not agency-scoped**, read-only to agencies): `id, source_id, source_name, source_url, source_url_hash (unique), title, excerpt, body, tags_json, source_published_at, status (ready|hidden), generated_at, cost_zar, timestamps, softDeletes`.
- `agent_articles`: add `library_article_id` nullable (for the "used" count and to stop duplicates for the same agent).

### C.3 Pipeline
1. Fetch each active source (existing scraper, existing `article_pool` as the staging store).
2. One cheap batch call (`models.fast`) keeps only items relevant to SA estate agents.
3. For each kept item: Sonnet writes a **150–250 word brief in original wording**: credit line + link, no invented figures or facts, no legal advice, SA context. **v1 writes only from the title + snippet (+ full `content:encoded` where the feed supplies it). It does NOT fetch full third-party pages** — copyright/terms risk. Briefs are therefore short; cost ≈ R0.20–0.40 each, attributed to the system (null agency).
4. Cap: max N new articles per run (`DevSetting article_library_max_per_run`, default 10).
- Schedule (`DevSetting article_library_schedule`: off · nightly · every 3 days) run via the existing Laravel scheduler.

### C.4 System-dev section (`/admin/article-library`, `owner_only`)
- **Sources list:** search (name, url), sort (name, last run, status; default name), filter (Active · Archived · All), pagination 15, empty state. **Add / Edit / Archive (soft) / Restore**, **Run now**, last-run result and error shown.
- **Articles list:** search title/source, filter ready/hidden/source/date, **Hide / Unhide** (soft; hidden items vanish from agencies).
- Settings: schedule, max per run.

### C.5 Agency tab (`tools.ad-manager.articles`, third pill in `_switcher.blade.php`)
- Gate `access_ad_manager`, `feature:ad-manager`, `agency.required`.
- **Search:** title, source, tags. **Sort:** date (default newest), title, source. **Filters:** source, tag, date range, "Not yet used by me". **Pagination:** 15. **Empty states:** library empty; no match (Clear filters).
- Row actions: **Use this** (creates an unpublished `AgentArticle` for the current user), **Read original** (new tab). Scoping: library rows are global by design; the copy is the user's own, agency-scoped by `AgentArticle`.
- Phase 2 (after D ships): **Make a social post** → drafts a post into Scheduled Posts.

### C.6 Acceptance
Automated proof: `tests/Feature/Tools/ArticleLibraryTest.php`.
- [x] Adding a feed link and pressing Run now produces new ready articles with a credit + working source link.
- [x] Archived sources are skipped (so are paused ones); the 5 seeded feeds behave as before for presentations.
- [x] No library article is ever published without an agent clicking Use this, then publishing themselves (the copy is created unpublished; nothing in the library code can publish).
- [x] Two agencies can each Use the same article; each gets their own draft; neither sees the other's.
- [x] A hidden article disappears from every agency's tab (and "Use this" on it by id is a 404).
- [x] Spend counts toward the system AI guard (A.2) — and is charged to the platform bucket (agency NULL) even when a signed-in user with an agency presses Run now.
- [ ] Johan tests on QA2.

### C.7 As built (2026-10-17) — decisions beyond the text above
- **System AI bucket (the correctness point).** Previously a `null` agency meant "the signed-in user's agency" in BOTH `AiUsageRecorder::record()` and `AiSpendGuard::assertAllowed()/resolveAgencyId()`, so an owner pressing Run now in a browser would have been charged to (and checked against) their own agency. Added an explicit system mode: `AiUsageRecorder::record(..., system: true)`, `AiSpendGuard::assertAllowed($agencyId, system: true)` / `resolveAgencyId($agencyId, system: true)`, and `NarrativeRequest::$system` which `AnthropicGateway` honours for the pause check and for both ledger writes. Every library call is `system: true`, so it is guarded by, and recorded to, agency NULL whether it runs from the scheduler or a web request (the ledger row still carries the pressing user's id). The default is `false` everywhere else — no existing caller changed. New `AiUsageEvent::SOURCE_ARTICLE_LIBRARY`.
- **Paused platform AI** refuses the run before any feed or AI call, with the plain sentence plus a pointer to turn it back on (AI Usage → Platform AI). A cut-out part-way stops the run and keeps what was already written.
- **Run now is synchronous** (QA2 has no queue worker): the controller calls `ArticleLibraryService::run()` inside the request with a 200-second budget (nginx allows 300s+); when the budget is spent it stops cleanly and says "press Run now again to carry on" (already-written items are skipped by URL hash, so nothing is repeated or re-billed). A cache lock stops two runs overlapping. Run now exists for all sources and per source; a paused source's per-row Run now is refused with a sentence, an archived one is a 404.
- **Schedule:** `article-library:run` is registered daily at 02:30 in `routes/console.php` and decides itself whether it is due from the setting (off · nightly · every 3 days; every-3-days counts 71h from the last run of any kind, so a manual Run now pushes the next scheduled run out). `--force` runs regardless. Settings are `DevSetting`s `article_library_schedule`, `article_library_max_per_run` (1–50, default 10) and `article_library_last_run_at`.
- **Pipeline detail:** `ArticleScraperService::fetchSource()` (new) returns each feed's items and records the fetch outcome on the source; `scrapeAll()` (presentations, `articles:scrape`) now reads `article_sources` and so also skips archived/paused sources — that is the owner's decision when they archive/pause. Newest first, at most 60 new items go to the ONE relevance call (fast model); items judged irrelevant are remembered for 30 days in the cache so they are not re-judged (and re-billed) each run; kept items are capped at max-per-run and each gets one quality-model call. Only `http(s)` links are ever stored. A brief outside 90–320 words is thrown away and reported (asked for 150–250; thin sources may honestly be shorter) — the model may also answer "skip". Output is stripped of HTML/markdown; tags are cleaned (max 5). Feed text is treated as untrusted data in the prompts.
- **Data:** one migration `2026_11_08_100000_at456_c_article_library.php` — `article_sources` (+ the five seeded feeds, idempotent, so a `git pull` deploy carries them), `library_articles` (global by design, documented in the model — same deviation as `ai_usage_events`), `agent_articles.library_article_id` (FK, null on delete). Extra column beyond the spec: `article_sources.last_new_articles` (how many new articles the last run produced).
- **Owner screens** (`/admin/article-library`, `owner_only` + `isOwnerRole()` on every action, no permission key): Sources (search name/link, sort, Active/Archived/All, last-read date range, pagination 15, Add/Edit/Archive/Restore, Run now, last-read time + result + error, an "Fetch this source" pause tick), Articles (search, status, source, date range, sort incl. times used and AI cost, Hide/Unhide, Read), Settings. Navigation: link item "Article Library" in the Dev Settings hub rail (group Content), and the Dev Settings sidebar entry stays lit on `admin.article-library.*`.
- **Agency tab:** `tools.ad-manager.articles` (third pill). Sort is one dropdown (Newest first · Oldest first · Title A–Z/Z–A · Source A–Z/Z–A). "Use this" needs `edit_own_profile` as well (the permission that manages own articles) so nobody gets a draft they cannot open; it copies title, summary, brief + the credit line ("Source: <name> — read the original: <link>") into an unpublished `AgentArticle`, sets the connecting link to the original, and sends the user to My Portal → Profile → Articles. If the user already has a (non-deleted) copy they are taken to it instead; a user who deleted their copy may use it again.
- **Not in scope / not built:** "Make a social post" (Phase 2, needs Part D); fetching full third-party pages (v1 rule); a separate audit trail for article-library settings changes.

---

## D. Post Scheduler (Ad Manager → Scheduled Posts)

### D.1 What it does
Pick an ad (image + copy) and platform(s), choose a date/time; CoreX publishes it at that time through the existing `MetaPublishingService` — **not** Facebook's own scheduler (Instagram has none; one mechanism for both).

### D.2 Data model
`property_marketing_posts` (already `BelongsToAgency`, soft deletes): extend `status` enum with `scheduled`, `publishing`, `cancelled`; add `scheduled_for` (UTC timestamp, indexed), `failure_reason` text nullable. Images for a scheduled post are saved to `marketing-scheduled/` and kept until published + 30 days.

### D.3 Behaviour
- `marketing:publish-due` every minute (`withoutOverlapping`, `onOneServer`). Each due post is **claimed atomically** (`UPDATE … SET status='publishing' WHERE status='scheduled' AND scheduled_for<=now()`), so a retry can never post twice. **No automatic re-publish on failure** (double-post risk): failure → `failed` + `failure_reason` + notification to the agent.
- Before publishing: re-check the social connection (use the existing token refresh in `MetaOAuthService`); expired/revoked → fail with a clear reason.
- Time rules: at least 5 minutes ahead, at most 60 days. Stored UTC, shown in the agency timezone (falls back to the app timezone — there is no per-agency timezone column yet).
- Bulk: from generated ads, "Schedule" with "one a day at HH:MM" spreading.
- Emit `MarketingPostPublished` (exists) and add `MarketingPostScheduleFailed` per `corex-domain-events-spec.md`; builder must read that spec first.

### D.4 Scheduled Posts screen (`tools.ad-manager.scheduled`, fourth pill)
- Columns: thumbnail · property · platform · agent · scheduled for · status · copy snippet.
- **Search:** property address/reference, agent name, copy text. **Sort:** scheduled for (default soonest first), status, agent, created. **Filters:** status, platform, date range, agent (where scope allows). **Pagination:** 15. **Empty states:** none scheduled (with a Schedule button); no match.
- Actions: **Schedule**, **Edit** (copy/time, only while `scheduled`), **Cancel** (status `cancelled`, soft), **Restore/Reschedule** a cancelled one. No hard delete.
- **Scoping at the query layer:** `PermissionService::getDataScope($user,'properties')` → own / branch / all, plus `BelongsToAgency`; direct-URL access by id to another agency's or out-of-scope post is 404/403.
- Permission: new key `ad_manager.schedule_posts` in `config/corex-permissions.php`, Role Manager entry, route middleware, controller check.

### D.7 As built (2026-10-17) — decisions beyond the text above
- **Data:** migration `2026_11_09_100000_at456_d_post_scheduler.php` — `status` was a MySQL ENUM, extended to `draft, published, failed, scheduled, publishing, cancelled` (a pure superset: no existing row changes). `scheduled_for` is a plain `DATETIME` holding UTC (not `TIMESTAMP`, which MySQL shifts by session zone), read/written through `App\Casts\UtcDateTime` because Eloquent's stock `datetime` cast would read the UTC string as Johannesburg time; indexed alone and with `status`. `failure_reason` text. Second migration `2026_11_09_100100_at456_d_grant_schedule_posts_permission.php` grants the new key to existing `agent / branch_manager / admin / office_admin` roles that already hold `access_ad_manager` (idempotent).
- **Where posts come from.** Scheduling happens from the Ad Manager results step: "Schedule this post" on an ad, or "Schedule N ads" for the batch. The browser renders each ad to a picture (JPEG, never wider than Instagram's 1440px), uploads it (`api.v1.ad-manager.scheduled.image`), then ONE request (`api.v1.ad-manager.scheduled.store`) schedules all of them — all or nothing. The Scheduled Posts screen's "Schedule a post" button takes you to the Ad Manager (a post needs an ad's picture + text); it has no create form of its own. Brochure PDFs and ads with no description cannot be scheduled.
- **One row per platform.** An ad on Facebook + Instagram becomes two rows with the same slot. Bulk modes: `daily` ("one a day at HH:MM" — the first ad on the chosen date, the next the following day, same local time) and `at` (all at one date/time). Both end-points are checked: the first must be >= 5 minutes ahead and the last <= 60 days.
- **Who may schedule what:** the user's PROPERTIES data scope (`Property::visibleTo`) plus on-market + marketing-readiness (same rules as posting straight away); the post publishes from the scheduling user's OWN connected Page. A platform not connected for the user is refused with a plain sentence pointing to My Portal, Tools, Social Media. The screen shows who is not connected.
- **Screen scoping** (`PropertyMarketingPost::visibleTo`): all = whole agency; branch = posts on the branch's listings + the user's own; own = posts on the user's book (an assistant's = their agent's) + the user's own. Edit / Cancel / Reschedule on an id outside that scope is a 404 (as is another agency's). The list shows only rows that carry a scheduled time, so posts made by the old direct publish are not mixed in.
- **Atomic everything.** `claim()` is `UPDATE ... WHERE id=? AND status='scheduled' AND scheduled_for<=now`; Edit and Cancel use the same compare-and-set on `status='scheduled'`, so cancel can never lose to a publish and an overlapping run can never publish twice. A post stuck `publishing` for 30 minutes (process died) is marked failed with "may or may not have gone out — check your page" and is NEVER re-published (double-post risk).
- **Publishing runs inline** in `marketing:publish-due` (no queue worker, no `PublishMarketingPostJob` class — the spec's indicative file list is superseded by this). Registered every minute (`withoutOverlapping`, `onOneServer`); runnable by hand. Pre-flight at publish time: listing still on the market and marketing-ready, the connected account exists and belongs to the post's agency, token refreshed through `MetaOAuthService` and not expired, picture still on disk. Meta's own error text is reduced to a reconnect instruction when it looks like a token/permission problem; tokens are scrubbed from every stored message.
- **Failure** = status `failed` + `failure_reason` + `MarketingPostScheduleFailed` (added to the events catalogue) -> `NotifyAgentOfFailedScheduledPost` -> in-app bell notification (`ScheduledPostFailedNotification`, direct, no queue). Success flips to `published` and the existing observer fires `MarketingPostPublished`.
- **Reschedule is for cancelled posts only** (as specced); a failed post is made fresh from the Ad Manager rather than resent, because Meta may already hold it. Reschedule re-checks the connection, the listing and the picture.
- **Pictures:** `marketing-scheduled/{agency}/{user}/{uuid}.jpg` on the public disk. `marketing:purge-scheduled-images` (daily 03:20) removes pictures of published posts 30 days after publishing, of failed/cancelled posts 30 days after they were last touched, and uploads that never became a post after 2 days; pictures of posts still waiting are never touched.
- **Times** typed and shown in the app timezone (Africa/Johannesburg) — no per-agency timezone column was added.
- **Permission:** `ad_manager.schedule_posts` ("Schedule social posts", module ad_manager, Role Manager via the config). Defaults: agent, branch manager, office admin (admin gets everything); not viewer. Route middleware + controller check + the fourth pill and the Schedule buttons are hidden without it.
- **Not built / out of scope:** a "publish now" button; automatic retry; a standalone create form; Phase 2 "Make a social post" from an article.
- **QA2:** no scheduler cron and no queue worker (left that way by Johan) — run `php artisan marketing:publish-due` by hand to publish what is due. Meta App Review is still pending, so only app Testers can connect an account to test with.

### D.8 Audit fixes (2026-10-17) — tests: `tests/Feature/Tools/ScheduledPostsAuditFixesTest.php`
- **Disconnected account no longer publishes (blocker).** The publisher looked the account up with every global scope lifted, which also lifted soft deletes — and "Disconnect" soft-deletes the row, so posts scheduled earlier still went out with the stored token. Now only the agency scope is lifted; a disconnected account fails the post with "No connected … account was found" and nothing is sent to Meta.
- **A killed run cannot block publishing.** `marketing:publish-due` is registered `withoutOverlapping(10)` (lock expires in 10 minutes, not 24 hours — the atomic claim already prevents double publishing) and `runInBackground()` (slow Meta calls do not hold up other scheduled tasks).
- **Write after Meta success.** The platform post id is logged before the database write; a failed save is retried as a plain row update; a failure on one post never aborts the run (an unexpected error leaves that post `publishing`, to be failed — never re-published — by the interrupted sweep). The plain-update fallback does not fire `MarketingPostPublished` (no model event); event payloads are unchanged.
- **Owner re-checked at publish.** Before publishing: the scheduling user exists, is active and not deleted, still holds `access_ad_manager` and `ad_manager.schedule_posts`, and the agency still has the `ad-manager` feature; otherwise the post fails with a plain "no longer has access" / "no longer switched on" reason. Reschedule also refuses a deleted or inactive owner.
- **Picture size cap.** Uploaded pictures are refused before decoding if either side is over 8000 px or the area over 40 megapixels; the upload's `image` field has a maximum length.
- **Picture address rebuilt at publish time** from the stored file path, so a host/domain change inside the 60-day window cannot send Meta a dead address.
- **Status sort** on Scheduled Posts follows the lifecycle (scheduled, publishing, published, failed, cancelled, draft), not the database enum order.
- **Direct publish wording/logging.** `ScheduledPostPublisher::explain()` takes a context: a direct publish says "…then post it again", a scheduled one "…then schedule the post again". The direct-publish error log keeps the original Meta message (token-scrubbed) for diagnosis; the user still sees the plain reason.

### D.5 Blockers / prerequisites
- **Meta App Review + Business Verification** must pass before any agency other than app Testers can connect. Build and test on Johan's/Andre's own accounts until then.
- QA2 has no scheduler cron and no queue worker running; test on QA1/Staging or start them on QA2.
- Setup Wizard: no new agency-level setting is introduced by D.

### D.6 Acceptance
Automated proof: `tests/Feature/Tools/ScheduledPostsTest.php` (Meta faked at the Guzzle layer).
- [x] A post scheduled 10 minutes ahead publishes within a minute of its time and shows `published` with the platform id.
- [x] Two scheduler runs overlapping on the same post publish it once.
- [x] Expired connection → `failed` with a readable reason and a notification; nothing silently lost.
- [x] Cancel before time prevents publication; Reschedule works.
- [x] A branch manager sees only their branch; an agent only their own; a forged id → 404/403.
- [x] Images still resolvable at publish time.
- [ ] Johan tests on QA2 (needs a connected Facebook/Instagram test account; run `php artisan marketing:publish-due` by hand there).

---

## API endpoints (CoreX non-negotiable #7) — as built 2026-10-17

The four JSON endpoints AT-456 added live in the session-authenticated `api/v1` group of `routes/web.php` (same cookie session + CSRF as `api.v1.logged-user`), are named `api.v1.*`, appear in **Admin -> API**, and are called from the browser through `window.CoreX.api.fetch` (`resources/js/corex-api.js`, unchanged - it already passes FormData and JSON bodies through and throws `error.status` / `error.data` on a non-2xx). Permission middleware, feature gate (`feature:ad-manager`), `agency.required` and throttles are exactly as before the move. The old `tools/ad-manager/...` POST URLs were removed (no aliases).

| Name | Method + URI | Controller | Gates (unchanged) |
|---|---|---|---|
| `api.v1.ad-manager.templates.ai.generate` | POST `/api/v1/ad-manager/templates/ai/generate` | `AdTemplateManagerController@generateWithAi` | `access_ad_manager`, `agency.required`, `feature:ad-manager`, throttle 8/min |
| `api.v1.ad-manager.templates.ai.save` | POST `/api/v1/ad-manager/templates/ai/{generation}/save` | `AdTemplateManagerController@saveAiOption` | `access_ad_manager`, `agency.required`, `feature:ad-manager` |
| `api.v1.ad-manager.scheduled.image` | POST `/api/v1/ad-manager/scheduled/image` | `ScheduledPostsController@uploadImage` | `access_ad_manager` + `ad_manager.schedule_posts`, `agency.required`, `feature:ad-manager`, throttle 60/min |
| `api.v1.ad-manager.scheduled.store` | POST `/api/v1/ad-manager/scheduled` | `ScheduledPostsController@store` | `access_ad_manager` + `ad_manager.schedule_posts`, `agency.required`, `feature:ad-manager`, throttle 20/min |

Every other AT-456 route returns a page or a redirect (Dev Settings Article Library, the AI usage page actions, Article Library "Use this", Scheduled Posts list/update/cancel/reschedule) and stays a normal web route. Callers: `tools/ad-manager/templates.blade.php` (generate, save), `tools/ad-manager.blade.php` (image, store; URLs handed over by `AdManagerController::scheduleConfig`). Test: `tests/Feature/Tools/AdManagerApiV1RoutesTest.php`.

## Settings audit (CoreX rule 10a — "NOT in the wizard", for Johan's confirmation)

| Setting | Where | In Setup Wizard? |
|---------|-------|------------------|
| AI alert/cut-out thresholds, window, recipients | Dev Settings → AI Spend Safety (owner) | **No** — platform-owner control, not an agency's (decision 12) |
| Per-agency template generation limit (default 20) | AI usage agency page (owner) | **No** — Johan sets it per agency |
| Article sources, schedule, max per run | `/admin/article-library` (owner) | **No** — system-level |
| (none for D) | — | n/a |

## Multi-agency check ("what does it look like for the SECOND agency?")
Own colours/logo in B; own drafts and own count in C; own posts, scope and connection in D; own spend window and pause state in A. No HFC wording, ids or defaults anywhere; library articles carry no agency name until an agent edits their copy.

## Files (indicative)
**A (as built):** `Services/AI/AiSpendGuard.php`, `Exceptions/AI/AiPausedException.php`, `Mail/AiSpendAlertMail.php` + `emails/ai-spend-alert.blade.php`, `Models/AI/AiSpendGuardEvent.php`, Dev Settings section (`DevSettingsController`, `admin/dev-settings/index.blade.php`), edits to `AiUsageRecorder`, `AnthropicGateway`, `MarketingCopyService`, `WarmThisWeekTilesJob`, all direct callers, `Agency.php`, `AiUsageController`, `admin/ai-usage/*.blade.php`, routes, 2 migrations, tests.
**B (as built):** `Services/AdTemplates/AiTemplateBuilderService.php`, `Services/AdTemplates/AdLayoutValidator.php`, `Models/AiTemplateGeneration.php`, `Exceptions/AI/AiTemplate*Exception.php`, `Console/Commands/PurgeAiTemplateReferenceImages.php` (+ `routes/console.php`), `Tools/AdTemplateManagerController` (+ `generateWithAi`, `saveAiOption`), `Admin/AiUsageController` (+ `updateTemplateLimit`), `tools/ad-manager/templates.blade.php`, `admin/ai-usage/{index,agency}.blade.php`, `NarrativeRequest` + `AnthropicGateway` (source/timeout), migration `2026_11_07_100000_at456_b_ai_template_builder.php`, 2 test files.
**C (as built):** `Services/Articles/ArticleLibraryService.php`, `ArticleScraperService` (reads `article_sources`, `fetchSource()`), `Models/{ArticleSource,LibraryArticle}.php`, `Admin/ArticleLibraryController`, `Tools/ArticleLibraryController`, `Console/Commands/RunArticleLibrary.php` (+ `routes/console.php`), views `admin/article-library/*` + `tools/ad-manager/articles.blade.php`, `_switcher.blade.php`, Dev Settings rail + sidebar highlight, system-bucket flag in `AiUsageRecorder` / `AiSpendGuard` / `AnthropicGateway` / `NarrativeRequest`, `AiUsageController` + `admin/ai-usage/index.blade.php` (Library articles used), migration `2026_11_08_100000_at456_c_article_library.php`, `tests/Feature/Tools/ArticleLibraryTest.php`.
**D (as built):** `Console/Commands/{PublishDueMarketingPosts,PurgeScheduledPostImages}.php` (+ `routes/console.php`), `Services/Marketing/{ScheduledPostService,ScheduledPostPublisher,ScheduledPostImages,ScheduleException}.php`, `Casts/UtcDateTime.php`, `Events/Marketing/MarketingPostScheduleFailed.php`, `Listeners/Marketing/NotifyAgentOfFailedScheduledPost.php`, `Notifications/ScheduledPostFailedNotification.php`, `Http/Controllers/Tools/ScheduledPostsController.php`, `AdManagerController` (+ `scheduleConfig`), `PropertyMarketingPost` (+ `visibleTo`), views `tools/ad-manager/scheduled.blade.php` + `tools/ad-manager.blade.php` (Schedule action/modal) + `_switcher.blade.php`, `config/corex-permissions.php`, `AppServiceProvider` (listener), routes, 2 migrations, `tests/Feature/Tools/ScheduledPostsTest.php`.

## Open items for Johan
1. ~~Article brief length~~ — **Closed 2026-10-14:** short briefs accepted (decision 9).
2. ~~Alert recipients~~ — **Closed 2026-10-14:** `andre@corexos.co.za`; add further addresses in Dev Settings → AI Spend Safety when wanted (decision 10).
3. ~~Section-0 readings~~ — **Closed 2026-10-17:** all three confirmed (decisions 11–13); settings get their own Dev Settings section (A.4).
4. ~~Final go to build Part A~~ — **Closed 2026-10-17:** "save spec and build". Part A built on QA2; awaiting Johan's test there.

## Reported findings (post-build review, 2026-10-17) — fixed
| # | Finding | Fix |
|---|---------|-----|
| 1 | `AnthropicGateway::generateStructured()` rebuilt the `NarrativeRequest` by hand and dropped `documents`, so the DocuPerfect PDF import never sent the PDF to the AI. | `NarrativeRequest::withSystemPrompt()` copies every constructor field (by reflection, so a future field is carried automatically); the gateway uses it. Test: `tests/Feature/AI/GenerateStructuredCarriesEveryFieldTest.php`. **Cost note:** a PDF import now really sends the PDF, so it costs more (about R1 to R4 per import on the quality model, scaling with page count). |
| 2 | `Property::isOnMarket()` compared status case-sensitively; a "Sold" from the P24 importer counted as on-market. | Comparison is case- and whitespace-insensitive, in the model only; the duplicate row-level checks (`PropertyController` list marketing status, `PpraMandateRegisterService`, `FlagPropertyUnderOfferOnDealCreated`) now call `isOnMarket()`; the Ad Manager picker query also TRIMs. Test: `PropertyIsOnMarketCaseTest`. |
| 3 | A failed direct (non-scheduled) social publish recorded no reason. | `PropertyMarketingController::publish()` stores the plain, token-scrubbed reason (`ScheduledPostPublisher::explain()`, now public) and returns it instead of the raw Meta text; the Marketing hub post history shows it under the Failed badge. Test: `AtFindingsDirectPublishReasonTest`. |
| 4 | `PropertyAdTemplateController::store()/update()` only needed `access_properties`. | Both now also require `access_ad_manager` (route middleware + controller re-check), matching §B.6. Test: `AdTemplateSaveNeedsAdManagerTest`. |
| 5 | The document importer parse failure returned the raw exception message and a stack trace to the browser. | Detail is logged server-side under a short reference; the browser gets "The document could not be read. Please try again or send this reference to support: <ref>" and no trace (AI-paused branch unchanged). Test: `DocumentImporterErrorHidesDetailTest`. |
| 6 | The importer's generate step flashed the raw exception text ("Generation failed: <message>"). | Unexpected failures show a plain sentence + support reference (detail logged under it); the two author-fixable refusals ("tag fields first") are a dedicated `TemplateGenerationRefused` and keep their sentence. Draft still preserved. Test: `DocumentImporterErrorHidesDetailTest`. |
| 7 | PDF-import AI spend was filed under `mic_narrative`. | `ImporterAiService::detectFromPdf()` sets `source: docuperfect_vision`. Test: `GenerateStructuredCarriesEveryFieldTest::test_pdf_import_spend_is_filed_under_docuperfect_vision`. |
