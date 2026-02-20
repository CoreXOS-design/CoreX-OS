# Finance Calculation Inventory
## HF Coastal Nexus / Performance Platform

> **Purpose:** Complete audit of every financial calculation in the repo, to guide
> centralisation into a single audit/finance engine.
> **Scope:** Read-only inventory. No application code changed.
> **Generated:** 2026-02-19
> **Status:** DO NOT centralise until this doc is approved.

---

## TABLE OF CONTENTS

1. [Deal-Level Calculations](#1-deal-level-calculations)
2. [Agent-Level Calculations](#2-agent-level-calculations)
3. [Branch-Level Calculations](#3-branch-level-calculations)
4. [Company-Level Calculations](#4-company-level-calculations)
5. [Worksheet-Level Calculations](#5-worksheet-level-calculations)
6. [Rentals-Level Calculations](#6-rentals-level-calculations)
7. [Blade / View Calculations (HIGH RISK)](#7-blade--view-calculations-high-risk)
8. [Cross-Cutting Findings](#8-cross-cutting-findings)
9. [Top 15 Canonical Definitions to Implement First](#9-top-15-canonical-definitions-to-implement-first)
10. [Top 10 Dangerous Anti-Patterns Found](#10-top-10-dangerous-anti-patterns-found)

---

## 1. DEAL-LEVEL CALCULATIONS

### 1.1 Commission Ex-VAT — `Deal::commissionExVat()`
| Field | Detail |
|---|---|
| **File** | [app/Models/Deal.php](../app/Models/Deal.php) line ~97 |
| **Value computed** | Commission excluding VAT from the stored incl-VAT bank figure |
| **Formula** | `commissionExVat = total_commission / (1 + vatRate)` |
| **Inputs** | `deals.total_commission` (stored INCL VAT), `PerformanceSetting['vat_rate']` (fallback 15) |
| **Used in** | All downstream pool calculations |

> **Convention (critical):** `deals.total_commission` is always stored INCL VAT (bank reality).
> All internal pools and allocations are calculated EX VAT.

---

### 1.2 Listing / Selling Side Pools — `Deal::calculateInternalPool()`
| Field | Detail |
|---|---|
| **File** | [app/Models/Deal.php](../app/Models/Deal.php) line ~107 |
| **Value computed** | Company's share of each side of the deal, ex VAT |
| **Formula** | `sidePool = commissionExVat × (side_split_percent / 100) × (side_our_share_percent / 100)` |
| **Inputs** | `commissionExVat()`, `listing_split_percent` (def 50), `listing_our_share_percent` (def 100), `listing_external` flag (pool = 0 if external) |
| **Used in** | Settlement previews, money-line rebuilder |

---

### 1.3 Company Income Ex-VAT Breakdown — `CommissionCalculator::companyIncomeExVatBreakdown()`
| Field | Detail |
|---|---|
| **File** | [app/Services/Finance/CommissionCalculator.php](../app/Services/Finance/CommissionCalculator.php) line ~29 |
| **Value computed** | Listing and selling side income ex VAT separately, and combined total |
| **Formula** | `sideIncl = total_commission × split% × ourShare%`  then  `sideExVat = sideIncl / (1 + vatRate)` |
| **Inputs** | `total_commission`, `listing/selling_split_percent`, `listing/selling_our_share_percent`, `listing/selling_external`, `PerformanceSetting['vat_rate']` |
| **Used in** | `CompanyPerformanceService` block 1 (AGENT_INCOME_ALLOCATIONS) |

> **Note:** `Deal::calculateInternalPool()` applies VAT removal first, then multiplies by split and share.
> `CommissionCalculator` applies split and share first, then divides by `(1+vat)`.
> Numerically equivalent but different order of operations.

---

### 1.4 Settlement Pool Formula — `DealController` (4× DUPLICATED)
| Field | Detail |
|---|---|
| **Files** | [app/Http/Controllers/Admin/DealController.php](../app/Http/Controllers/Admin/DealController.php) lines ~524, ~682, ~952, ~1080 |
| **Value computed** | Internal pools (ex VAT) and external payable amounts (incl VAT) for settlement |
| **Formula** | `totalEx = totalIncl / (1 + vatRate)` → `sideIncl = totalIncl × split%` → `sideEx = sideIncl / (1+vat)` → `pool = sideEx × ourShare%` → `externalPayable = sideIncl × (1 - ourShare%)` |
| **Inputs** | `total_commission`, `listing/selling_split_percent`, `listing/selling_our_share_percent`, `PerformanceSetting['vat_rate']` |
| **Used in** | `settle()`, `saveSettlement()`, `printSettlement()`, `printAgentPayslip()` |

> ⚠️ **HIGHEST PRIORITY BUG:** This ~30-line block is copy-pasted verbatim in FOUR methods.
> Any fix must be made in 4 places. Should be one private helper method.

---

### 1.5 Per-Agent Settlement Math — `DealController::buildSettleRows()`
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/Admin/DealController.php](../app/Http/Controllers/Admin/DealController.php) line ~1165 |
| **Value computed** | Agent's allocated gross, PAYE, deductions, net income; company retained |
| **Formula** | `allocated = pool × sharePercent%` → `gross = allocated × agentCut%` → `paye = gross × payeValue%` (or fixed `payeValue`) → `net = gross − paye − deductions` → `company = allocated − gross` |
| **Inputs** | `pool` (from 1.4), `deal_settlements.share_percent`, `deal_settlements.agent_cut_percent`, `deal_settlements.paye_method` (`fixed`/`percentage`), `deal_settlements.paye_value`, deductions |
| **Checksum** | `net + paye + deductions + company + external + vatAmt = totalCommissionExVat` (±0.01 tolerance) |
| **Used in** | Settlement payslip, settlement preview |

---

### 1.6 Canonical Deal Money Lines — `DealMoneyLineRebuilder`
| Field | Detail |
|---|---|
| **File** | [app/Services/DealMoneyLineRebuilder.php](../app/Services/DealMoneyLineRebuilder.php) line ~42 |
| **Value computed** | Authoritative `deal_money_lines` rows per agent per side |
| **Formula** | `totalEx = totalIncl / (1+vat)` → `sidePool = totalEx × split% × ourShare%` → `poolShare = sidePool × allocPct%` → `agentGross = poolShare × agentCut%` → `companyGross = poolShare − agentGross` → `paye = agentGross × payeValue%` (or fixed when paid) → `agentNet = agentGross − paye − deductions` |
| **Input priority** | `deal_settlements` overrides `deal_user` pivot overrides `users` defaults |
| **Used in** | Rebuilder service, triggered on settlement save |

> ⚠️ **Schema note:** Original migration added `agent_income_ex_vat` / `company_retained_ex_vat` columns.
> Patch migration adds `agent_gross_ex_vat` / `company_gross_ex_vat`. Rebuilder writes to patched names only.
> Original column names may exist in schema but are never written.

---

## 2. AGENT-LEVEL CALCULATIONS

### 2.1 Agent Income Allocation — Block 1 (correct)
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~237–255 |
| **Value computed** | `agent_income`, `company_retained` per agent per period |
| **Formula** | `agentIncome = CommissionCalculator::companyIncomeExVatForSide() × split%` → `companyRetained = sideIncome − agentIncome` |
| **Used in** | Period rollup, company performance dashboard |

---

### 2.2 Agent Income Allocation — Block 2 (DIVERGENT — overwrites block 1)
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~332–366 |
| **Value computed** | Same fields: `company_income`, `agent_income`, `company_retained` |
| **Formula** | `grossExVat = total_commission / (1+vat)` → `companyIncome = grossExVat × ourShare%` → `agentIncome = companyIncome × split%` (falls back to `user_default_split_percent = 50`) |
| **Inputs** | Does NOT call `CommissionCalculator`. Does NOT use `listing_split_percent` / `selling_split_percent`. |
| **Used in** | Overwrites block 1 results (lines ~372–374) |

> ⚠️ **CRITICAL RISK:** Block 2 overwrites block 1. Block 2 ignores side-specific split percentages
> and external flags. Results will DIVERGE from correct values whenever:
> - `listing_split_percent ≠ selling_split_percent`
> - A side is marked external
> - `agent_split_percent` is null (falls back to 50%)

---

### 2.3 Effective Commission Percent (Incl VAT version)
| Field | Detail |
|---|---|
| **File** | [app/Services/Agent/AgentPerformanceService.php](../app/Services/Agent/AgentPerformanceService.php) line ~105 |
| **Value computed** | Commission rate as % of sale price, INCL VAT |
| **Formula** | `effectiveCommPct = (total_commission_incl / sales_value) × 100` |
| **Used in** | Agent performance summary card |

> **Labelling inconsistency:** `AgentPerformanceService` calls this `effective_commission_percent` (no qualifier).
> `WorksheetController::dealRegisterStats()` computes an EX VAT version and calls it
> `effective_commission_percent_ex_vat`. Both feed different UI widgets. See §8.8.

---

### 2.4 Activity Points
| Field | Detail |
|---|---|
| **File** | [app/Services/Agent/AgentPerformanceService.php](../app/Services/Agent/AgentPerformanceService.php) line ~157 |
| **Value computed** | Agent points actual for period |
| **Formula** | `points = SUM(daily_activity_entries.value × activity_definitions.weight)` |
| **Used in** | Agent scorecard, branch rollup, company rollup |

---

### 2.5 Agent Progress Percentages
| Field | Detail |
|---|---|
| **File** | [app/Services/Agent/AgentPerformanceService.php](../app/Services/Agent/AgentPerformanceService.php) lines ~195–199 |
| **Formula** | `deals_pct = actual_deals / needed_deals × 100`, `value_pct = actual_value / value_target × 100` |
| **Used in** | Agent dashboard cards |

---

### 2.6 Agent Pace (per day)
| Field | Detail |
|---|---|
| **File** | [app/Services/Agent/AgentPerformanceService.php](../app/Services/Agent/AgentPerformanceService.php) lines ~302–305 |
| **Formula** | `deals_per_day = remaining_deals / days_left`, `value_per_day = remaining_value / days_left` |
| **Used in** | Agent pacing widget |

---

## 3. BRANCH-LEVEL CALCULATIONS

### 3.1 Branch Team Income (Summation)
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~640–642 |
| **Formula** | `teamCompanyIncome = SUM(branchRows.actuals.company_income)` — summed from per-agent rows |
| **Used in** | Branch performance card |

---

### 3.2 Branch Ledger Income (Deal-based)
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~707–717 |
| **Formula** | `ledgerIncome = SUM(CommissionCalculator::companyIncomeExVat($deal))` for all branch deals in period |
| **Used in** | Branch ledger card (deal-signed vs company-income reconciliation) |

---

### 3.3 Branch Points & Pacing Status
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~648–668, ~283–291 |
| **Formula** | `points = SUM(value × weight)` filtered by branch. `expectedByNow = (pointsTarget / daysInMonth) × daysElapsed`. Status: `Achieved / Ahead (≥105%) / On pace (≥95%) / Behind` |
| **Used in** | Branch scorecard. Same logic in 4 places (per-agent, per-branch ×2, company admin patch). |

---

### 3.4 Branch Projected Income — BM View (DISCONNECTED formula)
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/BM/PerformanceController.php](../app/Http/Controllers/BM/PerformanceController.php) lines ~33–37, ~437–445 |
| **Formula** | `projectedIncome = agentValueTargetSum × 0.05 × 0.50` (hardcoded 5% commission × 50% company share) |
| **Inputs** | `config/performance.php` — NOT `PerformanceSetting`, NOT worksheet `commission_percent` |
| **Used in** | BM budget planning view, branch value target derivation |

> ⚠️ **HIGH RISK:** Completely disconnected from the actual deal financial model. The real model uses
> commission_percent (default 7.5%), VAT removal, and agent_split_percent per agent. The BM view
> could show projected income that is materially wrong (e.g. 2.5% of value vs actual ~3.26% of value
> ex-VAT at 7.5% commission and 50% split).

---

### 3.5 Branch Progress Percentages
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~519–521 |
| **Formula** | `deals_pct = actuals.deals / targets.deals × 100`, same for value and points |
| **Used in** | Branch dashboard cards |

---

## 4. COMPANY-LEVEL CALCULATIONS

### 4.1 Period Totals — Rolled Up from Branches
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~527–554 |
| **Formula** | `SUM(branchTotals.ledger_company_income)`, `SUM(branchTotals.team_agent_income)`, etc. |
| **Used in** | Company admin performance overview |

---

### 4.2 Company Retained
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~396–398 |
| **Formula** | `company_retained = company_income − agent_income` (floor 0) |
| **Used in** | Company profitability cards |

---

### 4.3 Company Progress Percentages
| Field | Detail |
|---|---|
| **File** | [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~401–404 |
| **Formula** | `pct = actual / target × 100` for deals, value, points |
| **Used in** | Company admin overview |

---

## 5. WORKSHEET-LEVEL CALCULATIONS

### 5.1 Core Planning Engine — `WorksheetController::calculate()`
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~840–978 |
| **Value computed** | Full agent financial plan: sales needed, listings needed, company income per agent |
| **Formula chain** | |

```
netNeed = personal_net_target + business_net_target + want_net_target

grossCommissionPerSale  = avg_sale_price × (commission_percent / 100)   [INCL VAT]
commissionPerSaleExVat  = grossCommissionPerSale / (1 + vatRate)

agentGrossPerSale       = commissionPerSaleExVat × (agent_split_percent / 100)
payePerSale             = agentGrossPerSale × (paye_percent / 100)
agentNetPerSale         = agentGrossPerSale − payePerSale

salesNeededPerMonth     = netNeed / agentNetPerSale
listingsNeeded          = (salesNeededPerMonth × listingsPerSale) / (correctly_priced_percent / 100)
gap                     = listingsNeeded − currentListings

companyIncomePerSale    = commissionPerSaleExVat × (1 − agent_split_percent / 100)
companyIncome           = salesNeededPerMonth × companyIncomePerSale
```

| **Inputs** | `worksheets.personal/business/want_net_target`, `avg_sale_price` (overridden by `avg_sale_price_admin`), `commission_percent` (def 7.5), `paye_percent`, `agent_split_percent`, `correctly_priced_percent`, `PerformanceSetting['vat_rate']` (def 15), `PerformanceSetting['listings_per_sale']` (def 5) |
| **Used in** | Worksheet index (plan column) |

---

### 5.2 `calculateWithOverrides()` — Partial Duplication
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) line ~762 |
| **Value computed** | Same formula as 5.1 but accepts `avg_sale_price_override` and `commission_percent_override` |
| **Missing** | Does NOT compute `company_income` |
| **Used in** | Market Reality column on worksheet, called from Blade (see §7.1) |

> ⚠️ **Duplication:** `calculate()` and `calculateWithOverrides()` share 80% of the same logic.
> The market-reality commission percent input is already ex-VAT (see §8.8 for the resulting error).

---

### 5.3 Company Requirement Shortfall
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~99–109 |
| **Formula** | `shortfall = max(0, (branch_budget / active_agents) − current_company_income)` — floored to 0 if < R1 |
| **Used in** | Worksheet company requirement alert |

---

### 5.4 Branch Default Target Derivation — `applyBranchDefault()`
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~1179–1195 |
| **Formula** | Reverse-calculates `netNeedRequired` so company earns `requiredPerAgent`: `netNeedRequired = requiredPerAgent × agentNetPerSale / companyIncomePerSale` |
| **Hardcoded defaults** | `personal:business:want = 0.65 : 0.20 : 0.15` when no prior worksheet exists |
| **Used in** | First-time worksheet creation |

---

### 5.5 Ideal Commission Comparison
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~489–492 |
| **Formula** | `idealCommissionEx = salesEx × 0.075` → `lostVsIdeal = ideal − actual` |
| **Hardcoded** | 7.5% ideal rate — not configurable |
| **Used in** | Deal register stats / "money left on table" display |

---

### 5.6 CMA Overpricing Threshold
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~150–155 |
| **Formula** | `overpriced = asking_price_cents > (cma_price_cents × 105 / 100)` (asking > CMA by >5%) |
| **Hardcoded** | 5% threshold — not configurable |
| **Used in** | Listing stock analysis, correctly-priced % |

---

### 5.7 Value Target from Worksheet
| Field | Detail |
|---|---|
| **File** | [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) lines ~279–280 |
| **Formula** | `dealsTarget = ceil(sales_needed_per_month)` → `valueTarget = dealsTarget × avg_sale_price` |
| **Used in** | Period targets stored against agent |

---

## 6. RENTALS-LEVEL CALCULATIONS

### 6.1 Branch Rental Commission Total
| Field | Detail |
|---|---|
| **File** | [app/Services/Rentals/RentalWorksheetInclusionService.php](../app/Services/Rentals/RentalWorksheetInclusionService.php) line ~70 |
| **Formula** | `totalCommissionExcl = SUM(rental_amount_versions.commission_excl)` — pre-stored, no runtime calc |
| **Used in** | Worksheet inclusion summary |

---

### 6.2 Per-User Rental Share (Equal Split)
| Field | Detail |
|---|---|
| **File** | [app/Services/Rentals/RentalWorksheetInclusionService.php](../app/Services/Rentals/RentalWorksheetInclusionService.php) lines ~136–139 |
| **Formula** | `userShare = commission_excl / agentCount` (integer, min 1) |
| **Inputs** | `rental_amount_versions.commission_excl`, `rentals.agents_count` |
| **Used in** | Agent worksheet income inclusion |

> **Note:** Rentals use equal split only — no percentage-weighted split exists for rentals.

---

### 6.3 Rental VAT Consistency Risk
| Field | Detail |
|---|---|
| **File** | [database/migrations/2026_02_10_080641_create_rental_amount_versions_table.php](../database/migrations/2026_02_10_080641_create_rental_amount_versions_table.php) |
| **Risk** | Both `commission_incl` and `commission_excl` are stored independently. No DB constraint enforces `commission_excl × (1 + vatRate) = commission_incl`. Manual entry can create inconsistent rows. |

---

## 7. BLADE / VIEW CALCULATIONS (HIGH RISK)

### 7.1 Worksheet Index — Full Planning Math Block in Blade
| Field | Detail |
|---|---|
| **File** | [resources/views/worksheet/index.blade.php](../resources/views/worksheet/index.blade.php) lines ~487–595 |
| **Value computed** | Plan vs Market comparison: budget floor, planned sales needed, planned listings needed, reverse-calc of planned sales value |
| **Severity** | CRITICAL — ~100 lines of pure business logic inside a Blade view |
| **DB calls in Blade** | `\App\Models\PerformanceSetting::get('vat_rate', 15)` — direct DB call in template |
| **Controller call in Blade** | `\App\Http\Controllers\WorksheetController::calculateWithOverrides()` called from Blade |
| **Used in** | Worksheet Plan vs Market section |

---

### 7.2 Worksheet Blade — Delta Calculations
| Field | Detail |
|---|---|
| **File** | [resources/views/worksheet/index.blade.php](../resources/views/worksheet/index.blade.php) lines ~669–673 |
| **Formula** | `delta_comm = actual_comm − planned_comm`, `delta_net = actual_net − planned_net`, `delta_need = actual_net_need − planned_net_need` |
| **Severity** | Medium — simple arithmetic, but business logic in view |

---

### 7.3 Worksheet Blade — Net Target Sum
| Field | Detail |
|---|---|
| **File** | [resources/views/worksheet/index.blade.php](../resources/views/worksheet/index.blade.php) lines ~11–14 |
| **Formula** | `latestNet = personal + business + want` |
| **Severity** | Low — mirrors what `calculate()` already computes |

---

### 7.4 TV Branch View — Progress Percentages
| Field | Detail |
|---|---|
| **File** | [resources/views/tv/branch.blade.php](../resources/views/tv/branch.blade.php) lines ~9–20 |
| **Formula** | `pct = min(100, actual / target × 100)` for value, deals, points |
| **Severity** | Low — display-only, capped at 100 |

---

## 8. CROSS-CUTTING FINDINGS

### 8.1 VAT Rate — All Locations

All locations consistently use `PerformanceSetting::get('vat_rate', 15)` with fallback 15%.
The formula is universally `total_commission / (1 + vatRate)`.

**The problem:** `vatRate()` is implemented as a private static method in TWO separate classes:

| Class | File |
|---|---|
| `CommissionCalculator::vatRate()` | [app/Services/Finance/CommissionCalculator.php](../app/Services/Finance/CommissionCalculator.php) line ~8 |
| `DealMoneyLineRebuilder::vatRate()` | [app/Services/DealMoneyLineRebuilder.php](../app/Services/DealMoneyLineRebuilder.php) line ~183 |

These are identical private duplicates. A change to one will not propagate to the other.

**All VAT usage locations:**
- `Deal::commissionExVat()` — `app/Models/Deal.php`
- `CommissionCalculator` — `app/Services/Finance/CommissionCalculator.php`
- `DealMoneyLineRebuilder` — `app/Services/DealMoneyLineRebuilder.php`
- `DealController::settle/saveSettlement/printSettlement/printAgentPayslip` — ×4 copies
- `WorksheetController::calculate()`, `calculateWithOverrides()`, `dealRegisterStats()`, `applyBranchDefault()`
- `CompanyPerformanceService` block 2 (MONEY_ALLOC_BY_USER_PERIOD)
- `BM\AgentPerformanceController`
- `Admin\AgentPerformanceController`
- `worksheet/index.blade.php` ← DB call in Blade

---

### 8.2 Agent Income: Two Divergent Calculations in the Same Method
`CompanyPerformanceService::getPeriodRollup()` runs TWO allocation loops that compute the same
fields (`company_income`, `agent_income`, `company_retained`) for each agent, and the second
overwrites the first.

| | Block 1 (lines ~215–255) | Block 2 (lines ~332–366) |
|---|---|---|
| Uses CommissionCalculator | YES | NO |
| Respects listing_split_percent | YES | NO |
| Respects selling_split_percent | YES | NO |
| Respects external flags | YES | NO |
| Split fallback | none (0 if null) | `user_default_split_percent` → 50% |
| Result | Correct | Diverges when sides differ or external |

**Block 2 results overwrite block 1 at lines ~372–374.**
This is the single highest-risk financial bug in the codebase.

---

### 8.3 Hardcoded Rates Summary

| Rate | Value | Location | File | Risk |
|---|---|---|---|---|
| Ideal commission % | 7.5% | `dealRegisterStats()` line ~490 | WorksheetController.php | Medium — should be `PerformanceSetting` |
| BM projected commission rate | 5% | `config/performance.php` | PerformanceController.php | HIGH — disconnected from real model |
| BM projected company share | 50% | `config/performance.php` | PerformanceController.php | HIGH — disconnected from real model |
| CMA overpricing threshold | 5% (105/100) | line ~151 | WorksheetController.php | Medium |
| Budget allocation ratios | 65%/20%/15% | line ~1214 | WorksheetController.php | Low (fallback only) |
| Points status tolerance | ±5% (1.05/0.95) | lines ~285–287 | CompanyPerformanceService.php | Low (4 copies) |
| Demo commission rate | 5% | line ~206 | RichDemoSeeder.php | Low (demo only) |
| Worksheet commission default | 7.5% | schema default | worksheets migration | Low (schema default) |

---

### 8.4 Copy-Pasted Settlement Pool Formula (4 copies)
File: [app/Http/Controllers/Admin/DealController.php](../app/Http/Controllers/Admin/DealController.php)

| Method | Lines |
|---|---|
| `settle()` | ~524–563 |
| `saveSettlement()` | ~682–721 |
| `printSettlement()` | ~952–994 |
| `printAgentPayslip()` | ~1080–1115 |

Same ~30-line block verbatim. Any arithmetic correction must be made in 4 places.

---

### 8.5 Schema Inconsistency — `deal_money_lines` Column Names
| Migration | Columns added |
|---|---|
| `2026_01_29_081303` (original) | `agent_income_ex_vat`, `company_retained_ex_vat` |
| `2026_01_29_081921` (patch) | `agent_gross_ex_vat`, `company_gross_ex_vat` |

`DealMoneyLineRebuilder` writes to the patched column names only. The original columns may
still exist in the schema but are never written to, causing potential confusion in any raw SQL queries.

---

### 8.6 `effectiveCommissionPercent` Labelling Inconsistency
| Location | Formula | VAT treatment | Label |
|---|---|---|---|
| `AgentPerformanceService.php` line ~105 | `total_commission_incl / sales_value × 100` | INCL VAT | `effective_commission_percent` |
| `WorksheetController::dealRegisterStats()` | `commEx / salesEx × 100` | EX VAT | `effective_commission_percent_ex_vat` |

The ex-VAT version is fed back into `calculateWithOverrides()` as `commission_percent`.
Inside that function: `commissionPerSale = avgSalePrice × (commissionPercent / 100) / (1 + vatRate)`.
If `commissionPercent` is already ex-VAT, this applies a second VAT removal, **understating the
per-sale commission in the Market Reality column.** This is a subtle but real formula error.

---

### 8.7 Branch Rollup Loop Bug (Dead Code)
[app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) lines ~753–759:
`$tot['actuals']['ledger_*']` values are assigned inside a `foreach ($ledgerRows)` loop —
overwritten on every iteration, retaining only the last iteration's partial sum.
Correct final values are assigned after the loop (lines ~772–777), so the bug appears harmless,
but the inner assignments are dead/misleading code.

---

### 8.8 Double-Counting Guard (Correctly Implemented)
Both `AgentPerformanceService` and `CompanyPerformanceService` use `DISTINCT deal_id` via subquery
to prevent double-counting `property_value` and `total_commission` when an agent appears on
both sides of the same deal. ✅ Correctly implemented.

---

## 9. TOP 15 CANONICAL DEFINITIONS TO IMPLEMENT FIRST

Ordered by **impact × duplication × risk**:

| # | Definition | Why First |
|---|---|---|
| 1 | `vatRate(): float` — single source, reads `PerformanceSetting['vat_rate']` with fallback 15 | Used in 12+ locations; two duplicate private implementations |
| 2 | `commissionExVat(float $incl, float $vatRate): float` — `$incl / (1 + $vatRate)` | Core formula; 10+ inline duplicates |
| 3 | `sidePool(float $commExVat, float $splitPct, float $ourSharePct, bool $isExternal): float` | Used in Deal model AND CommissionCalculator AND DealController ×4 |
| 4 | `agentGross(float $poolShare, float $agentCutPct): float` | Used in DealMoneyLineRebuilder AND DealController ×4 |
| 5 | `payeAmount(float $agentGross, string $method, float $value, bool $isPaid): float` | Fixed vs percentage PAYE logic duplicated in 2 services |
| 6 | `agentNet(float $agentGross, float $paye, float $deductions): float` | Simple but appears in 4+ places |
| 7 | `companyRetained(float $pool, float $agentGross): float` | `pool − agentGross`; used everywhere |
| 8 | `settlementPoolBlock(object $deal): array` — replaces the 4× copy-pasted ~30-line block in DealController | 4 identical copies in one file; critical risk |
| 9 | `agentIncomeForPeriod(object $ar): array` — single correct implementation replacing the two divergent blocks in CompanyPerformanceService | Highest-risk inconsistency; block 2 silently overwrites block 1 |
| 10 | `projectedBranchIncome(float $valueTarget, float $commissionPct, float $agentSplitPct, float $vatRate): float` | BM view uses disconnected hardcoded formula; should use same model as worksheet |
| 11 | `worksheetCalculate(array $inputs): array` — consolidate `calculate()` and `calculateWithOverrides()` | Near-duplicate methods; market-reality path has VAT double-application bug |
| 12 | `effectiveCommissionPct(float $commissionExVat, float $salesValueExVat): float` | Two inconsistent definitions (incl vs ex VAT); feeds back into worksheet causing understatement |
| 13 | `pacingStatus(float $actual, float $target, float $expectedByNow): string` | Same `Achieved/Ahead/On pace/Behind` logic hardcoded with `±5%` tolerance in 4 places |
| 14 | `progressPct(float $actual, float $target): ?float` | `actual / target × 100`; trivial but used 20+ times; cap-at-100 applied inconsistently |
| 15 | `rentalUserShare(float $commissionExcl, int $agentCount): float` | Simple equal-split; isolated but should be canonical for future weighted-split support |

---

## 10. TOP 10 DANGEROUS ANTI-PATTERNS FOUND

| # | Pattern | Location | Risk |
|---|---|---|---|
| 1 | **Two allocation blocks computing the same agent income, second silently overwrites first, with different formulas** | `CompanyPerformanceService::getPeriodRollup()` lines ~215–366 | CRITICAL — produces wrong company/agent split for any deal where listing ≠ selling split or either side is external |
| 2 | **~30 lines of settlement pool math copy-pasted verbatim in 4 controller methods** | `DealController` lines ~524, ~682, ~952, ~1080 | HIGH — a fix in one copy won't propagate to the others |
| 3 | **~100 lines of business logic (VAT removal, reverse-calc, floor calculations) inside a Blade view** | `resources/views/worksheet/index.blade.php` lines ~487–595 | HIGH — untestable, invisible to PHPStan/Pint, DB call in template |
| 4 | **BM projected income uses hardcoded `5% × 50%` from `config/performance.php`, completely disconnected from the actual deal model** | `BM\PerformanceController` lines ~33–37 | HIGH — BM budget view can show projections that are materially wrong vs what the worksheet model predicts |
| 5 | **`vatRate()` implemented as a private duplicate in two separate service classes** | `CommissionCalculator` and `DealMoneyLineRebuilder` | HIGH — a VAT rate logic change must be made in 2 places; easy to miss |
| 6 | **`effectiveCommissionPercent` is incl-VAT in one place and ex-VAT in another; the ex-VAT version is fed into a formula that applies VAT removal a second time** | `AgentPerformanceService` vs `WorksheetController::dealRegisterStats()` + `calculateWithOverrides()` | HIGH — understates per-sale commission in the worksheet Market Reality column |
| 7 | **Hardcoded 7.5% ideal commission rate** | `WorksheetController::dealRegisterStats()` line ~490 | MEDIUM — should be a `PerformanceSetting` value; will be wrong for branches with different rates |
| 8 | **`rental_amount_versions` stores both `commission_incl` and `commission_excl` with no enforcement that they are consistent** | `2026_02_10_080641` migration | MEDIUM — manual entry can create rows where `excl × (1+vat) ≠ incl`, silently corrupting rental worksheet income |
| 9 | **`deal_money_lines` has two sets of column names** (`agent_income_ex_vat`/`company_retained_ex_vat` from original migration vs `agent_gross_ex_vat`/`company_gross_ex_vat` from patch migration); only the patch names are written | `DealMoneyLineRebuilder` + two migrations | MEDIUM — raw SQL queries or reporting tools reading the original column names get NULL/stale data |
| 10 | **Pacing status `±5%` tolerance and `deals/value/points_pct = actual/target×100` progress formula both hardcoded and duplicated in 4+ places each** | `CompanyPerformanceService` lines ~283–291, ~519–521, ~401–404, ~648–668 | LOW-MEDIUM — any threshold change requires hunting 4 locations; cap-at-100 inconsistently applied (TV blade caps, company performance service does not) |

---

## APPENDIX: FILE REFERENCE MAP

| File | Sections |
|---|---|
| [app/Models/Deal.php](../app/Models/Deal.php) | §1.1, §1.2 |
| [app/Services/Finance/CommissionCalculator.php](../app/Services/Finance/CommissionCalculator.php) | §1.3, §8.1 |
| [app/Services/DealMoneyLineRebuilder.php](../app/Services/DealMoneyLineRebuilder.php) | §1.6, §8.1, §8.5 |
| [app/Http/Controllers/Admin/DealController.php](../app/Http/Controllers/Admin/DealController.php) | §1.4, §1.5, §8.4 |
| [app/Services/Admin/CompanyPerformanceService.php](../app/Services/Admin/CompanyPerformanceService.php) | §2.1, §2.2, §3.1–3.5, §4.1–4.3, §8.2, §8.7 |
| [app/Services/Agent/AgentPerformanceService.php](../app/Services/Agent/AgentPerformanceService.php) | §2.3–2.6, §8.6 |
| [app/Http/Controllers/WorksheetController.php](../app/Http/Controllers/WorksheetController.php) | §5.1–5.7, §8.6 |
| [app/Http/Controllers/BM/PerformanceController.php](../app/Http/Controllers/BM/PerformanceController.php) | §3.4 |
| [app/Services/Rentals/RentalWorksheetInclusionService.php](../app/Services/Rentals/RentalWorksheetInclusionService.php) | §6.1, §6.2 |
| [resources/views/worksheet/index.blade.php](../resources/views/worksheet/index.blade.php) | §7.1–7.3 |
| [resources/views/tv/branch.blade.php](../resources/views/tv/branch.blade.php) | §7.4 |
| [database/migrations/2026_02_10_080641_...](../database/migrations/) | §6.3, §8.8 |
| [database/seeders/RichDemoSeeder.php](../database/seeders/RichDemoSeeder.php) | §8.3 |

---

*End of inventory. Next step: review and approve canonical definitions in §9 before any refactor begins.*
