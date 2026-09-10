# DR2 Financial Audit — 2026-09-10

**Status:** Investigation complete. No code changed. No data changed. Every fix approach below is a recommendation only — Johan approves each one separately before any line is written.
**Scope:** QA1 (read-only), Staging (read-only, for comparison only). No live access of any kind was used or is available for this audit.
**Trigger:** Johan found deal 1818 showing R12,750 against an expected R25,500. cc6 is fixing that specific deal (now ticketed **AT-403** — a share-percent commission defect). This document covers everything else, to answer whether 1818 was one bug or the tip of something larger.

**It was the tip of something larger.**

---

## For Johan — read this part, nothing else if you don't want to

**What's wrong, in plain terms:**

The system that works out commission — who gets paid what, and how much the company keeps — has grown **at least five different calculators that all do this same sum, in different places, and nothing keeps them talking to each other.** Most of the time they agree. When they don't, nobody is told, because the screens don't say which calculator produced the number you're looking at.

Two of these disagreements are real money, not theory:

1. **The screen your Branch Managers use to approve and pay out a deal does not say whether a number includes VAT or not.** Three figures sit side by side on that screen — total commission, the agency's own share, and what's owed to an outside agency — and one of the three is on a different VAT basis than the other two, with no label telling anyone. On a real example deal, that gap alone was **R6,522** on one deal. This is a live version of exactly the mistake behind deal 1818.

2. **When an agent leaves the company, every historical deal they were ever part of quietly loses their share of the commission from the company's own performance reports** — not from their payslip, but from the dashboards you and your Branch Managers use to see how the business is doing. Right now, for one month alone (May 2026), this has made the company's branch and company-wide performance numbers wrong by **just under R50,000**, every single time that report has been run, since **March**. The same fault, if left alone, is already sitting on **R625,804** worth of historical commission that isn't visible in at least one other report — that particular number isn't affecting anything today because nothing currently reads that report, but it is a live landmine: the moment anyone builds a new feature off that report, it wakes up.

3. **The tool that was supposed to catch exactly this kind of thing has been telling us the wrong reason for five months.** CoreX already has an internal checker that compares the "new" performance numbers against the "old" ones and flags a mismatch. It caught finding #2 back in March — but the note it left explaining *why* the numbers didn't match is wrong. It blamed a date mix-up that doesn't actually exist in the code. Anyone reading that note and trying to fix it would have gone looking in the wrong place. That's exactly what nearly happened during this audit, until the real cause was checked by hand against real deals.

**What it's costing, in rand, right now:**
- Confirmed wrong, live, recurring monthly: **~R49,793** on the company performance report for one period alone (likely more once every affected month is checked, not just the one sampled).
- Confirmed real but currently inert (not affecting anything today, but ready to): **R625,804.**
- Confirmed small but real, and growing with volume: **a few rand** from rounding differences across the whole deal book — not urgent on its own, but proof the underlying discipline is missing.
- The deal-1818 class of error (VAT basis confusion) is not bounded to one deal — it's a property of the screen itself, so the real number at risk is "every future deal with an external-agency split," not one incident.

**What you need to decide, not how to fix it (that's engineering's job once you say go):**
- Which of the findings below to authorise fixing, and in what order — several touch numbers that may already have been paid out, so a couple of these need a decision about whether to correct historical records or only fix things going forward.
- Whether the settlement screen's missing VAT labels should be fixed before anything else, since that's the one an actual person reads before actually paying someone.
- Whether you want the performance dashboards re-run for every past month once the agent-exclusion bug is fixed, or only from the fix date forward.

Nothing has been changed. Twelve tickets are attached below, one per finding, all sitting in your Jira board in **To Do**, so you can triage them at your own pace.

---

## For the engineer — the findings, worst first

Each finding states: what it is, money at risk, whether a wrong figure can reach a **real payment** or only a **screen**, the exact files/lines, how it was proven, and a one-paragraph recommended fix with an independence note.

### F1 — The live settlement screen does not disclose VAT basis; three money figures, three bases, no labels

**Money at risk:** unbounded per-deal — proven at R6,521.74 on one ordinary example deal (deal 6). Recurs on every deal with an external-agency split.
**Reaches:** a **real payment**. This is the class of error behind deal 1818 (AT-403) — a Branch Manager reads this exact screen to decide what to pay out.

`app/Models/Deal.php:289-292` documents the only stated rule: `total_commission` is captured inc-VAT; internal pools are calculated ex-VAT. Nothing documents the external-agency payable's basis anywhere. In the live calculation (`app/Services/DealMoneyLineRebuilder.php:46-60`):
```php
$listingExternalPayable = $listingSideInc;                              // inc-VAT, never divided
$listingPool = $listingSideEx * ($listingOurPct / 100.0);                // ex-VAT
```
The downstream checksum (`app/Http/Controllers/Admin/DealController.php:784-788`) divides the external figure by `(1+vatRate)` before reconciling — proof the codebase itself treats the conversion as necessary. It never happens before the number reaches a human: `resources/views/admin/deals/settle.blade.php:82,199,330` shows Total Commission (inc-VAT), Listing Pool (ex-VAT), and External Payable side by side with **no VAT qualifier on any of them**. The print/PDF version (`resources/views/admin/deals/print/settlement.blade.php:77,114,178`) labels all three correctly — only the interactive screen doesn't.

**Proof:** read both blade files directly; traced `computeDealPools()`'s arithmetic line by line; reproduced the R6,521.74 gap on deal 6 by hand (R50,000 external payable shown vs R43,478.26 true ex-VAT equivalent).

**Recommended fix:** label every money figure on `settle.blade.php` with its VAT basis, matching the print view exactly (cheapest, lowest-risk — a display-only change, no calculation touched). Separately and optionally, consider whether the external payable should be stored/shown ex-VAT everywhere for consistency with the internal pool — that's a bigger, calculation-touching change and should be a separate decision. **Safe to fix independently** — the labeling fix touches no other finding.

---

### F2 — `Deal::agents()` silently excludes agents whose account has since been deactivated — live on the performance dashboards, latent in a second, currently-dead path

**Money at risk:** **R49,793.48**, confirmed live and recurring, for period 2026-05 alone (company + 2 branches) — very likely more once every historical month is checked, not just the one this audit sampled. Separately, **R625,804.43** confirmed real across 16 of 157 deals, currently **inert** (see below — do not treat this as an active loss).
**Reaches:** a **real dashboard** (Branch/Company Performance, and whatever reads the same `finance_computed_values` table, including the TV leaderboard) — continuously, since March 2026. **Not confirmed to reach a real payment** — the actual payslip-generation path was checked directly and does not use the broken relationship (see "reassuring counter-finding" below).

Root cause: `Deal::agents()` (`app/Models/Deal.php:255-257`) is `belongsToMany(User::class)` with no `withTrashed()`. `User` uses SoftDeletes. Once an agent's account is deactivated, every read of `$deal->agents` for their historical deals silently drops them — the underlying `deal_user` pivot row (and the commission it represents) is untouched in the database; it just disappears from this one relationship.

**Manifestation A — live, currently wrong.** `app/Services/Finance/RollupService.php`'s per-agent aggregation loop reads `$deal->agents`. Reproduced by hand: deal 131 (agent id 41, soft-deleted, R13,043.48 dropped) and deal 132 (agent id 33, soft-deleted, R36,750.00 dropped), period 2026-05, agency 1 — sums exactly match the self-audit's own recorded diffs. This feeds `finance_computed_values` (written via `RollupService::refreshPeriod()`, called on every live deal store/update at `app/Http/Controllers/Dr2/DealRegisterController.php:245,783`) — documented elsewhere as the canonical read model for Branch/Company Performance. Confirmed the value currently being served for 2026-05 company-period IS the wrong one (R338,653.86, not the correct R388,447.34). The missing agent share isn't just dropped — `dealRetainedTotal = dealFullCompanyIncome − dealAgentIncomeTotal` means it's **silently re-labelled as company profit**. Every affected period currently overstates company retained earnings and understates agent income by exactly the departed agents' share.

**Manifestation B — real, but currently inert.** The same root cause breaks `Deal::allocations()`/`allocateSide()` (`Deal.php:362-433`) far more severely: 16 of 157 deals (10%) understated by more than R1, R625,804.43 total, including two deals (5 and 4) where an entire side's commission (R107,608.70 and R21,739.13) vanishes outright. Checked every caller: `app/Http/Controllers/Admin/AgentCommissionController.php:28` is the only one outside `Deal.php` itself, and **this controller has no registered route anywhere in the application** — confirmed by grepping every route file. It is dead code. `Deal::branchCommission()`, which also calls `allocations()`, likewise has zero external callers. **Do not report the R625,804.43 figure as an active loss — it is currently unreachable by any real screen or process.** It is reported here because it is the same bug, it is real in the data, and it will activate the instant anything new calls either method.

**Reassuring counter-finding, checked directly:** `DealMoneyLineRebuilder::rebuildSingleDeal()` — the function that actually builds `deal_money_lines`, which the real settlement/payslip printing (`printAgentPayslip`, `printSettlement`) reads — queries `DB::table('deal_user')` raw, not `$deal->agents`. **This specific bug does not reach an actual payslip.**

**Proof:** read `Deal::agents()`'s relationship definition; confirmed via Tinker that users 41 and 33 are soft-deleted (`User::withoutGlobalScopes()->find(41/33)`); reproduced both the RollupService and `allocations()` computations by hand against real `deal_user` rows for the affected deals; grepped every route file for `AgentCommissionController` (zero matches) and every call site of `branchCommission()`/`allocations()` (only the two named).

**Recommended fix:** add `withTrashed()` to `Deal::agents()` (and confirm `listingAgents()`/`sellingAgents()` inherit it, since they build on `agents()`) — one relationship definition, fixes both manifestations at once. This is a calculation-affecting change to a live, currently-wrong dashboard figure — **do this together with a decision on F3 below** (the audit tool's message needs its own separate fix, but Johan should be told about both at the same time since they're the same bug). After the fix, every past period should be re-rolled, which is a data question for Johan (re-run history, or only fix forward) — **not safe to fix silently in isolation from that decision.**

---

### F3 — The diagnostic tool built to catch exactly this kind of drift has been describing the wrong cause for five months

**Money at risk:** none directly — this is a trust-and-process finding, not a money finding. But it is the reason F2 went unfixed for five months, so its cost is measured in the unfixed time, not rand.
**Reaches:** nobody directly, but anyone who trusts its output is misdirected.

Every one of the 109 `severity=error` rows this audit tool has recorded since 2026-03-27 carries the stored message *"engine uses period field; legacy uses deal_date."* This is **false**. Verified directly: `app/Services/Finance/RollupService.php:142-143` buckets by `deals.period`. All three Legacy readers (`app/Services/Finance/Legacy/AgentRollupLegacyReader.php`, `BranchRollupLegacyReader.php`, `CompanyRollupLegacyReader.php`) also bucket by `deals.period` — each file's own docblock states this explicitly ("Canonical inclusion rule: deals.period = $period (no date-range math)"). **Both sides use the identical field.** The message text describing a `deal_date` vs `period` mismatch does not correspond to any code that currently exists — it appears to describe an earlier version of this logic that has since changed, and nobody updated the message. This audit initially followed that message and would have investigated the wrong hypothesis had the real deals not been checked by hand.

**Proof:** read the message-generation code (`app/Services/Finance/AuditService.php`, around the `writeComparisonItem()` call near line 1054 of `RollupService.php`); read both sides' actual bucketing logic; confirmed they match.

**Recommended fix:** correct the stored message text (or, better, generate it dynamically from which specific rows/columns actually differed, so it can't silently go stale again the next time the underlying logic changes). This is a diagnostics-only change — **safe to fix independently of F2**, and should probably be fixed first so the corrected message is available when F2 is being worked.

---

### F4 — Company Performance can silently switch which calculator produced the number on screen, with no indication of which one ran

**Money at risk:** currently the same R49,793.48 as F2 (this is the delivery mechanism for that number), but the design risk outlives that specific bug.
**Reaches:** a **real dashboard** (Branch/Company Performance).

`app/Services/Admin/CompanyPerformanceService.php:197-299`: `$useEngine = !empty($companyEngineResult['data'])`. If `finance_computed_values` has rows for the period, it reads them (RollupService's output, carrying F2's error). If not, it falls back to a completely separate inline computation (`CommissionCalculator::companyIncomeExVatForSide()` per deal, bucketed by `deals.deal_date` — yet another bucketing convention). The same screen, same period, produces a different total purely depending on whether a background job has run yet for that period — invisible to the user, with no source indicator anywhere on screen.

**Proof:** read the conditional and both branches directly; confirmed `finance_computed_values` has rows for 2026-05 (meaning the engine branch, not the fallback, is what's currently being served).

**Recommended fix:** add a visible indicator of which path served a given number (even just a tooltip/footnote), and long-term collapse this to one calculation path so there's nothing to silently switch between. This is independent of F2's specific bug but should be fixed after F2, so the "engine" path is actually trustworthy once it's the only one anyone sees.

---

### F5 — Two methods on the same model, feeding the same Branch Performance screen, use two different rules for "what period does a deal belong to"

**Money at risk:** not yet quantified in rand — this affects deal counts and averages, not directly a commission rand figure, but it means two cards on one screen can legitimately disagree.
**Reaches:** a **real dashboard** (Branch Performance).

`app/Models/Deal.php`: `statusSummaryForBranch()` (~line 439) buckets by `deals.deal_date` (line 449); `marketAveragesForBranch()` (~line 567) buckets by the `period` field (line 606). Both are called from `app/Http/Controllers/BM/PerformanceController.php` (lines 47 and 83) for the same screen. This is a second, independent instance of the deal_date-vs-period confusion — proving the underlying uncertainty about "what period" is systemic, not confined to the RollupService bug in F2.

**Proof:** read both static methods in full, confirmed the two different bucketing columns; confirmed both are called from the same controller for the same screen.

**Recommended fix:** pick one canonical rule for "what period a deal belongs to" across the whole codebase (this audit found at least 3 different conventions in use — `deal_date`, the `period` field, and in one case both are checked inconsistently) and make every aggregate method use it. This is a bigger, cross-cutting decision that touches F2 and F4 as well — **do not fix in isolation; decide the canonical rule once, then apply it everywhere in one pass.**

---

### F6 — Every agent's own "My Dashboard" performance figure comes from a formula that has never been cross-checked against anything else in the system

**Money at risk:** not quantified — this is agent-facing and viewed routinely, so the cost is in disputes/trust rather than a single wrong payment.
**Reaches:** a **real screen**, viewed by every agent, regularly.

`app/Http/Controllers/BM/MyDashboardController.php` delegates to `App\Services\Agent\AgentPerformanceService::getMonthlySnapshot()` — raw SQL (`deals.total_commission * split_percent/100`, bucketed by `deals.deal_date`, VAT stripped inline), calling none of `Deal::commissionExVat()`, `Deal::allocations()`, `CommissionCalculator`, or `FinanceReadModel`. This is a distinct implementation from every other one found in this audit.

**Proof:** read `MyDashboardController` (confirmed it delegates, no math of its own) and `AgentPerformanceService::getMonthlySnapshot()` in full (lines 112-134).

**Recommended fix:** point this at the same canonical calculation used elsewhere (ideally `DealMoneyLineRebuilder`'s output, once F2 is fixed) rather than its own raw SQL. **Safe to fix independently**, but low priority relative to F1-F5 since no wrong rand figure was proven here, only architectural risk.

---

### F7 — At least three independent "commission ex-VAT" formulas exist; they agree today only because the sampled deals don't expose the difference

**Money at risk:** measured directly — R0.10 across the full 157-deal portfolio, R0.03 within one period — from rounding-order differences alone. Small today, proven to compound with deal volume.
**Reaches:** a **screen only**, confirmed on the deals tested — no evidence this reaches a payment differently from the other findings above.

`Deal::commissionExVat()` (`Deal.php:294-304`, strip-then-nothing) is duplicated verbatim in `FinanceComputeService::dealTotalCommissionExVat()`, `FinanceComputeService::legacy()`, and three separate inline copies in `app/Http/Controllers/WorksheetController.php` (~lines 403-404, 560-561, 658-659). `CommissionCalculator::companyIncomeExVatBreakdown()` (`app/Services/Finance/CommissionCalculator.php:31-71`, split-then-strip-per-side, rounds each side) is algebraically equivalent in exact arithmetic but rounds at a different point in the calculation — the four "Legacy" readers call this one directly rather than reimplementing it themselves. `DealMoneyLineRebuilder` is a genuine third lineage (see F8).

Real sample tested (deals 4, 21, 6, 154 — clean, odd, external-split, and multi-agent shapes): every implementation agreed to the cent. Summing `Deal::commissionExVat()` two ways across all 157 real commission-bearing deals — round once at the end (R9,475,596.94) vs round each deal first then sum (R9,475,597.04) — produces a **R0.10 difference on the full book**, and **R0.03** restricted to period 2026-05 alone. This is the mechanism, proven with real numbers, not a theoretical risk.

**Proof:** read and algebraically compared all formulas; ran the actual `Deal` model methods (not reimplementations) against real deals via Tinker; ran the two summation orders across the full portfolio and one period.

**Recommended fix:** designate `Deal::commissionExVat()` as the single source, and have every other file call it rather than reimplement it — this removes 5 of the current duplicates at effectively no risk (they're proven equivalent formulas, just re-typed). **Safe to fix independently.**

---

### F8 — `DealMoneyLineRebuilder` silently changes the formula (not just the rounding) when a deal's stored splits don't add up to 100%

**Money at risk:** currently zero — checked all 158 deals with both split columns set; none deviate from 100% by more than 0.01. This is a live formula difference waiting for the first bad-data deal, not a current loss.
**Reaches:** would reach the **real settlement/payslip path** (`deal_money_lines` feeds `printAgentPayslip`) the moment it triggers — this is the one duplication finding that touches the actual payment path, which is why it's ranked above F9 despite being currently dormant.

`app/Services/DealMoneyLineRebuilder.php:123-128`: `rebuildSingleDeal()` normalizes listing+selling split percentages to sum to 100 when they don't. No other calculator in the codebase (`Deal::allocations()`, `CommissionCalculator`) does this. The file's own docblock (lines 19-23) only discusses a separate, genuinely tiny (~R0.01) rounding-order difference between its two methods — it does not flag this normalization as a second, larger risk.

**Proof:** read the normalization code directly; queried all 158 deals with both split percentages set and confirmed none currently trigger it.

**Recommended fix:** decide, as a business rule, what SHOULD happen when a deal's stored splits don't sum to 100% (reject at data entry? normalize? flag for review?) and apply that decision consistently everywhere, not just in this one rebuilder. **Should be decided alongside F2's fix**, since both touch `DealMoneyLineRebuilder`/the settlement path and a combined data-quality pass would be more efficient than two separate ones.

---

### F9 — Three hand-copied inline copies of the same formula in one file

**Money at risk:** none currently (proven algebraically and numerically identical to the source in F7's testing) — pure maintenance risk.
**Reaches:** a **screen only** (Worksheet).

`app/Http/Controllers/WorksheetController.php` contains the same VAT-strip expression written out three separate times (~lines 403-404, 560-561, 658-659) instead of calling `Deal::commissionExVat()`. Confirmed byte-identical to the source formula and to each other in the F7 investigation.

**Proof:** see F7.

**Recommended fix:** replace all three with a call to `Deal::commissionExVat()`. Trivial, no calculation change, no risk. **Safe to fix independently, and cheap enough to bundle with F7's fix.**

---

### F10 — Payroll's commission line has no automated connection to the deal register at all

**Money at risk:** unquantified — this is an absence of a control, not a wrong calculation. Any amount could currently be typed in without contradiction.
**Reaches:** a **real payment** (payroll), but via a manual human step with no system check, not via a wrong calculation.

A payroll earning type "Commission (tax-only)" exists (SARS code 3606, `database/seeders/PayrollEarningTypeSeeder.php:24`), entered through `app/Http/Controllers/Payroll/PayrollEmployeeController.php`/`StaffTakeOnController.php`. No code path was found connecting this to `deals`, `deal_money_lines`, or any of the calculators above — confirmed by grepping every payroll service file for any deal-related reference.

**Proof:** grepped `app/Services/Payroll/*` for `commission`/`DealV2`/`deals_v2`/`Deal::` — no matches beyond an unrelated comment.

**Recommended fix:** this is a product decision, not a bug fix — Johan should decide whether payroll commission SHOULD be automatically fed from the deal register (a real integration project) or whether manual entry with a reconciliation report against `deal_money_lines` is sufficient. **Not a code fix in isolation — needs a scoping decision first.**

---

### F11 — Money calculations use ordinary PHP numbers, not fixed-point arithmetic

**Money at risk:** the same R0.10/R0.03 measured in F7 — this finding is the mechanism behind that one, listed separately because it's a systemic practice, not a single location.
**Reaches:** a **screen only**, as measured.

Every money-bearing database column checked (`deals.total_commission`, `deals_v2.commission_amount`, `deal_user.paye_value`, etc.) is correctly typed `decimal` in the database. But every calculation method read in this audit (`Deal::commissionExVat()`, `CommissionCalculator`, `DealMoneyLineRebuilder`, etc.) casts to native PHP `(float)` and does ordinary floating-point arithmetic, not a fixed-point/decimal math library.

**Proof:** read the `decimal(...)` column definitions via `SHOW COLUMNS`; read the `(float)` casts in every calculation method covered by this audit.

**Recommended fix:** this is a larger, codebase-wide discipline question rather than a single patch — Johan and engineering should decide whether to adopt a fixed-point math approach for all money calculations going forward. **Do not attempt as a quick fix** — given how many places compute money (this audit alone found a dozen), a piecemeal conversion would itself introduce new inconsistencies. Scope as a deliberate, planned pass.

---

### F12 — DR1 and the retired DR2 prototype store price with different precision

**Money at risk:** negligible/none confirmed — `deals.sale_price` does not feed the commission calculation chain directly (commission is captured, not derived from price), and the retired `deals_v2.purchase_price` column belongs to dead code.
**Reaches:** neither — informational only.

`deals.sale_price` is `bigint unsigned` (whole rand only); the retired `deals_v2.purchase_price` is `decimal(14,2)`. Listed for completeness since Johan asked for the full inventory, not because it currently matters.

**Proof:** `SHOW COLUMNS` on both tables.

**Recommended fix:** none needed unless `sale_price` is ever used in a calculation that needs cent precision — if so, that would be a new, separate finding. **No action recommended at this time.**

---

## A note on what "DR2" means in this codebase, for whoever picks this up

Before reading these findings against the code: the live product's "DR2" (`Dr2\DealRegisterController`, routes `deals-dr2.*`) runs on **`App\Models\Deal`, the `deals` table** — the same model DR1 uses. `App\Models\DealV2\DealV2` (`deals_v2` table) is a **retired prototype** — its create/edit/list/show controller actions all redirect immediately (`DealV2Controller::dr2RetiredRedirect()`). It still has 86 old rows reachable through a separate, not-fully-dead settlement/payslip controller, but nothing in this audit's live findings depends on it. Every finding above concerns the real, live `Deal`/`deals` engine.

---

## Findings index (for cross-reference with Jira)

| # | Finding | Money at risk | Reaches | Ticket |
|---|---|---|---|---|
| F1 | Settlement screen doesn't disclose VAT basis | R6,521.74+ per affected deal | Real payment | **AT-407** |
| F2 | Soft-deleted agents dropped from commission aggregation | R49,793.48 live/period + R625,804.43 latent | Real dashboard (live); real settlement engine if reactivated (latent) | **AT-408** |
| F3 | Audit tool's own diagnostic message is wrong | N/A (process) | N/A | **AT-409** |
| F4 | Company Performance silently switches calculators | Same as F2 | Real dashboard | **AT-410** |
| F5 | Branch Performance's two methods disagree on period rule | Not yet quantified | Real dashboard | **AT-411** |
| F6 | Agent Dashboard uses an uncross-checked formula | Not quantified | Real screen | **AT-412** |
| F7 | 3 independent commission-ex-VAT formulas | R0.10 portfolio / R0.03 period | Screen only | **AT-413** |
| F8 | DealMoneyLineRebuilder's silent split-normalization | R0 today, live risk | Real settlement path if triggered | **AT-414** |
| F9 | Worksheet's 3 hand-copied formulas | R0 | Screen only | **AT-415** |
| F10 | Payroll commission has no automated link to deals | Unquantified | Real payment (manual) | **AT-416** |
| F11 | Money math uses float, not fixed-point | Same as F7 | Screen only | **AT-417** |
| F12 | sale_price/purchase_price precision mismatch | Negligible | Neither | **AT-418** |

(AT-403 — the share-percent commission defect behind deal 1818 itself — is cc6's ticket, not duplicated here.)

All 12 tickets are Tasks in the AT project (CoreX Os Web), assigned to Johan Reichel, status **To Do**. Each ticket's description repeats its finding's what/money/reaches/proof/recommended-fix summary and cross-references this document and the other tickets it's coupled with.
