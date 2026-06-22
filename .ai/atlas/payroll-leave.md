# Atlas — Payroll + Leave (HR pillar)

> **Status: DONE** · Last verified: 2026-06-22
> Pillar: **Agent/User** (employee). Tax data = SARS 2026/27. Payroll migrations 2026-04-23, Leave 2026-04-29.
> **Confirms the PAYE duality flagged in `deals-commission.md`** — payroll PAYE ≠ deal-settlement PAYE,
> unreconciled (§4).

---

## 1. WHAT IT DOES

Two HR modules. **Payroll** runs monthly payslips with a SARS PAYE/UIF/SDL engine, earnings/deductions, run
finalisation + PDF + auto-filing. **Leave** is a BCEA-compliant accrual engine (annual/sick/family/parental)
with a ledger-derived balance model, a staff take-on wizard for opening balances, an application approval
flow, 4 reports, and calendar integration. Both feed the calendar; neither is wired to commission/deals.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`)
- **Payroll** `:1701-1779` (`prefix('payroll')`, `permission:manage_payroll`+`agency.required`):
  `earning-types` `:1706`, `deduction-types` `:1708`, `employees` `:1711` (+earnings/deductions/banking
  `:1717-1736`), **runs** (`run_payroll`) `:1738` (cancel `:1740`, payslip show/edit `:1742-1748`, lines
  CRUD `:1749-1759`, recalculate `:1760`, finalise `:1768`, pdf `:1771`, bundle `:1774`), report
  (`view_payroll_reports`) `:1777`.
- **Leave Admin** `:1781-1840` (`prefix('payroll/leave')`, `auth`+`agency.required` — note: nested under
  payroll URL but uses `auth`, not `manage_payroll`): `types` (`manage_leave_types`) `:1786`, `dashboard`
  (`manage_leave`) `:1790`, `balances` `:1794-1808` (adjust → `adjust_leave_balances`), `public-holidays`
  `:1810`, applications approve/reject (`approve_leave`) `:1815-1828`, **4 reports**
  (`view_leave_reports`): register `:1831` (+CSV export `:1834`), branch-summary `:1836`,
  accrual-statement `:1838`, audit-log `:1841`.
- **MyPortal Leave** (self-service) `:1341-1349`; **Staff Take-On wizard** `:1844-1853`.

Controllers: `Payroll/PayrollRunController.php` (`store:117`, `finalise:409`, `recalculatePayslip:727`),
`Payroll/PayrollEmployeeController.php`, `PayrollEarningTypeController`/`PayrollDeductionTypeController`;
`Leave/LeaveApplicationController.php` (`approve:107`, `reject:168`), `LeaveBalanceController`,
`LeaveTypeController`, `LeaveReportController`, `LeaveDashboardController`, `PublicHolidayController`;
`MyPortal/MyPortalLeaveController.php`; `StaffTakeOnController.php`. Nav `corex-sidebar.blade.php`: Payroll
panel `:1051-1080`, Leave Management panel `:1083-1126`.

---

## 3. THE LEAVE MODULE

### BCEA accrual engine — `app/Services/Leave/LeaveAccrualService.php`
- `accrueForEmployee()` `:27` — per type, writes the delta as an `accrual` transaction; **idempotent**
  (derives current accrued from ledger `:53-59`, inserts only when delta>0 `:66`); caps at cycle end `:48`.
- `calculateTargetAccrued()` `:120` by `accrual_method`: `full_at_start` `:130`;
  `accrual_per_day_worked` `:147` (annual leave — BCEA "1 per 17 worked days", capped at entitlement
  `:167-172`); `accrual_first_six_months` `:181` (sick leave BCEA s22(2): first 6mo 1 per 26 worked, then
  full `:189-206`).
- `rolloverCycles()` `:244` — carryover `:291`, forfeiture for non-carryover types `:307`, compliance warning
  when available > 1.5× entitlement `:277`. `manualAdjustment()` `:365`.
- **Crons** (`routes/console.php`): `corex:leave:accrue-daily` 02:00 `:148`, `corex:leave:cycle-rollover`
  02:30 `:149` (separate commands/schedules — `AccrueDailyCommand`, `CycleRolloverCommand`,
  `RecalculateBalancesCommand`).

### Balance derivation — `app/Services/Leave/LeaveBalanceService.php`
`getBalance()` `:19` — **ledger-derived, never trusts cached entitlements** `:16`; sums txn types
`:38-56`; pending = submitted apps' `working_days_requested` `:59-63`;
`available = accrued + carryover − taken − pending` `:68`. `getCurrentCycleStart()` `:136` walks forward
from `employment_date` by `cycle_months` (**anniversary-based, not calendar-year**); `cycle_months=0`
(parental) → no recurring cycle.

### Leave types (defaults) — `database/seeders/LeaveTypeSeeder.php`
annual (15d/12mo, accrual_per_day_worked rate 17, **affects_payroll false**) `:52-73`; sick (30d/36mo,
accrual_first_six_months rate 26) `:81-102`; family_responsibility (3d/12mo, full_at_start) `:110-131`;
parental (130d, cycle 0, none, **affects_payroll true**) `:139-160`; study/unpaid (none, affects_payroll
true); special (paid, none). Model `LeaveType.php`: `entitlementForPattern()` `:92` (6-day vs 5-day),
guarded `delete()` `:103` (blocks system types / types with apps).

### Take-on wizard (opening balances) — `StaffTakeOnController.php`
8 steps `:22`; employment step creates `PayrollEmployee` `:244`; **leave step** `:291-340` runs accrual to
baseline `:310` then records "taken this cycle" as **negative `manual_adjustment`** `:313-323` and carryover
as **positive** `:326-336`.

### Application approval flow
Submit (`MyPortalLeaveController@store`) — working-days via `PublicHolidayService` `:102`, overlap check
`:127`, status `submitted` `:156` (**no reservation txn at submit** — pending derived from query). Approve
(`LeaveApplicationController@approve:107`) — **atomic guarded update** `where status=submitted, decided_at
null` `:110-118` ("first to act wins"), then negative `application_approved` txn `:133`, refresh entitlement
`:149`, create calendar event `:156`, notify `:162`. Reject `:168` — no reversal needed (no reservation).

### The 4 reports — `LeaveReportController.php`
`register():18` (+CSV `:45`; **PDF deferred to Tier 2** `:64`), `branchSummary():70`, `accrualStatement():115`
(running-balance ledger), `auditLog():160` (leave_transactions).

---

## 4. PAYROLL PAYE ENGINE — `app/Services/Payroll/PayrollCalculator.php`

`calculatePayslip()` `:27` — accepts `$preTaxAdjustments` (unpaid-leave) applied before PAYE/UIF `:40-72`;
gathers earnings `:188` + deductions `:216` (auto-statutory skipped unless overridden `:234-237`); computes
taxable/UIF/SDL remuneration `:60-72`; returns `PayslipCalculation` DTO.
**`calculatePaye()` `:255`** — annualise ×12 `:267`; `PayrollTaxTable::forTaxYear()` brackets `:271`;
`PayrollTaxRebate::forTaxYear()` `:278`; age via `User::getAgeOnDate()` (defaults 40 if unknown `:288`);
bracket marginal `:301-319`; rebates primary/secondary(≥65)/tertiary(≥75) `:322-335`; **medical-aid credits
NOT implemented (TODO `:337`)**; floor 0 `:343`; ÷12 monthly `:349`. `calculateUif()` `:358` (cap
17712 × 1%); `calculateSdl()` `:385` (only if `Agency::hasSdlObligation()` `:394`, employer-only).

**Runs:** `PayrollRunController@store:117` per-employee detects unpaid-leave overlap `:185-211`, builds
pre-tax adjustment at daily rate `:203`, persists payslip+lines `:236,261`. `@finalise:409` →
`PayrollFinaliseService::finalise()` — pre-checks (negative net `:33`), locks to `finalised` `:55`,
generates PDFs + `Document` rows `:76`, auto-files to `user_documents` `:97`, caches run totals `:151`.
**Finalise posts NOTHING to commission/deals or to the leave ledger** — confirmed.

---

## 5. THE PAYE DUALITY — CONFIRMED, UNRECONCILED

| | Payroll PAYE | Deal-settlement PAYE |
|---|---|---|
| Computed in | `PayrollCalculator::calculatePaye()` `:255` | `DealMoneyLineRebuilder` `:202-210` (the deal money-line writer) |
| Stored | `payroll_payslips.paye_amount` | `deal_money_lines.paye_amount` |
| Method | SARS annualised bracket tables + rebates + age thresholds | flat per-deal: `percentage` → `agent_gross × paye_value%`, or `fixed` flat (only when `paid_at` set) |
| Driver | monthly salary | commission agent gross |

**Reconciliation: NONE** (grep confirms no commission/deal refs in `app/Services/Payroll`, `Http/Payroll`,
`Models/Payroll`; no payroll refs in `DealMoneyLineRebuilder`/`DealMoneyLine`). An agent earning salary +
commission has **two separate PAYE deductions from two unrelated code paths, never summed for SARS**
(EMP201/IRP5). The calendar emits a single `sars_emp201` reminder (`PayrollCalendarSource.php:64`) but no
code reconciles the two figures behind it. **High audit/compliance risk** — see `deals-commission.md` §6/§8.

---

## 6. CALENDAR INTEGRATION

- **Leave → calendar** `app/Services/Leave/LeaveCalendarService.php`: `createEventForApplication()` `:27`
  on approval (per-category colours; **sick-leave reason hidden** for privacy `:35`; half-day windows;
  `event_type='leave'`, `source_type=LeaveApplication`); `removeEventForApplication()` soft-deletes on
  cancel/reject `:99`. Called from `LeaveApplicationController@approve:156`.
- **Payroll → calendar** `app/Services/CommandCenter/Calendar/Sources/PayrollCalendarSource.php` (7 classes):
  `payroll_run` from `payroll_runs.pay_date` `:41`, `sars_emp201` (7th, next 3 months) `:64`,
  `uif_declaration`/`sdl_submission` `:69-74`, `sars_emp501` (biannual) `:81`, `tax_year_end` (28 Feb) `:101`,
  `irp5_deadline` (31 May) `:119`. Statutory events use synthetic source_ids `crc32(class|date)`,
  `source_type='synthetic:payroll'`, null agency (agency-wide). See `calendar-command-center.md` §6.
  *(No distinct `leave_cycle_end` calendar event — cycle ends handled by the rollover cron.)*

---

## 7. DATA READ/WRITTEN

**Leave** (`2026_04_29_*`): `leave_types` (**SoftDeletes**), `leave_entitlements` (cache, no SoftDeletes),
`leave_applications` (**SoftDeletes**), `leave_application_documents`, **`leave_transactions` (the ledger —
no SoftDeletes, append-only, self-ref FK for reversals)**, `public_holidays` (no SoftDeletes, country-level),
`agency_leave_visibility_matrix`. **Payroll** (`2026_04_23_*`): `payroll_tax_tables`/`payroll_tax_rebates`
(reference, no SoftDeletes), `payroll_earning_types`/`payroll_deduction_types` (**SoftDeletes**),
`payroll_employees` (**SoftDeletes**), `payroll_employee_earnings`/`_deductions` (`current($asOf)` scope,
`override_statutory`), `payroll_runs` (**SoftDeletes**), `payroll_payslips` (**SoftDeletes**;
`paye_amount` decimal(15,2)), `payroll_payslip_lines` (no SoftDeletes).
**Audited:** no `Auditable`/`LogsActivity` trait — auditing is via the append-only `leave_transactions`
ledger (leave) and SoftDeletes + snapshot columns (`employee_name_snapshot`, `code_snapshot`) for payroll.

---

## 8. AGENCY SETTINGS / CONFIG

**No dedicated payroll/leave settings UI** — config is seeded reference data + per-type/per-employee fields.
- **PAYE tax year + thresholds** — `PayrollTaxRebateSeeder.php`: `tax_year_start='2026-03-01'` `:13`,
  primary_rebate 17235 `:15`, thresholds 95750/148217/165689 `:18-20`, uif_ceiling_monthly 17712 `:23`,
  uif_rate 1% `:24`, sdl_threshold_annual 500000 `:25`, sdl_rate 1% `:26`. Brackets
  `PayrollTaxTableSeeder.php:17-23` (SARS 2026/27, 7 brackets, top 45%). `forTaxYear()` resolves the active
  year (`PayrollCalculator.php:271/278`).
- **SDL obligation** — `Agency::hasSdlObligation()` `Agency.php:699-712` (last-12mo finalised run total_gross
  vs sdl_threshold_annual).
- **Public holidays / BCEA** — `PublicHolidayService.php` (10 fixed SA holidays `:112-123`, 2 Easter-based
  `:143-156`, Sunday→Monday roll-forward `:133-141`). BCEA cycle settings are **per-leave-type**
  (`cycle_months`, `accrual_method`, `accrual_rate_per_days`, carryover/forfeit) — NOT agency-global; editable
  via Leave Types CRUD. Working pattern → mask in take-on (mon-fri=31, mon-sat=63) `StaffTakeOnController.php:222-226`.

---

## 9. KNOWN FRAGILITIES

1. **PAYE duality (primary, §5).** Two unreconciled `paye_amount` figures; total PAYE withheld for an agent
   with salary+commission is the sum of two independently-computed numbers that never meet — nothing
   aggregates them for EMP201/IRP5. High audit/compliance risk.
2. **PAYE engine gaps.** Medical-aid tax credits unimplemented (TODO `:337`) despite take-on capturing
   medical aid; unknown age silently defaults to 40 `:288`; `round2()` uses float for the rounding decision
   on bcmath strings `:491-499`.
3. **BCEA accrual edge cases.** Accrual capped at entitlement `:170` (over-cycle accumulation only via
   carryover); sick first-6-months counts from `employment_date` (assumes it's the true start); rollover is
   a separate cron 30 min after accrual `:148-149` — if accrue runs but rollover fails, cycles drift;
   compliance warning hard-coded at 1.5× entitlement `:277`.
4. **Take-on opening balances.** Opening "taken" recorded as a `manual_adjustment` (not the distinct
   `opening_balance` txn type that `getBalance` recognises `LeaveBalanceService.php:39`) — semantic mismatch
   and harder audit trail; zero-delta adjustments swallowed silently `:320-322`; no max-carryover validation.
5. **Leave-payroll integration is partial.** Only `affects_payroll=true` types (parental/study/unpaid) flow
   into payroll as pre-tax deductions `PayrollRunController.php:185-211`; paid leave has no payslip
   representation. The leave→payslip link `:276-278` is set at run `store`; cancel/re-run integrity depends
   on controller logic. PDF leave reports deferred to Tier 2.
6. **Approval concurrency.** Status flip is atomically guarded `:110`, but the post-approval ledger write +
   calendar event are outside that guard (separate transaction `:127`, try/catch `:155`) — a failure after
   the flip leaves an approved app without its deduction txn or calendar event (calendar failure only logged).
7. **Soft-delete asymmetry.** Ledger/cache/reference tables (`leave_transactions`, `leave_entitlements`,
   `public_holidays`, `payroll_payslip_lines`, `payroll_tax_tables/rebates`) lack SoftDeletes — a hard-deleted
   payslip line or tax-table row is unrecoverable. The leave ledger is append-only by design;
   `leave_entitlements` is a rebuildable cache (`corex:leave:recalculate`).
8. **Built vs backlog.** Built: BCEA accrual, take-on wizard, balance/application admin, 4 leave reports
   (CSV), payroll PAYE/UIF/SDL, runs+finalise+PDF+auto-filing, leave & payroll calendar sources, unpaid-leave
   →payroll, daily accrual + rollover crons. Backlog: medical-aid credits, formatted PDF leave reports
   (Tier 2), agency-level payroll/leave settings UI, commission↔payroll PAYE reconciliation.

---

## Key file:line index
- `app/Services/Leave/LeaveAccrualService.php:27-365`, `LeaveBalanceService.php:19-165`, `LeaveCalendarService.php:27-99`.
- `app/Http/Controllers/Leave/LeaveApplicationController.php:107-195`, `LeaveReportController.php:18-160`; `StaffTakeOnController.php:22-340`.
- `app/Services/Payroll/PayrollCalculator.php:27-499`, `PayrollFinaliseService.php:33-151`; `PayrollRunController.php:117-809`.
- `app/Services/DealMoneyLineRebuilder.php:202-210` (the deal-PAYE side of the duality).
- `app/Services/CommandCenter/Calendar/Sources/PayrollCalendarSource.php:29-161`.
- Seeders: `LeaveTypeSeeder.php`, `PayrollTaxTableSeeder.php`, `PayrollTaxRebateSeeder.php`, `PublicHolidaySeeder.php`.
- `routes/console.php:148-149` (leave crons).
