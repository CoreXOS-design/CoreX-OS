# Deal Register V2 Programme — Investigation Report

> **Type:** READ-ONLY investigation feeding a Johan + Claude spec-design session. No code changed.
> **Date:** 2026-07-02 · **Jira:** AT-158 · **Branch verified:** `Staging`
> **Method:** 5 parallel subagent code-audits, every claim anchored to file:line in the repo.
> **Status:** Investigation only — HOLD. Nothing built, nothing promoted. Live gated on Johan.

---

## 0. Headline that reframes the brief

**Deal Register V2 is not greenfield — it is already ~Phase 1–3 built and sitting unused (0 rows on dev and live).** The "next major build" is therefore **complete DR2's runtime + add OTP (Offer-to-Purchase) document distribution + wire the DR1→DR2 transition**, NOT "build DR2 from scratch." This changes sequencing and risk: an *untested* pipeline engine is about to become the canonical deal store.

---

## 1. Deal Register V1 (in daily use) — as-built

**One system, three names.** "Deal Register" = "Agency Tracker" = "Commission Engine" — all on **`deals` / `App\Models\Deal`**. Sidebar group "Agency Tracker" → sub-link "Deal Register" → `route('admin.deals')` (`resources/views/layouts/corex-sidebar.blade.php:753,801`). Specs `.ai/specs/agency-tracker.md`, `.ai/specs/commission_engine_spec.md` confirm they are sub-modules over the same table.

- **Live data (`nexus_os`, reachable via mysql socket): 131 deals, `deal_date` 2025-10-02 → 2026-06-29.** Dev `corex_dev`: 123. `deals_v2`: **0 everywhere.** The Oct-2025 backfill lives in `deals`.
- **Status = a two-axis FREE dropdown, not a workflow:** `accepted_status` P/G/R/D + `commission_status` Not Paid/Paid/Loss. Changed via `Admin\DealController::quickUpdate` (`POST /admin/deals/{deal}/quick`, `admin.deals.quickUpdate`, `app/Http/Controllers/Admin/DealController.php:290`). Any state → any state. **No state machine, no domain events on status change; the only hard gate is the Paid-lock** (`Deal::isLocked()`, `Deal.php:19`). Status for rollups is *derived* from `registration_date`/`granted_at`/`accepted_status` (`Deal::statusSummaryForBranch()` `:434`).
- **Timer-capable dates:** `granted_at`, `registration_date`, `deal_date`.
- **Parties are free-text:** `seller_name`, `buyer_name`, `attorney_name` (added `2026_01_15_113405_add_register_fields_to_deals_table.php:16-19`). No contact FKs.
- **Agents:** read-only + remarks (`Agent\DealRegisterController`, `/agent/deals`). Money finalised in the settlement screen (`deal_settlements`, Paid-lock).
- Table: SoftDeletes + `agency_id` + `DealBranchScope`. Commission engine around it: `deal_user`, `deal_settlements`, `deal_logs`, `deal_money_lines`, `deal_branches`.

**Constraint carried into the build:** DR1 must keep working perfectly → touch it minimally; put new behaviour DR2-side.

---

## 2. Deal Register V2 — existing code & spec vs reality

**Spec:** `.ai/specs/deal-register-v2-spec.md` (853 lines, v1.1, 2026-03-30). Mandates: 3 deal types (bond/cash/sale-of-2nd), fully user-defined pipeline templates + steps (8 completion types, 4 trigger types, chain recalculation from *actual* completion date, positive/negative status triggers, BM-approval gate, per-step RAG thresholds), a 5-step creation wizard, deal-detail tracker + pipeline overview (board/cards/CSV), calendar integration with dynamic RAG colour, notifications + escalation chain, per-user iCal, and scheduled `deals:process-rag` / `deals:process-escalations` / `deals:daily-digest`.

### Built and solid
- Engine `app/Services/DealV2/DealPipelineService.php` (469 lines): `createDeal()` `:18`, `activateStep()` `:169`, `completeStep()` `:188` (positive/negative outcome, status_trigger, BM-approval gating, file upload), `approveStep()`/`rejectStep()` `:251`/`:284`, `activateDownstreamSteps()` `:308` (chain reaction from actual completion date), `cancelDownstreamSteps()` `:325`, `recalculateExpectedRegistration()` `:402`.
- Models `app/Models/DealV2/*`: `DealV2` (`scopeVisibleTo` `:206`, ref `DL-YYYY-NNNNN` `:262`, ported commission math), `DealStepInstance` (`calculateRag()` `:128`, `daysRemaining()` `:119`, `needsApproval()`), `DealPipelineStep`, `DealPipelineTemplate`, `DealStepDocument`, `DealActivityLog`, `DealV2Settlement`.
- Migrations: `deals_v2`, `deal_v2_contacts`, `deal_v2_agents`, `deal_step_instances` (RAG cols `2026_03_30_300006:35-38`), `deal_activity_log`, `deal_v2_settlements`, status triggers (`2026_03_30_400000_add_status_triggers_to_pipeline_tables.php`).
- Controllers: `DealV2Controller` (CRUD + search), `DealStepController` (complete/approve/reject/uploadDocument/overrideDueDate), `DealPipelineSetupController` (+duplicate), `DealPipelineStepController` (reorder), `DealV2SettlementController`.
- Routes `routes/web.php:596-637` (gated `deals_v2.*`). Permissions `config/corex-permissions.php:418-424`. Sidebar group `corex-sidebar.blade.php:1455-1487`.
- Views `resources/views/deals-v2/*`: `show.blade.php` (419 lines — full visual pipeline tracker with live RAG in Blade, milestone icons, overdue pulse, expandable step forms).
- Seeder `database/seeders/DealPipelineTemplateSeeder.php`: Standard Bond Sale (default, 15 steps), Cash Sale (9), Sale of Second Property (16, incl. `auto_from_linked_deal` step).
- Observers `DealV2Observer` + `DealStepInstanceObserver` (`AppServiceProvider.php:174-175`).

### Specced but NOT built — the entire "clock & escalation" half
1. `deals:process-rag` scheduled recompute — persisted `current_rag`/`overall_rag` go **stale**; `updateDealOverallRag()` (`DealPipelineService.php:383`) has **zero callers**.
2. `deals:process-escalations` + `deals:daily-digest` — the `escalation_config` column is **dead data**.
3. `NotificationService` + all notifications — two `// TODO Phase 5` stubs (`:243`, `:301`).
4. Dynamic RAG **colour written onto deal calendar events** (§9).
5. Pipeline Overview board/kanban + dashboard cards + CSV + scope switcher — index is a plain paginated table.
6. Per-user iCal; `document_signed` step auto-completion from e-sign; `auto_from_linked_deal` runtime resolution.
7. V1→V2 migration/sync tool.
8. **Zero automated tests for the DR2 engine** (createDeal/chain/complete/status-trigger/BM-approval all untested).

### Pipeline trace (spec's bond flow)
OTP Signed (on_creation) → Bond Application (+3d) → Bond Approved (+30d, granted + BM approval) → COC/Deposit/Attorney activate on approval → Rates Clearance → Deeds Lodgement → Registration (completed). **Everything up to and including status change + downstream activation works.** What never fires on its own: amber/red as the bond date nears, overdue escalation, notifications, calendar colour — those only appear when a human opens the deal-detail page (live Blade recompute).

---

## 3. OTP facility + document settings

> **Terminology:** "OTP" here = **Offer to Purchase** (a document type), NOT the one-time-PIN `App\Services\Otp\OtpService`. Do not conflate.

- Unified store `documents` / `App\Models\Document` with pivots `document_contacts` (carries `party_role`) + `document_properties` (`Document.php:40-51`). **No deal link exists.**
- E-sign auto-files signed docs to **property + contacts** (`SignatureService::autoFileSignedDocument` `:1806`, `Document::create(source_type='esign')` `:1872`, link `:2093`) — never a deal. PDF-splitter auto-files + routes via `AgencyComplianceDocTypeService` (`PdfSplitterController::fileGroupsToDestinations` `:548`). An OTP today naturally lands on `document_properties` + `document_contacts`.
- Deal docs live in a **disconnected** `deal_step_documents` (`App\Models\DealV2\DealStepDocument`; raw `file_path`, `document_id` NULL — `DealPipelineService.php:216`).
- **Doc-settings matrix today = `doc-type × party-role → destination`:** `document_types` catalogue (`App\Models\DocumentType`) + `agency_document_type_compliance` override + resolver `App\Services\Compliance\AgencyComplianceDocTypeService` + admin UI `/admin/settings/document-types` (`Admin\SplitterDocTypeController`). **The deal-stage axis is missing.**
- Reusable pack-email primitives: `FeedbackReportMail` (multi-attachment), `SalesDocumentSend`/`SalesDocumentRecipient` (recipient orchestration + signing order), `BaseSignatureMail` (agency branding). **Viewing Pack is deliberately download-only (POPIA) — do NOT reuse for sending.**

**Smallest correct additions:** (a) a deal anchor on the unified store — `documents.deal_id` (recommended) or a `document_deals` pivot; (b) a `deal_stage_document_rules` table (agency-scoped: `pipeline_step × document_type × party_role → distribute`), reusing the existing role vocabulary + raw-write service pattern.

---

## 4. Party / service-provider roles

- **Contact types are hard-locked to 4** (Seller/Buyer/Lessor/Lessee, each bound to an `esign_role`; `NormaliseContactTypes` actively demotes "Attorney"/"Other"). A contact **cannot** be typed electrician/entomologist/attorney/originator — intentional, per `.ai/specs/contact-types-and-tags.md`.
- **DR2 already has the right mechanism:** `deal_v2_contacts.role` enum (`2026_03_30_300004:15`) with `conveyancer` + `bond_originator` (+ buyer/seller/co_*/other). Needs **extension** to `transfer_attorney, bond_attorney, electrician_coc, entomologist, originator, service_provider`.
- **No preferred-supplier / panel directory exists** (grep of migrations for supplier/vendor/panel/service_provider = nothing).
- **Verdict:** address doc packs to providers via the **deal-party role**, never a 5th contact type. An agency supplier directory (for "the electrician we always use") would be net-new.

---

## 5. Comms tracking (post AT-132/153)

- `communication_links` is **polymorphic** (contact|deal|property capable; calendar already morphs to Property *and* DealV2 — `CalendarEvent.php:159,180`) but **every writer hardcodes `Contact::class`** (`EmailArchiveIngestor.php:146`, `WaArchiveIngestor.php:254`, `OutboundProvisionalLogger.php:68`). Comms show only on the contact today.
- **CoreX already archives its own outbound email** (`OutboundProvisionalLogger` + `ProvisionalReconciler`, AT-59/80; called from `SellerOutreachSenderService.php:135` and the contact Email/WhatsApp tiles). Three gaps for DR2:
  1. contact-only link (no Property, no Deal);
  2. `has_attachments=false` + no `communication_attachments` rows written (`OutboundProvisionalLogger.php:61`);
  3. `owner_user_id` **not stamped at create** (only on reconcile → the row is invisible to the sender's own/branch scope until then; contra AT-122).
- **Fix (no schema change — morph already supports it):** extend the outbound logger to stamp `owner_user_id` at create, write `communication_attachments` + `has_attachments=true`, and write **Contact + Property + DealV2** links. Then every distribution email surfaces on deal + contact + property.

---

## 6. DR1↔DR2 sync feasibility (Leave precedent corrected)

**Correction:** the **Leave "parallel run" is not a live code sync.** `.ai/docs/leave-parallel-run-procedure.md` is a **one-time human reconciliation** (Elize/Karin compare CoreX vs paper/Sage; Johan enters manual adjustments) into a single **canonical ledger**, after which the old system is retired. Leave's real lesson is *one canonical store + derive/recompute* (`LeaveTransaction` immutable + `LeaveBalanceService` + `corex:leave:recalculate-balances`), not two mirrored stores.

- **The only genuine live-mirror precedent in the repo** is the single-writer transactional service `ContactIdentifierService` (AT-125): one service owns the invariant, thin observers only *call* it (`ContactPhoneObserver`/`ContactEmailObserver`), `DB::transaction`, idempotent reconcile.
- House style for "v2 of a module" is a **separate table** (`deals`/`deals_v2`) — but that split shipped with **no** sync, so it gives no free template.
- Cross-pillar reactivity must go through the **domain-events catalogue** (non-negotiable #9). Deal events already exist: `DealCreated`, `DealRegistered`, `DealStageAdvanced`, `DealStatusChanged`, `DealClosed`, `DealCommissionFinalised`, `DealMoneyLineChanged`.

**Recommendation:** make **DR2 the canonical operational store**. During the overlap, a **single-writer `DealSyncService`** (ContactIdentifierService pattern) mirrors the *shared core fields* — status, granted/registration dates, parties, commission totals — between `deals` and `deals_v2`, keyed by a link column (`deals_v2.legacy_deal_id` ↔ `deals.deal_v2_id`). DR2-only concepts (pipeline steps) do not mirror back. Thin observers on both sides funnel into the one service; it stays transactional + idempotent so a re-run always converges. Then a **Leave-style human reconciliation** proves parity, DR1's UI is retired, and the `deals` row survives as an **audit record** (TrackedProperty `promoted_to_*` pointer pattern — no hard delete). This delivers the bidirectional behaviour required for the transition while following the one real precedent.

---

## 7. Calendar hooks (mostly already built)

Deal→calendar is fully wired and idempotent: `CalendarSourceContract` + `CalendarSourceRegistry` + `DealCalendarSource` (categories `deal_step_deadline` from `deal_step_instances.due_date` `:40`; `deal_registration_target` from `deals_v2.expected_registration` `:84`) + real-time `DealStepInstanceObserver`/`DealV2Observer` (step completion auto-completes the event; deal cancel/hold dismisses events) + nightly `corex:calendar:reconcile` (`routes/console.php:178`) keyed on `(source_type, source_id, category)`.

**Colour escalation already works on-read** via `CalendarThresholdResolver` (per-class red/amber/green_days; per-step overrides read from `deal_step_instances.rag_*_days` — `:104-128`), with transition notifications (`ReconcileCalendarEvents::detectAndFireTransitions` → `CalendarNotificationDispatcher::onColourTransition`), overdue sweep (`ProcessReminders.php:38-52`), and BM digests (`daily_digest_roles => ['bm']`).

**A new bond-due timer is usually just a pipeline step with a `due_date`** (flows through `deal_step_deadline`); a new event class is warranted only if it needs distinct thresholds. **Nuance:** two colour systems — the calendar tile (works on-read) vs the deal board's *persisted* `current_rag`/`overall_rag` (stale without `deals:process-rag`). V1 `deals` has no forward-date calendar feed at all (by design — `DealCalendarSource` header explicitly excludes V1).

---

## 8. Consolidated gap list

1. DR2 RAG timer (`deals:process-rag`); `updateDealOverallRag()` uncalled → persisted RAG stale.
2. Escalation runtime + `NotificationService` — none built (`escalation_config` dead).
3. Dynamic RAG colour persisted onto deal calendar events.
4. `deals` (V1) has no deal-type/pipeline column, no party FKs, no forward-date feed (by design — DR2 carries these).
5. No deal link on unified `documents`; `deal_step_documents` disconnected.
6. No stage×doc-type×role distribution matrix; no distribute action; outbound email lacks attachments + deal/property links + owner stamp.
7. Provider roles narrow (`deal_v2_contacts.role`); no provider typing; no supplier directory.
8. No DR1↔DR2 link column or sync service.
9. `document_signed` auto-completion + `auto_from_linked_deal` runtime unwired.
10. Pipeline Overview board/cards/CSV/scope switcher; per-user iCal.
11. **Zero automated tests for the DR2 engine.**

---

## 9. Recommended build shape — one continuous build, sequenced (no deferral)

1. **DR2 engine hardening + tests *first*** (untested, about to become canonical): feature tests for createDeal/chain/complete/status-trigger/BM-approval; build `deals:process-rag` + wire `updateDealOverallRag`; persist RAG colour to calendar events.
2. **DR1↔DR2 link + single-writer `DealSyncService`** (ContactIdentifierService pattern) + link columns + observers both sides + idempotent reconcile + a parity harness.
3. **Party model** — extend `deal_v2_contacts.role` (attorneys/COC/entomologist/originator/service_provider); optional agency preferred-provider directory.
4. **Document spine** — deal link on unified `documents`; wire e-sign + PDF-splitter auto-file to also link the deal; OTP-upload-onto-deal action; `document_signed` step auto-completion listener.
5. **Distribution matrix** — `deal_stage_document_rules` on the doc-types settings surface; distribute-docs action; multi-attachment mailable (`FeedbackReportMail`/`BaseSignatureMail`) + `SalesDocumentSend`-style tracking; **auto-trigger on stage ticks** (OTP granted → email the appointed provider). *This is what kills HFC's hand-filled COC request.*
6. **Comms archive** — extend `OutboundProvisionalLogger`: owner stamp at create + attachments + Contact/Property/DealV2 links → distributions show on all three pillars.
7. **Escalation + notifications** — `NotificationService`, `deals:process-escalations`, digest (reuse calendar dispatcher + class `*_notifications`).
8. **Overview surfaces** — board/kanban, dashboard cards, CSV, scope switcher, per-user iCal.
9. **Transition** — human reconciliation (Leave precedent), retire DR1 UI, preserve `deals` rows as audit.

---

## 10. Open decisions for the design session (to Johan now)

1. **Sync model:** permanent live bidirectional mirror **vs** Leave-style one-directional-during-transition-then-cutover. *(Lean: canonical DR2 + shared-field mirror during overlap, then cutover.)*
2. **Providers:** deal-party role only, **vs** also a reusable agency supplier directory.
3. **V1 footprint:** does `deals` gain a `deal_v2_id` pointer (+ minimal forward-date columns), **vs** stay fully untouched with all new behaviour DR2-side?
4. **OTP-on-deal anchor:** `documents.deal_id` (recommended) **vs** a `document_deals` pivot.

---

## Key files (index)
- Spec: `.ai/specs/deal-register-v2-spec.md`
- DR1: `app/Models/Deal.php`, `app/Http/Controllers/Admin/DealController.php`, `app/Http/Controllers/Agent/DealRegisterController.php`
- DR2 engine: `app/Services/DealV2/DealPipelineService.php`; models `app/Models/DealV2/*`; seeder `database/seeders/DealPipelineTemplateSeeder.php`; routes `routes/web.php:596-637`
- Docs: `app/Models/Document.php`, `app/Services/Docuperfect/SignatureService.php`, `app/Services/Compliance/AgencyComplianceDocTypeService.php`, `app/Http/Controllers/Tools/PdfSplitterController.php`
- Comms: `app/Services/Communications/OutboundProvisionalLogger.php`, `app/Models/Communications/{Communication,CommunicationLink}.php`
- Sync precedents: `app/Services/Contacts/ContactIdentifierService.php`, `.ai/docs/leave-parallel-run-procedure.md`, `.ai/specs/corex-domain-events-spec.md`
- Calendar: `app/Services/CommandCenter/Calendar/Sources/DealCalendarSource.php`, `app/Observers/DealStepInstanceObserver.php`, `app/Services/CommandCenter/Calendar/CalendarThresholdResolver.php`
