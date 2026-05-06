# CoreX OS — Leave Management Module Tier 1 Spec
> Single source of truth for all leave development.
> Save to: `.ai/specs/leave-tier-1-spec.md`
> Version: 1.0 — 2026-04-28
> Frozen on approval. Addendum required for any scope change.

---

## 1. Purpose & Scope

Build a comprehensive, BCEA-compliant leave management module that:

- Captures complete leave history including opening balances on take-on
- Enforces all SA statutory leave entitlements correctly
- Provides agent self-service application + BM/admin approval
- Integrates with payroll for unpaid leave deduction + leave balance display
- Integrates with the calendar for team-wide visibility
- Maintains an immutable audit trail for every transaction
- Handles public holidays automatically per BCEA s18

Stands up to any audit, any inspector, any CCMA dispute. World-class.

### Tier 1 IN scope
- All BCEA leave types (annual, sick, family responsibility, parental, study, unpaid)
- Working day calculation excluding weekends + public holidays
- Per-employee leave cycle anniversaries (BCEA-correct)
- Accrual engine (annual leave 1 day per 17 worked + sick leave first-6-months 1 per 26)
- Carry-over with no auto-forfeit (compliance warning if > 1 cycle accrued)
- Public holiday calendar (13 SA holidays seeded annually)
- Application workflow with BM OR admin approval (first to act wins)
- Document upload (medical certs, supporting docs)
- Calendar integration (approved leave events)
- Payroll integration (unpaid leave reduces gross, balances on payslip)
- Termination payout calculation
- Branch-scoped visibility (BMs see own branch only)
- Staff Take-On wizard for new hires (full onboarding flow, not just leave)
- Comprehensive audit log
- Reports: leave register, branch summary, accrual statement

### Tier 1 OUT of scope (deferred to Tier 2+)
- Multi-level approval workflows beyond BM-or-admin
- Leave forfeiture automation (manual admin task only)
- Time-of-day partial leave (half-day handled, hour-by-hour deferred)
- IOD/COIDA integration
- Long-service leave bonus accrual
- Custom leave type creation (admin can only enable/disable from BCEA-defined list in Tier 1)
- Leave cash-out before termination (paid-in-lieu while still employed)
- Public holiday substitution rules per Sectoral Determination 9 (real estate)

---

## 2. Architectural Principles

### 2.1 BCEA-correct by default, agency-configurable upward
The system enforces BCEA minimums. Agencies can be MORE generous (more days, faster accrual, generous mode for sick leave) but never less. Every override is an explicit toggle with audit trail.

### 2.2 Immutable transaction log
Every balance change is a `leave_transaction` record. Balances are derived by summing transactions, never stored as a "current balance" cache that can drift. (Optional cached balance for performance, refreshed from transactions, with reconciliation check.)

### 2.3 Separation of entitlement vs balance vs taken
- **Entitlement** — what the employee is allowed in this cycle (e.g., 15 working days annual leave)
- **Accrued** — what they've earned so far (e.g., 8 days as at today)
- **Available** — accrued minus taken (e.g., 6 days available)
- **Taken** — what's been deducted (e.g., 2 days)
- **Pending** — applied for but not yet approved
- **Carryover** — from previous cycles

These are all distinct concepts. The UI shows them clearly.

### 2.4 Per-employee leave cycle anniversaries
Each employee's annual leave cycle starts on their employment date and resets on the anniversary. Sick leave cycle is 36 months from employment date. This is BCEA-correct.

### 2.5 Working days, not calendar days
Leave is counted in working days per the employee's working pattern, excluding weekends and public holidays. The system calculates this automatically.

### 2.6 Branch isolation
BMs see leave applications/balances for staff in their own branch. Admins/owners see all. Agents see only themselves. Standard `BranchScope` pattern.

### 2.7 Audit-grade every action
- Every transaction immutable (cannot be edited, only reversed with new offsetting transaction)
- Every approval/rejection logged with timestamp + actor + reason
- Every manual adjustment logged with reason
- Document trail for every application (signed form, medical cert, supporting docs)
- Cannot retroactively change historical data — only forward-correct

---

## 3. Data Model

### 3.1 Tables

#### `leave_types` (per agency)
- `id`, `agency_id`, `code`, `label`, `description`
- `category` enum: annual, sick, family_responsibility, parental, study, unpaid, special, other
- `is_paid` boolean — whether time off is paid by employer
- `is_uif_claimable` boolean — whether UIF can be claimed (parental)
- `requires_documentation` boolean — must upload supporting doc?
- `documentation_label` — "Medical certificate", "Birth certificate", etc.
- `documentation_threshold_days` — only required if leave > N days (sick = 2)
- `entitlement_days_per_cycle` decimal(5,2) — e.g. 15.00 for annual (5-day week)
- `entitlement_days_per_cycle_six_day` decimal(5,2) — e.g. 18.00 for 6-day week
- `cycle_months` integer — 12 for annual, 36 for sick
- `accrual_method` enum: full_at_start, accrual_per_day_worked, accrual_first_six_months, none
- `accrual_rate_per_days` integer — e.g. 17 for annual (1 day per 17 worked), 26 for sick first-6-months
- `accrual_starts_at_employment_date` boolean
- `requires_pre_approval` boolean — must be approved before taken (annual yes, sick no)
- `min_advance_notice_days` integer — e.g., 30 for long annual leave (configurable)
- `allows_negative_balance` boolean — false by default (BCEA: requires written agreement)
- `carries_over_to_next_cycle` boolean
- `forfeit_after_months` integer nullable — null = no auto-forfeit (BCEA-correct)
- `payout_on_termination` boolean — true for annual
- `affects_payroll` boolean — true for unpaid leave (reduces gross)
- `is_system` boolean — BCEA standard types cannot be deleted
- `is_active` boolean
- `sort_order` integer
- `created_at`, `updated_at`, `deleted_at`

#### `leave_entitlements` (per employee)
- `id`, `agency_id`, `branch_id`, `payroll_employee_id`, `user_id`, `leave_type_id`
- `cycle_start_date` — for annual: employment date or anniversary; for sick: employment date or 36-mo anniversary
- `cycle_end_date` — calculated
- `entitlement_days` decimal(5,2) — total for this cycle (might be pro-rated for new hires mid-cycle)
- `accrued_days` decimal(5,2) — what's been earned to date in this cycle (calculated by accrual job)
- `carryover_from_previous_cycle` decimal(5,2)
- `taken_days` decimal(5,2) — derived from approved transactions
- `pending_days` decimal(5,2) — derived from pending applications
- `available_days` decimal(5,2) — generated column: accrued + carryover - taken - pending
- `last_accrual_run_at` timestamp
- `notes` text
- `created_at`, `updated_at`

Index: (payroll_employee_id, leave_type_id, cycle_start_date) UNIQUE
This is the per-employee, per-cycle balance row. Refreshed by the accrual job nightly.

#### `leave_applications`
- `id`, `agency_id`, `branch_id`, `payroll_employee_id`, `user_id`, `leave_type_id`
- `application_number` — e.g., LV-2026-00001
- `start_date`, `end_date`
- `is_half_day` boolean
- `half_day_period` enum: morning, afternoon, null
- `working_days_requested` decimal(5,2) — calculated by service excluding weekends + public holidays
- `calendar_days_requested` integer — for record
- `reason` text — required for some types
- `status` enum: draft, submitted, approved, rejected, cancelled, taken, no_show
- `submitted_at` timestamp
- `decided_at` timestamp — when BM/admin actioned
- `decided_by_user_id` foreign key
- `decided_by_role` enum: branch_manager, admin, owner
- `decision_reason` text — required if rejected
- `taken_at` timestamp — when leave actually started (auto-set on start_date passing)
- `cancelled_at` timestamp
- `cancelled_by_user_id`
- `cancellation_reason` text
- `payslip_id` foreign key nullable — once leave overlaps a finalised pay period, link
- `affects_payroll` boolean — copied from leave_type at submission
- `payroll_impact_amount` decimal(10,2) — for unpaid leave: calculated gross deduction
- `notes` text — admin notes
- `created_at`, `updated_at`, `deleted_at`

#### `leave_application_documents`
- `id`, `leave_application_id`, `document_id` (link to existing Document model)
- `document_role` enum: medical_certificate, supporting, signed_application_form, other
- `uploaded_by_user_id`, `created_at`

#### `leave_transactions`
The immutable ledger. Every balance change is a row here.
- `id`, `agency_id`, `payroll_employee_id`, `user_id`, `leave_type_id`
- `cycle_start_date` — which cycle this affects
- `transaction_type` enum: opening_balance, accrual, application_approved, application_cancelled, manual_adjustment, carry_over, forfeiture, termination_payout, reversal
- `days_delta` decimal(7,3) — POSITIVE = added to balance; NEGATIVE = deducted
- `effective_date` date — when this affects the balance
- `description` text
- `source_type` polymorphic — leave_application, accrual_run, manual, opening, etc.
- `source_id` integer
- `created_by_user_id`
- `reversal_of_transaction_id` nullable — if this is reversing an earlier txn
- `created_at` (immutable, no updated_at, no deleted_at)

#### `public_holidays`
- `id`, `country_code` (default 'ZA'), `holiday_date`, `name`, `is_movable`, `applies_to_year`
- `created_at`, `updated_at`
Seeded with all 13 SA public holidays per year. Includes the moveable feasts (Good Friday, Family Day) calculated for each year.

#### `staff_take_on_records`
The take-on wizard captures everything in one place when a new staff member joins.
- `id`, `agency_id`, `branch_id`, `user_id`, `payroll_employee_id`
- `take_on_date` date — date of take-on (typically employment_date, sometimes mid-employment for migrating off old system)
- `previous_employer` text — for full record
- `previous_employment_start_date` — if continuous service applies
- `original_employment_start_date` — for leave cycle calculations (might be earlier than CoreX onboarding)
- `take_on_type` enum: new_hire, migration_from_old_system, transfer_from_other_branch
- `personal_details_verified` boolean
- `banking_details_verified` boolean
- `tax_details_verified` boolean
- `employment_terms_verified` boolean
- `compensation_setup_verified` boolean
- `leave_balances_captured` boolean
- `compliance_documents_uploaded` boolean
- `signed_employment_contract_uploaded` boolean
- `completed_at` timestamp
- `completed_by_user_id`
- `notes` text
- `created_at`, `updated_at`

### 3.2 PayrollEmployee extensions
Add columns:
- `working_days_per_week` integer default 5 — 5 or 6
- `working_pattern` enum: monday_to_friday, monday_to_saturday, custom
- `working_days_mask` integer — bitmap if custom (bit 0=Mon, bit 6=Sun)
- `daily_rate_basis` enum: fixed_21_67, calendar_working_days, hours_per_day
- `hours_per_day` decimal(4,2) nullable — default 8.00
- `take_on_completed_at` timestamp nullable

### 3.3 User extensions
Add columns (or move to user_profiles if exists):
- `emergency_contact_name`
- `emergency_contact_phone`
- `emergency_contact_relationship`
- `next_of_kin_name`, etc.
- `medical_aid_provider` text nullable
- `medical_aid_number` text nullable
- `medical_aid_main_member` boolean
- `medical_aid_dependents_count` integer

(These are needed for the Take-On wizard but not strictly leave. Captured for compliance and future modules.)

---

## 4. Leave Types Seeded Per Agency

Default 6 types seeded on agency creation per BCEA + Oct 2025 ConCourt ruling:

### 4.1 Annual Leave (`annual_leave`)
- Category: annual
- Paid: yes | UIF: no | Doc required: no
- Entitlement 5-day: 15.00 | 6-day: 18.00
- Cycle: 12 months | Accrual: per_day_worked, 1 day per 17 worked
- Pre-approval: yes | Min notice: 30 days (configurable per agency)
- Carries over: yes | Forfeit: never auto | Termination payout: yes
- Affects payroll: no
- System: yes (cannot delete)

### 4.2 Sick Leave (`sick_leave`)
- Category: sick
- Paid: yes | UIF: no | Doc required: yes if > 2 consecutive days
- Doc label: "Medical certificate" | Threshold: 2 days
- Entitlement 5-day: 30.00 | 6-day: 36.00
- Cycle: 36 months | Accrual: first_six_months (1 per 26), then full_at_start of remainder
- Pre-approval: no (retrospective with cert)
- Carries over: no (cycle resets every 36 months)
- Forfeit: at end of 36-month cycle
- Termination payout: no
- Affects payroll: no
- System: yes

### 4.3 Family Responsibility Leave (`family_responsibility_leave`)
- Category: family_responsibility
- Paid: yes | UIF: no | Doc required: optional (death certificate, birth certificate)
- Entitlement 5-day: 3.00 | 6-day: 3.00
- Cycle: 12 months
- Eligibility: 4+ months service AND 4+ days/week (enforced in service)
- Accrual: full_at_start
- Pre-approval: yes (where possible)
- Carries over: no
- Termination payout: no
- Qualifying events captured in application reason:
  - Birth of own child
  - Sickness of own child
  - Death of spouse/life partner
  - Death of parent/adoptive parent/grandparent/child/adopted child/grandchild/sibling
- System: yes

### 4.4 Parental Leave (`parental_leave`) — NEW Oct 2025 ConCourt ruling
- Category: parental
- Paid: NO by BCEA | UIF: yes (claimable by employee) | Doc required: yes
- Doc label: "Birth certificate / adoption order / surrogacy agreement"
- Pool: 4 months + 10 days = approximately **130 calendar days** SHARED between parents
- If single parent OR sole employed parent: full 130 days
- If both parents employed: must be split by agreement
- Birthing parent must take 6 weeks (42 days) post-birth minimum
- Cycle: per child (no fixed cycle)
- Pre-approval: yes
- Carries over: N/A
- Termination payout: no
- Affects payroll: yes if employer chooses to pay (most don't); UIF claim handled separately
- System: yes

Note: This replaces the old maternity/paternity/adoption split per the Constitutional Court interim provisions effective 3 October 2025.

### 4.5 Study Leave (`study_leave`)
- Category: study | NOT BCEA-mandated; agency policy
- Paid: configurable per agency (default no) | UIF: no | Doc required: yes (proof of registration, exam timetable)
- Entitlement: 0 by default (agency configures)
- Cycle: 12 months
- Affects payroll: yes if unpaid
- System: no (can be disabled)

### 4.6 Unpaid Leave (`unpaid_leave`)
- Category: unpaid
- Paid: no | UIF: no | Doc: no
- Entitlement: unlimited (subject to approval)
- Pre-approval: yes
- Affects payroll: YES — gross is reduced by working_days × daily_rate
- System: yes

### 4.7 Special / Discretionary (`special_leave`)
- Category: special
- Paid: yes by default | UIF: no | Doc: optional
- Entitlement: 0 default (admin grants on case-by-case)
- For: bereavement extension, religious observance, compassionate circumstances
- Pre-approval: yes
- Affects payroll: configurable
- System: no

---

## 5. Public Holiday Calendar

### 5.1 SA Public Holidays Seeded
Per Public Holidays Act 36 of 1994:
- 1 Jan — New Year's Day
- 21 Mar — Human Rights Day
- Good Friday (moveable, calculated)
- Family Day / Easter Monday (Good Friday + 3 days)
- 27 Apr — Freedom Day
- 1 May — Workers' Day
- 16 Jun — Youth Day
- 9 Aug — National Women's Day
- 24 Sep — Heritage Day
- 16 Dec — Day of Reconciliation
- 25 Dec — Christmas Day
- 26 Dec — Day of Goodwill

If a public holiday falls on a Sunday, the following Monday is also a public holiday (BCEA s2(1) of Public Holidays Act).

### 5.2 Seeder
Console command: `php artisan corex:seed-public-holidays {year}` — seeds for one year. Run annually for the next year. Tier 1 seeds 2026 + 2027 + 2028.

### 5.3 Calculator
`PublicHolidayService::isPublicHoliday(Carbon $date): bool`
`PublicHolidayService::countWorkingDays(Carbon $start, Carbon $end, array $workingDayPattern): int`

Excludes weekends per pattern AND public holidays.

---

## 6. Accrual Engine

### 6.1 Daily Job: `php artisan leave:accrue-daily`
Run daily at 02:00 via scheduler.

For each active payroll_employee:
- For each active leave_type with `accrual_method = accrual_per_day_worked`:
  - Get current cycle (by employment date anniversary)
  - Days worked since cycle start = working days between cycle_start_date and yesterday
  - Days accrued = floor(days_worked / accrual_rate_per_days × entitlement_days)
  - Compare to leave_entitlements.accrued_days; if higher, create accrual transaction for the delta
- For sick leave with `accrual_method = accrual_first_six_months`:
  - If employment less than 6 months: 1 day per 26 worked
  - If employment >= 6 months and current cycle just started: full entitlement at start
  - If employment >= 6 months mid-cycle: already accrued at cycle start, no further accrual
- For types with `accrual_method = full_at_start`:
  - On cycle start, create one transaction = full entitlement

### 6.2 Cycle Anniversary Job: `php artisan leave:cycle-rollover`
Run daily, processes any employees whose cycle ends today.

For each cycle ending:
- Calculate carryover (carries_over_to_next_cycle and remaining balance)
- Create transaction `carry_over` for the carried amount
- Create new leave_entitlements row for the new cycle
- For sick leave: at end of 36-month cycle, create `forfeiture` transaction zeroing the old balance, then full entitlement transaction starting new cycle
- For annual leave: carry over remaining balance, no forfeit
- Email admin if any employee has > 1.5x their annual entitlement accumulated (compliance warning)

### 6.3 Manual Recalculation
Admin can trigger a per-employee or all-employees recalculation:
`leave:recalculate-balances --employee={id}` or `--all`
Useful after backfilling historical transactions or fixing data errors.

---

## 7. Application & Approval Flow

### 7.1 Agent Application (My Portal)
On `/corex/my-portal/leave`:
- Tab on the existing My Portal
- Shows: current balances per leave type, history of past applications, [+ Apply for Leave] button

Application form:
1. Leave type dropdown (only active types for agency)
2. Start date / end date (date pickers, end >= start)
3. Half day toggle (start AND end same day) → reveals morning/afternoon
4. **Live calculation** — system shows: "5 working days requested" (excluding weekends + public holidays) and "Available balance: 8 days, after this leave: 3 days"
5. Reason text — required for: family_responsibility (specify event), special, unpaid; optional for others
6. Document upload (if leave_type requires)
7. Notes field
8. [Submit] [Save as Draft] [Cancel]

Validation:
- Cannot apply for past dates (except sick leave with cert, retrospective)
- Cannot apply if `available_days < working_days_requested` AND leave_type does not allow negative balance
- Min advance notice if specified
- Family responsibility leave: must meet eligibility (4+ months service, 4+ days/week) — service checks
- Parental leave: requires birth/adoption proof in document upload
- Cannot have overlapping approved/pending applications

On submit:
- Create application with status='submitted'
- Create pending_days transaction (reservation against balance)
- Notify BM AND admin (in case BM unavailable)
- Email + in-app notification

### 7.2 BM/Admin Decision
On `/corex/payroll/leave/applications` (filtered by branch for BM, all for admin):
- List of pending applications
- Click application → detail page
- See: applicant, type, dates, working days, current balance, after-this-leave balance, documents, reason
- See team calendar for that period (other staff already on leave?)
- [Approve] [Reject] buttons
- Reject requires reason text
- Approve creates transaction `application_approved` with `days_delta = -working_days_requested`
- Reject removes the pending reservation

First to act wins. If BM and admin both view at the same time, race condition handled by checking `decided_at IS NULL` in the update query.

### 7.3 Notifications
On state changes:
- Submitted: BM (in-app + email) and admin (in-app)
- Approved: applicant (in-app + email)
- Rejected: applicant (in-app + email) with reason
- Cancelled by applicant before decision: BM/admin notified
- Pending > 3 days: reminder to BM/admin
- Leave starts in 3 days: reminder to applicant + BM
- Leave ends today: reminder to applicant ("welcome back tomorrow")

### 7.4 Cancellation
Applicant can cancel:
- Before decision: free cancellation (status='cancelled', pending reservation removed)
- After approval but before leave starts: cancellation request — same flow as application, BM/admin approves the cancellation
- After leave has started: cannot cancel (must apply for return-to-work adjustment)
- After leave taken: requires admin manual adjustment via leave_transactions

---

## 8. Staff Take-On Wizard

### 8.1 Purpose
When a new staff member joins (or migrating an existing one off old systems), this is the single onboarding flow that captures everything.

Accessible from: Admin → Staff Take-On → New Take-On
Or from existing User Manager → user detail → "Run Take-On" button

### 8.2 Wizard Steps
**Step 1: User Selection / Creation**
- Pick existing CoreX user OR create new user
- If creating: name, email, ID number, phone, role, branch, password set/email invite

**Step 2: Personal Details**
- Date of birth (auto-derived from ID with override)
- Emergency contact (name, phone, relationship)
- Next of kin
- Home address
- Marital status / dependents

**Step 3: Tax & Banking**
- Tax reference number (or "pending SARS registration")
- Bank, branch code, account number, account type, account holder
- UIF reference (auto from ID)

**Step 4: Employment Terms**
- Original employment start date (might be before CoreX onboarding)
- Designation
- Branch
- Working pattern (5-day / 6-day)
- Hours per day
- Pay day
- Daily rate basis

**Step 5: Compensation Setup**
(Reuses existing PayrollEmployee earnings/deductions screens)
- Basic salary
- Allowances (cell, travel, etc.)
- PAYE arrangements (override if applicable)

**Step 6: Leave Opening Balances**
For each active leave type:
- Show: entitlement for cycle, accrued to date (calculated from employment date), used to date (admin enters), remaining (calculated)
- Admin enters used to date — system calculates remaining
- For annual leave: also captures any carryover from previous cycles
- Each entry creates an `opening_balance` transaction
- All transactions tagged to take-on event for auditability

**Step 7: Compliance Documents**
- Upload: signed employment contract, ID copy, qualifications, FFC, training certificates
- Each filed to user_documents pivot with appropriate document_type

**Step 8: Review & Sign-Off**
- Summary of everything captured
- Checkbox: "All details verified and correct"
- Signed by admin/owner (digital signature optional)
- Submit → creates staff_take_on_record with completed_at

After submission:
- All payroll setup is live
- All leave balances loaded
- Employee can log in (if user account created with email invite)
- Welcome email sent
- BM notified of new team member

### 8.3 Migration Mode
For agencies migrating off old systems where the employee has been working for years:
- Take-on date is "today" (when they go live on CoreX)
- Original employment start date captures their actual tenure (used for sick leave 36-month cycle math)
- Leave balances captured as-at take-on date (admin types in current balances)
- All leave history before take-on is summarised, not transaction-by-transaction
- One opening_balance transaction per leave type captures the migrating balance

---

## 9. Payroll Integration

### 9.1 Unpaid Leave on Payslip
When payroll run is created:
- For each employee, find approved leave applications where status='approved' AND start_date <= period_end AND end_date >= period_start
- For each, calculate working days within the pay period
- For unpaid leave specifically:
  - Daily rate = monthly basic salary / 21.67 (BCEA standard for 5-day week)
  - Or use the employee's daily_rate_basis if configured otherwise
  - Deduction line auto-added to payslip: "Unpaid Leave (3 days)" with amount = days × daily_rate
  - Source link to the leave_application
- For paid leave: no payslip impact, but appears in the leave summary footer

### 9.2 Payslip PDF — Leave Summary Footer
Per spec §8.1 of payroll, add a new section near the bottom of the payslip PDF:

```
Leave Balances (as at {pay_date})
┌─────────────────────────┬──────────┬─────────┬──────────┬──────────┐
│ Type                    │ Entitled │ Taken   │ Pending  │ Available│
├─────────────────────────┼──────────┼─────────┼──────────┼──────────┤
│ Annual Leave            │     15.0 │     2.0 │      0.0 │     13.0 │
│ Sick Leave (3-yr cycle) │     30.0 │     5.0 │      0.0 │     25.0 │
│ Family Responsibility   │      3.0 │     0.0 │      0.0 │      3.0 │
└─────────────────────────┴──────────┴─────────┴──────────┴──────────┘
```

Only show types with non-zero entitlement. Hide unpaid + special.

### 9.3 Run Report Integration
The payroll run report page gets a new section: "Leave Taken in Period" — list of all approved leave transactions during the pay period grouped by employee.

---

## 10. Calendar Integration

### 10.1 Auto Event Creation
On leave application approval:
- Create a `calendar_event` (using Andre's existing model) with:
  - title: "{Employee Name} - {Leave Type}"
  - start_date / end_date
  - source_type: leave_application, source_id
  - colour: per leave type (configurable; default annual=teal, sick=amber, family=blue, parental=purple)
  - visible_to: branch members + admins
  - description: leave reason if not sick

On cancellation: soft-delete the event.
On dates changing (rare but possible): update event.

### 10.2 Conflict Detection
Before BM approves: show "Other staff on leave during this period" listing approved leave from other branch members in the same date range. Optional warning if > 50% of branch is out simultaneously.

---

## 11. Routes & Controllers

### 11.1 Routes
```php
// Admin / BM routes
Route::middleware(['auth','permission:manage_leave','agency.required'])
  ->prefix('corex/payroll/leave')
  ->name('payroll.leave.')
  ->group(function () {
    Route::get('/', LeaveDashboardController::class)->name('dashboard');
    Route::resource('types', LeaveTypeController::class);
    Route::get('applications', [LeaveApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}', [LeaveApplicationController::class, 'show'])->name('applications.show');
    Route::post('applications/{application}/approve', [LeaveApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{application}/reject', [LeaveApplicationController::class, 'reject'])->name('applications.reject');
    Route::get('balances', [LeaveBalanceController::class, 'index'])->name('balances.index');
    Route::get('balances/{employee}', [LeaveBalanceController::class, 'show'])->name('balances.show');
    Route::post('balances/{employee}/adjust', [LeaveBalanceController::class, 'adjust'])->name('balances.adjust');
    Route::get('reports/register', [LeaveReportController::class, 'register'])->name('reports.register');
    Route::get('reports/branch-summary', [LeaveReportController::class, 'branchSummary'])->name('reports.branch-summary');
    Route::get('reports/accrual-statement', [LeaveReportController::class, 'accrualStatement'])->name('reports.accrual-statement');
  });

// Staff Take-On
Route::middleware(['auth','permission:manage_staff_take_on','agency.required'])
  ->prefix('corex/staff-take-on')
  ->name('staff-take-on.')
  ->group(function () {
    Route::get('/', [StaffTakeOnController::class, 'index'])->name('index');
    Route::get('create', [StaffTakeOnController::class, 'create'])->name('create');
    Route::post('/', [StaffTakeOnController::class, 'store'])->name('store');
    Route::get('{takeOn}/wizard/{step}', [StaffTakeOnController::class, 'wizard'])->name('wizard');
    Route::patch('{takeOn}/wizard/{step}', [StaffTakeOnController::class, 'saveStep'])->name('save-step');
    Route::post('{takeOn}/complete', [StaffTakeOnController::class, 'complete'])->name('complete');
  });

// My Portal — agent self-service leave
Route::middleware(['auth','permission:apply_for_leave'])
  ->prefix('corex/my-portal/leave')
  ->name('my-portal.leave.')
  ->group(function () {
    Route::get('/', [MyPortalLeaveController::class, 'index'])->name('index');
    Route::get('apply', [MyPortalLeaveController::class, 'create'])->name('apply');
    Route::post('apply', [MyPortalLeaveController::class, 'store'])->name('store');
    Route::get('{application}', [MyPortalLeaveController::class, 'show'])->name('show');
    Route::post('{application}/cancel', [MyPortalLeaveController::class, 'cancel'])->name('cancel');
    Route::get('balances', [MyPortalLeaveController::class, 'balances'])->name('balances');
  });
```

### 11.2 Controllers
- `LeaveDashboardController` — admin dashboard with key stats
- `LeaveTypeController` — CRUD for leave types per agency
- `LeaveApplicationController` — admin/BM application management
- `LeaveBalanceController` — view + manually adjust balances
- `LeaveReportController` — three reports
- `MyPortalLeaveController` — agent self-service
- `StaffTakeOnController` — wizard

---

## 12. Permissions

Sort order 130-139 (after payroll which is 120-123):

- `manage_leave` (130) — admin/BM full access to leave admin
- `approve_leave` (131) — can approve/reject applications (BM + admin)
- `apply_for_leave` (132) — can apply for own leave (everyone with active payroll_employee)
- `view_leave_reports` (133) — access to reports
- `manage_leave_types` (134) — configure leave types per agency (admin only)
- `manage_staff_take_on` (135) — run the take-on wizard
- `view_team_leave_calendar` (136) — see team's leave on calendar
- `adjust_leave_balances` (137) — manual balance adjustments (admin only, audited)

Role defaults:
- admin: all 8
- branch_manager: manage_leave, approve_leave, apply_for_leave, view_leave_reports, view_team_leave_calendar
- agent: apply_for_leave, view_team_leave_calendar (own branch only via scope)
- office_admin: apply_for_leave

---

## 13. Sidebar Placement

Two new sidebar sections:

**In Admin section (after Payroll group):**
```
Leave Management
  ├── Dashboard
  ├── Applications
  ├── Balances
  ├── Leave Types
  ├── Reports
  └── Public Holidays (sub-link)
```

**Top-level in Admin section:**
```
Staff Take-On (its own item, important onboarding flow)
```

**In My Portal:**
Add "Leave" tab to agent portal alongside existing "Payslips" tab. Tab shows balances + apply button + history.

---

## 14. Audit Log

Every action logs to `audit_log` (existing CoreX table) with:
- entity_type: leave_application, leave_transaction, leave_entitlement, staff_take_on
- entity_id
- action: created, submitted, approved, rejected, cancelled, balance_adjusted, take_on_completed
- actor_user_id
- before / after JSON snapshots
- timestamp + IP

Admin Audit screen accessible at `/corex/payroll/leave/audit` filterable by employee, type, date range.

---

## 15. Acceptance Criteria

For 25 May 2026 parallel run readiness:
- [ ] All 23 HFC staff taken on via Staff Take-On wizard with leave opening balances captured
- [ ] Annual leave balances tally with whatever record HFC currently has (informal or otherwise)
- [ ] Sick leave 36-month cycles correctly calculated from each employee's actual employment start
- [ ] Public holidays for 2026 visible in calendar
- [ ] Accrual engine running daily, balances updating
- [ ] At least one test application end-to-end: agent applies, BM approves, balance deducts, calendar shows event
- [ ] At least one unpaid leave test: gross deduction reflects on payslip preview
- [ ] Termination payout calculation tested for a leaver
- [ ] Reports render with real data
- [ ] Audit log shows every transaction

---

## 16. Risk Register

| Risk | Mitigation |
|------|------------|
| Take-on opening balances wrong → all subsequent calcs wrong | Sign-off step before completion; admin can adjust with audit later |
| Accrual bug → silent under/over-accrual | Daily reconciliation report; transaction log allows full replay |
| Staff dispute "I had X days, system says Y" | Full transaction history visible in My Portal; PDF accrual statement on demand |
| Public holiday miscalculated (moveable feasts) | Seeder validated against gov.za sources; admin can manually adjust |
| Parental leave new ruling not understood | In-app help text on application form linking to gov.za guidance |
| BM approves their own leave (improper) | Service blocks BM from approving own application — only admin can |
| Data loss on take-on if browser crashes mid-wizard | Each step auto-saves; resume from last completed step |
| Leave taken before payroll period close → not on payslip | Approval cutoff = pay date; later approvals roll to next pay run |

---

## 17. 16-Prompt Build Sequence

Numbered for execution. Each prompt scoped to one VS Code session.

**A.** Migrations — 9 new tables + 5 user/employee column additions
**B.** Eloquent models — 8 new models, User + PayrollEmployee + Agency extensions
**C.** Public Holiday seeder + service (calculator, working day calc)
**D.** Leave types seeder (6 BCEA-compliant types per agency)
**E.** Permissions + sidebar placeholders (8 perms, 2 sidebar groups, all href=#)
**F.** Accrual engine — services + console commands + scheduler entry
**G.** Leave Type CRUD — admin manages per-agency leave types
**H.** Staff Take-On wizard (8-step flow)
**I.** Leave Balance admin screens — view + manual adjust + opening balance tools
**J.** Leave Application admin screens — list, detail, approve/reject
**K.** My Portal Leave tab + apply flow (agent self-service)
**L.** Payroll integration — unpaid leave deduction + payslip footer
**M.** Calendar integration — auto-event creation, conflict detection
**N.** Reports — register, branch summary, accrual statement, audit log viewer
**O.** End-to-end testing + visual polish + edge cases
**P.** Acceptance test for HFC's 23 staff (parallel test run)

Estimated 9-10 working days for all 16 prompts. Per-prompt time varies from 30 min (E) to half a day (H, K).

---

## 18. Out of Scope — Tier 2 Wishlist

- Custom leave types beyond BCEA list
- Multi-level approval chains
- Hour-by-hour partial leave (currently half-day max)
- Leave forfeiture automation
- IOD / COIDA integration
- Long-service award accrual
- Bargaining council variations (sectoral)
- Sectoral Determination 9 (real estate) compliance vs BCEA
- Time-off-in-lieu (TOIL) tracking for unpaid overtime
- Auto-public-holiday Sunday-roll-forward calculation (manual override only in Tier 1)

---

## End of Spec
