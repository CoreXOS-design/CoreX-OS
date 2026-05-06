# Parallel Leave Run — May 2026

## 1. Purpose

Validate that CoreX leave balances match HFC Coastal's current leave records (paper files, Sage, or informal tracking) for all 23 permanent staff. CoreX must match the existing records within 0.5 day tolerance per employee per leave type for sign-off.

This is a one-time reconciliation. Once validated, CoreX replaces all manual leave tracking permanently. All future leave applications, approvals, balances, and accruals are managed exclusively through CoreX.

---

## 2. Pre-Run Checklist (complete by Day -3)

- [ ] All 23 HFC staff have active CoreX user accounts
- [ ] All 23 have completed the Staff Take-On wizard (Payroll > Staff Take-On > all showing "Completed")
- [ ] Each take-on captured:
  - Original employment start date (for cycle calculation — critical for sick leave 36-month cycles)
  - Working pattern (5-day Mon-Fri or 6-day Mon-Sat)
  - Banking details
  - Leave opening balances for each type:
    - Annual leave: days taken this cycle + carryover from previous cycle
    - Sick leave: days taken in current 36-month cycle
    - Family responsibility leave: days taken this year
- [ ] HFC's existing leave records collected by Elize — whatever format they're in (paper files, Sage export, spreadsheet, or from memory). One row per employee per leave type.
- [ ] Public holidays for 2026 verified: visit Payroll > Leave Management > Public Holidays, filter year 2026, confirm 13 SA holidays listed
- [ ] Accrual engine has run at least once: check that balances update daily by comparing today's balance to yesterday's (the scheduler runs at 02:00 daily)
- [ ] One designated reviewer per branch (branch manager or admin)

---

## 3. Role Assignments

| Person | Role | Responsibilities |
|--------|------|-----------------|
| **Johan** | Technical lead | Runs system commands, resolves discrepancies, creates manual adjustments |
| **Elize** | HFC operations | Holds existing leave records, walks through each employee, signs off on balances |
| **Karin** | Independent accountant | Audit witness, verifies the reconciliation is complete and accurate, co-signs |
| **Andre** | On-call backend | Available if any system bugs surface during the run |

---

## 4. Run Procedure

### Step 1: System Health Check (Johan, ~15 minutes)

Run these commands on the server before starting:

```bash
# Confirm all leave migrations applied
php artisan migrate:status | grep leave

# Confirm leave permissions synced
php artisan corex:sync-permissions --merge-defaults

# Force fresh balance calculation from immutable ledger
php artisan corex:leave:recalculate-balances --all

# Verify scheduler entries
php artisan schedule:list | grep leave
# Expected: 3 entries
#   corex:leave:accrue-daily       (02:00 daily)
#   corex:leave:cycle-rollover     (02:30 daily)
#   corex:leave:send-reminders     (06:00 daily)

# Run accrual to bring balances up to today
php artisan corex:leave:accrue-daily

# Check total active employees
php artisan tinker --execute="echo App\Models\Payroll\PayrollEmployee::where('is_active',true)->count();"
# Expected: 23
```

If any command fails, resolve before proceeding.

### Step 2: Public Holiday Calendar Verification (Johan, ~5 minutes)

Navigate to **Payroll > Leave Management > Public Holidays**, year filter: 2026.

Verify all 13 South African public holidays present:

| Date | Name |
|------|------|
| 1 Jan | New Year's Day |
| 21 Mar | Human Rights Day |
| 3 Apr | Good Friday |
| 6 Apr | Family Day |
| 27 Apr | Freedom Day |
| 1 May | Workers' Day |
| 16 Jun | Youth Day |
| 9 Aug | National Women's Day |
| 10 Aug | National Women's Day (Observed) |
| 24 Sep | Heritage Day |
| 16 Dec | Day of Reconciliation |
| 25 Dec | Christmas Day |
| 26 Dec | Day of Goodwill |

If any missing, add via the + Add Holiday button or run:
```bash
php artisan corex:seed-public-holidays 2026
```

### Step 3: Per-Staff Balance Reconciliation (Elize + Karin, ~3 hours)

This is the core of the run. For each of 23 staff members:

1. Open **Payroll > Leave Management > Balances**
2. Click the employee's name to open their balance detail
3. For each leave type (Annual, Sick, Family Responsibility):

Compare the CoreX balance to HFC's existing record. Record your findings in the reconciliation table:

| Employee | Leave Type | CoreX Balance | HFC Record | Discrepancy | Resolution |
|----------|-----------|---------------|------------|-------------|------------|
| Elize Reichel | Annual | 3.74 | 4.0 | -0.26 | MATCHES (within 0.5 tolerance) |
| Elize Reichel | Sick | 30.00 | 30 | 0 | MATCHES |
| ... | ... | ... | ... | ... | ... |

**Resolution codes:**
- **MATCHES** — within 0.5 day tolerance, no action needed
- **ADJUST CoreX +X** — Johan creates manual adjustment via the Balance detail page > Manual Adjustment button
- **ADJUST CoreX -X** — same, negative adjustment
- **HFC RECORDS WRONG** — HFC paper record is incorrect; CoreX calculation is trusted (document why)
- **TIE-BREAK NEEDED** — defer to Johan + Elize joint decision

**To make an adjustment:**
1. Open the employee's Balance detail
2. Click the leave type tab (e.g., Annual Leave)
3. Click "Manual Adjustment"
4. Enter days (positive or negative), reason: "Parallel run reconciliation: HFC record showed X, CoreX showed Y, adjusted to match"
5. Click Save

**Important notes:**
- Annual leave: check both accrued balance AND carryover from previous cycle
- Sick leave: the 36-month cycle may start on a different date than annual (it starts from employment date, resets every 3 years)
- Family responsibility: 3 days per 12-month cycle, resets on employment anniversary
- Parental leave: only relevant if an employee has used it — 130-day shared pool per child
- Study/unpaid/special: typically zero unless the agency has granted specific leave

### Step 4: End-to-End Test Application (Elize as applicant, ~15 minutes)

With Elize logged into CoreX:

1. Navigate to **My Portal > Leave tab**
2. Click **Apply for Leave**
3. Select **Annual Leave**
4. Pick dates: next Monday to Wednesday (3 working days)
5. Verify the live calculation shows:
   - Working days: 3
   - Balance before: X.XX
   - Balance after: X.XX - 3.00
6. Enter reason: "Parallel run test"
7. Click **Submit Application**
8. Verify green success flash

Switch to admin (Johan):

9. Navigate to **Payroll > Leave Management > Applications**
10. Find the test application (status: Submitted, amber pill)
11. Click into it — verify applicant card, balance impact, dates
12. Click **Approve**
13. Verify status changes to Approved (teal pill)

Back as Elize:

14. Check My Portal > Leave — balance reduced by 3 days
15. Check calendar — leave block visible on those dates

**Cleanup:** Johan applies a manual +3 adjustment to reverse the test, with reason "Parallel run test reversal".

Expected outcome: every step works without backend intervention.

### Step 5: Unpaid Leave + Payroll Integration Test (~20 minutes)

With one volunteer staff member (not Elize — pick someone with a simple pay structure):

1. Apply for **1 day Unpaid Leave** for a date in next month
2. Approve the application
3. Navigate to **Payroll > Runs > + New Run**
4. Create a draft run for next month
5. Open the volunteer's payslip — verify:
   - A deduction line: "Unpaid Leave: LV-2026-XXXXX (1 day)"
   - Gross reduced by exactly 1 day's daily rate (basic salary / 21.67)
   - PAYE recalculated on the reduced gross
6. Open the run report — verify "Leave Taken in Period" section lists the leave
7. Preview payslip PDF — verify leave balance footer present
8. **Cancel the draft run** (do NOT finalise — this is just a test)
9. Reverse the test: admin applies +1 unpaid leave manual adjustment

### Step 6: Reports Validation (~15 minutes)

1. **Leave Register**: Payroll > Leave Management > Reports
   - Default current month — verify all April 2026 applications visible
   - Filter by status=Approved
   - Click Export CSV — download and open in Excel
   - Spot-check 3 random rows: dates, days, status match the UI
2. **Branch Summary**: click Branch Summary tab
   - Verify per-branch numbers (annual entitled, taken, available)
   - Check compliance flags (any employee with >22.5 days annual accumulated?)
3. **Accrual Statement**: pick Elize from the dropdown
   - Verify running balance increments correctly per transaction
   - Verify cycle dates match employment anniversary
4. **Audit Log**: click Audit Log tab
   - Verify immutable transactions listed with dates, types, deltas
   - Filter by employee — see only that person's transactions

### Step 7: Sign-Off (Elize + Karin, ~10 minutes)

Print the reconciliation table from Step 3.

Fill in the summary:

```
LEAVE MODULE PARALLEL RUN — SIGN-OFF

Date: _______________

Total staff reconciled: _____ / 23
Staff with no discrepancy: _____
Staff with adjustments applied: _____ (list names + adjustment amounts)
Staff with HFC records found wrong: _____ (list names + explanation)
Staff with tie-break needed: _____ (list names + resolution)

End-to-end test: PASS / FAIL
Unpaid leave payroll test: PASS / FAIL
Reports validation: PASS / FAIL

Signed:
  Elize Reichel: _______________  Date: _________
  Karin [Surname]: _____________  Date: _________
```

File the signed document in CoreX as a Document linked to the Agency record.

---

## 5. Success Criteria

- 100% of staff balances reconciled (every employee has a resolution code)
- Discrepancies within 0.5 day per leave type per employee (or explicitly adjusted)
- Test application end-to-end works without errors
- Unpaid leave payroll integration verified
- Reports render correctly with real data
- Two signatures on sign-off document
- No unresolved blocker-level issues

---

## 6. If Something Goes Wrong

- **Balance mismatch > 2 days for any employee**: STOP. Investigate the take-on opening balances. Was the employment start date entered correctly? Was the correct cycle used?
- **Application submission fails**: Check the employee has an active payroll profile. Check the leave type is active. Check balance > 0 for non-negative types.
- **Accrual looks wrong**: Run `php artisan corex:leave:recalculate-balances --employee={id}` to force re-derive from ledger. Compare before/after.
- **Calendar event not appearing**: Check the application was actually approved (not just submitted). Calendar events are only created at approval.
- **Payslip doesn't show unpaid deduction**: The unpaid leave application must be status=approved AND the leave type must have `affects_payroll=true`. Check both.
- **System bug found**: Johan creates a fault report in CoreX. Andre/AI fixes. Re-run the affected step after the fix is deployed.
- **HFC records are incomplete**: Elize makes her best estimate. Document the estimate with a note. Adjust later if better records surface.

---

## 7. Post-Run

After sign-off:
- [ ] Signed reconciliation filed in CoreX
- [ ] Production cutover date confirmed with team
- [ ] All staff trained on My Portal > Leave tab (separate 30-min session)
- [ ] Scheduler confirmed running on production server (3 leave commands)
- [ ] Paper leave forms discontinued
- [ ] Elize sends agency-wide email: "Leave is now managed through CoreX. Apply via My Portal > Leave."
- [ ] BMs briefed on approval workflow: Applications page, [Approve] / [Reject]
- [ ] Karin briefed on reports: Leave Register for monthly reconciliation, Branch Summary for compliance review

---

## 8. Appendix: Helper Scripts

### A. Dump all employee balances to console (copy to spreadsheet)

```bash
php artisan tinker --execute="
\$svc = new App\Services\Leave\LeaveBalanceService();
\$types = App\Models\Leave\LeaveType::where('is_active', true)->orderBy('sort_order')->get();
echo implode('\t', ['Employee', 'Branch', 'Employment Date']) . '\t';
foreach (\$types as \$t) echo \$t->label . ' (avail)\t';
echo PHP_EOL;

foreach (App\Models\Payroll\PayrollEmployee::where('is_active',true)->with('user','user.branch')->orderBy('created_at')->get() as \$emp) {
    echo \$emp->user->name . '\t' . (\$emp->user->branch->name ?? '-') . '\t' . \$emp->employment_date->format('Y-m-d') . '\t';
    foreach (\$types as \$t) {
        \$b = \$svc->getBalance(\$emp, \$t);
        echo number_format((float)\$b['available_days'], 2) . '\t';
    }
    echo PHP_EOL;
}
"
```

### B. Full transaction ledger for one employee

```bash
php artisan tinker --execute="
\$empId = 15;  // Change to the payroll_employee_id
\$txns = App\Models\Leave\LeaveTransaction::withoutGlobalScopes()
    ->where('payroll_employee_id', \$empId)
    ->with('leaveType', 'createdBy')
    ->orderBy('effective_date')
    ->orderBy('id')
    ->get();

\$running = [];
foreach (\$txns as \$t) {
    \$key = \$t->leave_type_id;
    \$running[\$key] = bcadd(\$running[\$key] ?? '0.000', (string)\$t->days_delta, 3);
    echo sprintf('%s | %-20s | %-25s | %+8s | running=%s | %s',
        \$t->effective_date->format('Y-m-d'),
        \$t->leaveType->label ?? '?',
        \$t->transaction_type,
        \$t->days_delta,
        \$running[\$key],
        substr(\$t->description, 0, 40)
    ) . PHP_EOL;
}
"
```

### C. Manual adjustment one-liner

```bash
php artisan tinker --execute="
\$emp = App\Models\Payroll\PayrollEmployee::find(15);  // Employee ID
\$type = App\Models\Leave\LeaveType::where('code', 'annual_leave')->first();
\$admin = App\Models\User::find(23);  // Admin user ID
\$svc = new App\Services\Leave\LeaveAccrualService();
\$txn = \$svc->manualAdjustment(\$emp, \$type, '2.5', 'Parallel run reconciliation: HFC record 15.5, CoreX 13.0, adjusted +2.5', \$admin);
echo 'Adjustment created: id=' . \$txn->id . ' delta=' . \$txn->days_delta . PHP_EOL;
echo 'New balance: ' . (new App\Services\Leave\LeaveBalanceService())->getBalance(\$emp, \$type)['available_days'] . PHP_EOL;
"
```

---

## Contact

- **System issues**: Johan Reichel (WhatsApp or email)
- **Leave policy queries**: Elize Reichel
- **BCEA compliance queries**: compare against Department of Employment and Labour guidance at labour.gov.za
- **Backend bugs**: Andre (via Johan)

---

*Document version: 1.0 | Created: 28 April 2026 | For: May 2026 leave parallel run*
