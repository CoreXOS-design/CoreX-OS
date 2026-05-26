<?php

namespace App\Services\Payroll;

class PayslipCalculation
{
    public function __construct(
        /** @var array<int, array{earning_type_id: int, label: string, sars_code: ?string, amount: string, is_taxable: bool, affects_uif: bool, affects_sdl: bool}> */
        public readonly array $earnings,

        /** @var array<int, array{deduction_type_id: int, label: string, sars_code: ?string, amount: string, is_statutory: bool}> */
        public readonly array $deductions,

        /** @var array<int, array{label: string, sars_code: ?string, amount: string}> */
        public readonly array $employerContributions,

        public readonly string $totalEarnings,
        public readonly string $totalDeductions,
        public readonly string $taxableIncome,
        public readonly string $nonTaxableIncome,
        public readonly string $uifRemuneration,
        public readonly string $sdlRemuneration,
        public readonly string $payeAmount,
        public readonly string $uifEmployeeAmount,
        public readonly string $uifEmployerAmount,
        public readonly string $sdlAmount,
        public readonly string $netPay,
        public readonly array $warnings = [],
        public readonly array $calculationTrace = [],
    ) {}

    public function toArray(): array
    {
        return [
            'earnings'               => $this->earnings,
            'deductions'             => $this->deductions,
            'employer_contributions' => $this->employerContributions,
            'total_earnings'         => $this->totalEarnings,
            'total_deductions'       => $this->totalDeductions,
            'taxable_income'         => $this->taxableIncome,
            'non_taxable_income'     => $this->nonTaxableIncome,
            'uif_remuneration'       => $this->uifRemuneration,
            'sdl_remuneration'       => $this->sdlRemuneration,
            'paye_amount'            => $this->payeAmount,
            'uif_employee_amount'    => $this->uifEmployeeAmount,
            'uif_employer_amount'    => $this->uifEmployerAmount,
            'sdl_amount'             => $this->sdlAmount,
            'net_pay'                => $this->netPay,
            'warnings'               => $this->warnings,
            'calculation_trace'      => $this->calculationTrace,
        ];
    }

    public function formatForDisplay(): array
    {
        $fmt = fn (string $v) => 'R ' . number_format((float) $v, 2, '.', ',');

        return [
            'total_earnings'      => $fmt($this->totalEarnings),
            'total_deductions'    => $fmt($this->totalDeductions),
            'taxable_income'      => $fmt($this->taxableIncome),
            'non_taxable_income'  => $fmt($this->nonTaxableIncome),
            'paye_amount'         => $fmt($this->payeAmount),
            'uif_employee_amount' => $fmt($this->uifEmployeeAmount),
            'uif_employer_amount' => $fmt($this->uifEmployerAmount),
            'sdl_amount'          => $fmt($this->sdlAmount),
            'net_pay'             => $fmt($this->netPay),
        ];
    }
}
