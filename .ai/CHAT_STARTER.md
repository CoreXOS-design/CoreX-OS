# CoreX OS — Chat Starter
> Auto-maintained by VS Code per CLAUDE.md rule. Paste into a new Claude chat to load context.
> Last updated: 2026-05-26 by B2 (role-block loop renderer)

<!-- ============================================================ -->
<!-- STABLE SECTION — rarely changes                              -->
<!-- ============================================================ -->

## 1. Identity & Stack

**Who:** Johan Reichel (CEO, HFC). Andre Roets (full-stack dev). Two-person team building CoreX OS — real estate operating system. HFC (Home Finders Coastal) is the first live agency. Long-term vision: SA's leading real estate OS, replacing PropCon / Sage / Property24 layers across the industry.

**Stack:** Laravel 12 / Blade / Alpine.js. MySQL prod (`hfc_corex`, `hfc_staging`). SQLite local. Vite. Ubuntu on Hetzner `91.99.130.85`. Code at `/hfc` (live) and `/hfc-staging`. Python AI service `/opt/hf-ai/app.py` port 3100 (not in git).

**Domain:** `corex.hfcoastal.co.za` live. Staging on port 8084.

**Branches:** `main` (live), `Staging`, `HFC2402` (Johan dev), `andre` (Andre dev), feature branches off Staging. Discipline: HFC2402 + andre → Staging → staging server → main → live. Hotfix exception: direct to main, then back-sync to Staging.

**VS Code Claude** is the coding agent. Johan pastes prompts, it executes. Specs in `.ai/specs/`, read at start of every prompt.

## 2. Working Rules

- **Investigate before fixing.** Every issue: (1) audit, (2) Johan approves fix approach, (3) only then fix prompt. Never guess root causes.
- **One concern per prompt.** Two only if tightly related and same file.
- **Surgical changes.** No refactoring beyond scope.
- **Every prompt opens:** read CLAUDE.md, .ai/STANDARDS.md, relevant spec, and this file.
- **Every prompt closes:** php -l, php artisan view:clear, scripts/dev-check.ps1 if structural change, Tinker verification, **update this CHAT_STARTER.md**.
- **No hard deletes.** SoftDeletes only.
- **PPRA not EAAB. POPIA consent rules apply.**
- **Bug report = code is wrong.** Never blame caching or user error.

<!-- ============================================================ -->
<!-- DYNAMIC SECTION — VS Code updates this                       -->
<!-- ============================================================ -->

## 3. State of the build

### 3.1 LIVE (production or staging — agents using or about to)

- **Agency Tracker** — deal register, settlements, commission engine, finance audit, daily activity points, branch performance
- **DocuPerfect e-sign V2** — 6-step wizard, agent signing, external gateway, document supersede/Edit & Re-send, candidate-practitioner supervisor gate, FICA gate, document cancellation, audit trail, document hash on every event, agent approval gate, multi-party sig tag fan-out
- **Sales Mandate Pack** — templates 116 + 117 + 119, hand-crafted Blade, declarative metadata, multi-tenant via `$agency->*`
- **Email signature system** — `BaseSignatureMail`, `agent-footer.blade.php`, 15 email templates with branded agent footer
- **FICA compliance module** — wet-ink + online intake, CO approval, agent doc upload, corrections cycle, auto-file to contact drive, 4-stage pipeline
- **RMCP** — 28+5 sections seeded per agency, acknowledgement workflow, primary CO + MLRO appointments
- **Onboarding pipeline + Training LMS + Agent Compliance Dashboard + Self-service portal**
- **Ellie AI** — KB vector embeddings (text-embedding-3-small), hybrid search (cosine 0.7 + structural 0.3), RAG, clause-aware chunker
- **P24 import** — IMAP hourly cron + Chrome extension, 1,545+ listings
- **Prospecting module** — Chrome extension, claim system 48h auto-expiry, seller-outreach compose flow, cross-portal dedup
- **Private Property sandbox** — SOAP Agency Feed Rev 4.6, listing T2870133 submitted
- **Property24 ExDev sandbox** — Listing Service v53, agency 31357 (in progress: env var issue)
- **Map workspace A.1–A.3 + A.2.7** — composite pins, action mapping, view modes, search/filter/saved searches, hover summaries, contact ID capture, GPS prospect collision
- **MIC** — 8 modules: Market Pulse, Content Studio, Listing Marketing, Social Hub, Campaigns, Newsletter, Scheduler, Analytics
- **Calendar Module** — RAG events, event classes, leave/birthday/holiday as informational vs actionable, Module 2.5 multi-property, Module 3 done, iCal per scope
- **Domain Events architecture** — spec at `.ai/specs/corex-domain-events-spec.md`, event bus + observers + queued listeners
- **Property cross-reference + unified buyer wishlist + buyer matching engine**
- **Commission & Revenue Share Engine** — 7 tables, bcmath, agent dashboard, principal P&L, sponsor tree
- **Branch Isolation** — spec built, 14 prompts A–N
- **Tax / Payroll** — SARS e@syFile IT3(a) submission for tax year ending Feb 2026
- **Presentations V2 phases 4–7** — snapshot links + engagement tracking (FirstViewed + FlaggedAccess notifications) / teaser route + lead capture / send-to-recipient delivery (whatsapp + email, sticky defaults) / refresh-request flow with "data may be dated" banner (default 21d, agency-configurable 7–90), 3/10min rate limit, supersession auto-redirect. All 5 tables present (`presentation_snapshot_links`, `presentation_snapshot_views`, `presentation_teaser_leads`, `presentation_deliveries`, `presentation_refresh_requests`). End-to-end flow verified working today.
- **Phase 9c POPIA trilogy** — (1) PPRA per-entity column on `agencies` + `branches` with branch→agency cascade, settings UI, `corex-document` mislabel fix, agent-footer + RCR export renders. (2) `information_officer_appointments` mirroring FICA pattern (primary + deputies, auto-end-old-primary), admin UI in Compliance Settings (POPIA s55 + s56), permission `manage_information_officer`, 5 lifecycle tests. (3) **Privacy policy: editable markdown field in Company Settings (mirrors Email Disclaimer pattern) + per-branch override (plain column names per existing override convention) + public token route at `/legal/privacy/{token}` + `Agency::effectivePopiUrl()` cascades internal published → external `popi_url` → null. Branch model has matching `effectivePopiUrl()` + `effectivePrivacyPolicyUrl()` helpers. 8 tests passing.** _(Phase 9c-3's prior `company_documents` table was rolled back 2026-05-26 per the documents-infrastructure audit — replaced by this field-based pattern.)_

### 3.2 IN FLIGHT

- **Branch `feature/map-workspace-overhaul`** — MIC collision fix (A.3.4) + M22 test fix + universal-signature audit + small fixes (419 / CMA logo / Tools logo) + **complete Phase 9c trilogy** (PPRA per agency+branch, Information Officer appointments, Company Documents infrastructure with `/legal/{token}` public links) all shipped. **Module 6 (Activity Points Engine) M6.0 + M6.1 + M6.2 shipped:** investigation + schema foundation (scope/agency_id, point_state/source/calendar_event_id/override audit, Eloquent ActivityDefinition + DailyActivityEntry models, 41 defs backfilled system, 1,492 entries backfilled confirmed/manual) + per-agency mapping table `activity_definition_calendar_classes` with admin UI at `/admin/activity-mappings` (CRUD + toggle, permission `manage_activity_mappings`, `resolveForEvent()` helper for M6.3). M6.3 (observer + provisional points + anti-gaming + audit table), M6.4 (confirm hook + revoke command + override service), M6.5 (daily view + override UI) ahead in next session.
- **Next:** Andre — Staging deploy + live DB pull. Figure-matching audit on staging.

### 3.3 SPECCED / partial — not fully built

- **Universal signature feature** — Layer 1 (upload tab in signing modal) + Layer 2 (stored signature on user profile) specced March 2026. Build state unconfirmed pending audit. Vision: apply pre-fill to every signature capture surface (FICA ack, RMCP ack, training, deal step sign-off) where signatory is authenticated user.
- **Tenant pre-approval** (TVA integration)
- **Inspection reports module**
- **Statistics tile builder** ("See every number the way you want to see it")
- **Map A.4 / A.5 / A.6** — logo pins, viewport pagination, mode pills (Opportunities / Analyse / Seller / Buyer)
- **CoreGolf** — Johan's personal project, Huawei GT6 Pro ArkTS app
- **Module 4 (Buyer CRM) + Module 5 (Seller Live Link)** — calendar follow-on
- **Deal Register V2** — full spec written
- **Sales pipeline + Rental pipeline views**

### 3.4 PARKED / future

- **Phase 9e** — 6 audit M-fixes + 5 deferred 9d.2 items
- **System-wide back-nav audit**
- **User activity tracking spec**
- **4 strategic spec pillars** — compliance philosophy, integration philosophy, supplier partnership thesis, naming canon
- **Auto activity tracking + Exclusive auto-notification**
- **CDS importer path** — keep or retire? (decided: hand-crafted Blade is the path; CDS legacy support TBD)
- **Accounting module** — long-term Sage replacement

## 4. Recent decisions log (last 15, newest top)

- **2026-05-26** — B2 shipped: role-block loop renderer. Two new services in `app/Services/Docuperfect/`: (1) `RoleBlockDetectionService` parses field names into `{role_base, instance_index, sub_name, pattern}` recognising 3 patterns (`role_idx_sub` like `seller_2_phone`, `role_sub_idx` like `seller_address_2`, `role_sub` singleton like `seller_first_name`) plus bare `role`; ROLE_BASES const ordered longest-first so `owner_party` wins over `owner`. (2) `RoleBlockExpansionService::stampIdentities()` single-pass regex rewrites every `<tag … data-field="…">` to inject `data-recipient-identity="{role}_{idx}"` + `data-role-token="{role}"`, plus `data-orphan-recipient="1"` when idx exceeds the document's recipient count for that role. Canonical-twin lookup (seller↔owner_party, buyer↔acquiring_party, lessor/landlord↔owner_party, lessee/tenant↔acquiring_party) keeps wizard-token templates interoperable with canonical-token recipients. Wired into `SigningController::show()` after `InsertableBlockRenderer::renderInDocument()`. **V1 scope decision: defer the "1 block → N copies via DOM rewrite" auto-loop — HFC's existing templates (e.g. template 111, 347) use hardcoded numbered fields (`seller_address_1`…`_4`); stamping the existing fields is the productive path today, auto-loop becomes useful only when a template uses a single-block authoring style.** 12 tests passing. Tinker walk against template 347 (2 sellers + 1 agent, 4-block hardcoded body): 13 stamps emitted, 6 correctly flagged orphan for seller_3 + seller_4. B3 (per-recipient editable scope at signing view) is next.
- **2026-05-26** — B1 shipped: indexed recipient identity. `role_index` column added to `signature_requests` (NOT `signature_template_recipients` — B0 had table name wrong; corrected in commit message) with PHP-regex backfill that split 84 `seller_2` rows into clean `seller` + role_index=2. `SignatureRequest` model gains `role_identity` accessor + `forRoleInstance(role, idx)` scope. `Template::mapSigningPartyKeys()` extended to auto-number duplicates while leaving singletons unchanged (backward compat). New `Template::roleDisplayLabel()` per-token helper recognises wizard's raw tokens (`seller`/`lessor`/`landlord`/`tenant`) in addition to canonical `owner_party`/`acquiring_party`. Wizard's `:1924-1937` keeps the legacy suffixed party_key string for 8 downstream callers but `SignatureService::createSigningRequest()` splits the suffix at insert so the persisted column is always clean. `SigningController::show()` now exposes `$currentRecipient` + `$currentRoleIdentity` to the view (foundation for B2 renderer + B3 signing scope). 13 tests passing. Tinker walk against live session 249 confirmed Johan→agent_1, James→seller_1, Steve→seller_2.
- **2026-05-26** — Fix A shipped: Step 5 chip rendering now iterates the full `editable_by` array. `ESignWizardController::buildFieldsFromMappings()` preserves the array as `editableBy` payload; `assignedTo` retained as legacy first-of-array string for 8 JS call sites. wizard.blade.php gains `fieldRoleTokens(f)` + `isCreatorRole(role)` helpers; chip render replaced with `<template x-for>` loop. 5 tests passing.
- **2026-05-26** — B0 recipient-loop-engine investigation: report at `.ai/audits/recipient-loop-engine-investigation-2026-05-26.md`. Smoking gun for Break 2: wizard DOES uniquify keys to `seller`/`seller_2`/etc. at ESignWizardController:1924-1937, but SigningController:1347-1385 maps them all back to `owner_party` role token, and sign.blade.php:1941 renders all sellers' address fields with the same `data-field` attribute so only the first converts to an input. Resolver IS correct; DOM matching IS broken. B1 builds indexed identity end-to-end, B2 the loop renderer, B3 per-recipient scope, B4 Step 5 multi-instance layout. Block-detection approach: field-clustering by `party` token (cheapest).
- **2026-05-26** — `editable_by` runtime investigation: two breaks confirmed at code level + verified vs Tinker dump of template 111 — Break 1 at `ESignWizardController.php:3508` truncates `editable_by` arrays to first element (`$editableBy[0] ?? 'agent'`) so Step 5 renders ONE chip when template has BOTH seller + agent. Break 2 in recipient signing view: multi-seller templates collapse all sellers to single `party_role='owner_party'` token, so resolver can't distinguish Seller 1 from Seller 3 — needs per-seller architectural decision. Report at `.ai/audits/editable-by-runtime-investigation-2026-05-26.md`.
- **2026-05-26** — E-Sign full state audit completed; report at `.ai/audits/esign-full-state-audit-2026-05-26.md` — comprehensive map of current state vs wet-ink target for Johan's strategic call. Headline: amendment surface was simplified (flag-based) 6h before the demo via Phase 1B.6 (commit 7521e63), NOT torn out; strikethrough endpoint returns 410 Gone deliberately; `editable_by` server-side logic at SigningController:1347-1385 reads correct per code but never operationally verified — gap between Johan's locked-fields report and the audit needs live-test resolution.
- **2026-05-26** — Rolled back Phase 9c-3 over-build. Privacy policy now lives as Company Settings field next to Email Disclaimer with branch override (plain column names, mirrors existing override convention). Public URL via `/legal/privacy/{token}`. `effectivePopiUrl()` accessor cascades internal published → external `popi_url` → null. Documents-infrastructure audit confirmed Phase 9c-3 duplicated scope of pre-existing `agency_compliance_provisions` system — table dropped, files deleted, replaced with field pattern.
- **2026-05-25** — Module 6 M6.2 shipped: `activity_definition_calendar_classes` mapping table (agency_id + event_class slug + activity_definition_id + value_per_event + requires_feedback + auto_revoke_after_hours + daily_cap + back_date_limit_hours + is_active). Model with `resolveForEvent(CalendarEvent)` static helper, admin CRUD at `/admin/activity-mappings`, permission `manage_activity_mappings`. 6 tests passing. Schema deviation from prompt: mapping keys off event_class slug not FK (real schema has `calendar_event_class_settings` + `calendar_events.category` string, no `calendar_event_classes` table). Stopping here for the session; M6.3 + M6.4 + M6.5 in next session per honest-scope rule.
- **2026-05-25** — Module 6 M6.1 shipped: activity_definitions gains scope/agency_id (varchar — `system`/`agency` enforced at model layer); daily_activity_entries gains point_state/source/calendar_event_id/confirmed_at/revoked_at/override metadata. New ActivityDefinition + DailyActivityEntry Eloquent models. 41 definitions backfilled to `system` (global → system rename). 1,492 entries backfilled to confirmed/manual. 7 tests passing.
- **2026-05-25** — Module 6 M6.0 investigation complete; report at `.ai/audits/activity-points-integration-investigation-2026-05-25.md`. Findings drove decisions: scope is varchar (not enum); 46 calendar event classes (4 buyer-facing); CalendarEventFeedbackObserver exists (M6.4 hook surface); no CalendarEvent observer yet (M6.3 creates it); zero architectural surprises.
- **2026-05-25** — Phase 9c-3 (Company Documents) shipped: `company_documents` table with token-based public `/legal/{token}` route mirrors `presentation_snapshot_links` pattern. Markdown content with admin live-preview editor. 6 curated types (privacy, T&Cs, complaints, AML, code of conduct, POPIA consent). Agency `privacy_policy_url` accessor cascades published doc → legacy `popi_url`. Phase 9c trilogy now LIVE.
- **2026-05-25** — Phase 9c-2 (Information Officer) shipped: `information_officer_appointments` mirrors FICA pattern (primary + deputies, auto-end-old-primary). Admin UI under Compliance Settings, permission `manage_information_officer`, 5 lifecycle tests passing.
- **2026-05-25** — CDS template-creation investigation completed; report at `.ai/audits/cds-template-creation-investigation-2026-05-25.md`. 125 templates total (28 CDS, 3 hand-crafted, 22 importer, 72 legacy). 71% multi-tenant safe. April hand-crafted-Blade decision stands. Brief ready for tomorrow's joint session.
- **2026-05-25** — Phase 9c-1 (PPRA number) shipped: agencies + branches columns + settings UI + corex-document mislabel fix + agent-footer + RCR export. Branch-overrides-agency cascade verified via Tinker.
- **2026-05-25** — Presentations V2 phases 4–7 audit completed; report at `.ai/audits/presentations-v2-phases-4-7-audit-2026-05-25.md`. All four phases ✅ fully built; moved into section 3.1 LIVE. End-to-end "4-month-old link → banner → refresh request → agent notified" flow operational.

## 5. Outstanding small fixes (none blocking)

- _(none open as of 2026-05-25 — 419 / CMA logo / Tools logo all shipped tonight)_

## 6. Next likely move

Branch `feature/map-workspace-overhaul` carries everything from tonight: A.3.4 MIC fix + M22 test + 419 + CMA + Tools logo + chat starter + 4 audit reports + **complete Phase 9c trilogy** (PPRA, Information Officer, Company Documents). All 9c POPIA blockers closed. Next when Johan returns: (1) populate HFC's PPRA numbers + appoint Elize as primary IO + draft+publish HFC privacy policy via Tinker; (2) tomorrow's CDS architecture session (brief at `.ai/audits/cds-template-creation-investigation-2026-05-25.md`); (3) merge feature branch → Staging; (4) Andre pulls live DB, walks map + MIC + e-sign + Phase 9c surfaces on staging; (5) figure-matching audit; (6) decide whether universal-signature pre-fill (Layer 2) makes the 1 Aug cut. Bug-hunt week from 2 June.

<!-- ============================================================ -->
<!-- MAINTENANCE RULE FOR VS CODE                                 -->
<!--                                                              -->
<!-- At the end of every prompt that lands work:                  -->
<!-- • If something moved SPECCED → IN FLIGHT: move it.           -->
<!-- • If something moved IN FLIGHT → LIVE: move it.              -->
<!-- • If a decision was made: prepend dated entry to section 4;  -->
<!--   cap at 15 entries (remove oldest).                         -->
<!-- • If a small fix completed: remove from section 5.           -->
<!-- • Update the "Last updated by" header line.                  -->
<!-- • Keep total length under 350 lines.                         -->
<!-- • Use str_replace targeted edits; do not rewrite sections    -->
<!--   wholesale unless structure demands it.                     -->
<!-- ============================================================ -->
