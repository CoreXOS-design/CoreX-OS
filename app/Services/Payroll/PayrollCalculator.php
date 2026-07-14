<?php

namespace App\Services\Payroll;

use App\Models\Agency;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeDeduction;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollTaxRebate;
use App\Models\Payroll\PayrollTaxTable;
use App\Models\User;
use Carbon\Carbon;

class PayrollCalculator
{
    /**
     * Calculate a full payslip for an employee in a given pay period.
     *
     * @param PayrollEmployee $employee  The payroll employee profile
     * @param Carbon          $periodMonth  First of the month (e.g. 2026-05-01)
     * @param Carbon|null     $asOfDate  Date for resolving effective earnings/deductions (defaults to end of period month)
     */
    /**
     * @param array $preTaxAdjustments Array of ['label'=>string, 'amount'=>string (negative for deductions)]
     *   Applied before PAYE/UIF calculation. Used for unpaid leave deductions.
     */
    public function calculatePayslip(
        PayrollEmployee $employee,
        Carbon $periodMonth,
        ?Carbon $asOfDate = null,
        array $preTaxAdjustments = [],
        ?Carbon $cutDate = null,
    ): PayslipCalculation {
        // AT-237 B1/B2 — partial-period window. The cut defaults to the full month
        // (period end); a run's operator-selected cut, or a mid-month TERMINATION,
        // pulls it earlier; a mid-month EMPLOYMENT date pushes the start later. Basic
        // pro-rates over [effectiveStart..effectiveCut]; allowances always pay full.
        $periodStart = $periodMonth->copy()->startOfMonth();
        $periodEnd = $periodMonth->copy()->endOfMonth();

        $effectiveCut = $cutDate ? $cutDate->copy() : $periodEnd->copy();
        if ($effectiveCut->gt($periodEnd)) { $effectiveCut = $periodEnd->copy(); }
        if ($effectiveCut->lt($periodStart)) { $effectiveCut = $periodStart->copy(); }
        if (! empty($employee->termination_date)) {
            $term = $employee->termination_date instanceof Carbon
                ? $employee->termination_date->copy() : Carbon::parse((string) $employee->termination_date);
            if ($term->lt($effectiveCut)) { $effectiveCut = $term->copy(); }
        }
        $effectiveStart = $periodStart->copy();
        if (! empty($employee->employment_date)) {
            $hire = $employee->employment_date instanceof Carbon
                ? $employee->employment_date->copy() : Carbon::parse((string) $employee->employment_date);
            if ($hire->gt($effectiveStart)) { $effectiveStart = $hire->copy(); }
        }

        // Resolve the employee template as of the cut (their package on their last worked day).
        $asOfDate = $asOfDate ?? $effectiveCut->copy();
        $warnings = [];
        $trace = [];

        [$prorationFactor, $prorationTrace, $prorationWarn] =
            $this->prorationFactor($employee, $periodStart, $periodEnd, $effectiveStart, $effectiveCut);
        $trace = array_merge($trace, $prorationTrace);
        $warnings = array_merge($warnings, $prorationWarn);

        // 1. Gather earnings — Basic pro-rated to the cut; allowances (and all non-basic) full.
        $earnings = $this->gatherEarnings($employee, $asOfDate, $prorationFactor);

        // 1b. Apply pre-tax adjustments (e.g. unpaid leave deductions)
        // These reduce gross/taxable BEFORE PAYE/UIF calc
        $adjustmentTotal = '0.00';
        foreach ($preTaxAdjustments as $adj) {
            $adjustmentTotal = bcadd($adjustmentTotal, $adj['amount'], 2);
            $trace[] = "Pre-tax adjustment: {$adj['label']} R{$adj['amount']}";
        }

        // 2. Gather non-statutory deductions (+ any overridden statutory)
        $templateDeductions = $this->gatherDeductions($employee, $asOfDate);

        // 3. Compute income breakdowns
        $totalEarnings = $this->sumAmounts($earnings);
        // Apply adjustment to total earnings (negative adjustment reduces)
        $totalEarnings = bcadd($totalEarnings, $adjustmentTotal, 2);
        if (bccomp($totalEarnings, '0', 2) < 0) {
            $totalEarnings = '0.00';
            $warnings[] = 'Gross earnings reduced to zero by pre-tax adjustments';
        }

        $taxableIncome = bcadd($this->sumTaxable($earnings), $adjustmentTotal, 2);
        if (bccomp($taxableIncome, '0', 2) < 0) {
            $taxableIncome = '0.00';
        }
        $nonTaxableIncome = bcsub($totalEarnings, $taxableIncome, 2);
        $uifRemuneration = bcadd($this->sumUifRemuneration($earnings), $adjustmentTotal, 2);
        if (bccomp($uifRemuneration, '0', 2) < 0) {
            $uifRemuneration = '0.00';
        }
        $sdlRemuneration = bcadd($this->sumSdlRemuneration($earnings), $adjustmentTotal, 2);
        if (bccomp($sdlRemuneration, '0', 2) < 0) {
            $sdlRemuneration = '0.00';
        }

        $trace[] = "Total earnings: R{$totalEarnings}";
        $trace[] = "Taxable income: R{$taxableIncome}/month";
        $trace[] = "Non-taxable income: R{$nonTaxableIncome}";
        $trace[] = "UIF remuneration: R{$uifRemuneration}";
        $trace[] = "SDL remuneration: R{$sdlRemuneration}";

        // 4. Check for statutory overrides
        $payeOverride = $this->findStatutoryOverride($templateDeductions, 'paye');
        $uifOverride = $this->findStatutoryOverride($templateDeductions, 'uif_employee');

        // 5. Calculate PAYE
        if ($payeOverride !== null) {
            $payeAmount = $payeOverride['amount'];
            $trace[] = "PAYE: override applied = R{$payeAmount}";
        } else {
            $employee->loadMissing('user');
            $payeResult = $this->calculatePaye($taxableIncome, $periodMonth, $employee->user);
            $payeAmount = $payeResult['amount'];
            $warnings = array_merge($warnings, $payeResult['warnings']);
            $trace = array_merge($trace, $payeResult['trace']);
        }

        // 6. Calculate UIF
        if ($uifOverride !== null) {
            $uifEmployeeAmount = $uifOverride['amount'];
            $uifEmployerAmount = $uifOverride['amount'];
            $trace[] = "UIF: override applied = R{$uifEmployeeAmount}";
        } else {
            $uifResult = $this->calculateUif($uifRemuneration, $periodMonth);
            $uifEmployeeAmount = $uifResult['employee'];
            $uifEmployerAmount = $uifResult['employer'];
            $trace = array_merge($trace, $uifResult['trace']);
        }

        // 7. Calculate SDL (employer-only, no override)
        $employee->loadMissing('agency');
        $sdlResult = $this->calculateSdl($sdlRemuneration, $employee->agency, $periodMonth);
        $sdlAmount = $sdlResult['amount'];
        $trace = array_merge($trace, $sdlResult['trace']);

        // 8. Build final deductions array (non-statutory + PAYE + UIF employee)
        $deductions = [];

        // Non-statutory deductions from template (exclude overridden statutory — they've been handled)
        foreach ($templateDeductions as $d) {
            if ($d['is_override']) {
                continue; // already accounted for above
            }
            $deductions[] = [
                'deduction_type_id' => $d['deduction_type_id'],
                'label'             => $d['label'],
                'sars_code'         => $d['sars_code'],
                'amount'            => $d['amount'],
                'is_statutory'      => $d['is_statutory'],
            ];
        }

        // Add PAYE deduction
        $deductions[] = [
            'deduction_type_id' => $this->resolveDeductionTypeId($employee, 'paye'),
            'label'             => 'PAYE',
            'sars_code'         => '4102',
            'amount'            => $payeAmount,
            'is_statutory'      => true,
        ];

        // Add UIF employee deduction
        $deductions[] = [
            'deduction_type_id' => $this->resolveDeductionTypeId($employee, 'uif_employee'),
            'label'             => 'UIF',
            'sars_code'         => '4141',
            'amount'            => $uifEmployeeAmount,
            'is_statutory'      => true,
        ];

        // 9. Employer contributions
        $employerContributions = [
            ['label' => 'UIF (employer)', 'sars_code' => '4141', 'amount' => $uifEmployerAmount],
            ['label' => 'SDL',            'sars_code' => null,   'amount' => $sdlAmount],
        ];

        // 10. Compute totals
        $totalDeductions = '0.00';
        foreach ($deductions as $d) {
            $totalDeductions = bcadd($totalDeductions, $d['amount'], 2);
        }

        $netPay = bcsub($totalEarnings, $totalDeductions, 2);

        $trace[] = "Total deductions: R{$totalDeductions}";
        $trace[] = "Net pay: R{$netPay}";

        return new PayslipCalculation(
            earnings: $earnings,
            deductions: $deductions,
            employerContributions: $employerContributions,
            totalEarnings: $totalEarnings,
            totalDeductions: $totalDeductions,
            taxableIncome: $taxableIncome,
            nonTaxableIncome: $nonTaxableIncome,
            uifRemuneration: $uifRemuneration,
            sdlRemuneration: $sdlRemuneration,
            payeAmount: $payeAmount,
            uifEmployeeAmount: $uifEmployeeAmount,
            uifEmployerAmount: $uifEmployerAmount,
            sdlAmount: $sdlAmount,
            netPay: $netPay,
            warnings: $warnings,
            calculationTrace: $trace,
        );
    }

    // ── Earnings & Deductions Gathering ──

    private function gatherEarnings(PayrollEmployee $employee, Carbon $asOfDate, string $prorationFactor = '1.000000'): array
    {
        $rows = PayrollEmployeeEarning::withoutGlobalScopes()
            ->where('payroll_employee_id', $employee->id)
            ->current($asOfDate)
            ->with('earningType')
            ->get();

        // AT-237 B3 — keep only the LATEST effective row per earning type. A raise
        // entered as a new row without end-dating the old one leaves both "current"
        // at month-end; summing them = double pay. The most recent version wins.
        $rows = $this->latestEffectivePerType($rows, 'earning_type_id');

        $earnings = [];
        foreach ($rows as $row) {
            $type = $row->earningType;
            if (! $type || ! $type->is_active) {
                continue;
            }
            $amount = $this->round2((string) $row->amount);
            // AT-237 B1 — pro-rate ONLY earnings flagged pro_rates_on_partial (Basic).
            // Allowances / bonus / overtime / commission are NOT flagged → pay full.
            if ($type->pro_rates_on_partial && bccomp($prorationFactor, '1', 6) < 0) {
                $amount = $this->round2(bcmul($amount, $prorationFactor, 6));
            }
            $earnings[] = [
                'earning_type_id' => $type->id,
                'label'           => $type->label,
                'sars_code'       => $type->sars_source_code,
                'amount'          => $amount,
                'is_taxable'      => (bool) $type->is_taxable,
                'affects_uif'     => (bool) $type->affects_uif_remuneration,
                'affects_sdl'     => (bool) $type->affects_sdl_remuneration,
            ];
        }

        return $earnings;
    }

    /**
     * AT-237 B1 — proration factor [0..1] for pro-ratable (Basic) earnings, per the
     * employee's daily_rate_basis, over the worked window vs the period. 1.0 for a
     * full unbroken month (so full-month payslips are UNCHANGED). hours_per_day is
     * absorbed to calendar working days with a warning (not yet a supported basis).
     *
     * @return array{0:string,1:array,2:array} [factor(6dp), traceLines, warnings]
     */
    private function prorationFactor(PayrollEmployee $employee, Carbon $periodStart, Carbon $periodEnd, Carbon $effectiveStart, Carbon $effectiveCut): array
    {
        if ($effectiveStart->lte($periodStart) && $effectiveCut->gte($periodEnd)) {
            return ['1.000000', [], []]; // full month — no proration
        }
        if ($effectiveCut->lt($effectiveStart)) {
            return ['0.000000', ['Proration: no worked days in period → Basic R0'], []];
        }

        $worked = $employee->workingDaysBetween($effectiveStart, $effectiveCut);
        $basis = $employee->daily_rate_basis ?: 'fixed_21_67';
        $warnings = [];
        $trace = [];

        if ($basis === 'calendar_working_days') {
            $total = max($employee->workingDaysBetween($periodStart, $periodEnd), 1);
            $factor = min(1.0, $worked / $total);
            $trace[] = "Basic pro-rated (calendar_working_days): {$worked}/{$total} working days";
        } elseif ($basis === 'fixed_21_67') {
            $factor = min(1.0, $worked / 21.67);
            $trace[] = "Basic pro-rated (fixed_21_67): {$worked}/21.67 working days";
        } else {
            $total = max($employee->workingDaysBetween($periodStart, $periodEnd), 1);
            $factor = min(1.0, $worked / $total);
            $warnings[] = "daily_rate_basis '{$basis}' not supported for proration — used calendar working days ({$worked}/{$total})";
        }

        return [number_format($factor, 6, '.', ''), $trace, $warnings];
    }

    /**
     * AT-237 B3 — reduce effective-dated rows to ONE per type: the row with the
     * latest effective_from (ties broken by highest id). Prevents overlapping
     * effective ranges being summed into double pay / double deduction.
     */
    private function latestEffectivePerType(\Illuminate\Support\Collection $rows, string $typeKey): \Illuminate\Support\Collection
    {
        return $rows
            ->sort(fn ($a, $b) => [$b->effective_from->timestamp, $b->id] <=> [$a->effective_from->timestamp, $a->id])
            ->unique($typeKey)
            ->values();
    }

    private function gatherDeductions(PayrollEmployee $employee, Carbon $asOfDate): array
    {
        $rows = PayrollEmployeeDeduction::withoutGlobalScopes()
            ->where('payroll_employee_id', $employee->id)
            ->current($asOfDate)
            ->with('deductionType')
            ->get();

        // AT-237 B3 (fix the class) — same overlapping-effective-rows guard as
        // earnings: latest row per deduction type, never summed into double deduction.
        $rows = $this->latestEffectivePerType($rows, 'deduction_type_id');

        $deductions = [];
        foreach ($rows as $row) {
            $type = $row->deductionType;
            if (! $type || ! $type->is_active) {
                continue;
            }

            $isStatutory = (bool) $type->is_statutory;
            $isOverride = $isStatutory && (bool) $row->override_statutory;

            // Skip statutory deductions that are NOT overridden — they're auto-calculated
            if ($isStatutory && ! $isOverride) {
                continue;
            }

            $deductions[] = [
                'deduction_type_id' => $type->id,
                'label'             => $type->label,
                'sars_code'         => $type->sars_source_code,
                'amount'            => $this->round2((string) $row->amount),
                'is_statutory'      => $isStatutory,
                'is_override'       => $isOverride,
                'code'              => $type->code,
            ];
        }

        return $deductions;
    }

    // ── PAYE Calculation ──

    private function calculatePaye(string $monthlyTaxableIncome, Carbon $periodMonth, User $user): array
    {
        $trace = [];
        $warnings = [];

        // Zero taxable → zero PAYE
        if (bccomp($monthlyTaxableIncome, '0', 2) <= 0) {
            $trace[] = 'PAYE: taxable income is zero — PAYE = R0.00';
            return ['amount' => '0.00', 'trace' => $trace, 'warnings' => $warnings];
        }

        // Annualise
        $annualTaxable = bcmul($monthlyTaxableIncome, '12', 2);
        $trace[] = "PAYE: annualised taxable = R{$annualTaxable}";

        // Get tax brackets. AT-237 C2 — a missing statutory table is a HARD STOP,
        // never a silent PAYE = R0 (which finalised whole runs under-deducted).
        $brackets = PayrollTaxTable::forTaxYear($periodMonth)->get();
        if ($brackets->isEmpty()) {
            throw new \App\Exceptions\Payroll\MissingTaxDataException(
                $periodMonth->format('Y-m'), 'tax tables (PAYE brackets)'
            );
        }

        // Get rebate data
        $rebate = PayrollTaxRebate::forTaxYear($periodMonth)->first();
        if (! $rebate) {
            throw new \App\Exceptions\Payroll\MissingTaxDataException(
                $periodMonth->format('Y-m'), 'tax rebate / threshold data'
            );
        }

        // Determine age
        $age = $user->getAgeOnDate($periodMonth);
        if ($age === null) {
            $warnings[] = 'Age unknown (no date_of_birth or valid ID number) — assumed under 65 for PAYE';
            $age = 40; // Conservative: under 65
        }
        $trace[] = "Employee age at period: {$age}";

        // Check tax threshold first
        $threshold = $this->getThresholdForAge($age, $rebate);
        if (bccomp($annualTaxable, (string) $threshold, 2) < 0) {
            $trace[] = "Below tax threshold R{$threshold} for age {$age} — PAYE = R0.00";
            return ['amount' => '0.00', 'trace' => $trace, 'warnings' => $warnings];
        }

        // Find applicable bracket
        $annualTax = '0.00';
        foreach ($brackets as $bracket) {
            $from = $this->round2((string) $bracket->income_from);
            $to = $bracket->income_to !== null ? $this->round2((string) $bracket->income_to) : null;

            if (bccomp($annualTaxable, $from, 2) >= 0 &&
                ($to === null || bccomp($annualTaxable, $to, 2) <= 0)) {

                $baseTax = $this->round2((string) $bracket->base_tax);
                $excess = bcsub($annualTaxable, $from, 4);
                // Add 1 because bracket starts at income_from (inclusive)
                $excess = bcadd($excess, '1', 4);
                $rate = bcdiv((string) $bracket->rate_percent, '100', 6);
                $marginalTax = bcmul($excess, $rate, 4);
                $annualTax = bcadd($baseTax, $marginalTax, 2);

                $trace[] = "Bracket {$bracket->bracket_order}: base R{$baseTax} + (R{$annualTaxable} - R{$from} + 1) x {$bracket->rate_percent}% = R{$annualTax}";
                break;
            }
        }

        // Apply rebates
        $totalRebate = $this->round2((string) $rebate->primary_rebate);
        $rebateTrace = "Primary rebate: R{$totalRebate}";

        if ($age >= 65) {
            $secondary = $this->round2((string) $rebate->secondary_rebate);
            $totalRebate = bcadd($totalRebate, $secondary, 2);
            $rebateTrace .= " + secondary R{$secondary}";
        }
        if ($age >= 75) {
            $tertiary = $this->round2((string) $rebate->tertiary_rebate);
            $totalRebate = bcadd($totalRebate, $tertiary, 2);
            $rebateTrace .= " + tertiary R{$tertiary}";
        }
        $trace[] = $rebateTrace . " = R{$totalRebate}";

        // TODO: Medical aid credits (Tier 1 skips — no dependents field yet)

        $annualTax = bcsub($annualTax, $totalRebate, 2);
        $trace[] = "Annual tax after rebates: R{$annualTax}";

        // Floor at zero
        if (bccomp($annualTax, '0', 2) <= 0) {
            $trace[] = 'Annual tax ≤ 0 after rebates — PAYE = R0.00';
            return ['amount' => '0.00', 'trace' => $trace, 'warnings' => $warnings];
        }

        // Monthly PAYE
        $monthlyPaye = bcdiv($annualTax, '12', 4);
        $monthlyPaye = $this->round2($monthlyPaye);
        $trace[] = "Monthly PAYE: R{$annualTax} / 12 = R{$monthlyPaye}";

        return ['amount' => $monthlyPaye, 'trace' => $trace, 'warnings' => $warnings];
    }

    // ── UIF Calculation ──

    private function calculateUif(string $uifRemuneration, Carbon $periodMonth): array
    {
        $trace = [];

        if (bccomp($uifRemuneration, '0', 2) <= 0) {
            $trace[] = 'UIF: remuneration is zero — UIF = R0.00';
            return ['employee' => '0.00', 'employer' => '0.00', 'trace' => $trace];
        }

        $rebate = PayrollTaxRebate::forTaxYear($periodMonth)->first();
        $ceiling = $rebate ? $this->round2((string) $rebate->uif_ceiling_monthly) : '17712.00';
        $rate = $rebate ? (string) $rebate->uif_rate_percent : '1.000';

        // Cap at ceiling
        $capped = bccomp($uifRemuneration, $ceiling, 2) > 0 ? $ceiling : $uifRemuneration;

        $rateFraction = bcdiv($rate, '100', 6);
        $amount = bcmul($capped, $rateFraction, 4);
        $amount = $this->round2($amount);

        $trace[] = "UIF: min(R{$uifRemuneration}, R{$ceiling}) = R{$capped} x {$rate}% = R{$amount}";

        return ['employee' => $amount, 'employer' => $amount, 'trace' => $trace];
    }

    // ── SDL Calculation ──

    private function calculateSdl(string $sdlRemuneration, Agency $agency, Carbon $periodMonth): array
    {
        $trace = [];

        if (bccomp($sdlRemuneration, '0', 2) <= 0) {
            $trace[] = 'SDL: remuneration is zero — SDL = R0.00';
            return ['amount' => '0.00', 'trace' => $trace];
        }

        if (! $agency->hasSdlObligation()) {
            $trace[] = 'SDL: agency below annual threshold — exempt, SDL = R0.00';
            return ['amount' => '0.00', 'trace' => $trace];
        }

        $rebate = PayrollTaxRebate::forTaxYear($periodMonth)->first();
        $rate = $rebate ? (string) $rebate->sdl_rate_percent : '1.000';

        $rateFraction = bcdiv($rate, '100', 6);
        $amount = bcmul($sdlRemuneration, $rateFraction, 4);
        $amount = $this->round2($amount);

        $trace[] = "SDL: R{$sdlRemuneration} x {$rate}% = R{$amount}";

        return ['amount' => $amount, 'trace' => $trace];
    }

    // ── Summation Helpers ──

    private function sumAmounts(array $items): string
    {
        $total = '0.00';
        foreach ($items as $item) {
            $total = bcadd($total, $item['amount'], 2);
        }
        return $total;
    }

    private function sumTaxable(array $earnings): string
    {
        $total = '0.00';
        foreach ($earnings as $e) {
            if ($e['is_taxable']) {
                $total = bcadd($total, $e['amount'], 2);
            }
        }
        return $total;
    }

    private function sumUifRemuneration(array $earnings): string
    {
        $total = '0.00';
        foreach ($earnings as $e) {
            if ($e['affects_uif']) {
                $total = bcadd($total, $e['amount'], 2);
            }
        }
        return $total;
    }

    private function sumSdlRemuneration(array $earnings): string
    {
        $total = '0.00';
        foreach ($earnings as $e) {
            if ($e['affects_sdl']) {
                $total = bcadd($total, $e['amount'], 2);
            }
        }
        return $total;
    }

    // ── Utility Helpers ──

    private function findStatutoryOverride(array $deductions, string $code): ?array
    {
        foreach ($deductions as $d) {
            if ($d['is_override'] && $d['code'] === $code) {
                return $d;
            }
        }
        return null;
    }

    private function resolveDeductionTypeId(PayrollEmployee $employee, string $code): ?int
    {
        $type = \App\Models\Payroll\PayrollDeductionType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('code', $code)
            ->first();

        return $type?->id;
    }

    private function getThresholdForAge(int $age, PayrollTaxRebate $rebate): string
    {
        if ($age >= 75) {
            return $this->round2((string) $rebate->tax_threshold_75_plus);
        }
        if ($age >= 65) {
            return $this->round2((string) $rebate->tax_threshold_65_74);
        }
        return $this->round2((string) $rebate->tax_threshold_under_65);
    }

    /**
     * Round a bcmath string to 2 decimal places, half-up.
     */
    private function round2(string $value): string
    {
        // PHP's round() with mode does half-up; we convert carefully
        // to avoid float precision issues on large numbers.
        // For payroll amounts (< R10M), float precision is sufficient
        // for the rounding decision only. The result is formatted back to string.
        $rounded = number_format(round((float) $value, 2, PHP_ROUND_HALF_UP), 2, '.', '');
        return $rounded;
    }
}
