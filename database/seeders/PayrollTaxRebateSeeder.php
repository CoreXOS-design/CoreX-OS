<?php

namespace Database\Seeders;

use App\Models\Payroll\PayrollTaxRebate;
use Illuminate\Database\Seeder;

class PayrollTaxRebateSeeder extends Seeder
{
    public function run(): void
    {
        PayrollTaxRebate::updateOrCreate(
            ['tax_year_start' => '2026-03-01'],
            [
                'primary_rebate'            => 17235.00,
                'secondary_rebate'          => 9444.00,
                'tertiary_rebate'           => 3145.00,
                'tax_threshold_under_65'    => 95750.00,
                'tax_threshold_65_74'       => 148217.00,
                'tax_threshold_75_plus'     => 165689.00,
                'medical_credit_main'       => 364.00,
                'medical_credit_additional' => 246.00,
                'uif_ceiling_monthly'       => 17712.00,
                'uif_rate_percent'          => 1.000,
                'sdl_threshold_annual'      => 500000.00,
                'sdl_rate_percent'          => 1.000,
            ]
        );

        $this->command->info('Seeded 2026/27 tax rebates and thresholds.');
    }
}
