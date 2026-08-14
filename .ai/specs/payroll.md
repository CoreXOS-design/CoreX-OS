# Payroll (Salary Payslip) Module — Spec

> Status: **DRAFT for Johan review** · 2026-08-01 · owner: payroll lane
> This spec was written **after** the module was built (spec-first violation AT-237/I1). It
> captures the module **as it currently exists** and defines the **acceptance criteria** the
> outstanding AT-237 fixes must land against. Doc-only — no code changes accompany it.
> Companion audits: `.ai/audits/2026-07-13-AT-237-payroll-audit.md` (board),
> `.ai/audits/2026-08-01-AT-237-payroll-current-status.md` (current status).

---

## 1. Purpose & business requirement

CoreX processes **salary payroll** for an agency's own staff (not deal commission). For a pay
period it must: compute each employee's gross → statutory deductions (PAYE, UIF, SDL) → net;
produce a compliant, immutable PDF payslip; file it to the agency and surface it to the worker
in their portal. This is real money and a SARS/UIF/SDL compliance obligation — **wrong money or
a wrong statutory declaration is the worst failure mode**, worse than an error page.

> **Salary payroll ≠ deal-commission payslips.** Commission payslips live at `dr2/print/payslip`,
> `deals-v2/settlement/payslip`, `admin/deals/print/payslip`. This spec is ONLY the salary module
> at `/corex/payroll/*`.

## 2. Pillars

- **Agent / User** (`User` in the agent role) — the employee being paid; `payroll_employees`
  links a `User` to an agency's payroll.
- **Agency** — the employer; owns tax-obligation flags (SDL) and scopes all payroll data.

Reads from: `users` (identity, ID number, banking). Writes back: payslip Documents + the worker
portal "My Payslips" view. SARS reference data (PAYE brackets, rebates, UIF ceiling, SDL rate) is
**global**, not agency-scoped.

## 3. Current behaviour (as-built)

### 3.1 Navigation & permissions
- URL prefix `/corex/payroll/*`; sidebar **Payroll → Employees / Earning Types / Deduction Types / Runs**.
- Routes: `routes/web.php` (payroll group). Controllers: `app/Http/Controllers/Payroll/*`.
- Permissions (`config/corex-permissions.php`, section `payroll`):
  `manage_payroll` (employees/types), `run_payroll` (run & finalise), `view_payroll_reports`,
  and portal `view_own_payslips`. All admin routes require `agency.required`.

### 3.2 Data model (tables)
- `payroll_tax_tables` (SARS PAYE brackets; `tax_year_start/end`) — **global, seed-only**.
- `payroll_tax_rebates` (primary/secondary/tertiary rebates, thresholds, UIF ceiling, SDL rate) — **global, seed-only**.
- `payroll_earning_types` (agency, `code`, `sars_source_code`, `is_taxable`, `affects_uif_remuneration`,
  `affects_sdl_remuneration`, `pro_rates_on_partial`, soft-deletes, generated `active_code_key`).
- `payroll_deduction_types` (agency, `code`, soft-deletes, generated `active_code_key`).
- `payroll_employees` (agency, `user_id`, `is_active`, `employment_date`, `termination_date`,
  `daily_rate_basis`, leave columns, soft-deletes).
- `payroll_employee_earnings` / `payroll_employee_deductions` (effective-dated per-employee lines, soft-deletes).
- `payroll_runs` (agency, `run_number`, `period_month`, `pay_date`, `cut_date`, `status`
  draft|finalised|cancelled, finalised/cancelled audit cols, totals, soft-deletes,
  **generated `active_period_key`**).
- `payroll_payslips` (run, employee, snapshots of name/ID/tax-ref/employment-date/designation,
  totals, `document_id`, soft-deletes, **generated `active_payslip_key`**).
- `payroll_payslip_lines` (payslip, line_type, snapshots incl. `sars_source_code_snapshot`, `amount`,
  `is_taxable_snapshot`, **soft-deletes**).
- `user_banking_details` (`user_id` **globally unique** — see FIX-1).

### 3.3 Workflow (happy path — verified working)
1. **Setup types** (seeded defaults exist): Earning Types, Deduction Types.
2. **Onboard employee** — Employees → Add → pick a `User`; seeds Basic Salary R0 + PAYE/UIF R0
   (operator **must edit Basic off R0**). Optional banking.
3. **New Run** — Runs → New Run; period auto-selects (current month if before the 25th, else next).
   `cut_date` may be set (agency default or per-run) to drive proration.
4. **Create Draft Run = the calculate step** (there is no separate "calculate" button). One DB
   transaction computes + persists every payslip.
5. **Review / edit draft** — per-payslip Recalculate-from-Profile; watermarked preview PDF.
6. **Finalise** — validates (see §5); status → `finalised` (permanent); generates un-watermarked
   PDFs; auto-files to Documents + writes the worker-portal copy.
7. **Cancel** (draft only) — soft-deletes the run + its payslips + lines; frees the month.
8. One **active** (draft OR finalised) run per `(agency, month)`; a cancelled/soft-deleted run does
   **not** occupy the month (generated `active_period_key`).

### 3.4 Calculation engine (`app/Services/Payroll/PayrollCalculator.php`)
- **PAYE** — annualise taxable income ×12 → SARS brackets (`PayrollTaxTable::forTaxYear`) → subtract
  age rebates (`PayrollTaxRebate`) → ÷12. Age from ID number as of the run period.
- **UIF** — 1% employee + 1% employer, each capped at the monthly ceiling.
- **SDL** — 1% employer, only if the agency is SDL-obligated.
- **Proration** — an employee starting/terminating mid-period is prorated over the worked window
  using their `daily_rate_basis`; `payroll_earning_types.pro_rates_on_partial` decides whether a
  line prorates (Basic yes, fixed allowances no). Full month = factor 1.0.
- **Unpaid leave** — a pre-tax deduction using a period-aware daily rate.
- **Overlapping effective-dated earnings** — the **latest** effective row per type is used (not summed).

### 3.5 Finalisation & immutability (`PayrollFinaliseService.php`, `PayslipPdfService.php`)
- Finalise takes `lockForUpdate` + re-checks `isDraft()` (no concurrent double-finalise).
- A finalised payslip's PDF is served from the **stored** file (not re-rendered with live data).
- Missing/broken tax data is a **hard refusal at run creation** (`MissingTaxDataException`) — PAYE
  can no longer silently zero.

### 3.6 Reference-data provisioning
- `PayrollTaxTableSeeder` + `PayrollTaxRebateSeeder` are registered in
  `app/Console/Commands/Deploy/SyncReferenceData.php` (`deploy:sync-reference-data`) so a
  promoted/fresh env gets SARS data (they are seed-only and do NOT run on a git-pull deploy).

### 3.7 Already-fixed constraint classes (AT-237 batches 1–4 + A3, landed staging+qa1+main)
Soft-delete/status-blind uniques replaced with generated **active-key** columns on `payroll_runs`
(`active_period_key`), `payroll_payslips` (`active_payslip_key`), `payroll_earning_types` /
`payroll_deduction_types` (`active_code_key`); `payroll_payslip_lines` gained soft-deletes; finalise
gained lock + re-check; finalised PDF no longer drifts; PAYE hard-stops on missing tax data. **These
are DONE — this spec documents them as current behaviour, not pending work.**

---

## 4. Outstanding defects → fixes (acceptance criteria)

> These are the record the fixes land against. Each fix is small and behaviour-preserving on the
> happy path. Evidence file:line are from the current Staging tree (2026-08-01).

### FIX-1 — `user_banking_details` second write → 1062 (500)
**Current:** `user_id` is globally unique (`database/migrations/2026_04_23_100002:13`); `storeBanking()`
does an unconditional `UserBankingDetail::create()` (`PayrollEmployeeController.php:480`) while
`updateBanking()` guards with a `->first()` (`:506`). Saving banking for a user who already has a row
(same agency re-entry, or the same user across two agencies — `user_id` is global) → **1062 → 500**.
**Acceptance criteria:**
- Saving banking for a user who already has banking **updates** it, never 500s (`updateOrCreate` on `user_id`, or route store→update when a row exists).
- A user who is an employee in two agencies can have banking saved from either without collision.
- Regression test: onboard employee with banking → save banking again → 200 + single row.

### FIX-2 — `payroll_employees` re-add after remove → 1062 (500)
**Current:** unique `(agency_id, user_id)` is **not** soft-delete-aware (`..100008:27`); `destroy()`
soft-deletes (`PayrollEmployeeController.php:286`); `reactivate()` only flips `is_active`
(`:306`) so it cannot restore a trashed row; the Add flow's eligible-user list + guard exclude the
trashed row, so the operator re-picks the user and `PayrollEmployee::create()` (`:62`) hits the
unique → **1062 → 500** (dead-end, no in-UI recovery).
**Acceptance criteria:**
- Removing (soft-deleting) an employee and re-adding the same user **succeeds** (no 1062), OR the Add
  flow detects the trashed row and offers **Restore** instead of a raw create.
- Preferred shape (matches the module's fixed pattern): generated `active_user_key`
  (= `user_id` when `deleted_at IS NULL` else NULL) + unique on `(agency_id, active_user_key)`.
  ⚠️ Migration ordering: add the new unique **before** dropping the old `(agency_id,user_id)` unique
  (the `agency_id` FK needs a covering index — dropping first 1553's; the A3 migration hit this).
- Regression test: add → destroy → re-add same user → 200; audit trail (earnings/deductions) preserved.

### FIX-3 — orphaned/soft-deleted `user_id` on a run member → TypeError (500)
**Current:** `store()` reads `$emp->user->id` un-guarded (`PayrollRunController.php:254,295`); the
`create()` preview wraps each employee in try/catch and **silently skips** the bad one, so preview and
commit **disagree on headcount** and commit **500s the whole run** on one orphaned `user_id`.
**Acceptance criteria:**
- An employee whose `user_id` is orphaned/trashed is **skipped with a surfaced warning** in BOTH
  preview and `store()` (identical membership logic) — the run never 500s on one bad member.
- The run summary reports "N included, M skipped (reason)". Preview headcount == committed headcount.
- Regression test: run with one orphaned-user employee → run creates, bad one excluded + warned.

### FIX-4 — unknown `daily_rate_basis` + unpaid leave → un-caught RuntimeException (500)
**Current:** an unrecognised `daily_rate_basis` (e.g. `hours_per_day`) `throw`s inside the `store()`
loop with no try/catch (`PayrollEmployee.php:146`) → **500s the whole run**.
**Acceptance criteria:**
- An unknown/unsupported `daily_rate_basis` is handled per-employee (skip+warn, same channel as FIX-3),
  never 500s the whole run.
- Supported bases are documented; onboarding validates `daily_rate_basis` against that set.
- Regression test: employee on an unsupported basis + unpaid leave → run completes, that employee warned.

### FIX-5 (correctness) — UIF `override_statutory` rewrites employer contribution (B5)
**Current:** an override sets **both** employee and employer UIF to the single override value —
silently rewriting the employer 1%.
**Acceptance criteria:** an override targets **only** the intended side; the employer contribution is
never silently changed by an employee-side override. Recompute test asserts employer UIF = 1%×capped
remuneration regardless of an employee override.

### FIX-6 (correctness) — R0 / no-Basic payslip finalises (F2)
**Current:** a payslip with a `0.00` Basic (onboarding default never edited) **finalises + auto-files**
a meaningless R0 payslip; whether it blocks or files is an accident of whether a zero-row exists.
**Acceptance criteria:** finalise **refuses** (or explicitly flags for confirmation) a payslip with no
positive earning configured; "no salary configured" is a named, surfaced state, not a silent R0 file.

### FIX-7 (integration) — `payslip` UserDocument is write-only (G1)
**Current:** finalise writes `UserDocument document_type='payslip'`, but `'payslip'` is absent from
`UserDocument::$documentTypeLabels` → the row is invisible in the portal Documents grid (the payslip
still shows via the PayrollPayslip-backed Payslips tab).
**Acceptance criteria:** either add the `payslip` label/card so the write is visible, **or** drop the
redundant `user_documents` write. (Johan's call which — see §6.)

---

## 5. Finalisation gates (target state)

Finalise must refuse (not silently proceed) when any of these hold, each with a user-facing reason:
- negative net for any payslip (existing);
- zero earning lines / no positive earning configured (FIX-6);
- PAYE uncomputable (missing/boundary tax data) — already a creation-time hard stop (§3.5), re-assert at finalise;
- (post-fix) any employee that was skipped for FIX-3/FIX-4 reasons is listed, not silently dropped.

---

## 6. E1 — queue the payslip PDF generation (design-first)

**Problem:** finalise spawns **N synchronous Chromium processes inside the HTTP request**
(`PayrollFinaliseService` → `PayslipPdfService`, ~25s each). A 20–40 person run exceeds the FPM
timeout → **504 mid-transaction**. `bundlePdf()` (admin ZIP) and the agent's own payslip download
have the same synchronous-render exposure (E2/E3), and a live pool missing Chromium hard-500s the
worker's download (AT-169 env-parity).

**Target design (queue, do not render in the web request):**
1. **Finalise = fast + transactional.** Finalise validates, sets `status=finalised`, persists payslip
   rows/totals, and enqueues one **`GeneratePayslipPdf` job per payslip** (or a batch). It does **not**
   render any PDF synchronously. The DB transaction commits in well under the FPM timeout regardless of
   headcount.
2. **`GeneratePayslipPdf` job** (queued worker) renders one payslip via `PayslipPdfService`, stores the
   file, sets `payroll_payslips.document_id` + `pdf_generated_at`, and is **idempotent** (safe to retry;
   a second run overwrites the same stored artifact, never a duplicate Document — enforce a unique on
   `documents(source_type, source_id)` per D2).
3. **Run PDF status** — the run exposes a generation state (`pending / generating / ready / failed(n)`);
   the finalised-run screen shows progress; a failed job is retryable per payslip, not all-or-nothing.
4. **Reads render-on-miss, never render-in-request as the primary path.** Worker download and admin
   ZIP serve the **stored** PDF; if absent they enqueue/await the job (or show "generating…"), and a
   **missing Chromium is caught** → friendly "your payslip is being prepared" (E3), never a 500 stack
   trace on the worker's own page.
5. **Bundle/ZIP** streams stored PDFs; one missing/failed payslip is reported inline, does not 500 the
   whole ZIP (E2). No N+1 `loadMissing('run')`.

**Acceptance criteria:**
- Finalising a 40-person run returns in < a few seconds and never 504s; PDFs appear as jobs complete.
- Killing the queue mid-generation leaves the run `finalised` with a retryable `failed`/`pending` set —
  no duplicate Documents on retry (unique on `documents(source_type,source_id)`).
- Worker payslip download with Chromium absent shows a friendly state, not a 500.
- Admin ZIP with one un-generated payslip streams the rest + reports the gap.

**Queue/infra notes:** honour the existing CoreX queue worker topology (staging=Supervisor,
qa1=systemd `corex-qa1-queue`); ensure the live FPM **and** worker pools both have the Chromium
binary the renderer needs (AT-169). Unit tests skip the browser step (no puppeteer in the worktree
test env) — verify PDF generation on qa1.

---

## 7. OPEN questions — Johan's call (do NOT decide in code)

- **OPEN-C4 (passport / non-SA-ID age).** `User::getAgeOnDate()` parses the first 6 digits of
  `id_number` as an SA-ID `YYMMDD` with no validity check → a **passport holder gets a confident wrong
  age** → wrong rebate/threshold, no warning. Decision needed: (a) require a separate `date_of_birth`
  field for non-SA-ID employees; (b) detect non-SA-ID and refuse/flag rather than guess; (c) capture
  age band explicitly. Until decided, the calculator should **flag "age unverified"** rather than
  silently assume.
- **OPEN-G1 (FIX-7 direction).** Add the `payslip` portal doc label/card, or drop the redundant
  `user_documents` write? (Payslips already show via the Payslips tab.)
- **OPEN-D4 (correction / un-finalise path).** There is currently **no** un-finalise / amendment /
  credit-note path — a finalised error has zero in-system remedy. Decide the correction model
  (reversing entry + re-issue vs. controlled un-finalise) before building; this is a larger design item.
- **OPEN — proration & tax-year rollover policy.** Confirm the cut-date/proration rules and the
  tax-year-boundary behaviour (a new SARS year needs seeded tables; there is no admin CRUD — H1).
- **OPEN — SDL toggle surfacing (H3)** and **email-payslip-to-employee distribution (H2)** — desired?

---

## 8. Definition of Done (for the AT-237 fix pass)

1. FIX-1…FIX-4 land as one small "soft-delete/guard" pass; each has a regression test proving the
   previously-500 flow now returns 200. **No normal payroll flow 500s.**
2. FIX-5 + FIX-6 land with recompute/finalise tests; **no silent wrong money**.
3. E1 queue design implemented; a 40-person finalise never 504s; worker download never 500s on a
   missing renderer.
4. OPEN-C4/G1/D4 resolved by Johan and reflected here before their code lands.
5. Reference data verified on **live**: migrations ran + `deploy:sync-reference-data` seeded the SARS
   tables on 91.99.130.85 (the audit could not confirm this remotely — operational check).
6. This spec updated to match what shipped; the "OPEN" list emptied or explicitly deferred.

## 9. Files in scope (fix pass)
- `app/Http/Controllers/Payroll/PayrollEmployeeController.php` (FIX-1, FIX-2)
- `app/Http/Controllers/Payroll/PayrollRunController.php` (FIX-3, finalise gates)
- `app/Services/Payroll/PayrollCalculator.php`, `PayrollEmployee.php` (FIX-4, FIX-5)
- `app/Services/Payroll/PayrollFinaliseService.php`, `PayslipPdfService.php` (E1 queue, FIX-6)
- `app/Models/UserDocument.php` (FIX-7, if the "add label" direction is chosen)
- `app/Models/User.php` (`getAgeOnDate`, OPEN-C4)
- new: `app/Jobs/Payroll/GeneratePayslipPdf.php` (E1) + a migration for `active_user_key`
  (FIX-2) + a unique on `documents(source_type,source_id)` (E1 idempotency).

## 10. Test plan
Single-file, targeted (per the testing-speed rule): one regression test per FIX proving the exact
previously-500 flow returns 200, plus recompute assertions for FIX-5/FIX-6, plus a queued-finalise
test (idempotent retry, no duplicate Document). PDF/browser steps verified on qa1 (no puppeteer in the
unit env). Never run the full suite without Johan's go.

---

_History: module built 2026-04-23 (no spec — I1). Audited 2026-07-13 (AT-237): 1 Critical/10 High.
Batches 1–4 + A3 fixed the walk-blocker + silent-R0 + finalisation integrity (staging+qa1+main).
Current-status re-audit 2026-08-01 confirmed 11 fixed / 3 partial / 8 open; this spec is the record
the remaining fixes land against._
