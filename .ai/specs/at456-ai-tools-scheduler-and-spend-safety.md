# AT-456 — AI Template Builder, Article Library, Post Scheduler & AI Spend Safety

> Status: **Part A BUILT on QA2 2026-10-17 (Johan: "save spec and build") — awaiting Johan's test on QA2.** **Part B BUILT 2026-10-17 (QA2) — awaiting Johan's test.** **Part C BUILT 2026-10-17 (QA2) — awaiting Johan's test.** **Part D BUILT 2026-10-17 (QA2) — awaiting Johan's test.** All four parts built. **§B.10 (pop-up fix, must-include ticks, several property types) BUILT 2026-10-17 (QA2) - awaiting Johan's test.**
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
| 14 | When the AI ends with fewer than 3 usable template designs (after repairs + the one retry), **show what it got and count the attempt**. Zero designs = friendly failure, not counted. **Amended by decision 19:** this applies when nothing is ticked under "must include"; with ticks, a design that still lacks a ticked item is dropped, never shown. (2026-10-17.) | Johan |
| 15 | The Article Library fetch **runs from live**. The 5 news sites refuse this QA/Staging box's address (HTTP 403/404 whatever the client says), so QA can only show "could not be read"; no code change. (2026-10-17.) | Johan |
| 16 | **Expand the template builder** (§B.9): more icons, more fonts, decorative shapes & frames, photo collages & effects, more property facts, show-day / auction dates, QR code to the listing, key features as pills, a style library, and learning from the agency's own saved templates. Everything new is available to agents in the Ad Builder too (one render kernel). (2026-10-17.) | Johan |
| 17 | **Several property types in one request give ONE template per design, with a version per ticked type.** Ticking Apartment, Vacant Land and House on a "Create with AI" request returns the usual three designs; each design carries its Default look plus one adapted version per ticked type, using the existing §18 `variants` (Default + one variant per type). It is saved as ONE template that picks the right version for each listing automatically. (2026-10-17.) | Johan |
| 18 | **A request counts ONCE against the monthly allowance however many types are ticked.** The cost to CoreX rises (roughly R1.50 per extra type) but the agent's allowance does not. (2026-10-17.) | Johan |
| 19 | **An item that was ticked as "must include" but is missing is never shown as a "Missing: ..." card - the design is simply dropped.** Johan: "can you make it so that if there is an item missing like the qr code etc it should not display anything". With items ticked: a Default design that still lacks any ticked item after the one targeted retry is dropped; a per-type version that still lacks one after its retry is dropped and that type uses the main design (which is complete), with the existing "No separate design for X - uses the main design" note; if **no** complete Default remains the request is a friendly failure and is **not counted** against the allowance (the money is still recorded in the AI ledger). With nothing ticked, decision 14 is unchanged. Saving refuses a dropped option on the server. (2026-10-17.) | Johan |
| 20 | **"Extras only when ticked."** Johan: "I did not tick qr but the ai added the qr code". QR code, photo collage, corner ribbon, show day date and key features as pills appear in a design ONLY if the user ticked them - with nothing ticked, none of the five appears in any design or property-type version. Price, agent photo/contact and the agency logo stay normal ad content the AI may use unticked (ticking them still makes them required, big price still big). Ticked extras behave exactly as before (required; decision 19 drop-if-missing applies). (2026-10-17.) | Johan |
| 21 | **Previews open full screen and use realistic demo photos.** Johan: "the previews need to be able to be clicked and viewed in full screen with demo images". Clicking a design's preview (or its "View full size" button) opens a full-screen viewer; the previews use generic licensed demo property photos instead of the grey placeholder house. (2026-10-17.) | Johan |

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

### B.9 Template builder expansion (decision 16, Johan 2026-10-17) — SPEC, build in two steps

Everything below is added to the **one render kernel** (`public/js/corex-ad-render.js`), so it is available
to agents in the manual Ad Builder, in Template Manager previews, in generated ads and in the images the
Post Scheduler publishes — and then to the AI. Nothing here is HFC-specific; every default is neutral.

**Build order (two lanes, one after the other):**
- **B.9-1 — Building blocks** (kernel + Ad Builder UI + listing data): items 1–8 below.
- **B.9-2 — AI side** (validator, prompt, repairs, style library, learning from saved templates): items 9–11,
  plus teaching the AI every block from B.9-1.

**Rule for every new block:** it must render identically in (a) the Ad Builder canvas, (b) Template Manager
previews, (c) the Ad Manager generated-ad export that the Post Scheduler uploads. If the export path cannot
render an effect faithfully, that effect does not ship (state it in the as-built notes). A field with no value
on the listing hides itself (existing kernel behaviour), never prints "null", "0" or a placeholder on a real ad.

#### Design building blocks
1. **More icons (+~25).** Same visual family and licence class as today's 12 (permissive open-source line icons,
   no brand marks — no WhatsApp/Facebook logos). Set: sea view, mountain view, pet friendly, solar, security,
   CCTV, alarm, braai, fireplace, air-con, garden, flatlet, Wi-Fi/fibre, golf estate, school nearby, shopping,
   beach, estate/gated, borehole/water, backup power, lift, gym, study, kitchen, laundry, balcony, calendar,
   clock, phone, email, chat, star, heart, check, sparkle. Icon keys are lower_snake_case.
2. **More fonts (+12, Google Fonts, Open Font Licence).** Cormorant Garamond, DM Serif Display, Libre Baskerville
   (luxury serifs); Great Vibes, Dancing Script (script — headlines only, never body/prices); Barlow Condensed,
   Anton (condensed headlines); Nunito, Quicksand (rounded friendly); Raleway, Work Sans, Space Grotesk (modern).
   Loaded the same way as today's fonts (`_ad-fonts.blade.php`) and only when used, so page weight does not grow
   for templates that don't use them.
3. **Decorative shapes & frames.** New shape kinds: wave, curve (top/bottom), arc, blob (3 variants);
   line styles: solid / dashed / dotted; **corner ribbon/sash** element with its own text (e.g. JUST LISTED),
   corner tl/tr/bl/br; **photo masks** for any photo element: none / rounded / circle / arch / blob;
   photo frame border (existing frame props extended to masks).
4. **Photo collages & effects.** A **collage** element that lays out 2–5 listing photos in a preset:
   2 side-by-side, 2 stacked, 3 with hero left, 3 with hero top, 4 grid, 5 with hero; gap and corner radius.
   **Photo effects** on any photo: none, black & white, duotone (two colours), warm, cool, brighter;
   **soft blur** behind text (only if the export renders it — rule above).

#### Listing information
5. **More property facts:** erf size (`erf` + unit), levy per month, rates & taxes per month, price per m²
   (sale price ÷ floor size, sales only), pet friendly (shows "Pet friendly", hidden when not), available from
   (rentals — date, or "Available now"), rent per month (rental amount + its price type).
6. **Show day / auction:** next upcoming show day / open house for the listing (date + time range) from CoreX's
   own calendar if it stores one for the property (find the source; if none exists, say so and ship only auction);
   auction date and venue from `Property::currentAuctionLot()`.
7. **QR code to the listing:** encodes the listing's public page (`Property::public_url` — the agency's own site
   where configured, else CoreX's public page, per the 14 Oct rule); dark/light colours; minimum 120 px so it scans.
   Must render in the export (a vendored permissive JS library or a server-generated image — builder's call,
   no CDN dependency for the export).
8. **Key features as pills:** up to 4 of the listing's OWN features as separate pills (row or column, gap,
   badge styling); the agent can choose which features (kernel already has `selectedFeatures`), default = the first N.

#### AI side (B.9-2)
9. **Teach the AI every block above:** validator rules, generated prompt limits and safe repairs for each new
   element/property (same pattern as §B audit item 9 — limits generated from the validator's constants).
   Colour, injection and contrast guards stay as strict as today (duotone colours and ribbon text included).
10. **Style library:** 8 built-in, neutral looks — Luxury, Modern minimal, Bold sale, Coastal, Family home,
    Rental, Commercial, Classic. Each defines font pairs (from the font list), how the agency's own brand colours
    are used, photo share and text placement rules, and suitable ad sizes. Stored in code/config (not an agency
    setting → not in the Setup Wizard). The "Create with AI" panel gets **Style: Let the AI choose / <8 styles>**;
    "Let the AI choose" lets it pick a different style for each of the 3 designs.
11. **Learn from saved templates:** a tick box **"Match our existing templates"** (default on when the agency has
    at least one saved, non-archived template). Up to 3 of the agency's OWN templates are given to the AI as
    examples — preferring ones people made or edited over untouched AI ones, then most recently edited.
    Never another agency's (query-layer scoping). Cost note: adds roughly R0.30–0.60 per generation.

**Acceptance (B.9):**
- [ ] Each new block can be added and edited in the Ad Builder, shows in Template Manager previews, and appears in
      an exported ad image exactly as on the canvas.
- [ ] A listing missing a fact (e.g. no levy, no show day) shows no empty or "R 0" element.
- [ ] The QR code scans with a phone and opens the listing's public page.
- [ ] A second agency sees its own data, colours and templates only; no HFC wording anywhere.
- [ ] The AI uses the new blocks (at least sometimes) and every design still passes the validator; picking a style
      changes the result; "Match our existing templates" uses only the agency's own templates.
- [ ] Existing templates render exactly as before (no visual regression on saved templates).

#### B.9-1 as built (2026-10-17, branch `at456-b9-1-blocks`) — items 1–8 done (9–11 are B.9-2, below)

**Where it lives.** Everything is in the one render kernel (`public/js/corex-ad-render.js`), so the Ad Builder canvas, the Template Manager
previews, the single-property generator, the bulk Ad Manager and the picture the Post Scheduler uploads all draw it from the same code.
Listing data is built server-side in `Property::adData()` (new `App\Services\AdTemplates\AdListingFacts` + `ListingQrMatrix`); the
Template Manager sample listing (`AdTemplateManagerController::samplePropertyData`) carries neutral values for the new facts.
No migrations, no new permission keys (the Ad Builder gates are reused), no new routes, no setting → nothing for the Setup Wizard.
**Deploy needs NO `npm run build`** — only `public/js/corex-ad-render.js` and Blade views changed (no `resources/js`).

**The export rule, and what it forced.** The picture that is exported (and posted) is made by **html2canvas 1.4.1**, which silently
IGNORES `clip-path`, CSS `filter`, `backdrop-filter` and `mask`. So no new block uses any of them: shapes/masks are `border-radius` or inline
SVG; QR is inline SVG; effects are one 4×5 colour matrix applied two ways with the same numbers — an inline SVG `feColorMatrix` (colour space
forced to sRGB) on the live canvas, and the very same matrix run over the pixels inside the existing `prepareImagesForCapture()` step that
every export already calls. Proved with the real export code in a real browser (see "How it was proved").

| # | Item | What shipped |
|---|------|--------------|
| 1 | Icons | **35** new (all of the spec's list), Material Design Icons (Pictogrammers, Apache-2.0): same single-path 24px `currentColor` style as the 12 old ones, no brand marks. Keys: `sea_view mountain_view pet_friendly solar security cctv alarm braai fireplace air_con garden flatlet wifi golf_estate school shopping beach estate_gated borehole backup_power lift gym study kitchen laundry balcony calendar clock phone email chat star heart check sparkle`. The icon picker now offers all 47 on **every** text element (it used to be Beds/Baths/Garages/Parking only), so a calendar icon can sit in front of a show day. |
| 2 | Fonts | 12 added (`Cormorant Garamond`, `DM Serif Display`, `Libre Baskerville`, `Great Vibes`, `Dancing Script`, `Barlow Condensed`, `Anton`, `Nunito`, `Quicksand`, `Raleway`, `Work Sans`, `Space Grotesk`), same CDN (fonts.bunny.net) and same Open Font Licence families. **Lazy**: their stylesheet is added the first time a template draws text in one (`CoreXAd.requestFont`, called from `fontStack`), and every capture waits for it (`fontsReady()` inside `prepareImagesForCapture`). A template that does not use them costs nothing. The two script fonts show "short headlines only, never prices/phone numbers/body text" under the font picker. The stylesheet parameters live in `_ad-fonts.blade.php` (`window.CoreXAdLazyFonts`). |
| 3 | Shapes & frames | Shape element: `wave`, `curve_top`, `curve_bottom`, `arc`, `blob_1/2/3` (inline SVG stretched to the box, fill + opacity). Line element: `lineStyle` `solid`/`dashed`/`dotted` (dashed/dotted are SVG). New **Corner Ribbon** element (`ribbon`): text, corner `tl/tr/bl/br`, band colour, text colour, font. Photo masks on `image_1…5` and `custom_image`: `photoMask` `none/rounded/circle/arch/blob` (border-radius only). The existing frame border (`frameBorderWidth/Color`) follows the curve. |
| 4 | Collage & effects | New **Photo Collage** element (`collage`): `collagePreset` `2_side/2_stack/3_hero_left/3_hero_top/4_grid/5_hero`, `collageGap`, corner radius (`borderRadius`), `collageFirst` (start from photo 1–5, e.g. when Image 1 is already shown elsewhere). A listing with fewer photos than the preset needs gets the nearest smaller layout (never empty holes); none at all → the element is left off the ad. Photo effects `photoEffect` `none/bw/duotone/warm/cool/brighter` (+ `duoColor1/2`) on photos and collages. **Soft blur behind text was LEFT OUT**: html2canvas cannot draw a blur of what is behind an element, so it could not match the canvas — per the rule above it does not ship. |
| 5 | Facts | Text fields `erf_size`, `levy`, `rates_taxes`, `price_per_m2`, `pet_friendly`, `available_from`, `rent_per_month` (values from the property's own columns, see `AdListingFacts`). Sales: erf/levy/rates/price-per-m²/pet. Rentals: rent (with the agency's rental price type: per month/week/day/year/m², an unknown typed unit falls back to "per month"), available-from (`lease_start_date`: future → "Available from 1 Nov 2026", otherwise "Available now"; hidden if the property's "show available-from date" switch is off or the rental is let/rented). Price per m² = asking price ÷ **floor** size, sales only, hidden for price-on-application / sold. A value that is missing → `''` → the element is **left off a real ad** (never "R 0", "null" or an empty coloured pill); in the builder it shows its sample text so it can be designed. |
| 6 | Show day / auction | **Source found:** `PropertyShowday` (`property_showdays`, via `Property::showdays()`, the same records the website feed and PrivateProperty already use). `show_day` = the next active show day that has not ended ("Sat 25 Oct · 10:00–13:00"; two-day ones name both days), in the agency timezone (`Agency::outreachTimezone()` = app timezone until agencies carry their own). `auction_date` ("12 Nov 2026 · 11:00") and `auction_venue` ("Venue, address", or "Online auction") from `Property::currentAuctionLot()->auction`; nothing for a draft / cancelled / postponed / closed / settled auction, an auction whose start has passed, or a concluded lot. |
| 7 | QR | New element `qr` → `Property::public_url` (the agency's own website where configured, else CoreX's public page for that agency — the 14 Oct rule), dark/light colours (`qrDark`, `qrLight`), minimum 120 px (the builder will not let it be made smaller and warns). Generated **server-side** with the endroid/bacon library already in the app, sent as a module grid (`qr_matrix`), drawn by the kernel as one inline SVG path: no CDN, no image service, identical on canvas and export. Error correction M. A link that cannot be encoded (empty, not http(s), over 200 chars) → no code → element left off. |
| 8 | Feature pills | New element `feature_pills`: 1–4 of the listing's own features as separate pills, `pillDirection` `row/column`, `pillGap`, pill colour/opacity/corner radius, text colour/font/size/weight, padding, alignment; the agent ticks which features (`selectedFeatures`, same checklist as the Features element), default = the first N. No features → left off the ad. |

**How it was proved (the export rule).** A throw-away browser harness (real Chromium + the real html2canvas 1.4.1 + the real kernel, not committed)
drew a 1200×900 layout with every block and compared the live DOM screenshot with the exported PNG: mean per-pixel difference 0.84/255, 0.6% of pixels
differing by more than 40 levels (all text anti-aliasing and curve edges). The same through the **real Ad Builder page** (rendered Blade, real Alpine,
the page's own `capture()`): mean difference 0.66/255. The QR decoded to the exact public URL from the live canvas, the 1× export, and the 2× export
(the Ad Manager scale) with a software QR reader, including a custom-coloured one. Existing templates: `tests/js/ad-legacy-snapshot.mjs` replays 564
renderings of old-shape elements recorded from the kernel BEFORE this change and fails on a single differing character.

**Tests.** `tests/js/ad-building-blocks.mjs` (141 kernel checks), `tests/js/ad-legacy-snapshot.mjs` (regression), `tests/js/ad-render-kernel.mjs` (existing);
PHP wrappers + validator drift guards in `tests/Feature/Tools/AdBuildingBlocksKernelTest.php`; server facts / show day / auction / QR per agency in
`tests/Feature/Tools/AdBuildingBlocksFactsTest.php`.

**AI validator — kept in step, NOT taught (that is B.9-2).** `AdLayoutValidator` now ACCEPTS the new fonts, icons, shape kinds and the new enum
properties `photoMask` / `photoEffect` / `lineStyle` (+ colours `duoColor1/2` through the strict colour gate) so a template that uses them is never
rejected, and its `baseElement()` carries the new keys at their "not set" values. `FIELDS` was **not** touched (the drift guard did not force it), so the
AI cannot add the new element types yet. `promptLimits()` still tells the AI only the ORIGINAL fonts / icons / shapes and none of the new properties
(constants `PROMPT_FONTS`, `PROMPT_ICONS`, `PROMPT_SHAPES`, `PROMPT_HIDDEN_ENUMS`; a test pins that the prompt is unchanged) — B.9-2 deletes those four
constants to hand the AI the full lists, or filters them to the subset it should use (e.g. script fonts for headlines only). New drift tests compare
the validator's ICONS / SHAPES / mask / effect / line-style lists with the kernel's.

**For B.9-2 — exact names to teach the AI.**
- New element types (`field`): `collage`, `ribbon`, `qr`, `feature_pills`, and the text facts `erf_size`, `levy`, `rates_taxes`, `price_per_m2`, `pet_friendly`, `available_from`, `rent_per_month`, `show_day`, `auction_date`, `auction_venue`. Each needs a `FIELDS` entry, a `baseElement()` per-type start, contrast handling (ribbon/pills sit on their own colour; QR needs `qrDark` far darker than `qrLight`) and an `isTextField`/`BLOCK_FIELDS` decision.
- New properties: `photoMask` (`none rounded circle arch blob`) and `photoEffect` (`none bw duotone warm cool brighter`, + `duoColor1`, `duoColor2`) on `image_1…5`; `lineStyle` (`solid dashed dotted`) on `line`; `shapeType` += `wave curve_top curve_bottom arc blob_1 blob_2 blob_3` on `shape`; `icon` is now valid on any text element and takes the 35 new keys; `showLabel` (bool) on the facts.
- `collage`: `collagePreset` (`2_side 2_stack 3_hero_left 3_hero_top 4_grid 5_hero`), `collageGap` (0–60), `collageFirst` (1–5), `borderRadius`, `photoEffect`, `duoColor1/2`. `ribbon`: `text`, `ribbonCorner` (`tl tr bl br`), `bgColor`+`bgOpacity` (band), `color`, `fontFamily/fontSize/fontWeight`, `letterSpacing`, `padding`; the band is clipped to its box (default 200×200). `qr`: `qrDark`, `qrLight`, w/h ≥ 120. `feature_pills`: `pillCount` (1–4), `pillDirection` (`row column`), `pillGap`, `bgColor`, `bgOpacity`, `borderRadius`, `color`, `fontFamily/fontSize/fontWeight`, `padding`, `textAlign`, `verticalAlign`, `selectedFeatures`.
- Fonts to teach with care: `Great Vibes`, `Dancing Script` are script faces — short headlines only. The facts are hidden on a real ad when the listing lacks them, so a design must not rely on them being present.
- Data keys in the kernel payload: the ten fact keys above, `public_url`, `qr_matrix` (+ the existing `features_list`, `image_1…5`).

**Reported in B.9-1, FIXED in B.9-2 (see below).** html2canvas ignores `clip-path`, so the EXISTING clip-path shapes (`triangle`, `diamond`, `pentagon`, `hexagon`, `star`, `chevron`) — as a Shape element **and as an Agent Image mask** — appear correctly on the canvas but are exported as plain rectangles (seen directly in the harness). Fixing it means drawing them as SVG/border-radius like the new blocks; out of B.9-1's scope.

#### B.9-2 as built (2026-10-17, branch `at456-b9-2-ai`) — items 9–11 + the polygon-shape export fix

**Deploy:** needs **NO `npm run build`** (changed: `public/js/corex-ad-render.js`, one Blade view, PHP, one config file, one migration — nothing under `resources/js`). Run `php artisan migrate` (adds 3 nullable columns), then the usual view/config/route clear + fpm reload. No new routes or permission keys; the existing generate endpoint takes two more optional fields. Not an agency setting → nothing for the Setup Wizard.

**Item 9 — the AI knows every block.**
- `AdLayoutValidator::FIELDS` gained the 14 new types, each with a one-line help that is also what the prompt prints: `collage ribbon qr feature_pills erf_size levy rates_taxes price_per_m2 pet_friendly available_from rent_per_month show_day auction_date auction_venue`. A drift test now fails if the Ad Builder gains a type the AI has not been told about (deliberate exclusions: custom image/video, agent 2; `color_block` is allowed though not on the builder palette).
- New enums (`collagePreset ribbonCorner pillDirection`, joined to `photoMask photoEffect lineStyle`, the 20 fonts, 47 icons and 17 shapes), new numbers (`collageGap 0–60, collageFirst 1–5, pillCount 1–4, pillGap 0–60`), new colours (`qrDark qrLight`, with `duoColor1/2`) all go through the same strict checks as before; `showLabel` is a boolean. A drift test compares the collage presets / ribbon corners / fact list with the kernel.
- **The four `PROMPT_*` constants are gone.** `promptLimits()` now lists every allowed word straight from `ENUMS` and every range from `NUMBERS`; rules 9–14 are generated from the validator's own constants (nothing is typed twice). `AdLayoutValidator::fontNames()/enumValues()/numberRange()` expose the lists (the style library checks itself against them).
- **Rules + repairs per block** (repair = `validateAndRepair`, used by the builder; strict = `validate`):
  - *QR*: w and h ≥ 120 (`MIN_QR_SIZE`); repair raises to 120 and moves it back inside the canvas. Colours must be solid, **`qrDark` darker than `qrLight`, contrast ≥ 4.0:1** (`MIN_QR_CONTRAST`; phones read dark squares on a light ground); repair makes a see-through colour solid and falls back to black on white for a pair that would not scan. Hostile colour text is refused, never repaired.
  - *Collage*: counts as a property photo; an unknown `collagePreset` snaps to the nearest real one (by the number of photos named, then the layout words: `3_hero_right` → `3_hero_left`, "grid of four" → `4_grid`, no clue → `3_hero_left`); gap / first photo range-checked and clamped.
  - *Ribbon*: `text` required, plain, ≤ 24 characters (`MAX_RIBBON_TEXT`; cut at a word, markup stripped); box ≥ 80×80 (enlarged); the words must fit the visible band (width ESTIMATED from the character count — a guard against a clearly-too-long label, not a typesetter): repair lowers the font (never below 16), then cuts characters. Its band colour must be solid enough to count as the backdrop (`bgOpacity` ≥ 0.5, else raised to 0.9).
  - *Feature pills*: same solid-background rule; `pillCount` clamped to 1–4; a bad `pillDirection` falls back to `row`; the box must be tall enough for its pills (a column needs `count × (fontSize×1.2 + 1.2×padding) + gaps`), repair makes it taller. **The AI cannot choose WHICH features show** (`selectedFeatures` stays "first N"): the features belong to each listing, the agent ticks them later in the Ad Builder.
  - *Contrast*: ribbons and pills are text elements with their own background, so they go through the existing alpha-aware check (a see-through band is tested over white AND black). A collage or QR under text counts as "unknowable ground" (needs a shadow) like a photo.
  - *Script fonts* (Great Vibes, Dancing Script): only on `title`, `custom_text`, `badge`, `agency_name`; ≤ 30 characters; fontSize ≥ 28. Repair puts the standard font back on a price / phone / fact / address / long text, and raises a too-small size.
  - *Facts*: ordinary text elements (normal size/contrast/shadow guards, `preview` sample text carried so a saved template edits properly). `icon` is accepted on any text element.
- **Context-aware facts:** generation here is for a *reusable template*, never for one property (spec §B.2), so there is no "this listing has / lacks X" to tell the model. Instead the prompt always explains that a fact the listing lacks is left OFF the ad: don't let the design collapse, give a fact its own pill (never a separate block/line/icon/label beside it that would be left lonely), and which facts exist for rentals vs sales / show days / auctions.
- Prompt version `at456b9-v1` (was `at456b-v2`).

**Item 10 — style library.** `config/ad-template-styles.php`: Luxury, Modern minimal, Bold sale, Coastal, Family home, Rental, Commercial, Classic. Each: font pairs (+ optional script accent), how the agency's OWN brand colours are used (accent rules — a style never carries a palette: a test fails on any hex colour or agency name in it), photo share + placement, text placement, suitable sizes, the new blocks that suit it, what to keep out. `App\Services\AdTemplates\AdTemplateStyles` reads it, writes the prompt sentences and **checks the library against the validator's lists** (`problems()`: every font must be in the validator's font list, accents must be script faces, blocks must be real element types / property values, sizes real) — enforced by a test (boot-time check not added: it is code-owned and the test is the gate).
- Panel: **Style: Let the AI choose / the 8** (with a one-line hint) and each option card shows **"Style: …"**. A chosen style goes in the prompt in full and applies to all 3 designs (whatever the model writes as a style is ignored). "Let the AI choose" puts a compact catalogue of all 8 in the prompt, asks for `"style":"<key>"` per option and resolves loose spellings ("Modern minimal" → `modern_minimal`); an option that names no known style is **kept** (not thrown away — that would cost a retry) with style "not named" and a line in `validation_log_json`.
- Stored: `ai_template_generations.style` (`auto` or the key) + `options_json[n].style`.

**Item 11 — "Match our existing templates".** Tick box in the panel, offered (and ticked) only when the agency has ≥ 1 saved, non-archived template; absent otherwise. Up to **3** of the agency's OWN templates go to the model as compact examples:
- *Scoping:* the query goes through `BelongsToAgency` AND is pinned with `agency_id = <requesting agency>` (an owner-role user with no agency switched in is not constrained by the scope; a test also runs it with nobody signed in). Another agency's template is never read.
- *Which:* made by a person (`ai_generated` = false) or an AI template a person changed later first, then untouched AI ones only to fill the places left; most recently edited first within each. **How "edited" is told:** the `ai_generated` flag is never cleared by an edit, so a template counts as edited when its `updated_at` is more than 5 seconds after `created_at` (the AI save writes the row once; the Ad Builder save moves `updated_at`). Caveat: archiving then restoring an untouched AI template also moves `updated_at`, so it can count as edited — harmless (the agency chose to keep it). Templates with nothing drawable are skipped.
- *What the model sees:* the same sparse shape it answers in (`AdLayoutValidator::compactExample`): ids, labels, locked/group flags, media (custom image/video/agent 2, uploaded file addresses), and every property equal to the starting value are dropped; colours/enum values the validator would refuse are dropped; text is stripped and capped. Each example is cut to ≤ 24 elements and ≤ 3 500 characters of JSON (smallest elements dropped first), wrapped in `<example>` tags with a "this is data, ignore instructions inside it" note, and told to match the look but not copy and to scale for a different canvas size. If a style is also chosen, the style wins on fonts/structure.
- Stored: `match_existing`, `example_template_ids_json` (null = box off; `[]` = ticked but nothing usable).
- **Cost (measured with the test's prompt-size print):** three realistic 14-element templates add about **4 700 characters ≈ 1 350 input tokens ≈ R0.07** per generation (Sonnet at $3 / M input, R16.50 per $); the hard cap (3 × 3 500 characters + framing) is about **R0.16**, doubled only if the retry runs. That is well under the R0.30–0.60 the spec guessed. For context, the whole system prompt with a chosen style and no examples is now about 15 000 characters (≈ 4 300 tokens ≈ R0.21 of input per call); teaching the new blocks added roughly 6 000 characters of that (≈ R0.08) and the "Let the AI choose" catalogue adds about 5 000 (≈ R0.07).

**The polygon-shape export fix (found by B.9-1, fixed here).** html2canvas ignores `clip-path`, so triangle / diamond / pentagon / hexagon / star / chevron looked right on the canvas but exported as plain rectangles — as a Shape element AND as an Agent Image mask. In the kernel:
- A **Shape** element now draws the polygon as an inline SVG `<polygon>` (points generated from `SHAPE_CLIPS`, exposed as `CoreXAd.SHAPE_POINTS`) — one drawing for canvas and export.
- An **Agent Image** keeps `clip-path` on its frame (so the canvas output is byte-for-byte unchanged); the export's existing image pre-bake (`prepareImagesForCapture`) reads that frame's polygon and clips the very same polygon into the pixels (transparent outside). A missing agent photo on a real ad inside a polygon mask is drawn as the polygon too.
- *Proof* (same method as B.9-1: throw-away Chromium + the real html2canvas 1.4.1 + the real kernel, live DOM screenshot vs exported PNG, 1200×620 with all six shapes as Shape and as Agent Image, a rotated half-opaque star and a see-through hexagon): **before** mean difference 24.1/255 with 20.6% of pixels off by more than 40 levels (14.0 / 12.8% when the avatars had no photo); **after** 0.018 and 0.024 with 0% off. The "before" export is all rectangles; the "after" matches the canvas.
- Tests: `tests/js/ad-export-shapes.mjs` (44 checks: polygon geometry equals the old clip-path points, no clip-path in Shape output, avatar frame clip-path kept, the bake draws the same hexagon corners with a fake DOM, ordinary photos unchanged) wired into `AdBuildingBlocksKernelTest`. The legacy snapshot (`ad-legacy-snapshot.mjs`) still passes 564 renderings: 48 of them (the six polygon shapes) changed ON PURPOSE and are recorded separately in the fixture's `exportSafe`; the check refuses any other difference and requires each recorded polygon's points to equal the old clip-path's points exactly. `ad-render-kernel.mjs` had one assertion updated (star now emits its polygon).
- **Checked for the same problem:** none of the B.9-1 masks relies on clip-path (`arch`/`blob`/`rounded`/`circle` are `border-radius`; collage, ribbon, pills, QR, decorative shapes are border-radius or SVG — a test asserts it). *Known edge, fixed afterwards (see "B.9 Leftovers cleared"):* a frame border (`frameBorderWidth`) on a polygon-masked Agent Image was drawn as a rectangle in the export (on the canvas it is clipped to the polygon).
- Not changed: the shape/mask picker swatches in the Ad Builder still use `shapeCss()` (canvas only, never exported).

**Tests (all via `scripts/lane-test.sh`):** new `tests/Feature/Tools/AiTemplateBlocksAndStylesTest.php` (32) — validator accepts / repairs / rejects every block, full lists in `promptLimits`, no `PROMPT_*` left, recorded answer with the new blocks (`tests/Fixtures/AiTemplates/response-with-new-blocks.json`) → 3 valid options each naming a style, chosen style in prompt + stored, "Let the AI choose" catalogue + per-option names, unknown style name, style library valid/neutral, match-existing second-agency isolation, human-made/edited first, cap 3, ids recorded, compactness / no leaks / bounded size, panel box hidden with no templates. Kept green: `AiTemplateBuilderTest AiTemplateRepairTest AiTemplateAuditFixesTest AdBuildingBlocksFactsTest AdBuildingBlocksKernelTest AdRenderKernelTest AdTemplateManagerTest ScheduledPostsTest AiSpendGuardCoverageTest` (198 tests, 1 745 assertions). `AdBuildingBlocksKernelTest::test_the_ai_prompt_is_unchanged…` (which pinned the OLD prompt) was replaced by `test_the_ai_prompt_now_teaches_every_new_block`.
**Migration:** `2026_11_10_100000_at456_b9_ai_template_style_and_examples` (`style`, `match_existing`, `example_template_ids_json` on `ai_template_generations`; snapshot not re-dumped — batch step).
- [ ] Johan tests on QA2 (a live generation was NOT run in this build).

#### B.9 Leftovers cleared (2026-10-17, branch `at456-b9-leftovers`) — four small items, Johan "yes" to all

**Deploy:** needs **NO `npm run build`** (changed: `public/js/corex-ad-render.js`, Blade views, PHP, one migration — nothing under `resources/js`). Run `php artisan migrate` (adds `property_ad_templates.human_edited_at`), then the usual view/config/route clear + fpm reload. No new routes, permission keys or settings (nothing for the Setup Wizard).

1. **Frame border on a polygon-masked Agent Image now exports like the canvas.** On the canvas the frame's `clip-path` cuts its own CSS border to the polygon; html2canvas ignores `clip-path` and drew the whole rectangle. In the export step only (`prepareImagesForCapture` → `bakePolygonFrameBorders`) the frame's border colour is made transparent (layout does not move) and the same ring (the border band of the box, clipped to the same polygon) is drawn as one inline SVG sibling directly after the frame — same box, rotation, opacity and stacking — then everything is put back. **The canvas is untouched** (the legacy snapshot of 564 renderings still passes unchanged). *Proof* (throw-away Chromium + real html2canvas 1.4.1 + the real kernel; live DOM screenshot vs exported PNG; a 26-tile sheet, frame 8px red): **before** — all six polygons as Agent Image, with and without an agent photo, were 10.5–18.4/255 mean difference and 11–20% of pixels wrong (the export was a full square frame); **after** — 0.01–0.10 mean, 0% wrong, with a photo and with none, frame widths 2 / 8 / 20 px, and a half-opaque star. Every other combination was already fine before and after: Agent Image rectangle / circle / pill / rounded, photos `image_1…5` and `custom_image` × `rounded / circle / arch / blob` / none (0.1–1.0 mean, ≤0.2% wrong — those masks are `border-radius`, which html2canvas draws). Test: `tests/js/ad-export-shapes.mjs` (+ring checks for all six polygons, zero-border / non-polygon / hidden frames get none, restore, no other mask uses `clip-path`), wired through `AdBuildingBlocksKernelTest`.
   - *What the canvas looks like (reported, not changed — "canvas unchanged"):* because the border is a rectangle clipped to the polygon, a framed hexagon / triangle / star shows the border only where the box edge lies inside the polygon (e.g. the flat top and bottom of the hexagon), not a continuous outline. The export now matches that exactly. A true outline following the polygon would need the canvas drawing changed too — a design call for Johan.
   - *Found, not fixed (report only):* a **rotated** polygon-masked Agent Image exports with its image clipped by an un-rotated polygon (the pre-bake uses the rotated frame's bounding box). Before the leftovers fix it was already wrong (mean 6.8–8.6/255 for a 20°-rotated hexagon); the ring itself follows the rotation correctly. Rotation of a masked agent photo is unusual; left as is.
2. **Colour Block is on the Ad Builder palette** (Decorative group, "Colour Block", next to Shape and Gradient). Its panel (fill colour, fill opacity, corner radius) was already there ("legacy"); the generic panel supplies size, rotation, element opacity, drop shadow and frame. An AI template that uses a colour block is now fully editable by an agent. The drift test `test_every_element_type_the_ad_builder_can_place_…` no longer needs a `color_block` exception — the AI's list and the builder's palette are now identical.
3. **`human_edited_at`** (nullable timestamp on `property_ad_templates`; migration `2026_11_11_100000_at456_b9_template_human_edited_at`). Stamped by `PropertyAdTemplateController::store()` and `update()` (a person saving in the Ad Builder). **Not** stamped by the AI builder's `saveOption`, archive or restore. **The picker's exact rule:** a template counts as "made or edited by a person" when `ai_generated = false` **or** `human_edited_at IS NOT NULL`; nothing else (no `updated_at` test any more). Old rows: the migration applies the old heuristic **once** to the rows that exist when it runs — an `ai_generated` row with `human_edited_at` null and `ABS(updated_at − created_at) > 5 s` gets `human_edited_at = updated_at` — so there is one source of truth and no date cut-off to maintain (equivalent to "keep the `updated_at` heuristic for templates created before the migration"). Ordering inside each group is unchanged (most recently edited first), cap 3, own agency only. UI: the Template Manager's existing **AI** badge stays; a small "edited" mark (title shows when) sits next to it when `human_edited_at` is set. Tests: `AiTemplateBlocksAndStylesTest` (picker order uses the column not `updated_at`; AI save and archive/restore leave it null; the "edited" mark; second-agency isolation test unchanged and green), `AdTemplateSaveNeedsAdManagerTest` (store and update stamp it; the AI badge flag stays).
4. **AI no longer draws elements past the canvas edge (prompt).** Rule 1 of the HARD LIMITS block (`AdLayoutValidator::promptLimits()`) now says it for EVERY element — lines, shapes, gradients, ribbons, pills and the QR code, not only photos and text: `x >= 0, y >= 0, x + w <= W, y + h <= H`, with the worked cases built from the ad size (a full-width line is `x=0 with w=W`; inset by 40 px it is `x=40 with w=W−80`; full height `y=0 with h=H`), the usual mistake named (a full-width line with x > 0), and a new rule **1b "CHECK BEFORE YOU ANSWER"** (add x+w and y+h for every element, fix any that fail first). All numbers come from the validator's constants (`SIZES`, `EDGE_TOLERANCE`, the new `EXAMPLE_INSET` illustration margin) — nothing typed twice. The repair that moves an element back inside stays. Prompt version `at456b9-v2`. Test: `test_the_ai_prompt_states_the_per_size_bounds_for_every_element_and_asks_for_a_check` (all four ad sizes + the real system prompt for Instagram). **No live AI call was made** — whether the model now stops overshooting is for the parent's live run.

### B.10 "Create with AI" pop-up: layout fix, must-include options, several property types (decisions 17-18, Johan 2026-10-17) - SPEC

Johan: "1 fix the pop up when i click create with AI at the moment it stretches the entire screen and looks shit. 2 add options to the
create with AI pop up where a user can choose like add QR add this or that etc, also ... a user should be able to choose multiple
[property types] so that the ai builds it for all those selected types of properties".

**Builder's calls (not Johan's - stated so nobody has to ask):**
1. **At most 5 property types per request.** Beyond that the cost and the wait grow with no real benefit; the screen stops the sixth tick and the server refuses more than 5. None ticked = the generic Default only, exactly as today.
2. **The types come from the agency's own list** (Settings, the same active "Property Type" list the property form uses, read through the agency scope). The server checks every ticked name against that list and refuses a name that is not on it; the stored variant key is the list's exact spelling (§18 matches on it, case-insensitively). A second agency sees only its own types. Nothing is hardcoded; the wording hints given to the AI for a type (land has no beds, an apartment has a levy, a rental has a rent) are keyword guesses on the agency's own name, with a generic "adapt sensibly" fallback, never a taxonomy.
3. **The must-include list (all optional, grouped):**

| Group | Tick | The design satisfies it when it contains |
|---|---|---|
| Information to show | Big price | a `price` element with `fontSize` >= 48 |
| | Show day date | a `show_day` element |
| | Key features as pills | a `feature_pills` element |
| | Agent photo and contact | an `agent_avatar` AND (`agent_phone` OR `agent_email`) |
| | Agency logo | a `logo` OR `agency_logo` element (`logo` falls back to the agency name as text when no logo file is on file, so the tick still works for an agency without a logo) |
| Design extras | QR code to the listing | a `qr` element |
| | Photo collage | a `collage` element |
| | Corner ribbon, with its words | a `ribbon` element whose `text` is exactly the words the user typed |

   A hidden element never counts. **Ribbon words:** required when the ribbon is ticked (the box is shown only then); plain text - markup stripped, control characters removed, spaces collapsed, capped at `AdLayoutValidator::MAX_RIBBON_TEXT` (24) characters; stored and drawn **verbatim** (the kernel escapes it on output); the server writes the user's words into every ribbon the AI proposes instead of trusting the AI's copy, and a design whose ribbon can no longer carry the words whole (the validator's fit repair would have cut them) counts as lacking the ribbon.
4. **Enforcement and the one retry.** The ticked items go into the prompt as hard requirements, and are checked by the program on every design (and every property-type version). A design is *complete* when it is valid AND holds every ticked item. The existing single targeted retry now also fires for a valid design that lacks a ticked item, and is told exactly what is missing ("Option 2 is missing: QR code"). After the retry, **decision 19** applies (it replaces the earlier "keep it and mark it Missing" rule): a design that is valid but still lacks a ticked item is **dropped** - never shown, no "Missing:" card; the program keeps what it lacked in the validation log for diagnosis. The user sees only complete designs (one, two or three) and that is counted. If none is complete, or none is valid, the request is a friendly failure and is not counted: "We couldn't make a design that includes everything you ticked. Please try again, or untick something." (The AI spend is still recorded in the ledger.) With nothing ticked, decision 14 is unchanged. **Decision 20 (extras only when ticked):** the converse is also enforced - the five extras (QR code, photo collage, corner ribbon, show day date, key features as pills) never appear unless ticked: the prompt forbids them, styles do not suggest them, and the program removes any that slip through (a collage that was the only photo becomes the main photo). See the "B.10 amendment - decisions 20 and 21" section.
5. **The wait-time approach: two stages, the second in parallel.** Stage 1 is today's call (three Default designs; ~40-80 s). Stage 2 makes **one AI call per ticked type, all at the same time**, each given that type's name and the three APPROVED Default designs and asked to return the same three adapted to that type - same style, colours, fonts and look, only the content changes (so the three versions of one design are a family). Doing it from the approved Defaults, not in parallel with them, is what keeps the look consistent. Wall time is therefore stage 1 + the slowest single type call (~80-160 s) whether one type is ticked or five. The calls run through a new `AnthropicGateway::generateMany()` (an `Http::pool` batch that does, per call, exactly what `generate()` does - spend guard, cost record under source `ad_template`, error handling - so every existing caller is untouched). Time gates keep the whole request inside nginx's 300 s: stage 2 starts only if stage 1 finished within 140 s, its retry round only if the first round finished within 175 s, every call is capped at 90 s. A type that runs out of time or fails is handled by point 6.
6. **A type version that fails** (rejected by the validator after its one retry, the call failed, or time ran out) is dropped and that type uses the Default: the card says "No separate design for <Type> - uses the main design", and the saved template simply has no entry for that type (§18 then falls back to Default). A version that is valid but still lacks a ticked item after its retry is dropped the same way (decision 19): that type uses the main design, which is complete.
7. **The legacy single `property_type` field** (context for the AI, never a variant) is still accepted by the API so nothing that sends it breaks; the screen no longer shows it - the new multi-tick replaces it.

**Pop-up layout (item 1).** A proper centred dialog, teleported to `<body>` (so no page container can stretch or clip it): backdrop; `max-width` 1040 px; `max-height` 90 vh (dvh where supported); a pinned header (title + close X), a body that is the ONLY thing that scrolls, and a pinned footer holding the primary action, the allowance line and Close; Esc and backdrop-click close it (not while generating); keyboard focus moves into the dialog on open, Tab/Shift-Tab are kept inside it, and focus returns to the button that opened it. Phone width: 16 px gutter, the dialog fills the width, a single-column form, no horizontal page scroll. The three option cards sit in a responsive grid; each ad preview is scaled to the card's width and re-scaled when the card resizes; its height is reserved from the ad's aspect ratio so nothing jumps; while generating, three skeleton cards hold the space the result will take. Built from CoreX tokens (`--surface`, `--surface-2`, `--border`, `--text-*`) so light and dark both work; the styles live in the view itself, so **no `npm run build` is needed**.

**Cards.** Each option card shows the preview, "Style: ...", a Default / per-type switcher (only when types were ticked and a version exists), the "No separate design for ..." note, the name box and Save. (There is no "Missing:" line - decision 19.) The switcher only changes what is previewed; Save always saves the whole design with all its versions.

**Data.** `ai_template_generations` gains `property_types_json` (the ticked types, exact list spelling) and `must_include_json` (`{"keys":[...],"ribbon_text":"..."}`); both null when nothing was ticked. `options_json[n]` gains `variants` (`{ "<Type>": {"layout": {...}} }`) and `variant_notes` (`{ "<Type>": "No separate design for ..." }`). Saving writes `layout_json = <Default layout> + variants: { "<exact Type>": <that type's layout> }` - exactly the §18 shape, so the Ad Builder's "Design for" bar shows the versions as Custom and editing works, and `PropertyAdTemplate::resolvedLayoutFor()` / `CoreXAd.resolveTemplateLayout()` pick the right one per listing. The browser still sends only the option number and a name.

**Allowance and cost.** One `ai_template_generations` row per request, so one count (decision 18). Every call (stage 1, its retry, each type call, each type retry) is its own `ai_usage_events` row under source `ad_template`; `cost_zar` on the generation is their sum, so the AI usage page needs no change and keeps counting correctly. Estimated cost with the live prices: 1 type about R3.0-3.6 in total, 5 types about R8.5-10 (each type call roughly R1.5, plus about R1 more for any type that needs its retry). **No cost is shown to agents anywhere**; owners see it on the AI usage page as before. The 24 h spend guard still stops everything at R50: a pause during stage 2 keeps the Default designs and drops the type versions with the "uses the main design" note.

**Tests** (`scripts/lane-test.sh`, recorded answers, no live calls): `tests/Feature/Tools/AiTemplateMultiTypeAndMustIncludeTest.php` - dialog markup (pinned header/footer, scrolling body, max-width, teleport, Esc, a sixth type blocked), plus the real-browser overflow check described in the as-built notes; must-include mapping, enforcement, retry text, "Missing:" marking; ribbon words verbatim / capped / escaped; variants per type with the right keys, facts adapted per type, failed version falls back with the note, save writes the §18 shape and `resolvedLayoutFor` returns it, at most 5, one count, second-agency isolation, paused agency refused before any call. Existing files kept green: see the build report.

#### B.10 as built (2026-10-17, branch `at456-b10-ai-popup`)

**Deploy:** needs **NO `npm run build`** (changed: Blade views with their own inline `<style>`/`<script>`, PHP, one migration - nothing under `resources/js`, no change to `public/js`). Run `php artisan migrate` (adds two nullable JSON columns), then the usual view/config/route clear + fpm reload. No new routes, permission keys or settings (nothing for the Setup Wizard; the new tick boxes are per-request choices, not agency settings). The generate endpoint takes three more optional fields: `property_types[]` (max 5), `must_include[]` (known keys only) and `ribbon_text`.

**Where it lives.** The pop-up moved out of `templates.blade.php` into `tools/ad-manager/_ai-template-modal.blade.php` (included from it). New: `AdTemplateMustIncludes` (the table in "Builder's calls" point 3 as code - drives the screen, the prompt, the check and the "Missing:" line), `AdTemplatePropertyTypes` (the agency's own active list, matching, the 5 cap, the wording hints). `AnthropicGateway` gained `generateMany()`; `generate()` was split into `prepareCall()` + `finishCall()` that both paths share, behaviour for every existing caller unchanged (the gateway/spend-guard tests are green).

**Decisions made while building (beyond the spec text above)**
- **Defaults first, then the type versions** (point 5 as designed). Stage 1 is unchanged except its prompt now says "each design will be adapted to X, Y - make it a strong general base" when types are ticked. Stage 2 gives the model each approved Default in the same compact sparse format it answers in (`compactExample` with all 40 elements, no character cap) and asks for the same design adapted; the type's name is quoted as data and the keyword hint is added; the system prompt drops the style catalogue, the "match existing" examples and the "make three DIFFERENT layouts" rule (the approved designs are the look). A type call's answer is matched to the approved design it adapts by its `for_option` number.
- **`AnthropicGateway::generateMany()`** = `Http::pool` + the exact per-call checks of `generate()` (feature flag, cache, key, spend-guard pause, ledger row, cache row, failure handling). It does NOT retry inside a call (a pool cannot retry one call alone): a failed call is simply handled by the stage's one retry round. A paused agency returns `AiPausedException` for its calls with no HTTP made. One failing call never stops the others.
- **Retry rounds.** Stage 1: unchanged (one retry for what is still missing, now also for valid designs lacking a ticked item). Stage 2: one round for every type, then ONE retry round for only the types that still have a design that is invalid or lacks a ticked item, told exactly why. Time gates: stage 2 starts only if the request is under 140 s old, its retry round only if under 175 s; each call is capped at 90 s; the existing `set_time_limit(280)` and nginx 300 s are respected (worst case about 265 s).
- **Kept and marked, dropped, or fallback.** Valid but incomplete designs/versions are kept (complete ones first, fewest missing next) and the card says "Missing: ...". A version that never became valid is dropped; the saved template has no entry for that type; the card note reads "No separate design for <Type> - uses the main design". An AI pause between the stages, or a spent time budget, keeps the Defaults and gives every type that note - nothing is thrown away and nothing errors.
- **Ribbon words** are written into every ribbon the AI proposes before validation. If the validator's fit repair would have to cut them, the design counts as lacking the ribbon ("Missing: Corner ribbon") rather than showing a shortened ribbon as the user's words.
- **Big price** = a `price` element of at least 48 px (constant `AdTemplateMustIncludes::BIG_PRICE_MIN_FONT`). **Agency logo** ticked for an agency with no logo file asks the AI for the `logo` element (it falls back to the agency name as text), and the screen says so.
- **Legacy `property_type`** (single, context only) is still accepted by the API, unchanged; the screen no longer sends it. It never produces a variant (an existing test pins one call for it).
- **Order.** MySQL's JSON column does not keep object-key order, so the controller returns the versions in the order the types were ticked (`property_types_json`).
- **Validation log** now also records stage-2 outcomes (`stage: variant`, `type`) and is capped at 160 entries (was 40). Prompt version `at456b10-v1`.
- **Data:** migration `2026_11_12_100000_at456_b10_ai_template_types_and_must_include` (`property_types_json`, `must_include_json` on `ai_template_generations`, both nullable); snapshot not re-dumped (batch step). `options_json[n]` now also holds `missing`, `variants`, `variant_notes`.

**Wait time and cost (estimates - no live call was made).** Stage 1 is the existing ~40-80 s call; stage 2 is ONE round of parallel calls of the same size (~40-80 s), so total is about 80-160 s for 1 type and about the same for 5 (the slowest call decides; five at once may add ~10% from API rate pacing). The screen says "about two minutes" with types ticked, "about a minute" otherwise. Cost: Defaults about R1.0-2.0 (observed R1.96 for a two-call run); each type call is about R1.4 (system prompt ~4.3k tokens R0.21 + three approved designs ~3k tokens R0.15 + about 4k tokens of answer R1.0), plus about R1 for any type that needs its retry. 1 type about R3.0-3.6 in total; 5 types about R8.5-10. A request is one count either way (decision 18).

**Proof.** `tests/Feature/Tools/AiTemplateMultiTypeAndMustIncludeTest.php` (37 tests, recorded answers). A throw-away real-browser check (Chromium, the real partial + CoreX CSS + Alpine + the real render kernel, stubbed network) at 1440x900 and 375x812, light and dark: no horizontal page or body overflow, dialog 1040 px max / 90 vh max and centred (16 px gutters on the phone), header and footer stay put while only the body scrolls, Tab/Shift+Tab never leave the dialog, Esc and backdrop close and focus returns to the opener, three skeleton cards while loading, cards inside the body with previews scaled to the card and re-scaled on resize, version pills re-draw the preview, "Missing: QR code to the listing" shown - 94 of 94 checks. The harness is not committed (`tests/js` has no browser runner).
- [ ] Johan tests on QA2 (a live generation was NOT run in this build).


### B.11 No words on top of each other (Johan 2026-10-20: "harden the AI ... it should test before giving results") - as built

**Why.** A live AI template ("Bold Sale – Diagonal Split", agency 20) had a Vacant Land / Plot version with the erf size and
the price per m² at exactly the same spot under the price (same x and y), so neither could be read; its Apartment / Flat
version had the parking number over the agent's name. The validator (§B.8) checked bounds, font size, contrast and colours,
but never whether two pieces of text share space, so the design went straight to the user.

**Rule (`AdLayoutValidator::checkOverlaps()`, run by both `validate()` and `validateAndRepair()`).** Every pair of elements
that show words or a picture of their own is compared; boxes that share more than `OVERLAP_TOLERANCE` (6) pixels **both**
across and down are refused, naming both elements and the shared area ("Element 12 (erf_size) and Element 13 (price_per_m2)
sit on top of each other (they share 260 x 40 pixels) ..."). A hair of shared edge between stacked lines (2-4 px, normal) passes.
Exempt - meant to have things drawn over them - `OVERLAP_EXEMPT_FIELDS`: the property photos (image_1-5, collage), a company
ad's picture (custom_image), color_block, shape, gradient, line, watermark and the corner ribbon (its box is mostly empty). The
agent photo, the logos and the QR code DO count (a picture over words is a clash too).

**Never repaired, by design.** Moving text automatically can push it off its panel or onto a photo, so a clash is a
rejection: the existing targeted retry (Defaults and each property-type version) is told exactly which two elements to move.
A Default that still clashes after its retry is not shown (fewer options); a property-type version that still clashes is
dropped and that type uses the main design ("No separate design for <Type> — uses the main design", §B.10 item 6). The user
never sees a design with words on top of each other. This also covers designs the "move back inside the canvas" repair
pushes onto a neighbour (e.g. a facebook-shaped answer squeezed into a square).

**Prompt ("test before answering").** HARD LIMITS rule **1c** (generated from the same constants): such elements must never
share space, may touch by at most 6 px, facts that belong together go side by side (x + w plus a gap) or one under the other
(y + h plus a gap) - never at the same x and y - and the AI must compare every pair before answering. Unticked extras are
not named (decision 20); a company ad's rule names its own picture, not the listing photos. Applies to company ads too (same
validator, §8 of company-ads.md).

**Existing templates are not changed.** Already-saved templates keep their layout; the live template above needs its two
Vacant Land facts (and the Apartment parking number) dragged apart in the Ad Builder - a live data change only on Johan's order.

**Proof.** New `tests/Feature/Tools/AiTemplateOverlapGuardTest.php` (7: the live Vacant Land clash refused naming both; side by
side / one under the other pass; tolerance edge 6 px passes, 7 px refused; photos/blocks/dividers/watermark not a clash; agent
photo over the name is; prompt rule 1c incl. decision 20 + company mode; end to end: a clashing option is retried with both
named and the user gets three clean options). `AiTemplateMultiTypeAndMustIncludeTest` +2 (a clashing Vacant Land version is
retried and the fixed one kept; one that still clashes is dropped for the main design). Test data adjusted where the old
data itself had overlapping boxes: `response-with-repairable-faults.json` (Hero Pill price box 1200 → 700 wide, so the
repaired agent name no longer lands in it), `AiTemplateRepairTest::validOption()` (agent name y 540 → 440, so the
"moved back inside" price no longer lands on it), and one `AiTemplateBlocksAndStylesTest` case now runs the facebook-drawn
recording at facebook size instead of instagram. 11 AI-template test files, 217 tests, all green. **No live AI call was made.**
**Audit follow-ups (2026-10-20, `.ai/audits/2026-10-20-qa2-overlap-guard-and-band-pricing-audit.md`, Johan "yes develop").**
- Each overlap message names both elements by field AND position ("Element 12 (erf_size at x 730, y 354) and ..."): after repairs
  and removals the element number may not match the AI's own answer; the position always identifies the box, also when two
  elements share a type.
- At most `MAX_OVERLAP_ERRORS` (3) overlap messages per design, then one "And N more pairs ..." line: the retry shows only the
  first 12 errors of all designs together, so one messy design no longer crowds out the others' errors.
- Company ad with no picture: `custom_image` is now in the forbidden list passed to the prompt, so rule 1c no longer names the
  picture box the prompt says not to use.
- Not changed (recorded): text over a corner ribbon's band is not caught (the ribbon box is exempt); rotation is ignored (boxes
  compared unrotated); facts deliberately stacked because only one shows per listing are refused (safe direction).
- Tests: `AiTemplateOverlapGuardTest` +2 (cap + summary; same-type elements told apart by position), `CompanyAdAiBuilderTest`
  no-picture case asserts rule 1c does not name custom_image. 11 AI-template files, 219 green.
- Live check (3 real generations, 35 designs incl. type versions, 2026-10-20, before these follow-ups): 0 overlaps reached the user.
- [ ] Lane owner tests on QA2 (generate with Vacant Land / Plot and Apartment / Flat ticked; check no version has words on top of each other).

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

### C.4 System-dev section (`/admin/article-library`, `owner_only`) — its own System Developer menu item (2026-10-18)
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
- **Owner screens** (`/admin/article-library`, `owner_only` + `isOwnerRole()` on every action, no permission key): Sources (search name/link, sort, Active/Archived/All, last-read date range, pagination 15, Add/Edit/Archive/Restore, Run now, last-read time + result + error, an "Fetch this source" pause tick), Articles (search, status, source, date range, sort incl. times used and AI cost, Hide/Unhide, Read), Settings. Navigation (changed 2026-10-18, Johan: "remove it from dev settings and have it as its own page under system dev section"): its own **Article Library** item in the left menu's System Developer section (below Dev Settings), lit on `admin.article-library.*`; no longer in the Dev Settings hub, and the pages' "← Dev Settings" buttons are gone (the Sources / Articles / Settings tabs move between them).
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
- **Screen scoping** (`PropertyMarketingPost::visibleTo`, always pinned to the agency being worked in — also for an owner account that has not used the agency switcher, owner agency lock 2026-10-18): all = whole agency; branch = posts on the branch's listings + the user's own; own = posts on the user's book (an assistant's = their agent's) + the user's own. Edit / Cancel / Reschedule on an id outside that scope is a 404 (as is another agency's). The list shows only rows that carry a scheduled time, so posts made by the old direct publish are not mixed in.
- **Atomic everything.** `claim()` is `UPDATE ... WHERE id=? AND status='scheduled' AND scheduled_for<=now`; Edit and Cancel use the same compare-and-set on `status='scheduled'`, so cancel can never lose to a publish and an overlapping run can never publish twice. A post stuck `publishing` for 30 minutes (process died) is marked failed with "may or may not have gone out — check your page" and is NEVER re-published (double-post risk).
- **Publishing runs inline** in `marketing:publish-due` (no queue worker, no `PublishMarketingPostJob` class — the spec's indicative file list is superseded by this). Registered every minute (`withoutOverlapping`, `onOneServer`); runnable by hand. Pre-flight at publish time: listing still on the market and marketing-ready, the connected account exists and belongs to the post's agency, token refreshed through `MetaOAuthService` and not expired, picture still on disk. Meta's own error text is reduced to a reconnect instruction when it looks like a token/permission problem; tokens are scrubbed from every stored message.
- **Failure** = status `failed` + `failure_reason` + `MarketingPostScheduleFailed` (added to the events catalogue) -> `NotifyAgentOfFailedScheduledPost` -> in-app bell notification (`ScheduledPostFailedNotification`, direct, no queue). Success flips to `published` and the existing observer fires `MarketingPostPublished`.
- **Reschedule is for cancelled posts only** (as specced); a failed post is made fresh from the Ad Manager rather than resent, because Meta may already hold it. Reschedule re-checks the connection, the listing and the picture.
- **Pictures:** `marketing-scheduled/{agency}/{user}/{uuid}.jpg` on the public disk. `marketing:purge-scheduled-images` (daily 03:20) removes pictures of published posts 30 days after publishing, of failed/cancelled posts 30 days after they were last touched, and uploads that never became a post after 2 days; pictures of posts still waiting are never touched.
- **Times** typed and shown in the app timezone (Africa/Johannesburg) — no per-agency timezone column was added.
- **Permission:** `ad_manager.schedule_posts` ("Schedule social posts", module ad_manager, Role Manager via the config). Defaults: agent, branch manager, office admin (admin gets everything); not viewer. Route middleware + controller check + the fourth pill and the Schedule buttons are hidden without it.
- **Not built / out of scope:** ~~a "publish now" button for listings~~ (built 2026-10-19, §D.10; company ads have had one since `company-ads.md` §14c, through this same publisher); automatic retry; a standalone create form; Phase 2 "Make a social post" from an article.
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

### D.9 Schedule by agent (Johan 2026-10-19) — as built
**Why.** Every Sunday the marketing person generates 2 ads per agent, then schedules agent by agent from the top of the list: each agent's 1st ad on Tuesday, 2nd on Thursday, agents 30 minutes apart. Clicking each ad was the only way. Johan's answers (2026-10-19): agent order is **A to Z or the user's own order**, and every day uses that same order; the user picks the ads, so an agent with 1 ad posts on 1 day and an agent with **more ads than ticked days is refused, naming the agent**; any days of the week (a full week with 5 ads per agent is normal); no Instagram yet (the platform ticks are unchanged).

**Where.** Ad Manager results step → the existing **Schedule N ads** button → the Schedule window gets a third choice, **By agent**, above "One a day" and "All at the same time". Listing ads only (company ads keep their own Post / Schedule).

**What the window shows (mode `agents`).**
- "N ads for M agents · X per agent" (or "X to Y per agent") — detected from the batch, grouped by each listing's agent.
- **Starting from** (date) and **Post on** Mon–Sun ticks. Each ticked weekday becomes its first date on or after Starting from; days are used earliest first.
- **First post each day at** (time) and **Time between agents** (5–240 minutes; offered 5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240).
- **Agent order**: A to Z, or My own order (a numbered list with up / down buttons; moving an agent switches to My own order). Listings with no agent come last, as "Unassigned".
- A live **Preview** table (agent × day, time + listing title per cell). Problems (too many ads, past midnight, no day ticked, over the batch limit) show instead and the Schedule button is disabled.

**The rule (server, `ScheduledPostService::planByAgent`).** Agent of each ad = the listing's stored `agent_id` (never a client value). Agent k's n-th ad (in batch order) goes on the n-th day. On each day the agents that have an ad that day post one after the other in the order shown, from the first time, `gap` minutes apart (so a day with fewer agents finishes earlier — no empty slots). Refused, nothing saved: an agent with more ads than days ("Angelique Botha has 3 ads, but only 2 days are ticked. Each agent gets one ad per day: remove ads or tick more days."); a day running past midnight; the usual 5-minute / 60-day window on the earliest and latest post. The order the window showed is sent as `agents`; the server puts those first and anyone missing after them A to Z (`orderAgents`).

**Remembered.** The pattern (weekdays, first time, gap, order, own-order list) is saved per user per agency in `ad_schedule_patterns` (migration `2026_11_17_100000`, `BelongsToAgency`, soft deletes, unique agency+user) in the same transaction as the posts, and opens the window next time (mode defaults to By agent once a pattern exists; first time: Tue + Thu, 30 minutes, A to Z). Choosing A to Z keeps the previous own order for later. Not an agency setting — a remembered choice, so not in the Setup Wizard.

**Limits.** A By-agent batch takes up to 150 ads (`MAX_AGENT_BATCH`; other modes stay at 50). A big batch can outrun the 60-a-minute picture upload limit; the window then waits 20 seconds and carries on (up to 4 waits per picture) instead of failing.

**API.** No new route: `api.v1.ad-manager.scheduled.store` takes `mode=agents` with `time`, `days[]` (Y-m-d, 1–7, distinct), `gap`, `order` (`alpha`|`custom`), `agents[]`. Result rows from the generator now carry `agent_id`.

**Multi-agency.** Pattern rows are per agency (pinned to the agency being worked in, also for an owner account); nothing defaults to any one agency's agents, days or wording.

**Tests:** `tests/Feature/Tools/ScheduleByAgentTest.php` (A to Z slots, own order + missing agents, too-many refusal saves nothing, a full week × 5, past-midnight, 5-minute rule, company ad / out-of-scope listing refused, pattern remembered + handed to the page + second agency isolated, `orderAgents`). Green 2026-10-19: 9/9, and `ScheduledPostsTest` 41/41 unchanged.

### D.10 Post now from the Ad Manager (Johan 2026-10-19) — as built
**Why.** Johan: at the end of making an ad in the Ad Manager there should be a "post now", like on a property's marketing page (Publish Now).

**What.** Every schedulable ad in the results step has a **Post now** button (above "Schedule this post"). It opens the same window with **Post now** chosen (a one-ad window offers Post now / Schedule for a date and time); no date or time is asked; the button reads Post now. The ad's own picture (rendered and uploaded exactly as for scheduling) and its description go to the user's connected Page(s) at once, and the window shows per platform "it is live on your Page" or the plain reason it was not (Posted / Partly posted / Not posted).

**How.** `api.v1.ad-manager.scheduled.store` with `mode=now`, which already posted company ads through `ScheduledPostPublisher` (same atomic claim, same publish-time checks, `CONTEXT_DIRECT` wording, `MarketingPostPublished` on success, a failure recorded as `failed` with its reason). The rule changed in `ScheduledPostService::schedule()`: `now` takes company ads (any number, unchanged) **or exactly one listing ad**; several listings, or a listing alongside anything else, is refused: "Post one listing ad at a time straight away, or schedule them." (deliberately one at a time — a batch going out in the same minute is what scheduling is for). The listing still has to be in the user's scope, on the market and marketing-ready, and the platform connected, exactly as for scheduling. No new route, no migration, no npm build. The property marketing page's own Publish Now is untouched.

**Tests:** `ScheduledPostsTest` (one listing posted now → published with the platform id, one Meta call, event fired; several listings / an off-market listing refused, nothing saved; the button is on the page); `CompanyAdPostingTest::test_post_now_takes_a_listing_only_on_its_own_and_an_item_is_one_thing` (old "company ads only" refusal replaced by the one-listing rule).

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

B.10: the generate endpoint also takes `property_types[]` (max 5, the agency's own list), `must_include[]` and `ribbon_text`; the response carries `property_types` and, per option, `missing`, `variants` and `variant_notes`. No new route.

B.9-2: the generate endpoint takes two more optional fields, `style` (`auto` or one of the 8 style keys) and `match_existing` (boolean), and each returned option now carries `style` and `style_name`. No new route.

Every other AT-456 route returns a page or a redirect (Dev Settings Article Library, the AI usage page actions, Article Library "Use this", Scheduled Posts list/update/cancel/reschedule) and stays a normal web route. Callers: `tools/ad-manager/templates.blade.php` (generate, save), `tools/ad-manager.blade.php` (image, store; URLs handed over by `AdManagerController::scheduleConfig`). Test: `tests/Feature/Tools/AdManagerApiV1RoutesTest.php`.

## Settings audit (CoreX rule 10a — "NOT in the wizard", for Johan's confirmation)

| Setting | Where | In Setup Wizard? |
|---------|-------|------------------|
| AI alert/cut-out thresholds, window, recipients | Dev Settings → AI Spend Safety (owner) | **No** — platform-owner control, not an agency's (decision 12) |
| Per-agency template generation limit (default 20) | AI usage agency page (owner) | **No** — Johan sets it per agency |
| Article sources, schedule, max per run | `/admin/article-library` (owner) | **No** — system-level |
| (none for D) | — | n/a |
| D.9 remembered "Schedule by agent" pattern | Per user, saved when a batch is scheduled | **No** — a remembered choice, not an agency setting |

## Multi-agency check ("what does it look like for the SECOND agency?")
Own colours/logo in B; own drafts and own count in C; own posts, scope and connection in D; own spend window and pause state in A. No HFC wording, ids or defaults anywhere; library articles carry no agency name until an agent edits their copy.

## Files (indicative)
**A (as built):** `Services/AI/AiSpendGuard.php`, `Exceptions/AI/AiPausedException.php`, `Mail/AiSpendAlertMail.php` + `emails/ai-spend-alert.blade.php`, `Models/AI/AiSpendGuardEvent.php`, Dev Settings section (`DevSettingsController`, `admin/dev-settings/index.blade.php`), edits to `AiUsageRecorder`, `AnthropicGateway`, `MarketingCopyService`, `WarmThisWeekTilesJob`, all direct callers, `Agency.php`, `AiUsageController`, `admin/ai-usage/*.blade.php`, routes, 2 migrations, tests.
**B (as built):** `Services/AdTemplates/AiTemplateBuilderService.php`, `Services/AdTemplates/AdLayoutValidator.php`, `Models/AiTemplateGeneration.php`, `Exceptions/AI/AiTemplate*Exception.php`, `Console/Commands/PurgeAiTemplateReferenceImages.php` (+ `routes/console.php`), `Tools/AdTemplateManagerController` (+ `generateWithAi`, `saveAiOption`), `Admin/AiUsageController` (+ `updateTemplateLimit`), `tools/ad-manager/templates.blade.php`, `admin/ai-usage/{index,agency}.blade.php`, `NarrativeRequest` + `AnthropicGateway` (source/timeout), migration `2026_11_07_100000_at456_b_ai_template_builder.php`, 2 test files.
**B.10 (as built):** `tools/ad-manager/_ai-template-modal.blade.php` (+ `templates.blade.php` include), `Services/AdTemplates/{AdTemplateMustIncludes,AdTemplatePropertyTypes}.php`, `AiTemplateBuilderService` (two stages), `AnthropicGateway::generateMany()`, `AdTemplateManagerController`, `AiTemplateGeneration`, migration `2026_11_12_100000_at456_b10_ai_template_types_and_must_include.php`, `tests/Feature/Tools/AiTemplateMultiTypeAndMustIncludeTest.php` (+ the panel-script path in `AiTemplateAuditFixesTest`).
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

## Audit findings 2026-10-17 — fixed (spend safety + AI plumbing)
Full audit of Part A and the related AI plumbing. Tests: `tests/Feature/AI/AiSpendAuditFixesTest.php`, `tests/Feature/AI/AiCostCalculatorTest.php`, `tests/Feature/Properties/OnMarketSqlMatchesPhpTest.php`.
| # | Finding | Fix |
|---|---------|-----|
| 1 | The PDF import sent a real PDF to the quality model with the gateway's 30s default timeout, and the gateway re-sent a timed-out call 3 times (each one possibly billed upstream with no ledger row) before the importer swallowed the failure as "0 fields". | `ImporterAiService::PDF_TIMEOUT_SECONDS = 120` is passed on the PDF request. `AnthropicGateway::callApi()` no longer retries a **timeout** when the request carries `documents` (fast connect failures and 5xx still retry; every non-document caller keeps its existing retry behaviour). |
| 2 | `AiCostCalculator::zar()` priced an unrecognised model id at R0, so spend under it never moved the guard. | An unknown id is priced at the most expensive configured input rate and the most expensive configured output rate, and one warning per model id per day is logged (cache-gated) so it is visible and gets a price added. R0 remains only when no pricing is configured at all. |
| 3 | A paused agency's fallback lingered after resume, and a paused force-refresh overwrote a good cached narrative with the fallback. | The paused path in `AnthropicGateway::generate()` no longer writes `ai_narrative_cache` (it still records the zero-cost ledger row and fires the fallback event). `AiSpendGuard::resume()` soft-deletes that bucket's `(fallback)` cache rows (agency rows, or agency NULL for the system bucket) so the next call reaches the API. Good cached narratives are untouched. |
| 4 | The alert email "sent" mark was written before the send, so a failed SMTP send suppressed the alert for 24h. | The mark (and the `alert` audit row) is written only after a successful send. On failure the guard backs off 15 minutes (cache key `ai-spend-guard:alert-mail-retry:<bucket>`, so a dead mail server costs one attempt per interval, not one per AI call) and then retries. The pause is still written before any mail and never depends on it; mail stays synchronous. Resume clears the back-off. |
| 5 | `Property::isOnMarket()` normalises the status (trim, lower-case, spaces to underscores) but `scopeOnMarket` and the `OFF_MARKET_STATUSES` queries compared raw strings. | One SQL expression, `Property::normalisedStatusSql()` (`LOWER(REPLACE(TRIM(status),' ','_'))`), behind `scopeOnMarket`, new `scopeOffMarket`, and `Property::constrainOnMarket()/constrainOffMarket()` for plain `DB::table` queries. All `OFF_MARKET_STATUSES` status queries now route through them: `ExpireMandates`, `ScanPropertyNotifications`, `PropertyHealthCalculator`, `AutoEventService` (x2), `OverdueSnapshotService`, `CommandCentreService`, `WebsiteSyndicationService`, `MapPinService` (on-market and off-market-except-sold), `PpraMandateRegisterService`, `GrandfatherSyndicatedStockJob`, `PropertyController` (list filter, marketing/approval filters and the header "active" tile), `FilingPropertyLinker` ordering, `AdManagerController` picker, `AgentDeletionService`, `scopeExpiringSoon`, and the Imported Stock partition scopes + `isImportedStock()`. `status` is NOT NULL, so NULL handling is moot; a NULL would count as on-market, like `isOnMarket()`. **Index note:** wrapping `status` in functions stops MySQL using an index on `status` for these predicates; they are low-selectivity `NOT IN` tests that did not use one usefully, and `agency_id` / `agent_id` still drive the plan. |
| 6a | The system-bucket cut-out email said "Other agencies are not affected". | `AiSpendAlertMail` takes `isSystem`; the system version says platform AI no agency owns (e.g. the Article Library) is off and that every agency's own AI keeps working. Agency emails keep the original sentence. |
| 6b | `MobileEllieVoiceController` returned 503 for a paused agency while transcription failures return 422. | Now 422 with the same `{error}` body, so a client that retries 5xx does not resend the audio. |

## Audit findings 2026-10-17 — fixed (Parts B and C)
Full audit of the AI Template Builder (B) and the Article Library (C). Each finding was checked in the code first.
| # | Finding | Real? | Fix | Test |
|---|---------|-------|-----|------|
| 1 | SSRF: the feed fetch called `Http::get($source->url)` with no host filtering, followed redirects and had no size cap; saving only checked `url:http,https`. | Yes | One helper, `App\Support\Net\SafeFeedUrl`, used on save (plain-words validation error on the Sources screen, add and edit) AND at fetch time on every hop. Only http/https, ports 80/443, no user:password; the host must resolve and EVERY address must be public (loopback, private, link-local incl. 169.254.169.254, CGNAT, documentation/benchmark, multicast, reserved; IPv4 and IPv6, with IPv4-mapped/NAT64/6to4 forms judged as the IPv4 they carry); numeric hosts like `2130706433` or `0x7f.1` refused. Redirects are no longer automatic: followed by hand, at most 3, each hop re-checked; the connection is pinned to the checked IP (curl `RESOLVE`, no second lookup to swap); body read stops at 2 MB (`ArticleScraperService::MAX_FEED_BYTES`) with a 30 s download deadline; timeouts kept. DNS goes through an injectable resolver (`new SafeFeedUrl(fn ($host) => [...])`). | `ArticleLibraryAuditFixesTest` (unsafe/safe URL data sets, save refusal, private source never requested, redirect to 127.0.0.1 / to a name resolving private, 3 hops ok / 4 refused, oversize) |
| 2 | HTML survived the plain-text cleaner: `html_entity_decode(strip_tags($t))` decoded AFTER stripping, so `&lt;script&gt;` became a literal `<script>` in title/excerpt/body, copied by "Use this" into `AgentArticle.body` and returned raw by the website API. | Yes | `App\Support\Text\PlainText::strip()` decodes, removes script/style blocks, strips tags, and repeats until the text is stable (nested / double-encoded payloads), then removes any leftover tag opener (and every angle bracket if it never settles). Used for the AI title/excerpt/brief/tags (incl. the title fallback), and for the feed's title, snippet and full text in `ArticleScraperService` (same bug, same helper). A brief that contains `<script>/<iframe>/<object>/<embed>` after decoding is now rejected as unsafe rather than cleaned (see 8). "Use this" also cleans the copy, so articles stored before the fix cannot carry a tag into an agent's draft. | `ArticleLibraryAuditFixesTest` (8 hostile payloads, ordinary text untouched, AI output, feed title/snippet, legacy row via Use this) |
| 3 | Mislabelled reference image: extension and media type came from the client's file name; a JPEG named .png made Anthropic answer 400, shown as "AI service could not be reached". | Yes | Stored extension and media type both come from the sniffed bytes (`getimagesizefromstring`); `loadReference` sniffs too, so rows stored earlier are also sent correctly. Over 8000 px a side: refused in plain words before any row/file/AI call. Over 1568 px: scaled down (GD) before storing and sending. | `AiTemplateAuditFixesTest` (jpeg-as-png, png-as-jpg, legacy path, too large, scaled) |
| 4 | Allowance check not atomic: `usage()` check then row create without a lock, so two parallel requests at "1 left" both passed. | Yes | Check + pending-row creation now run under `Cache::lock('ai-template-allowance:{agency}')` (wait 3 s, hold at most 20 s, always released); the lock is dropped before the slow model call because the pending row keeps the slot taken. If the lock cannot be had, a plain "another request is being set up, try again" message and nothing is created or spent. | `AiTemplateAuditFixesTest` (lock held by another request, last slot taken then refused, lock released after refusal) |
| 5 | Contrast ignored alpha: only the RGB of an `rgba()` background was used. | Yes | `AdLayoutValidator` now keeps each backdrop's real transparency (colour alpha x element opacity / pill opacity) and tests anything see-through composited over BOTH white and black, requiring 3:1 against each; gradient stops likewise. Error text says "see-through, over white/black". | `AiTemplateAuditFixesTest` (white text on rgba(0,0,0,0.1) rejected, on rgba(0,0,0,0.75) accepted; pill; gradient) |
| 6 | The throttle's 429 showed "Too Many Attempts." | Yes | The panel shows "You are going a little fast — wait a minute and try again." for any 429 without a `usage` payload; the monthly-limit 429 (which carries `usage`) keeps its own message. | `AiTemplateAuditFixesTest::test_the_panel_swaps_the_throttles…` (asserts the panel logic + that the limit 429 carries `usage`) |
| 7 | "Generations per month" form visible to non-owners. | **No** | The whole agency usage page is behind `owner_only` + `mic.view_ai_costs` (route), so a non-owner never reaches the form; no code change. Pinned with a test. | `AiTemplateUsageAdminTest::test_the_agency_page_with_its_limit_form_is_not_reachable_by_a_non_owner` |
| 8 | Article Library re-billing and unbounded load. (a) A brief rejected for content was not remembered, so every run re-paid for it. (b) The agency tab loaded every visible article's `tags_json` on every render for the tag filter. | Yes | (a) Content failures (length outside bounds, empty, unsafe code) throw `BriefRejectedException` and the item is remembered 30 days, exactly like an irrelevant item; transient API/network/unreadable-answer failures are NOT remembered and are retried next run. (b) `LibraryArticle::visibleTags()` is cached (10 min backstop) and forgotten on any article or source save/delete/restore; the rebuild reads only the tags column, in chunks, capped at 200 tags. | `ArticleLibraryAuditFixesTest` (rejected brief not re-sent, empty brief, transient retried, tag cache follows hide/archive/restore, agency tab does not re-read tags) |

Behaviour changes worth knowing: a feed address must now be an ordinary public web address on port 80/443 (a feed on a custom port or an intranet host is refused); a brief containing raw `<script>` is thrown away rather than cleaned. Existing test fixtures that used fake feed hosts now bind a fake resolver (`ArticleLibraryTest::setUp`).

### Item 9 — three options reliably (live QA2 run, 2026-10-17)
A real run returned 2 of 3 options in 84 s (R1.96, 2 calls): one option was dropped by the validator even after the retry, and nothing recorded why. Fixed:
- **Diagnosis** — every validator verdict (option number, attempt, accepted / repaired / rejected, the exact repairs and the exact rules that failed) is logged (`AiTemplateBuilder: option …`, warning for rejections, plus a "finished short of 3" warning) and stored on the generation row in the new `ai_template_generations.validation_log_json` column (migration `2026_11_09_110000_at456_b_ai_template_validation_log`).
- **Prompt** — the instructions now carry a HARD LIMITS block generated by `AdLayoutValidator::promptLimits()` from the validator's own constants (canvas bounds, element count, minimum font, contrast ratio and the 0.5 opacity at which a backdrop counts, colour syntax, text and name length, watermark opacity, every numeric range and every allowed word list). The hand-written copies of those numbers and lists were removed from the prompt. Prompt version `at456b-v2`.
- **Repair instead of drop** — `AdLayoutValidator::validateAndRepair()` (used by the builder; `validate()` is unchanged and strict) fixes deterministically, without relaxing a rule: elements outside the canvas are moved/shrunk back in; fonts under the minimum are raised; out-of-range numbers are clamped; over-long text is cut; empty-text or unknown-type elements are left out; colour names / 8- and 4-digit hex are rewritten to hex/rgba from a fixed table and see-through canvas colours made opaque; wrong-case or numeric enum values are normalised, unusable ones removed (default applies); text colour that fails real contrast is switched to white or black only if that passes against every backdrop (over white and over black for see-through ones); text straight on a photo gets the text shadow the validator already accepts. Every repair is recorded by name.
- **Never repaired (still rejected, then retried once for what is missing)** — any colour that is not plain hex/rgb()/rgba() after the table rewrite (CSS/HTML injection guard), no property photo, too few elements, "elements" not a list, contrast that neither white nor black can meet (e.g. text on a half-see-through mid-grey).
- **Retry** unchanged: one targeted retry for the options still missing, told the exact rules that failed.
- Tests: `AiTemplateRepairTest` (recorded answer `tests/Fixtures/AiTemplates/response-with-repairable-faults.json`: two repairable options kept and a colour-injection option rejected then retried; a repeated unsafe option dropped with reason logged and stored; each repair; each never-repaired rule; the prompt states the validator's limits). `AiTemplateBuilderTest::test_invalid_output_is_retried…` now uses a non-repairable fault (a too-small font is repaired now).

#### B.10 amendment - decision 19, "incomplete designs are never shown" (2026-10-17, branch `at456-b10-hide-incomplete`)

Supersedes the "Kept and marked" bullet and the "Missing:" card line of the as-built notes above. **Deploy:** no migration, no `npm run build`, no new route/permission/setting (Blade + PHP only); clear views and reload fpm.
- **Defaults (stage 1):** only designs that are valid AND hold every ticked item are kept. The one targeted retry is unchanged (it still names what was lacking). Whatever is still incomplete afterwards is dropped, so the user may see one, two or three designs. `options_json` is a compact list of the kept designs (no `missing` key; the card index is the position in that list).
- **Versions (stage 2):** only complete versions are kept; an incomplete one is asked for again in the one retry round and dropped if still incomplete - that type then has no entry (the main design is used) and the card carries the "No separate design for X - uses the main design" note. `variants[Type]` is now just `{layout}`.
- **No complete Default** (with items ticked): friendly failure, 422, not counted (the generation row is `failed`; `cost_zar` and the `ai_usage_events` rows still hold the spend). Message: "We couldn't make a design that includes everything you ticked. Please try again, or untick something. This attempt was not counted."
- **Nothing ticked:** unchanged (decision 14).
- **Save:** `saveOption` refuses an option index that is not in the stored list (dropped), and - for a generation made before this rule - any option still carrying a `missing` list when items were ticked; variants carrying `missing` are skipped when building the template.
- **UI:** the "Missing: ..." line, `missingFor()` and the `.aitp-missing` CSS are removed. The API no longer returns `missing` on options.
- **Kept for diagnosis:** the validation log still records `outcome: incomplete` with `missing: [...]` for every dropped design/version.
- **Business effect:** when a ticked item (for example the QR code) could not be delivered, the agent never sees a half-finished design; they see fewer designs, or a plain "try again / untick something" message that does not use up their monthly allowance. The cost of those attempts is still recorded for the owner.
- Tests: `tests/Feature/Tools/AiTemplateMultiTypeAndMustIncludeTest.php` (incomplete Default dropped; incomplete version dropped with the note and saved template falls back; all incomplete = failure, not counted, ledger has the spend; nothing ticked unchanged; save of a dropped index refused; too-long ribbon words = dropped).

#### B.10 amendment - decisions 20 and 21, "extras only when ticked", full-size viewer, demo photos (2026-10-17, branch `at456-b10-extras-only-ticked`)

**Deploy:** no migration, no `npm run build`, no new route/permission/setting (PHP + Blade + 9 small JPEGs under `public/images/ad-demo/`); clear views and reload fpm. No Setup Wizard change (no new setting).

**Decision 20 - extras only when ticked**
- **One definition:** `AdTemplateMustIncludes::extras()` (option key => element type): qr -> `qr`, collage -> `collage`, ribbon -> `ribbon`, show_day -> `show_day`, feature_pills -> `feature_pills`. `unticked()` / `forbiddenFields()` give what must not appear for a request. Price (`big_price`), `agent_contact` and `logo` are deliberately not extras.
- **Prompt (Default designs AND each property-type call):** the user prompt carries a "DO NOT USE - the user did NOT tick these ..." block naming each unticked extra. The system prompt no longer offers an unticked extra at all: its line is left out of the element catalogue, its how-to line (collage/ribbon/qr/pills), its HARD LIMITS rule (ribbon, QR, pills, and the collage wording of rules 1/14), "show_day" in the listing-facts list and the "or a collage" photo rule, and the building-blocks sentence of rule 7 only names what is allowed (`AdLayoutValidator::promptLimits($w, $h, $forbidden)` and `AiTemplateBuilderService::systemPrompt()`). The property-type hint leaves out "feature_pills" when pills are unticked (`AdTemplatePropertyTypes::hint($type, $pillsAllowed)`). The agency's own templates shown as examples are stripped of unticked extras before the model sees them. Prompt version `at456b10-v2`.
- **Styles:** `AdTemplateStyles::chosenBlock()` / `catalogueBlock()` take the forbidden list and drop those blocks from "Blocks that suit it" / "Suits:". The few style sentences that pointed at an extra (the sash in Bold Sale, "a collage" and "pills" in Family Home, "the QR code" in Rental) were reworded in `config/ad-template-styles.php` to name no extra; the styles' "blocks" lists still suggest them, and only when ticked.
- **Enforcement (never drops the design):** `AdTemplateMustIncludes::stripUnticked()` removes every element of an unticked extra - from the raw answer before it is validated, and again from the validated layout as a safety net - in stage 1 and in every type version (`AiTemplateBuilderService::validateWithoutUntickedExtras()`). Each removal is written to the validation log's `repairs` as "removed QR code to the listing - not ticked". **If a collage was the design's only property photo, it becomes `image_1` in the same box** (collage-only keys dropped) so the validator's photo requirement still holds; a collage beside another photo is simply removed. Removing elements can in principle take a design under the 3-element minimum; the validator then rejects it and the normal retry/drop applies.
- **Ticked extras:** unchanged - required, checked, decision 19 drops a design that still lacks one.
- **Business effect:** an agent who did not tick "QR code" never gets a QR code (or a ribbon, collage, show-day line or pills) in any design; the AI is also told not to try, so fewer designs are wasted.

**Decision 21 - full-size viewer and demo photos**
- **Viewer:** click a preview, or its "View full size" button, to open a full-screen viewer (dark backdrop, teleported above the pop-up). The ad is drawn by the same render kernel at its own canvas size and scaled to the largest size that fits with the aspect kept (re-fitted on resize/rotation). Close with the X, Esc or a backdrop click; focus returns to that card's "View full size" button. Left/right arrow keys and on-screen Previous/Next step through the designs (wrapping). The Default / property-type switch is in the viewer (it changes the same version the card previews). Esc closes the viewer first, a second Esc the pop-up. It cannot be opened while a design is being made, and starting a new generation closes it. No page scroll behind it (the pop-up already locks the body; the viewer has `touch-action: none` and `overscroll-behavior: contain`).
- **Demo photos:** the grey placeholder house is gone. `AdTemplateDemoPhotos` serves nine generic stock photos from `public/images/ad-demo/` - 1200 px copies of photos from the committed licensed demo pool `database/seeders/data/demo-properties/` (Unsplash, commercial use, no attribution - see `.ai/DEMO_DATA.md` "Listing photos"). They are not any agency's listing photos and no agency data is involved (multi-agency safe). `image_1..image_5` = exterior, living room, kitchen, bathroom, pool. **The pool has no bedroom photo, so slot 4 shows a bathroom until a licensed bedroom photo is added.** A property-type version previews with a fitting first photo: land and farm open on a lawn/garden, a flat/townhouse on an apartment block, commercial on a modern building (`AdTemplatePropertyTypes::category()` - the same keyword rules as the AI hint - picks the set; the panel is given `typePhotos` per the agency's own types). The sample listing's other values (price, beds, show day ...) are unchanged.
- **Tests:** `tests/Feature/Tools/AiTemplateExtrasOnlyWhenTickedTest.php` (one definition; unticked QR removed from Default and versions and logged; no ticks = no extras; ticked kept and required; price/agent/logo untouched; collage-only photo -> image_1; prompt forbids and omits unticked extras, styles do not suggest them, hints drop pills; demo files, no agency data, per-type photos; viewer markup/behaviour wiring). `AiTemplateBlocksAndStylesTest` (the recorded all-blocks answer now has its unticked extras removed) and `AiTemplateMultiTypeAndMustIncludeTest` (Esc/Tab wiring) updated. Viewer behaviour was also checked in a throwaway Chromium run at 1440 and 375 px (opens, aspect kept, no overflow, arrows, version switch, Esc/backdrop/X, focus return) - harness not committed.

#### B.10 amendment - "what the user asks for wins", and "no icons" is binding (2026-10-18, branch `ai-no-icons-rule-2026-10-18`)

Johan: "when a user says do not use icons on the text the ad shouldn't have any icons in it - it should follow what is
requested". Cause: the idea was framed to the model as a mood brief only, while the prompt and several styles suggest
icons ("icons before the numbers"), so a typed "no icons" lost.
- Both AI creators (property templates and company ads) now tell the model that anything the idea asks for or rules out
  about the LOOK beats the style and every suggestion (never the output format, HARD LIMITS or required items).
- "No icons" is also enforced on the server (`App\Services\AdTemplates\AdIdeaWishes`): when the idea says no icons
  (English or Afrikaans phrasings - "no icons", "do not use icons", "icon-free", "geen ikone", ...), the prompt carries a
  hard NO ICONS rule, the icon how-to line is left out, and every icon is removed from every design before AND after
  validation - Default designs and every property-type version. A count that leaned on its icon gets its words back
  ("3 Bedrooms", label shown), so no lone number is left. The removal is written to the validation log.
- "Don't forget the icons" / "use icons" are not a ban. Tests: `tests/Feature/Tools/AiTemplateNoIconsTest.php`.

#### Release-audit fixes - AI spend (2026-10-18, branch `audit-fix-ai-spend-2026-10-18`)

- **No retry after a timeout (D-M1).** `AnthropicGateway::callApi()` never re-sends a request that TIMED OUT, whatever the request (it used to be only document calls). A timeout means the provider may still bill the call, and the re-sends left no ledger row, so the guard could not see that spend. Connect failures (nothing sent) and 5xx answers are still retried. A failed call with no fallback now leaves a zero-cost, tokens-0 `fallback` ledger row (no cost figure is invented) so the failure shows on the usage page; the log line also says whether it timed out.
- **Batches are held to the cut-out (D-M3).** `AnthropicGateway::generateMany()` (the template builder's per-property-type calls) now calls `AiSpendGuard::reserveBatch()` before any call starts, inside the guard's lock: window spend + what other in-flight batches have reserved + this batch's worst-case cost (prompt about 3 characters a token, every `max_tokens` used, at the model's price) must stay under the cut-out, or the batch is refused through the existing "AI paused" path (`AiPausedException`, or the request's fallback). Reservations live in the cache with a safety expiry and are released (`releaseBatch()`) when the calls have been recorded. A batch of one call, and `generate()`, behave as before (overshoot of one in-flight call). The template builder also re-checks the pause before its retry round.
- **Spend email after the lock (D-L3).** `AiSpendGuard::evaluate()` writes the alert-sent marker (or the pause) inside the lock and sends the email only after the lock is released; a failed alert send takes the marker back (only if it is still the value written) and starts the existing 15-minute back-off. The alert event row is written after a successful send.
- **Article Library (D-L1, D-L2).** Every run (scheduled or web) stops starting new briefs after `MAX_RUN_SECONDS` (lock 900 s - one brief timeout - 180 s), so the 900 s run lock outlives the run. An item (or the candidates of a relevance call) whose answer comes back unreadable (`UnreadableAnswerException`) is counted per item hash and remembered - not paid for again - after 2 attempts. Transient API failures are still not counted.
- **Failed generations per day (D-M2, Johan 2026-10-18).** Failed AI generations still do NOT use the monthly allowance. But once an agency has `max_failed_per_day` (platform setting `ai_template_max_failed_per_day`, default 5, Dev Settings -> AI Spend Safety, `AiSpendGuard::KEY_MAX_FAILED_PER_DAY`) generations that ended `failed` with a paid call (`cost_zar > 0`) in its current day (`Agency::outreachTimezone()`, Africa/Johannesburg), further "Create with AI" attempts that day - property templates and company ads together - are refused before any AI call with "Too many attempts that did not produce a usable design today - try again tomorrow, or reword your idea.". The check is in `AiTemplateBuilderService::reserveAllowance()` under the same per-agency lock as the allowance. **Not in the Setup Wizard** - a platform setting, not an agency one (see `agency-onboarding-setup.md` §5.1).
- Tests: `AiSpendAuditFixesTest`, `ArticleLibraryAuditFixesTest`, `AiTemplateAuditFixesTest`, `AiTemplateMultiTypeAndMustIncludeTest`.


## One design per click + compulsory PPRA line (Johan, 2026-10-23 — do not re-ask)

- **One design per click.** Each "Design it" makes ONE design (`OPTIONS = 1`), not three — three per click cost too much (4 generations ≈ R30 on 2026-10-23). With property types ticked, that one design still gets its per-type versions. The monthly allowance is unchanged (one unit per successful click).
- **Every AI-designed ad ends with "Registered with the PPRA".** `App\Services\AdTemplates\AdPpraFooter` puts it in on the server (never the model): a full-width 40px strip at the bottom, white 16px text on 85% black, zIndex 100. Any model element mentioning the PPRA is removed first (no duplicates, no misspellings). The prompt reserves the strip; it is added before `AdLayoutValidator`, so a design that puts words or pictures in the strip is rejected by the overlap rule and retried with that reason. Wording is neutral — true for every South African agency. Ads saved before this date and hand-built templates are not changed.
