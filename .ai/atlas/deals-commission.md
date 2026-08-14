# Atlas — Deal Register / Commission

> **Status: DONE** · Last verified: 2026-07-14
> Pillar: **Deal** (× Property, Contact, Agent). Companion specs: `.ai/specs/deals.md` (stub),
> `.ai/specs/deal-register-v2-spec.md`, `.ai/specs/commission_engine_spec.md`,
> `.ai/specs/VS_CODE_Commission_Phase1.md`. **This doc separates BUILT from BACKLOG/V2 explicitly.**

---

## 1. WHAT IT DOES — three parallel systems at different maturity

| System | Status | What |
|--------|--------|------|
| **A — Deal Register V1** | **BUILT, live, primary money path** | `Deal` + `Admin\DealController` settlement engine + `deal_money_lines` |
| **B — Deals V2 pipeline** | **BUILT but parallel/new** | `DealV2` + configurable pipeline templates/steps (spec: runs "alongside V1", V1 untouched) |
| **C — Commission / Revenue-Share / Cap engine** | **BUILT tables+service, ORPHANED → effectively backlog** | `CommissionLedger` + `CommissionCalculationService` — **never invoked from any live flow** |

A deal records a transaction (sale), its parties, sale price, commission split, and settlement (who gets
paid what, net of PAYE/deductions). V1 is the live money path; V2 is a newer configurable pipeline; the
cap/revenue-share engine exists but is not wired to deal completion.

---

## 2. ENTRY POINTS

### Deals V1 (BUILT) — `routes/web.php`
| Route | Name | Handler |
|-------|------|---------|
| `/admin/deals` `:434` | `admin.deals` | `Admin\DealController@index` (perm `create_deals`) |
| create/store `:441,443` | `admin.deals.create/store` | `@create/@store` |
| edit/update/quickUpdate `:445,449,450` | — | `@edit/@update/@quickUpdate` |
| **settle (money engine)** `:453` / `:456` | `admin.deals.settle` / `.settle.save` | `@settlement/@saveSettlement` (perm `settle_deals`) |
| agent self-view `:436-438` | `agent.deals.*` | `Agent\DealRegisterController` (perm `view_deals`) |
| read-only API `:287-288` | `deals.index/show` | `Api\V1\DealsController` |

### Deals V2 (BUILT) — `routes/web.php:552-593`
Pipeline **template** setup `:552-565` (perm `deals_v2.manage_pipeline`) → `DealV2\DealPipelineSetupController`;
deal CRUD `:569-580` → `DealV2\DealV2Controller`; step lifecycle complete/approve/reject/upload `:583-587`
→ `DealV2\DealStepController`; settlement `:590-593` → `DealV2\DealV2SettlementController`.
**⚠ Naming trap:** the route named `deals-v2.pipeline.index` (`:553`) is the pipeline **TEMPLATE setup**
screen, NOT a deal kanban — the V2 deal list is `deals-v2.index` (`:570`).

### Commission (System C) — `routes/web.php:1414-1422`
`commission.dashboard` (agent "My Earnings"), `commission.index`, `commission.principal`, `.confirm`,
`.pay` → `Commission\CommissionController` (**read-only dashboards over the ledger**). Settings
`corex.settings.commission` `:2017`. *(Unrelated quote tools: `calculators.commission` `:348`,
`tools.commission` `:717`.)*

### Payroll — `routes/web.php:1701-1783` (perm `manage_payroll`)
Employees / earning / deduction types / runs / leave. Nav `corex-sidebar.blade.php:1071-1123`.

### Nav
V1 `admin.deals` `:720,808`, agent "My Deals" `:700`; Commission "My Earnings" `:614`, "Commission
Overview/Management" `:837-838`; Deals V2 group `:1426-1444`.

---

## 3. THE DATA MODEL

### V1 — `app/Models/Deal.php` (`SoftDeletes` + `BelongsToAgency` `:14`)
Fillable `:80-123`. Money: `property_value`, `total_commission` (**captured INCL VAT** `:88`), `sale_price`
(int), `sale_date` `:117-118`. **Parties are freeform strings (V1):** `seller_name`, `buyer_name`,
`attorney_name` `:93-95` — NOT contact FKs. Status: `accepted_status` (`D`=declined/`G`=granted/`R`=registered),
`granted_at`, `commission_status`, `registration_date` `:96-99`. Per-side split config:
`listing_/selling_ external/split_percent/our_share_percent/external_agency` `:102-110`. Links (Phase 3i):
`property_id`, `presentation_id`, `link_source/confidence/reviewed_at` `:115-122`. **No stage table** —
status is *derived* from columns (`statusSummaryForBranch` `:390-393`, `statusSummaryForCompany` `:633-636`);
`commission_status === 'Paid'` is the lock. Relations: `property()` `:144`, `presentation()` `:150`,
`agents()` (M2M `deal_user` pivot with `side`/`agent_split_percent`/`agent_cut_percent`/`paye_method`/
`paye_value`/`deductions` `:155-173`), branch isolation via `DealBranchScope` `:16-44`, `scopeVisibleTo()` `:699`.

### V2 — `app/Models/DealV2/DealV2.php` (`deals_v2`, `BelongsToAgency` + `SoftDeletes` `:23`)
Real FK columns: `property_id`, `listing_agent_id`, `selling_agent_id`, `pipeline_template_id`,
`linked_deal_id` `:31-35`; money `purchase_price`, `commission_percentage`, `commission_amount`,
`commission_vat` `:36-39`; `status` enum(active/completed/cancelled/on_hold) `:30`; reference `DL-YYYY-NNNNN`
`:262-280`. M2M `contacts` (`deal_v2_contacts` role) `:102`, `agents` (`deal_v2_agents`) `:108`,
`stepInstances` `:135`, `settlements` `:130`. Pipeline models: `DealPipelineTemplate`, `DealPipelineStep`,
`DealStepInstance`, `DealStepDocument`, `DealActivityLog`, `DealV2Settlement`.

### Commission/settlement models
`CommissionLedger` (`commission_ledger`, SoftDeletes; `calculateSplit()` `:124-208`, `generateRevenueShare()`
`:213` — System C), `CommissionSetting`, `DealSettlement` (V1 per-agent override rows), **`DealMoneyLine`**
(`deal_money_lines` — the canonical computed money rows: `side_pool_ex_vat`, `allocation_percent`,
`agent_gross_ex_vat`, `paye_amount`, `agent_net_ex_vat`, `paid_at`; SoftDeletes), `DealLog`.

---

## 4. THE FLOW (offer → deal → registered)

### V1 (BUILT)
**No automated offer→deal or e-sign→deal creation exists** (confirmed: no `Offer` model; `Deal::create`
only from `Admin\DealController@store` `:231`, manual capture). Property/presentation linking is a separate
Phase-3i reconciliation (`DealLinkReviewController`, `Services/Deals/DealPropertyLinkService.php`). Stage
progression = **column edits**, not transitions (no state machine). Money realised at **settlement**:
`Admin\DealController@saveSettlement` `:622` validates per-side shares total 100 `:677`, computes pools via
`DealMoneyLineRebuilder::computeDealPools` `:659`, runs a VAT-aware checksum and blocks "mark paid" if
unbalanced `:737-748`, writes in a transaction `:750`.

### V2 (BUILT — proper state machine) — `Services/DealV2/DealPipelineService.php`
`createDeal()` `:18` creates deal + snapshots agents/contacts + instantiates all template steps. Step
lifecycle: `activateStep` `:169`, `completeStep` `:188` (positive/negative outcome, BM-approval gate),
`approveStep` `:251`, `rejectStep` `:284`, `changeDealStatus` `:340` (sets `actual_registration` on
`completed` `:345-347`), RAG `:356`. Deal status driven by per-step `status_trigger`/`negative_status_trigger`
— fully configurable, no hardcoded stages. **Due-date model: this engine does NOT pre-project — a
not-started step's `due_date` stays null until `activateStep` sets it from the real anchor, so it is correct
by construction (unchanged by AT-216).**

### DR2 pipeline overlay (BUILT — AT-216) — `app/Services/Deal/Dr1PipelineService.php`
DR2 doctrine (Johan): DR2 is an exact duplicate of DR1 on the SAME `deals` tables, plus a pipeline. Rather
than entangle the AT-158 `DealV2` engine (which sunsets with the `deals_v2` module) with two models, this is
a **parallel overlay** that anchors a template's steps to a DR1 `deals` row via
`deal_step_instances.dr1_deal_id` (`deal_id`/deals_v2 pointer stays null). It reuses the same
`DealStepInstance` model + AND-gate dependency tables as V2.
- `createPipeline()` `:67` — materialises the template's steps against the DR1 deal, wires the primary
  `trigger_step_instance_id` chain + additional AND-gate dependencies (`deal_step_instance_dependencies`),
  stamps `deals.deal_pipeline_template_id` + `pipeline_started_at`, activates the `on_creation` steps, and
  queues the calendar sync.
- **Due-date PROJECTION at attach (R2, `:159-180`):** projects EVERY step's `due_date` = `anchor +
  cumulative offset up the PRIMARY trigger chain` (anchor = `from_date` opt, else `deal_date`, else now),
  so RAG warnings apply across the whole pipeline **from day one**. This projection is an ESTIMATE — it
  ignores when predecessors actually complete and ignores AND-gate deps.
- **RE-ANCHOR on real activation (AT-216, `activateStep` `:217`):** when a step truly activates, its clock
  runs from the REAL anchor: `$fromDate + days_offset`, where `$fromDate` = the LATEST predecessor
  completion (AND-gate) returned by `dependencyReadiness()` `:381` (a resolved predecessor = completed /
  N-A / skipped; `$fromDate` = max `completed_at` across the full predecessor set). Only a genuine agent
  edit (`due_date_manual = true`, `:231-233`) is preserved — the stale system estimate is always
  overwritten.
- **`due_date_manual` flag** (migration `2026_07_14_120000_add_due_date_manual_to_deal_step_instances.php`,
  default false; `DealStepInstance` fillable/`boolean` cast): `updateStepDueDate()` `:547` sets it true on
  an inline agent edit (false again when the date is cleared → re-anchors on next activation);
  `addCustomStep()` `:502` sets it true when the agent supplies a due date.
- Completion (`completeStep` `:254`, the single choke point for board / My Deals / calendar) cascades via
  `activateDownstreamSteps` `:346` and fires the step's `status_trigger` onto the DR1 deal's
  `accepted_status` (P→G→R/D, forward-only — Sweep #2). Deal-level machinery (overall_rag,
  expected_registration, BM-approval holds) is NOT ported — DR1 has no such columns. Ticket AT-216;
  QA1 only. Tested: `tests/Feature/DealV2/Dr1PipelineAttachTest.php`.

---

## 5. COMMISSION CALC — three distinct calculators

### (a) V1 settlement engine — BUILT, primary money path
`app/Services/Finance/CommissionCalculator.php`: `companyIncomeExVatBreakdown()` `:31` — gross
`total_commission` is INCL VAT; per side `gross × split% × our_share%`, zeroed if `*_external`; inc→ex VAT
via `/(1+vatRate)` `:63-64`; **VAT rate dynamic** `PerformanceSetting::get('vat_rate', 15)` `:11-13`.
Per-agent retained 3-tier `dealRetainedByAgentExVat()` `:98`. Settlement math
(`DealController@saveSettlement:713-723`): `allocated = pool × share%`; `gross = allocated × agent_cut%`;
PAYE fixed or `gross × paye%` `:716-720`; `net = gross − paye − deductions`; `company = allocated − gross`.
**Split = agency/agent/co-agent via per-side pools + per-agent `agent_split_percent` + `agent_cut_percent`;
co-agents = multiple rows per side.** The 5–7.5% rate is **not enforced** — `total_commission` is captured
manually. Computed → `deal_money_lines` via `Services/DealMoneyLineRebuilder.php`.

### (b) Cap / revenue-share engine — BUILT (tables+service) but ORPHANED → BACKLOG
`app/Services/CommissionCalculationService.php`: `calculateDealCommission()` `:20` (cap, mentor fee, risk
fee, post-cap transaction fee, revenue-share pool), `distributeRevenueShare()` `:172`, 7-tier sponsor walk
`getSponsorChain()` `:233`. **CRITICAL GAP:** grep confirms `calculateDealCommission` /
`CommissionLedger::calculateSplit` / `CommissionLedger::create` are **called from nowhere** in the live
app. No deal→ledger auto-creation despite `commission_engine_spec.md:517` ("Deal completion → auto-create
CommissionLedger"). `CommissionController` only **reads** the ledger for dashboards (`:29-207`).

### (c) V2 inline — BUILT, used by V2 settlement
`DealV2.php:157-192` (same inc→ex VAT/pool logic); `DealV2SettlementController` writes money lines.

---

## 6. PAYROLL / PAYE

**BUILT:** `app/Services/Payroll/PayrollCalculator.php::calculatePayslip()` `:27` → `calculatePaye()` `:90`
(SARS tax tables/rebates `PayrollTaxTable`/`PayrollTaxRebate`), UIF, deductions. Models `PayrollRun`,
`PayrollPayslip`, `PayrollEmployee`, etc. Routes `payroll.*` `:1701-1783`.

**Touchpoint gap (BACKLOG):** Payroll has **NO link to deals or commission** (grep across
`Services/Payroll` + `Models/Payroll` for commission/deal = nothing). The "PAYE" in deal settlement
(`paye_method`/`paye_value` on `deal_user` + `deal_money_lines.paye_amount`) is a **separate, simplistic
per-deal PAYE deduction** (`DealController@saveSettlement:716-720`) — it does NOT feed the real payroll
PAYE engine, and commission earnings are not pushed into payslips. **Two unreconciled PAYE figures.**

---

## 7. DATA READ / WRITTEN + DEPENDENCIES

**V1 tables:** `deals`, `deal_user` (agent allocations), `deal_settlements` (overrides), `deal_money_lines`
(computed results), `deal_branches`, `deal_logs`. Links: `deals.property_id` → Property `:144`;
`presentation_id` → Presentation `:150`; agents via `deal_user` → User; **buyer/seller = freeform strings,
not contact FKs** `:93-94`. Audited: `deal_logs` + `Admin\FinanceAuditController` `:981` + settlement
before/after snapshot `:637-655`. SoftDeletes on Deal/DealSettlement/DealMoneyLine/CommissionLedger.

**V2 tables:** `deals_v2`, `deal_v2_contacts` (→ Contact, real buyer/seller roles), `deal_v2_agents`,
`deal_step_instances`, `deal_step_documents`, `deal_activity_log`, `deal_v2_settlements`.

**Dependencies:** Property — V1 `property_id` (Phase-3i, many legacy deals unlinked); V2 hard-requires
`property_id` at creation (`DealPipelineService::createDeal:27`). Contacts — V1 freeform names; V2 real
`deal_v2_contacts`. Agents — both via pivot → User. **E-sign / signed OTP** — V2 spec defines a
`document_signed` step type but **no deal is created from a signed offer anywhere** (grep empty) — BACKLOG.
**FICA gating** — present as configurable V2 template steps but **no hard gate** in `DealV2Controller@store`
(grep empty) — informational, not enforced — BACKLOG.

---

## 8. KNOWN FRAGILITIES + V2 BACKLOG

### Live-state fragilities
1. **System C commission engine is orphaned (biggest gap).** Tables + `CommissionCalculationService` +
   "My Earnings" dashboards are BUILT, but the engine is **never invoked** — no deal triggers a ledger
   entry, so cap-progress / revenue-share / earnings dashboards read empty unless populated manually
   (`commission_engine_spec.md:517` integration point unbuilt).
2. **Three copies of the inc→ex-VAT pool math** (`Deal.php:206`, `DealV2.php:167`,
   `Finance/CommissionCalculator.php:31`) — drift risk. Two settlement engines (V1 `DealController` + V2
   `DealV2SettlementController`) both write `deal_money_lines`.
3. **V1 status is implicit/derived**, duplicated in `statusSummaryForBranch` `:390-393` vs
   `statusSummaryForCompany` `:633-636` — fragile, easy to desync.
4. **Legacy unlinked data:** deals without `branch_id` only visible to `branches.view_all` (`Deal.php:22-24`);
   deals without `property_id` rely on the link-review queue.
5. **PAYE duality** (§6) — deal-settlement PAYE independent of the real payroll PAYE engine.
6. **DR2 pipeline due-date anchoring — RESOLVED (AT-216, 2026-07-14).** The DR1 overlay projects every
   step's `due_date` at attach for day-one RAG (an estimate). Previously `activateStep` kept that stale
   estimate (`$step->due_date ??`), so an AND-gated step showed `deal_date + offset` instead of its real
   `latest-predecessor-completion + offset` (evidence: a Lodgement step showed deal_date+12 = 2026-03-13
   instead of latest-completion 03-25 + 7 = 2026-04-01). Fix: `activateStep` now re-anchors to
   `$fromDate + days_offset` unless `due_date_manual` marks a genuine agent edit — see §4 "DR2 pipeline
   overlay" + `Dr1PipelineService.php:217-244`. The V2 `DealPipelineService` never pre-projected, so it
   was correct by construction (unchanged).

### Explicit V2 / future backlog (specs)
- V1→V2 migration tool — Phase 7, unbuilt (`deal-register-v2-spec.md:11,836`).
- Calendar integration (iCal feeds) — Phase 4/6 `:366-421,811-832` (V2 has `calendarEventLinks()` `:284`,
  service may be partial).
- Notification/escalation — Phase 5; explicit `// TODO: Fire notification to BM (Phase 5)` markers
  (`DealPipelineService.php:243,301`).
- Scheduled `deals:process-rag`, `deals:process-escalations`, `calendar:generate-ical`, `deals:daily-digest`
  — spec §10 `:744-759` (RAG currently on-write, not scheduled).
- `deals.md` is a **stub** — "Partially live — full consolidation required"; pending sales/rental pipeline
  views, deal-to-Flow integration (`:33-38`).

---

## Key file:line index
- `app/Models/Deal.php` — `:14,80-123` model, `:144-173` relations, `:206-221` commission, `:390-393`/`:633-636` status, `:699` scopeVisibleTo.
- `app/Http/Controllers/Admin/DealController.php` — `:231` store, `:622-750` saveSettlement.
- `app/Services/Finance/CommissionCalculator.php:11-98`; `app/Services/DealMoneyLineRebuilder.php`.
- `app/Models/DealV2/DealV2.php:23-280`; `app/Services/DealV2/DealPipelineService.php:18-402`.
- `app/Services/Deal/Dr1PipelineService.php` — DR2 overlay: `:67` createPipeline (`:159-180` due-date
  projection), `:217` activateStep (re-anchor), `:381` dependencyReadiness, `:547` updateStepDueDate,
  `:502` addCustomStep. `DealStepInstance` `due_date_manual` fillable/cast; migration
  `database/migrations/2026_07_14_120000_add_due_date_manual_to_deal_step_instances.php`; test
  `tests/Feature/DealV2/Dr1PipelineAttachTest.php`.
- `app/Services/CommissionCalculationService.php:20-233` (orphaned); `app/Models/CommissionLedger.php:124-213`.
- `app/Services/Payroll/PayrollCalculator.php:27-131`.
- Specs: `.ai/specs/deal-register-v2-spec.md`, `.ai/specs/commission_engine_spec.md`, `.ai/specs/deals.md`.
