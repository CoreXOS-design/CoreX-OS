# AT-456 — AI Template Builder, Article Library, Post Scheduler & AI Spend Safety

> Status: **DRAFT — decisions confirmed by Johan 2026-10-14 and 2026-10-17 (all section-0 readings confirmed); spec awaiting final approval. DO NOT BUILD until Johan says go.** No code written.
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
- `AgencyAiBudgetWarning` event and the gateway's budget-warning detection.
- The budget form/columns on `admin/ai-usage` (`ai_monthly_budget_zar`, warning %, hard-cap %, overage). Route `admin.ai-usage.budget.update` is removed.
- DB columns `ai_monthly_budget_zar`, `ai_budget_*` are **left in place, unused** (no destructive migration now; drop in a later cleanup once nothing reads them).
- `tests/Feature/AI/AiUsageDashboardTest.php` and `AiUsageLedgerIntegrationTest.php` updated for the removal.
- **Kept:** `ai_usage_events` ledger, `AiUsageRecorder`, `AiCostCalculator`, the dashboard's spend/daily-burn views.

### A.2 New behaviour
- **Window:** rolling 24 hours of `ai_usage_events.cost_zar` per `agency_id` (index `(agency_id, occurred_at)`).
- **Alert (R30):** when an agency's 24h spend first reaches the alert level, email the alert recipients: agency name, spend, top 3 sources, link to its AI usage page. Sent **at most once per agency per 24h** (`agencies.ai_alert_last_sent_at`).
- **Cut-out (R50):** when 24h spend reaches the cut-out level, set `agencies.ai_paused_at`, `ai_paused_reason`, `ai_paused_spend_zar`. While paused every AI call for that agency is refused with a plain message ("AI is paused for your agency — please contact CoreX support."). Email recipients the cut-out notice too.
- **Resume:** owner-only button on the agency's AI usage page sets `agencies.ai_resumed_at` and clears the pause. The guard counts spend **since `max(now − 24h, ai_resumed_at)`** — without this an agency would re-trip instantly, because the last 24h still contains the R50. Resume also resets the alert state and writes an audit entry (who, when, spend at the time).
- **System calls** (`agency_id` null — e.g. Article Library): same two thresholds evaluated across null-agency events platform-wide; paused flag stored as a `DevSetting` (`ai_system_paused_at`). My decision, not discussed.
- **Known limit:** spend is recorded after a call, so one in-flight call can overshoot R50 by its own cost. Acceptable; stated so nobody is surprised.

### A.3 One choke point — fix the class, not the instance
- New `App\Services\AI\AiSpendGuard`: `assertAllowed(?int $agencyId): void` (throws `AiPausedException` with the friendly message) and `evaluate(?int $agencyId): void` (called by `AiUsageRecorder::record()` after each write; trips alert/cut-out).
- **Every** direct Anthropic caller calls `assertAllowed` first: `AnthropicGateway`, `MarketingCopyService`, `IntentExtractionService`, `VisionRecognitionService`, `ImporterAiService`, `AiFieldMapperService`, `ClaudeVisionParserService`, `AIExtractionService`, `AiSummaryService`, `StrategicBriefService`, `EllieAgentService`, plus the new B and C services. Each caller's friendly-failure path already exists for `AiCopyUnavailableException`; paused maps onto the same pattern.
- **Guard test:** a test that scans `app/` for `api.anthropic.com` / `AnthropicGateway` usage and fails if a file lacks `AiSpendGuard`, so a future service cannot bypass it silently.

### A.4 Platform settings — new Dev Settings section "AI Spend Safety" (decision 12)
- **Settings** (`DevSetting` store): `ai_alert_threshold_zar` (30), `ai_cutout_threshold_zar` (50), `ai_guard_window_hours` (24), `ai_alert_recipients` (`andre@corexos.co.za`, comma-separated).
- **Where:** a new page `/admin/dev-settings/ai-spend` (route names `admin.ai-spend-settings.edit` / `.update`), `owner_only` middleware, following the existing Dev Settings sub-section pattern (`admin/dev-settings/demo-connection`, `admin/dev-settings/webinars`).
- **Navigation:** new item in the Dev Settings group of `corex-sidebar.blade.php` (same gating as its siblings), plus a "Change thresholds" link from the AI usage page.
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
| Template generations | `used / limit` this month |
| AI templates saved | count of `property_ad_templates.ai_generated` |
| Library articles used | count of `agent_articles.library_article_id` |
| Actions | **Turn AI back on** (only when Paused), View detail |

- **Search:** agency name. **Sort:** every column; default = 24h spend, highest first. **Filters:** status (All · Active · Alerted · Paused), month. **Pagination:** 25. **Empty state:** "No AI usage recorded for this month."
- Agency detail page: adds per-source breakdown, last alert/pause/resume history, and the per-agency **template generation limit** input (default 20).
- Scoping: owner-only at the route; no agency user can reach it.

### A.6 Acceptance
- [ ] No AI call anywhere checks `ai_monthly_budget_zar`; the budget form is gone.
- [ ] 24h spend ≥ R30 → exactly one email to the configured recipients; second event inside 24h sends none.
- [ ] 24h spend ≥ R50 → agency paused; Ellie, copy, briefs, voice, photo, DocuPerfect and new features all refuse with the friendly message; other agencies unaffected.
- [ ] Resume works and the agency is not re-paused by old spend; resume is audited.
- [ ] Guard-coverage test passes.
- [ ] Changing a threshold or recipient in Dev Settings → AI Spend Safety changes behaviour without a deploy; the section is reachable from the sidebar and is refused for non-owners.

---

## B. AI Template Builder (inside Template Manager)

### B.1 What it does
On `tools/ad-manager/templates` a **"Create with AI"** button opens a panel: **idea box** (text, required) + **reference image** (optional) + **ad size** (Facebook / Instagram / Story / WhatsApp) + **property type** (optional, drives §18 variants). Generate → **three previews** → agent saves any they like → each saved option becomes a normal `PropertyAdTemplate` in the agency library (editable in the Ad Builder, archivable, restorable).

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
- [ ] "Create with AI" yields three valid previews for a one-line idea; each saves as a normal template that opens in the Ad Builder.
- [ ] Uploading a reference image changes the result and is purged after 7 days.
- [ ] Invalid AI output never reaches the user and doesn't consume allowance.
- [ ] 21st generation in a month is refused with the reset date; Johan raising the agency limit takes effect immediately.
- [ ] Second agency sees its own colours/logo and none of HFC's.
- [ ] **Pre-build trial (before any UI):** run the prompt against 5 real listings and let Johan judge the ads. Go/no-go on the output quality.

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
- [ ] Adding a feed link and pressing Run now produces new ready articles with a credit + working source link.
- [ ] Archived sources are skipped; the 5 seeded feeds behave as before for presentations.
- [ ] No library article is ever published without an agent clicking Use this, then publishing themselves.
- [ ] Two agencies can each Use the same article; each gets their own draft; neither sees the other's.
- [ ] A hidden article disappears from every agency's tab.
- [ ] Spend counts toward the system AI guard (A.2).

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

### D.5 Blockers / prerequisites
- **Meta App Review + Business Verification** must pass before any agency other than app Testers can connect. Build and test on Johan's/Andre's own accounts until then.
- QA2 has no scheduler cron and no queue worker running; test on QA1/Staging or start them on QA2.
- Setup Wizard: no new agency-level setting is introduced by D.

### D.6 Acceptance
- [ ] A post scheduled 10 minutes ahead publishes within a minute of its time and shows `published` with the platform id.
- [ ] Two scheduler runs overlapping on the same post publish it once.
- [ ] Expired connection → `failed` with a readable reason and a notification; nothing silently lost.
- [ ] Cancel before time prevents publication; Reschedule works.
- [ ] A branch manager sees only their branch; an agent only their own; a forged id → 404/403.
- [ ] Images still resolvable at publish time.

---

## Settings audit (CoreX rule 10a — "NOT in the wizard", for Johan's confirmation)

| Setting | Where | In Setup Wizard? |
|---------|-------|------------------|
| AI alert/cut-out thresholds, window, recipients | Dev Settings → AI Spend Safety (owner) | **No** — platform-owner control, not an agency's |
| Per-agency template generation limit (default 20) | AI usage agency page (owner) | **No** — Johan sets it per agency |
| Article sources, schedule, max per run | `/admin/article-library` (owner) | **No** — system-level |
| (none for D) | — | n/a |

## Multi-agency check ("what does it look like for the SECOND agency?")
Own colours/logo in B; own drafts and own count in C; own posts, scope and connection in D; own spend window and pause state in A. No HFC wording, ids or defaults anywhere; library articles carry no agency name until an agent edits their copy.

## Files (indicative)
**A:** `Services/AI/AiSpendGuard.php`, `Exceptions/AI/AiPausedException.php`, `Mail/AiSpendAlert.php`, `Admin/AiSpendSettingsController.php` + `admin/ai-spend-settings/edit.blade.php` + Dev Settings sidebar item, edits to `AiUsageRecorder`, `AnthropicGateway`, `MarketingCopyService`, `WarmThisWeekTilesJob`, all direct callers, `Agency.php`, `AiUsageController`, `admin/ai-usage/*.blade.php`, routes, 2 migrations, tests.
**B:** `Services/AdTemplates/AiTemplateBuilderService.php`, `Tools/AdTemplateManagerController` (+ `generateWithAi`, `saveAiOption`), `tools/ad-manager/templates.blade.php`, migration, tests.
**C:** `Services/Articles/ArticleLibraryService.php`, `ArticleScraperService` (read from table), `Admin/ArticleLibraryController`, `Tools/ArticleLibraryController`, views, `_switcher.blade.php`, 2–3 migrations, command + schedule entry, tests.
**D:** `Console/Commands/PublishDueMarketingPosts.php`, `Jobs/PublishMarketingPostJob.php`, `Tools/ScheduledPostsController`, views, `_switcher.blade.php`, permission keys, migration, event, tests.

## Open items for Johan
1. ~~Article brief length~~ — **Closed 2026-10-14:** short briefs accepted (decision 9).
2. ~~Alert recipients~~ — **Closed 2026-10-14:** `andre@corexos.co.za`; add further addresses in Dev Settings → AI Spend Safety when wanted (decision 10).
3. ~~Section-0 readings~~ — **Closed 2026-10-17:** all three confirmed (decisions 11–13); settings get their own Dev Settings section (A.4).
4. Final go to build Part A.
