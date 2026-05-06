# Parallel Payroll Run — May 2026

## Purpose

Validate CoreX payroll output against the existing Excel spreadsheet for the May 2026 pay run. CoreX must match the spreadsheet within R1 tolerance per employee for sign-off. This is a one-time parallel run — once validated, CoreX replaces the spreadsheet permanently from June 2026.

---

## Pre-run checklist (complete by 22 May)

- [ ] All permanent staff employees added in CoreX (Payroll > Employees)
- [ ] Each employee has correct:
  - ID number
  - Date of birth (used for PAYE age rebate — critical for over-65 employees)
  - Tax reference number (optional for Tier 1 but add if available)
  - Designation
  - Branch
  - Employment date
  - Banking details (bank, branch code, account number, account type)
- [ ] Each employee has correct earnings set up:
  - Basic Salary (mandatory — this is the main earning)
  - Cell allowance (where applicable)
  - Travel allowance (where applicable)
  - Any other agreed recurring earnings
- [ ] Statutory deductions present on each profile: PAYE and UIF
  - These are auto-added when you create the employee
  - PAYE and UIF amounts are auto-calculated each run — do NOT enter amounts manually unless you have a specific SARS arrangement
- [ ] Where an employee has a PAYE arrangement with SARS that overrides the standard calculation, set the override amount:
  - Go to the employee's profile > Current Setup tab
  - Find the PAYE row > click Edit > tick "Override auto-calculation" > enter the fixed PAYE amount > Save
- [ ] Banking details captured for all employees who are paid by EFT
- [ ] Run a test: Payroll > Runs > + New Run > check projected totals are in the right ballpark before 25 May

---

## On run day (25 May)

### Step 1: Run the spreadsheet first

Run the existing payroll spreadsheet exactly as you have done every month. Complete it fully and save it. This is the source of truth for comparison. Print a copy for the sign-off table.

### Step 2: Create the CoreX run

1. Login as admin
2. Navigate to **Payroll > Runs** in the sidebar
3. Click **+ New Run**
4. Set:
   - Period: **May 2026** (should default correctly)
   - Pay date: **25 May 2026**
5. The employee table shows all active payroll employees with their basic salary. Verify all employees are listed. Tick all of them.
6. Check the **Projected Totals** panel at the bottom:
   - Total Gross should be close to your spreadsheet total
   - If the total is wildly different (more than R5,000 off), **STOP** and investigate which employee's earnings are wrong before proceeding
7. Click **Create Draft Run**

### Step 3: Compare per employee

The run detail page shows all payslips. For each employee:

1. Click **View** on their payslip row
2. Compare these numbers with the spreadsheet line for that employee:
   - **Gross** (total earnings)
   - **PAYE** (tax deducted)
   - **UIF** (employee contribution — should be 1% of gross, capped at R177.12)
   - **Net pay** (take-home)

**Differences expected and acceptable:**
- SDL = R0.00 in CoreX. The spreadsheet may show SDL. CoreX is correct — SDL only activates after 12 months of finalised runs accumulate to meet the R500,000 annual threshold. For the first run, this will show R0.
- PAYE rounding: a difference of up to R1.00 is acceptable if the spreadsheet uses SARS weekly tables vs CoreX monthly tables.

**Differences NOT acceptable:**
- Any employee net pay more than R1 different
- Any UIF amount more than R0.50 different (UIF is a simple 1% calculation)
- Any missing earning or deduction line
- Any employee missing from the run entirely

### Step 4: Fix any discrepancies

If a payslip doesn't match:

1. Click **Edit** on that payslip
2. Find the line that's wrong
3. Either:
   - Edit the amount directly (click the amount field, type new value, click Save)
   - Or click **Recalculate from Current Profile** to pull fresh values from the employee's profile (useful if you updated their profile after creating the run)
4. Go back to the run and re-check

### Step 5: Sign-off

Once every payslip matches the spreadsheet within tolerance:

1. **Karin** (accountant) signs the printed comparison sheet confirming all figures match
2. **Elize** confirms banking details are correct for EFT processing
3. On the run detail page, click the green **Finalise** button
4. A confirmation dialog appears: "Finalise X payslips totalling R... net pay? This action cannot be undone."
5. Click **Yes, Finalise**
6. The system will:
   - Generate a PDF payslip for each employee
   - File each PDF to the employee's document profile automatically
   - Lock the run permanently (no further edits possible)

### Step 6: After finalise

1. Click **Download Bundle** — saves a ZIP file containing all payslip PDFs
2. Click **View Report** — shows the run summary with EMP201 figures:
   - **PAYE (4102)**: total income tax deducted
   - **UIF Employee (4141)**: total employee UIF contribution
   - **UIF Employer**: total employer UIF contribution (same amount as employee)
   - **SDL**: Skills Development Levy (R0 for first run)
3. These are the figures you key into **eFiling** for the EMP201 monthly submission, due **7 June 2026**
4. Each employee's payslip is automatically available in their **My Portal > Payslips** tab
5. Email or distribute the individual payslip PDFs from the bundle as needed

---

## If something goes wrong

- **DO NOT click Finalise until everything matches.** Once finalised, the run cannot be edited. The only fix is to create a correction run for the next month, which causes audit confusion.
- **If you Cancel a draft run by mistake**, just create a new one. Cancelled runs do not block new runs for the same period.
- **If you finalise and immediately spot an error**, contact Johan immediately. A manual database correction may be possible within the first hour, but after that the run is permanently sealed.
- **If the projected totals are wildly off**, the most likely causes are:
  1. An employee's basic salary is entered incorrectly (check the Employees list for basic salary column)
  2. An employee is missing from the payroll roster (check Payroll > Employees)
  3. A PAYE override was set when it shouldn't be (or vice versa)

---

## Post-run verification (by 27 May)

- [ ] All employees received their payslip (via email from the bundle, or printed)
- [ ] All payslips appear in employees' My Portal > Payslips tab (ask one employee to check)
- [ ] EMP201 figures captured and ready for SARS eFiling submission by 7 June
- [ ] Bundle ZIP archived to the company shared drive (backup copy)
- [ ] Printed comparison sheet (spreadsheet vs CoreX) signed by Karin and filed
- [ ] Any discrepancies documented with explanation for spec adjustment
- [ ] Decision made: CoreX replaces spreadsheet from June 2026 onward (yes/no)

---

## Contact

- **System issues**: Johan Reichel (WhatsApp or email)
- **Tax calculation queries**: Compare against SARS PAYE calculator at sars.gov.za
- **Banking/EFT queries**: Elize Reichel

---

*Document version: 1.0 | Created: 28 April 2026 | For: May 2026 parallel run only*
