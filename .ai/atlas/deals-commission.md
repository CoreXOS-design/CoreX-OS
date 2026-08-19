# Atlas — Deal Register / Commission

> **Status: DONE** · Last verified: 2026-08-19
> Pillar: **Deal** (× Property, Contact, Agent). Companion specs: `.ai/specs/deals.md` (stub),
> `.ai/specs/deal-register-v2-spec.md`, `.ai/specs/commission_engine_spec.md`,
> `.ai/specs/VS_CODE_Commission_Phase1.md`. **This doc separates BUILT from BACKLOG/V2 explicitly.**

---

## 1. WHAT IT DOES — three parallel systems at different maturity

| System | Status | What |
|--------|--------|------|
| **A — Deal Register V1** | **BUILT, live, primary money path** | `Deal` + `Admin\DealController` settlement engine + `deal_money_lines` |
| **B — Deals V2 pipeline** | **BUILT but parallel/new** | `DealV2` + configurable pipeline templates/steps (spec: runs "alongside V1", V1 untouched) |
| **C — Commission / Revenue-Share / Cap engine** | **BUILT, WIRED 2026-08-19** | `CommissionLedger` + `CommissionCalculationService`, now auto-created via `GenerateCommissionLedgerEntries` on `DealCommissionFinalised` |

A deal records a transaction (sale), its parties, sale price, commission split, and settlement (who gets
paid what, net of PAYE/deductions). V1 is the live money path; V2 is a newer configurable pipeline; the
cap/revenue-share engine now generates its ledger from V1 deal completion (V2 completion is NOT yet wired —
see §8).

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
— fully configurable, no hardcoded stages.

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

### (b) Cap / revenue-share engine — BUILT + WIRED (2026-08-19)
`app/Services/CommissionCalculationService.php`: `calculateDealCommission()` `:20` (cap, mentor fee, risk
fee, post-cap transaction fee, revenue-share pool), `distributeRevenueShare()` `:172`, 7-tier sponsor walk
`getSponsorChain()` `:233`. **Trigger:** `App\Listeners\Deal\GenerateCommissionLedgerEntries`, registered
on `DealCommissionFinalised` (fires from `DealObserver` when V1 `commission_status` flips to a terminal
Paid state) — closes `commission_engine_spec.md:517`'s "Deal completion → auto-create CommissionLedger"
integration point, previously unbuilt (found via a live report: Retha Kelly / Demo Agency Test on
production had zero CommissionLedger rows despite the dashboard existing). One ledger row per agent per
deal, summed across that agent's listing+selling pivot rows (dual-mandate agents don't get two rows); the
amount fed in is each agent's own allocation of their side's pool (`side pool × their
deal_user.agent_split_percent`) — never the raw side pool or the deal total, which would double-count
co-agents. Idempotent on re-fire (checked via existing `CommissionLedger` row for the same deal+agent).
**Fixed alongside it:** `CommissionSetting::forAgency()` returned a NULL-hydrated model for a
brand-new agency's first-ever call (`firstOrCreate` doesn't read DB column defaults back into the
in-memory model), which NOT-NULL-violated `agent_cap_periods.cap_amount` — the very first agent processed
on any agency's very first finalised deal would have silently failed. Now passes `NO_AGENCY_DEFAULTS` as
the create-values.
**Still open:** V2 (`deals_v2`) completion does not trigger this listener — only legacy V1 `Deal` does.
`CommissionController` still only **reads** the ledger for dashboards (`:29-207`); no manual "add entry"
UI exists (spec `:420` `store()` was never built either).

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
1. ~~System C commission engine is orphaned (biggest gap).~~ **FIXED 2026-08-19** — see §5(b).
   V1 deal completion now auto-creates the ledger via `GenerateCommissionLedgerEntries`. **Remaining
   gap:** DealsV2 (`deals_v2`) completion does not trigger it — an agency running only V2 deals still
   sees empty cap-progress / revenue-share dashboards.
2. **Three copies of the inc→ex-VAT pool math** (`Deal.php:206`, `DealV2.php:167`,
   `Finance/CommissionCalculator.php:31`) — drift risk. Two settlement engines (V1 `DealController` + V2
   `DealV2SettlementController`) both write `deal_money_lines`.
3. **V1 status is implicit/derived**, duplicated in `statusSummaryForBranch` `:390-393` vs
   `statusSummaryForCompany` `:633-636` — fragile, easy to desync.
4. **Legacy unlinked data:** deals without `branch_id` only visible to `branches.view_all` (`Deal.php:22-24`);
   deals without `property_id` rely on the link-review queue.
5. **PAYE duality** (§6) — deal-settlement PAYE independent of the real payroll PAYE engine.

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
- `app/Services/CommissionCalculationService.php:20-233`; `app/Models/CommissionLedger.php:124-213`;
  `app/Listeners/Deal/GenerateCommissionLedgerEntries.php` (trigger, wired 2026-08-19); `app/Models/CommissionSetting.php:139-146` (`forAgency` hydration fix).
- `app/Services/Payroll/PayrollCalculator.php:27-131`.
- Specs: `.ai/specs/deal-register-v2-spec.md`, `.ai/specs/commission_engine_spec.md`, `.ai/specs/deals.md`.
