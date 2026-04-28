# CoreX OS — Payroll Module — Tier 1 Spec

**Version:** 1.0
**Author:** Johan Reichel (product), Claude (architecture)
**Status:** Draft — awaiting approval before Prompt A
**Scope:** Tier 1 only — permanent-staff salary payroll. Agent commission stays in existing Commission Ledger.
**Target:** First real 25 May 2026 run through CoreX, replacing the existing Excel payslip workbook.

---

## 1. Purpose

Replace HFC Coastal's existing Excel payslip spreadsheet (`Payslip.xlsx`) with a production-grade payroll module inside CoreX OS. The module must:

1. Maintain a roster of permanent-staff payroll employees (admin picks from the CoreX user list)
2. Hold a per-employee recurring "profile" of earnings and deductions that persists month to month
3. Run a monthly payroll (25th of every month) generating one PDF payslip per active employee
4. Auto-calculate PAYE, UIF, SDL per SARS 2026/27 tables (manual override always available)
5. File each finalised payslip to the employee's document drive
6. Expose a read-only "Payslips" tab on My Portal for staff to view their history
7. Tag every earning and deduction with a SARS BRS source code from day one so Tier 2 (IRP5/IT3(a) generation) is a trivial extension

Commission payslips are **explicitly out of scope**. They live in `commission_ledger` and pay out instantly on deal settlement as they do today. Tier 2 will merge both streams into a unified annual IRP5 per agent — but Tier 1 does not touch commission.

---

## 2. Governing rules

This module follows the CoreX `.ai/STANDARDS.md` without exception:

- All models use `BelongsToAgency` trait + global `AgencyScope`
- All "deletes" are soft deletes (Rule 10) — archive, never destroy
- Every new route has a permission guard
- Every new page has a sidebar link
- Plus Jakarta Sans, teal `#00d4aa`, dark `#0f172a`, 3px border radius, no emojis
- All PAYE/UIF/SDL arithmetic uses `bcmath` — never floating point on money
- All monetary fields stored as `decimal(15, 2)`
- All rates (tax tables, UIF ceiling, SDL threshold) are **seeder data with `effective_from` dates** so the 2027/28 tax year is a data change, not a code change

Branch isolation: payroll respects `BranchScope`. `payroll_employees` has `branch_id`. When Split Branches is ON, a branch manager only sees their own branch's employees. Company-wide users (admin / super_admin) see everyone.

---

## 3. Data model

### 3.1 New tables

#### `payroll_employees`
One row per user on the payroll roster.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | BelongsToAgency |
| `branch_id` | foreignId nullable | BranchScope |
| `user_id` | foreignId | unique within agency — one payroll profile per user |
| `employment_date` | date | Start date |
| `termination_date` | date nullable | Set when offboarded |
| `designation_snapshot` | string | Captured at add time; independent of `users.designation` which may change |
| `pay_frequency` | enum('monthly') | Tier 1 = monthly only |
| `pay_day_of_month` | tinyInt | Default 25 |
| `is_active` | boolean | Default true. False = skip in runs but keep history |
| `notes` | text nullable | |
| `created_by` | foreignId | |
| `created_at/updated_at/deleted_at` | | |

Index: `(agency_id, branch_id, is_active)`.

#### `payroll_earning_types`
Agency-definable earning catalogue. Seeded per-agency with common items + SARS source codes.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | Per-agency catalogue |
| `code` | string(30) | e.g. `basic`, `cell_allowance`, `fuel_allowance`, `bonus`, `overtime` |
| `label` | string | Display name shown on payslip |
| `sars_source_code` | string(4) | e.g. `3601`, `3605`, `3701`, `3713`. Nullable for non-SARS items. |
| `is_taxable` | boolean | Default true |
| `is_fringe_benefit` | boolean | Default false |
| `affects_uif_remuneration` | boolean | Default true (most earnings do) |
| `affects_sdl_remuneration` | boolean | Default true |
| `sort_order` | tinyInt | |
| `is_system` | boolean | System types can't be deleted (e.g. Basic Salary) |
| `is_active` | boolean | |

#### `payroll_deduction_types`
Agency-definable deduction catalogue.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | |
| `code` | string(30) | e.g. `paye`, `uif_employee`, `cellphone_deduction`, `loan_repayment` |
| `label` | string | |
| `sars_source_code` | string(4) nullable | e.g. `4102` PAYE, `4141` UIF employee |
| `is_statutory` | boolean | PAYE, UIF — can't be deleted |
| `is_system` | boolean | |
| `sort_order` | tinyInt | |
| `is_active` | boolean | |

#### `payroll_employee_earnings`
Recurring earnings per employee (the "template" applied to every future run).

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | |
| `payroll_employee_id` | foreignId | |
| `earning_type_id` | foreignId | |
| `amount` | decimal(15,2) | Zero allowed (e.g. bonus = 0 default, edited per run) |
| `effective_from` | date | |
| `effective_to` | date nullable | For historical changes — old row closed off, new row inserted |
| `notes` | string nullable | |
| `created_by` / timestamps / soft deletes | | |

#### `payroll_employee_deductions`
Recurring deductions per employee.

Same structure as `payroll_employee_earnings` but references `deduction_type_id`. Includes a `override_statutory` boolean for cases where admin wants to set a fixed PAYE/UIF amount manually on a per-employee basis (rare but needed for edge cases).

#### `payroll_runs`
One per pay period.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | |
| `run_number` | string | Format `YYYYMM-seq` e.g. `202605-001` |
| `period_month` | date | First of the month (e.g. `2026-05-01`) |
| `pay_date` | date | Usually 25th of the period month |
| `status` | enum | `draft`, `finalised`, `cancelled` |
| `finalised_at` | datetime nullable | |
| `finalised_by` | foreignId nullable | |
| `cancelled_at` | datetime nullable | |
| `cancelled_by` | foreignId nullable | |
| `cancellation_reason` | text nullable | |
| `payslip_count` | int | Cached count after finalise |
| `total_gross` | decimal(15,2) | Cached after finalise |
| `total_paye` | decimal(15,2) | |
| `total_uif_employee` | decimal(15,2) | |
| `total_uif_employer` | decimal(15,2) | |
| `total_sdl` | decimal(15,2) | |
| `total_net` | decimal(15,2) | |
| `notes` | text nullable | |
| `created_by` / timestamps / soft deletes | | |

Unique index: `(agency_id, period_month)` — one run per month per agency.

#### `payroll_payslips`
Immutable snapshot per employee per run.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `agency_id` | foreignId | |
| `branch_id` | foreignId nullable | Snapshot of employee's branch at run time |
| `payroll_run_id` | foreignId | |
| `payroll_employee_id` | foreignId | |
| `user_id` | foreignId | Denormalised for easy self-service query |
| `payslip_number` | string | Unique, format `{agency_code}-{YYYYMM}-{seq}` e.g. `HFC-202605-001` |
| `employee_name_snapshot` | string | Captured at finalise |
| `id_number_snapshot` | string nullable | |
| `tax_reference_snapshot` | string nullable | |
| `employment_date_snapshot` | date | |
| `designation_snapshot` | string | |
| `period_month` | date | |
| `pay_date` | date | |
| `total_earnings` | decimal(15,2) | |
| `total_deductions` | decimal(15,2) | |
| `taxable_income` | decimal(15,2) | |
| `paye_amount` | decimal(15,2) | |
| `uif_employee_amount` | decimal(15,2) | |
| `uif_employer_amount` | decimal(15,2) | |
| `sdl_amount` | decimal(15,2) | |
| `net_pay` | decimal(15,2) | |
| `document_id` | foreignId nullable | Link to `documents` table after PDF generation |
| `pdf_generated_at` | datetime nullable | |
| `notes` | text nullable | Per-payslip note, e.g. "Adjusted for mid-month start" |
| timestamps / soft deletes | | No update after finalise — snapshots are immutable |

Unique: `(payroll_run_id, payroll_employee_id)`. Index: `(user_id, period_month)` for self-service.

#### `payroll_payslip_lines`
Immutable snapshot of every earning and deduction on the payslip. Critical for reporting and Tier 2 IRP5.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `payroll_payslip_id` | foreignId | |
| `line_type` | enum('earning','deduction','employer_contribution') | |
| `source_type_id` | foreignId | FK to `payroll_earning_types` or `payroll_deduction_types` depending on line_type |
| `code_snapshot` | string(30) | |
| `label_snapshot` | string | |
| `sars_source_code_snapshot` | string(4) nullable | |
| `amount` | decimal(15,2) | |
| `is_taxable_snapshot` | boolean | |
| `sort_order` | tinyInt | |

Employer contributions (employer UIF, SDL) are stored as lines with `line_type='employer_contribution'` so they appear on the payslip footer without affecting employee net.

#### `payroll_tax_tables`
SARS PAYE brackets by tax year. Seeded.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `tax_year_start` | date | e.g. `2026-03-01` |
| `tax_year_end` | date | e.g. `2027-02-28` |
| `bracket_order` | tinyInt | 1–7 for SA's 7 brackets |
| `income_from` | decimal(15,2) | Annual |
| `income_to` | decimal(15,2) nullable | Null on highest bracket |
| `base_tax` | decimal(15,2) | Cumulative tax at start of bracket |
| `rate_percent` | decimal(5,2) | e.g. `18.00`, `26.00`, `45.00` |

#### `payroll_tax_rebates`
Age-based rebates per tax year. Seeded.

| Column | Type | Notes |
|---|---|---|
| `tax_year_start` | date | |
| `primary_rebate` | decimal(15,2) | Under 65 |
| `secondary_rebate` | decimal(15,2) | 65–74 additional |
| `tertiary_rebate` | decimal(15,2) | 75+ additional |
| `tax_threshold_under_65` | decimal(15,2) | |
| `tax_threshold_65_74` | decimal(15,2) | |
| `tax_threshold_75_plus` | decimal(15,2) | |
| `medical_credit_main` | decimal(10,2) | Section 6A — main member + first dependent |
| `medical_credit_additional` | decimal(10,2) | Section 6A — each additional dependent |
| `uif_ceiling_monthly` | decimal(15,2) | e.g. `17712.00` |
| `uif_rate_percent` | decimal(5,3) | `1.000` |
| `sdl_threshold_annual` | decimal(15,2) | `500000.00` |
| `sdl_rate_percent` | decimal(5,3) | `1.000` |

### 3.2 Modifications to existing tables

#### `users` — add columns
```sql
ALTER TABLE users
  ADD COLUMN employment_date DATE NULL AFTER designation,
  ADD COLUMN tax_reference_number VARCHAR(20) NULL AFTER id_number,
  ADD COLUMN date_of_birth DATE NULL AFTER id_number;
```

Note: `date_of_birth` is used for PAYE age rebate calculation. We can derive it from `id_number` (first 6 digits YYMMDD) in a backfill migration with manual override available.

#### `user_banking_details` — new table
```
id, user_id (unique), agency_id, account_holder, bank_name, branch_code,
account_number, account_type (enum: cheque, savings, transmission),
is_primary (boolean, default true — supports future multi-account split),
verified_at, verified_by, created_at, updated_at, deleted_at
```

Separate table because banking details are sensitive and need independent access control from user profile editing.

#### `agencies` — add columns
```sql
ALTER TABLE agencies
  ADD COLUMN paye_registration_no VARCHAR(20) NULL,
  ADD COLUMN uif_employer_no VARCHAR(20) NULL,
  ADD COLUMN sdl_registration_no VARCHAR(20) NULL,
  ADD COLUMN employer_bank_name VARCHAR(100) NULL,
  ADD COLUMN employer_bank_account VARCHAR(30) NULL,
  ADD COLUMN employer_bank_branch_code VARCHAR(10) NULL;
```

#### `user_documents` — extend enum
Add `payslip` to the `document_type` enum. Existing auto-file pattern from `SignatureService` then handles filing cleanly.

---

## 4. Calculation service

### 4.1 `App\Services\Payroll\PayrollCalculator`

Single source of truth for all payroll math. Uses `bcmath` with scale 2 for money, scale 4 for rate work.

Public methods:

```php
calculatePayslip(PayrollEmployee $employee, Carbon $periodMonth): PayslipCalculation
```

Returns a DTO with:
- `earnings[]` — array of `['type_id', 'amount', 'is_taxable']`
- `deductions[]` — array including auto-calculated PAYE, UIF, SDL
- `taxable_income`
- `paye_amount`
- `uif_employee`
- `uif_employer`
- `sdl_amount`
- `net_pay`

Behaviour:

1. **Gather earnings** from `payroll_employee_earnings` where current at period date
2. **Compute taxable income** = sum of earnings where `is_taxable = true`
3. **PAYE calculation**:
   - Annualise taxable income × 12
   - Look up bracket from `payroll_tax_tables` for current tax year
   - Compute annual tax = `base_tax + (income - income_from) × rate`
   - Subtract age-based rebate (derive age from `date_of_birth` or `id_number`)
   - Subtract medical aid credits if `medical_aid_dependents > 0` on the profile (future field — Tier 1 leaves this nullable, defaults to 0)
   - Divide by 12 for monthly PAYE
   - If result is negative or below threshold, PAYE = 0
4. **UIF**:
   - Employee: `min(remuneration, uif_ceiling) × 1%`
   - Employer: same. Stored separately as employer contribution line.
   - Respects `override_statutory` on `payroll_employee_deductions` — if admin has hard-coded a UIF override, use that instead
5. **SDL**:
   - Employer-only. `remuneration × 1%`
   - Agency-level toggle: if agency's total annual remuneration < R500k, SDL = 0. Computed against last 12 months of finalised runs.
6. **Statutory override** — if `override_statutory = true` on any deduction line, use that amount verbatim instead of calculated
7. **Net pay** = total earnings − total deductions

### 4.2 Edge cases handled in Tier 1

- **Mid-month starters / leavers**: Tier 1 does NOT pro-rata. Admin edits the specific run's payslip to adjust. Pro-rata engine is Tier 2.
- **Zero earnings**: Generates payslip with zero gross, zero PAYE. Useful for maternity leave tracking before Tier 2's leave module exists.
- **Negative net pay**: Validation blocks finalise. Admin must resolve.
- **Date of birth missing**: Log warning, assume under-65 for PAYE (conservative — employee pays more tax than may be due, which they reclaim annually).
- **No tax reference**: Log warning, still generate payslip. Tier 2 ITREG bulk-registration will solve. Payslip PDF will show `[Pending SARS registration]`.

---

## 5. Routes & controllers

All under `/corex/payroll` prefix. Permission-gated.

### 5.1 Admin controllers

#### `PayrollEmployeeController`
```
GET    /corex/payroll/employees                  index     (list all active + inactive)
GET    /corex/payroll/employees/create           create    (user picker + profile form)
POST   /corex/payroll/employees                  store
GET    /corex/payroll/employees/{id}             show      (profile + history)
GET    /corex/payroll/employees/{id}/edit        edit
PATCH  /corex/payroll/employees/{id}             update
POST   /corex/payroll/employees/{id}/deactivate  deactivate
POST   /corex/payroll/employees/{id}/reactivate  reactivate
DELETE /corex/payroll/employees/{id}             destroy   (soft delete)
```

#### `PayrollEarningTypeController`
Standard CRUD. Seeded system types can't be destroyed, only deactivated.

#### `PayrollDeductionTypeController`
Same. Statutory types (PAYE, UIF) have name + SARS code locked.

#### `PayrollRunController`
```
GET    /corex/payroll/runs                       index
GET    /corex/payroll/runs/create                create    (month picker + active-employees preview)
POST   /corex/payroll/runs                       store     (creates run + draft payslips)
GET    /corex/payroll/runs/{id}                  show      (payslip list + totals + finalise button)
GET    /corex/payroll/runs/{id}/payslip/{pid}    payslipShow  (individual payslip preview)
PATCH  /corex/payroll/runs/{id}/payslip/{pid}    payslipUpdate  (edit lines while run is draft)
POST   /corex/payroll/runs/{id}/payslip/{pid}/recalculate  recalculate  (after line edits)
POST   /corex/payroll/runs/{id}/finalise         finalise  (irreversible — generates PDFs, auto-files)
POST   /corex/payroll/runs/{id}/cancel           cancel    (only if not finalised)
GET    /corex/payroll/runs/{id}/pdf/{pid}        payslipPdf  (stream PDF)
GET    /corex/payroll/runs/{id}/bundle           bundlePdf   (all payslips for the run as zip)
```

### 5.2 My Portal controller extension

Add method to existing `AgentPortalController`:

```
GET /corex/my-portal/payslips                    myPayslips  (list own)
GET /corex/my-portal/payslips/{pid}              myPayslipShow
GET /corex/my-portal/payslips/{pid}/pdf          myPayslipPdf
```

Permission: `view_own_payslips` (new, default for all authenticated roles).

---

## 6. Permissions

Add to `config/corex-permissions.php` following the established pattern:

```php
['key' => 'manage_payroll',         'label' => 'Manage Payroll (employees, types)', 'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 120],
['key' => 'run_payroll',            'label' => 'Run & Finalise Payroll',            'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 121],
['key' => 'view_payroll_reports',   'label' => 'View Payroll Reports',              'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 122],
['key' => 'view_own_payslips',      'label' => 'View Own Payslips',                 'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 123],
```

**Default role assignments:**
- `super_admin`: all (implicit)
- `admin`: `manage_payroll`, `run_payroll`, `view_payroll_reports`, `view_own_payslips`
- `branch_manager`: `view_own_payslips` only
- `agent`: `view_own_payslips` only
- `viewer`: none
- `office_admin`: `view_own_payslips` only (admin can grant `manage_payroll` manually via role manager per person if needed)

---

## 7. Views & UX

All views conform to STANDARDS.md: Plus Jakarta Sans, teal `#00d4aa`, dark `#0f172a`, 3px radius, no emojis, sticky headers, AJAX saves, no horizontal scroll at 1280px.

### 7.1 Payroll Employees list (`payroll/employees/index.blade.php`)

Sticky header with search + status filter (all / active / inactive / terminated) + `[+ Add Employee]` button.

Table columns:
- Name (with photo avatar + designation subtitle)
- Branch
- Basic Salary (from current `basic` earning row)
- Employment Date
- Status pill (Active / Inactive / Terminated)
- Actions (View / Edit / Deactivate)

Empty state: "No payroll employees yet. Add someone from your user list to get started."

### 7.2 Add Employee (`create.blade.php`)

Two-step form:

**Step 1: Pick user**
- Search box filtering user list (all users in agency, excluding those already on payroll)
- User card shows name, role, designation, branch, start-date-on-system (if known)

**Step 2: Payroll profile**
- Employment date (defaults to today, editable)
- Designation (prefilled from `users.designation`, editable → saved as snapshot)
- Date of birth (prefilled from ID number if present, editable)
- Tax reference number (text, optional — validates format if provided)
- Banking details section (expandable, optional):
  - Account holder name (defaults to user full name)
  - Bank (dropdown of SA banks), branch code, account number, account type
- Default earnings table (prefilled with Basic Salary at R0, admin edits):
  - Add row button shows dropdown of earning types
- Default deductions table (prefilled with PAYE auto, UIF auto)
- Notes textarea

Save button creates the `payroll_employees` row plus initial earning/deduction rows dated today.

### 7.3 Employee profile (`show.blade.php`)

Two-column layout:

**Left:** Employee summary card, banking card, edit button.

**Right:** Tabs:
- **Current setup** — editable earnings + deductions tables, "Save" creates new effective-dated rows (closes old ones)
- **History** — list of all payslips for this employee, clickable to view
- **Audit log** — who changed what when

### 7.4 Payroll Runs list (`runs/index.blade.php`)

Table: Run number, Period month, Pay date, Status pill, Payslip count, Total net, Finalised by, Finalised at, Actions.

`[+ New Run]` button opens run creation.

### 7.5 New run (`runs/create.blade.php`)

- Period month picker (defaults to current month; validates no existing run)
- Pay date (defaults to 25th of period)
- Checkbox list of active employees (all ticked by default — untick to skip)
- Preview panel showing projected totals (gross, PAYE, UIF, SDL, net)
- `[Create Draft Run]` button

On create, system generates draft payslips for each selected employee by running `PayrollCalculator` against each.

### 7.6 Run detail (`runs/show.blade.php`)

Sticky header: `Run {number} — {month}` + status pill + `[Finalise]` + `[Cancel]` buttons.

Summary card: run totals (gross, PAYE, UIF emp+er, SDL, net, headcount).

Payslip list table:
- Employee name
- Gross
- PAYE
- UIF
- Net pay
- Status (`Draft`, `Edited`, `Ready`)
- Actions (View / Edit)

Finalise button:
1. Validates all payslips have net ≥ 0
2. Generates PDF per payslip (via Puppeteer)
3. Creates `Document` row with `source_type='payroll'`, links to user via `user_documents` with `document_type='payslip'`
4. Updates `payslips.document_id` and `pdf_generated_at`
5. Sets run `status='finalised'`, caches totals
6. Run becomes read-only from this point

### 7.7 Payslip edit (`payslips/edit.blade.php`)

Only editable while run is `draft`. Opens modal or dedicated edit page with:

- Employee header (read-only)
- Earnings table: inline edit amounts, add/remove lines (from active earning types), AJAX save per change
- Deductions table: same. PAYE and UIF rows show an "Override" toggle that reveals a manual amount input.
- Running totals recompute live via `PayrollCalculator` on each save
- Per-payslip note field
- `[Recalculate from profile]` button to discard edits and re-pull from employee's current earnings/deductions template

### 7.8 My Portal — Payslips tab

New 7th tab on agent portal. Content:

- List of own payslips, most recent first
- Columns: Period, Pay date, Gross, Net, PDF button
- Click row → opens payslip preview (same template as PDF but HTML)
- `[Download PDF]` button on each

---

## 8. PDF template

Layout matches `Payslip.xlsx > Salary Payslip Print` sheet. Dynamic agency header (not hard-coded to HFC) so multi-tenant-ready.

### 8.1 Structure

```
┌────────────────────────────────────────────────────────────┐
│ {agency.trading_name}                                      │
│ Reg: {agency.reg_no} | VAT: {agency.vat_no}                │
│ PAYE: {agency.paye_registration_no} | UIF: {agency.uif}    │
│ {agency.address}                                           │
│ {agency.phone} | {agency.email}                            │
├────────────────────────────────────────────────────────────┤
│ PAYSLIP {payslip_number}              Pay Date: {pay_date} │
│                                       Period: {period}    │
├────────────────────────────────────────────────────────────┤
│ Employee:         {name_snapshot}                          │
│ ID:               {id_number_snapshot}                     │
│ Tax Ref:          {tax_reference_snapshot}                 │
│ Designation:      {designation_snapshot}                   │
│ Employed:         {employment_date_snapshot}               │
│ Banking:          {bank} *{last_4_digits}                  │
├────────────────────────────────────────────────────────────┤
│ EARNINGS                                          Amount   │
│ Basic Salary                                    R 16,926.00│
│ Cell Allowance                                    R 500.00 │
│ Overtime — Dec bonus                            R  2,000.00│
│                                                  ─────────  │
│ Total Earnings                                  R 19,426.00│
├────────────────────────────────────────────────────────────┤
│ DEDUCTIONS                                         Amount  │
│ PAYE                                             R 2,347.00│
│ UIF                                                R 177.12│
│ Cellphone                                          R 250.00│
│                                                  ─────────  │
│ Total Deductions                                 R 2,774.12│
├────────────────────────────────────────────────────────────┤
│ NET PAY                                         R 16,651.88│
├────────────────────────────────────────────────────────────┤
│ Employer contributions (not deducted from you):            │
│ UIF (employer)                                     R 177.12│
│ SDL                                                R 194.26│
├────────────────────────────────────────────────────────────┤
│ YTD (current tax year)                                     │
│ Taxable income: R ... | PAYE paid: R ... | UIF: R ...      │
└────────────────────────────────────────────────────────────┘
Generated: {timestamp} | Verification: {verification_hash}
```

### 8.2 Generation pipeline

Follow the proven RMCP receipt pattern:

1. Render Blade view to HTML
2. Write HTML to `storage/app/temp/payslip-{id}-{hash}.html`
3. Invoke `scripts/html-to-pdf.mjs` with input + output paths
4. Read PDF, move to permanent `storage/app/payslips/{year}/{month}/{payslip_number}.pdf`
5. Create `Document` row + `user_documents` pivot
6. Clean up temp HTML

### 8.3 Verification hash

SHA256 of `{payslip_number}|{net_pay}|{finalised_at}|{agency_id}` — printed on payslip for manual verification against the DB if questioned.

---

## 9. Sidebar placement

Edit `resources/views/layouts/corex-sidebar.blade.php`. Insert new "Payroll" group after "Finance Engine" in the Admin section (audit confirmed line 914–1032).

```blade
@permission('manage_payroll|run_payroll|view_payroll_reports|view_own_payslips')
<li>
    <button x-data="{ open: {{ request()->routeIs('payroll.*') ? 'true' : 'false' }} }"
            @click="open = !open">
        {{-- Payroll icon (wallet/receipt) --}}
        <span>Payroll</span>
    </button>
    <ul x-show="open">
        @permission('manage_payroll')
        <li><a href="{{ route('payroll.employees.index') }}">Employees</a></li>
        <li><a href="{{ route('payroll.earning-types.index') }}">Earning Types</a></li>
        <li><a href="{{ route('payroll.deduction-types.index') }}">Deduction Types</a></li>
        @endpermission
        @permission('run_payroll')
        <li><a href="{{ route('payroll.runs.index') }}">Runs</a></li>
        @endpermission
    </ul>
</li>
@endpermission
```

My Portal "Payslips" tab added to existing agent portal tab loop (spec section 5.2).

---

## 10. Seeders

### 10.1 `PayrollTaxTableSeeder`
Seeds `payroll_tax_tables` with SA 2026/27 brackets (tax year 1 Mar 2026 – 28 Feb 2027):

| Bracket | Income from | Income to | Base tax | Rate |
|---|---|---|---|---|
| 1 | 1 | 237,100 | 0 | 18% |
| 2 | 237,101 | 370,500 | 42,678 | 26% |
| 3 | 370,501 | 512,800 | 77,362 | 31% |
| 4 | 512,801 | 673,000 | 121,475 | 36% |
| 5 | 673,001 | 857,900 | 179,147 | 39% |
| 6 | 857,901 | 1,817,000 | 251,258 | 41% |
| 7 | 1,817,001 | null | 644,489 | 45% |

### 10.2 `PayrollTaxRebateSeeder`

2026/27 values:
- Primary rebate: R17,235
- Secondary rebate: R9,444
- Tertiary rebate: R3,145
- Tax threshold under 65: R95,750
- Tax threshold 65–74: R148,217
- Tax threshold 75+: R165,689
- Medical credit main: R364
- Medical credit additional: R246
- UIF ceiling monthly: R17,712
- UIF rate: 1.000%
- SDL threshold annual: R500,000
- SDL rate: 1.000%

**Important:** These seeders must be safe to re-run and must insert with `effective_from` dating. When 2027/28 rates are announced in Feb 2027, we add a new row with `tax_year_start = 2027-03-01`, no code changes.

### 10.3 `PayrollEarningTypeSeeder`
Per-agency on agency creation (or via a "seed defaults for this agency" action). Seed set:

| Code | Label | SARS | Taxable | FB | UIF | SDL | System |
|---|---|---|---|---|---|---|---|
| `basic` | Basic Salary | 3601 | Y | N | Y | Y | Y |
| `bonus` | Bonus | 3605 | Y | N | Y | Y | N |
| `overtime` | Overtime | 3607 | Y | N | Y | Y | N |
| `cell_allowance` | Cell Allowance | 3713 | Y | N | Y | Y | N |
| `fuel_allowance` | Fuel Allowance | 3713 | Y | N | Y | Y | N |
| `travel_allowance_fixed` | Travel Allowance | 3701 | Y | N | Y | Y | N |
| `travel_reimbursive` | Reimbursive Travel | 3703 | N | N | N | N | N |
| `subsistence` | Subsistence | 3714 | N | N | N | N | N |
| `commission_earnings` | Commission (tax-only) | 3606 | Y | N | Y | Y | Y |

The `commission_earnings` line is how Tier 2 will pull commission ledger entries onto a combined IRP5. For Tier 1 it sits unused on the seed list.

### 10.4 `PayrollDeductionTypeSeeder`

| Code | Label | SARS | Statutory | System |
|---|---|---|---|---|
| `paye` | PAYE | 4102 | Y | Y |
| `uif_employee` | UIF | 4141 | Y | Y |
| `cellphone_deduction` | Cellphone | — | N | N |
| `loan_repayment` | Loan Repayment | — | N | N |
| `garnishee` | Garnishee Order | — | N | N |

---

## 11. Build sequence

Fourteen prompts, executed in order. Each prompt must pass `dev-check.ps1` with zero new failures and each prompt's tinker verification before the next runs.

### Prompt A — Data model migrations
Create all tables in section 3.1 + column additions in 3.2. Run migrations, confirm schema via tinker column listing.

### Prompt B — Models + traits + scopes
Eloquent models for all tables, `BelongsToAgency` trait applied, `BranchScope` applied where appropriate, relationships wired, fillable guards, soft deletes, casts. Tinker: create test rows and query them.

### Prompt C — Seeders
Tax tables, rebates, earning types, deduction types. Run seeders, tinker-verify data present.

### Prompt D — Permissions + sidebar
Add 4 permissions to `corex-permissions.php`, run `corex:sync-permissions --merge-defaults`, add sidebar "Payroll" group. Tinker: confirm permissions synced, load sidebar as admin user.

### Prompt E — `PayrollCalculator` service + unit tests
Build the calculation service. Write tinker-runnable test scenarios covering:
- Under-65 employee, R20k basic, no other earnings → PAYE X, UIF 177.12, SDL 200
- Over-65 employee, same → lower PAYE (secondary rebate applied)
- Employee with R25k basic + R5k fringe → PAYE on R30k, UIF capped
- Manual PAYE override → calculated value ignored, override used
- Zero earnings → all zeros, no errors

### Prompt F — Earning/Deduction type CRUD
Controllers + views for earning types and deduction types. Admin can add agency-custom types. System types show locked. Full STANDARDS.md compliance.

### Prompt G — Payroll Employee CRUD
`PayrollEmployeeController` + list view + create wizard (two-step) + edit + deactivate/reactivate. Banking details sub-form. Tinker-create an employee end-to-end.

### Prompt H — Run creation + draft payslip generation
`PayrollRunController::store` creates the run row, then iterates active employees and generates draft payslips via `PayrollCalculator`. Run detail page shows the draft payslip list with totals.

### Prompt I — Payslip edit + recalculate
Edit view for draft payslips. Line-level edits, add/remove lines, manual overrides. Live recalc via AJAX. Save path preserves history.

### Prompt J — PDF template + generation
Build the Blade payslip template matching the layout in section 8.1. Wire Puppeteer pipeline. Tinker-generate a PDF for one payslip, verify file written.

### Prompt K — Finalise workflow
`PayrollRunController::finalise` locks the run, batch-generates PDFs, creates `Document` rows, files to `user_documents` with `document_type='payslip'`, updates cached totals. Test end-to-end on a 2-employee run.

### Prompt L — My Portal Payslips tab
Add 7th tab to agent portal. Controller method, view, read-only list + individual payslip view + PDF download. Permission-gated.

### Prompt M — Bundle PDF + admin reports
`bundlePdf` method generates zip of all payslips in a run. Basic "Run summary" report (gross/PAYE/UIF/SDL totals per run, per-employee breakdown) for admin view. Foundation for Tier 2 EMP201.

### Prompt N — End-to-end test + STANDARDS.md review
Full run-through: Add 3 test employees, create May 2026 run, edit one payslip, finalise, verify PDFs auto-filed, verify My Portal shows them, verify cancelled run doesn't leak. Visual pass at 1280px and 1440px. Screenshot for approval.

---

## 12. Acceptance criteria

Before marking Tier 1 done:

- [ ] All 8 new tables created with correct indexes + FKs + soft deletes
- [ ] `users`, `agencies`, `user_documents` extensions applied
- [ ] All seeders run clean on fresh DB and are idempotent
- [ ] `PayrollCalculator` produces values matching SARS's own PAYE calculator (±R1 rounding) for 5 test scenarios
- [ ] UIF caps correctly at R17,712 remuneration
- [ ] SDL auto-skips for agencies under R500k annual
- [ ] Earning/Deduction type CRUD follows STANDARDS.md
- [ ] Employee CRUD follows STANDARDS.md
- [ ] Draft payslip line edits persist and recalculation is correct
- [ ] Finalise is irreversible and produces PDF + Document row + user_documents pivot per payslip
- [ ] PDFs match the section 8.1 layout and pull agency header dynamically
- [ ] My Portal Payslips tab shows own payslips only (BranchScope + ownership check)
- [ ] Sidebar nav present, permission-gated correctly
- [ ] No horizontal scroll at 1280px on any new view
- [ ] `scripts/dev-check.ps1` passes with 0 new failures
- [ ] Tinker end-to-end test from section N succeeds
- [ ] Running the actual 25 May 2026 payroll against HFC's 9 active staff produces correct numbers (verified by Johan + Elize against current spreadsheet method — one parallel run)

---

## 13. Deliberately deferred (Tier 2+)

Logged so we don't forget:

- **IRP5 / IT3(a) CSV export** for e@syFile import (SARS BRS v24 format)
- **EMP201 monthly** auto-submission data
- **EMP501 bi-annual** reconciliation
- **Bank EFT file export** for all SA banks (BankServ ACB, FNB Enterprise CSV, Standard, ABSA, Nedbank, Capitec, Investec)
- **Leave management** (BCEA-compliant: 21 annual, 30 sick per 36-month, 3 family resp, 4 months maternity, 10 days parental post ConCourt Oct 2025)
- **Pro-rata** calculation for mid-month starters/leavers
- **Unified commission + salary IRP5** per agent per tax year
- **Medical aid scheme integration** (Discovery, Momentum, etc.) — dependents field + Section 6A/6B
- **Retirement fund contributions** with 27.5% / R350k cap
- **ETI** — skipped per Johan's decision (candidate practitioners not on basic)
- **EEA2/EEA4** employment equity reports
- **COIDA** annual W.As.8 return of earnings
- **Pipeline-aware cash flow** showing projected payroll liability from deal pipeline
- **Compliance gating** — block agent commission if FFC expired / RMCP unacknowledged / FICA outstanding
- **Earned wage access** partner integration
- **WhatsApp payslip delivery**
- **Accounting journal export** to Xero / Sage / QuickBooks
- **Directors' remuneration** (Sec 7B)
- **Payroll analytics** dashboards

---

## 14. Risks & mitigations

| Risk | Mitigation |
|---|---|
| SARS PAYE calc rounding disagrees with SARS's own calculator | Test against SARS calculator output during Prompt E; accept ±R1 tolerance; document in code comments |
| Puppeteer PDF generation fails at scale (e.g. 50+ employees) | Queue PDF generation as a job in Tier 1 if batch size exceeds 20. Tier 1 target is 9-20 employees so synchronous is fine. |
| Employee has no ID number → can't derive age → PAYE miscalc | Employee profile form requires date_of_birth (auto-filled from ID if present); if both missing, payslip generation blocks with clear error |
| Admin edits employee earnings after run finalised expecting retroactive change | Spec is clear: finalised runs are immutable. New month picks up the change. Document this in admin help text. |
| A user has two payroll employee records accidentally | Unique index `(agency_id, user_id)` on `payroll_employees` prevents it. |
| Banking details leaked in logs/errors | `user_banking_details.account_number` masked in toString/toArray (except last 4). Not logged in exceptions. |
| Tax table outdated for next financial year | Seeder uses `effective_from` dating; new tax year = new seeder row. Documented in STANDARDS note and `.ai/CODEBASE_MAP.md`. |

---

## 15. Open questions

None. All product decisions captured per conversation of 23 April 2026.

**Commission model confirmed (section 1):** Commission stays in `commission_ledger`, instant payout on deal settlement. Tier 2 merges commission + salary into one annual IRP5 per agent. Tier 1 does not touch commission.

**Bank EFT deferred (section 13):** Tier 1 captures banking details on employee profile but does not generate bank files. Tier 2+.

**Leave deferred (section 13):** Data model has no leave hooks in Tier 1. Tier 2 adds `payroll_leave_accrual` and `payroll_leave_requests` without breaking existing schema.

**Backfill:** Tier 1 starts fresh at 1 March 2026 (new tax year). Pre-March 2026 payslips stay in Excel. No import needed.

---

## 16. Approval

This spec is frozen once Johan approves. Any scope change during the build requires a spec addendum and explicit re-approval. No surprises, no creep.

- [ ] **Johan approval:** _______________  Date: _________
- [ ] **Claude confirmation received:** _______________  Date: _________

Upon approval, execution begins with Prompt A.
