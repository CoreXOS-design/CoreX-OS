# CoreX OS — Chat Starter
> Auto-maintained by VS Code per CLAUDE.md rule. Paste into a new Claude chat to load context.
> Last updated: 2026-05-25 by prompt-H (Phase 9c-2 Information Officer appointments)

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

### 3.2 IN FLIGHT

- **Branch `feature/map-workspace-overhaul`** — MIC collision fix landed (A.3.4); Tonight: M22 test fix + universal-signature audit + Phase 9c investigation + three small fixes (419 / CMA logo / Tools logo) shipped on same branch.
- **Phase 9c — POPIA blockers** — three-part build in flight.
  - **9c-1 PPRA number ✅** — `agencies.ppra_number` + `branches.ppra_number` columns added (nullable string(32)). Settings UI fields wired in agency + branch panels (PPRA above FFC). Render fixed: `corex-document.blade.php` mislabel ("PPRA" was reading `ffc_no`) corrected; PPRA + FFC now render as separate lines with branch→agency cascade and a `[not configured]` placeholder when missing. Email agent-footer gains a PPRA line beneath FFC. RCR export-pdf relabel done. Population: Tinker after deploy (HFC numbers TBD per branch).
  - **9c-2 Information Officer ✅** — `information_officer_appointments` table mirrors FICA pattern verbatim (primary + deputies, soft delete, auto-end-old-primary boot hook). Model `App\Models\Compliance\InformationOfficerAppointment` with `currentPrimary()` / `activeDeputiesFor()` static helpers. Controller mirrors `FicaOfficerAppointmentsController`. Agency model gains `currentInformationOfficer()` + `allActiveInformationOfficers()`. Admin UI panel under Compliance Settings (POPIA s55 + s56). Permission: `manage_information_officer`. 5 tests cover lifecycle. Population: Tinker after deploy with Elize's user_id.
  - **9c-3 Privacy policy / Company Documents** — pending (admin-managed content, public `/legal/{token}` link, mirrors `presentation_dispatches` token pattern).
- **Next:** Andre — Staging deploy + live DB pull after batch lands. Figure-matching audit on staging.

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

- **2026-05-25** — Phase 9c-2 (Information Officer) shipped: `information_officer_appointments` mirrors FICA pattern (primary + deputies, auto-end-old-primary). Admin UI under Compliance Settings, permission `manage_information_officer`, 5 lifecycle tests passing.
- **2026-05-25** — CDS template-creation investigation completed; report at `.ai/audits/cds-template-creation-investigation-2026-05-25.md`. 125 templates total (28 CDS, 3 hand-crafted, 22 importer, 72 legacy). 71% multi-tenant safe. April hand-crafted-Blade decision stands. Brief ready for tomorrow's joint session.
- **2026-05-25** — Phase 9c-1 (PPRA number) shipped: agencies + branches columns + settings UI + corex-document mislabel fix + agent-footer + RCR export. Branch-overrides-agency cascade verified via Tinker.
- **2026-05-25** — Presentations V2 phases 4–7 audit completed; report at `.ai/audits/presentations-v2-phases-4-7-audit-2026-05-25.md`. All four phases ✅ fully built; moved into section 3.1 LIVE. End-to-end "4-month-old link → banner → refresh request → agent notified" flow operational.
- **2026-05-25** — POPIA columns investigation completed; report at `.ai/audits/popia-columns-investigation-2026-05-25.md`. Conclusion: `ffc_no` ≠ PPRA reg number (legally distinct under PPA 22/2019). Phase 9c rename-vs-add decision = ADD new column.
- **2026-05-25** — CLAUDE.md tightened: subagents producing audit/report files MUST write to disk via file-write tools, not return content in chat only (response to tonight's universal-signature subagent that skipped the write step).
- **2026-05-25** — Tonight's batch: 419 redirect + CMA cert logo + Tools page logo all shipped on `feature/map-workspace-overhaul`. Agency `logo_path` is now the canonical logo source for Tools (wins over stale `PerformanceSetting.company_logo_url`).
- **2026-05-25** — Phase 9c investigation: privacy policy + Information Officer + agency PPRA number — findings reported, moved from PARKED to IN FLIGHT; awaiting architectural decisions before fix.
- **2026-05-25** — Universal signature audit completed; report at `.ai/audits/universal-signature-audit-2026-05-25.md`. Layers 1 + 2 both unbuilt. 8 capture surfaces total (3 authenticated, 4 token, 1 CO). Wire-up effort: M-size (~10 files); encryption at rest: S-size (independent).
- **2026-05-25** — `.ai/CHAT_STARTER.md` bootstrap landed; CLAUDE.md close-checklist now requires updating it every prompt.
- **2026-05-25** — Staging deploy after MIC collision fix; Andre handles deploy + live DB pull. Map + MIC walk on staging. Figure-matching audit on real data next.
- **2026-05-25** — 1 Aug live target locked. Weekly cadence: 26 May staging stable → 2 June bug-hunt week 1 (Cindy/Rochelle/Gerda/Shawn) → 9 June feature freeze → 16 June stability → 23 June PropCon notice decision → 30 June 30-day notice → 1 Aug live.
- **2026-05-25** — Demo stays separate (synthetic Uvongo data, midnight reseed). Staging is proving ground with live DB.
- **2026-05-25** — Workflow: lightweight verification per prompt, full M1-MN sweep + dev-check every 3rd prompt or on explicit ask.
- **2026-04-29** — Architecture: Claude owns template design centrally. Hand-crafted Blade with declarative metadata, bypass CDS UI. Templates 116/117/119 first under this model.

## 5. Outstanding small fixes (none blocking)

- _(none open as of 2026-05-25 — 419 / CMA logo / Tools logo all shipped tonight)_

## 6. Next likely move

Branch `feature/map-workspace-overhaul` carries everything from tonight (A.3.4 MIC fix + M22 test + 419 + CMA + Tools logo + chat starter + 3 audit reports). Next when Johan returns: (1) decide Phase 9c scope — new `agencies.ppra_number` (per-agency or per-branch?), Information Officer (User FK vs free-text + scope?), privacy policy (per-agency external URL vs CoreX-hosted page?) — then VS Code writes the fix prompt; (2) merge feature branch → Staging; (3) Andre pulls live DB, walks map + MIC + e-sign on staging; (4) figure-matching audit; (5) decide whether universal-signature pre-fill (Layer 2) makes the 1 Aug cut. Presentations V2 phases 4–7 are LIVE — no action needed there. Bug-hunt week from 2 June.

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
