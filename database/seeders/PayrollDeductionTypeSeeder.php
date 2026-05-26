<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Payroll\PayrollDeductionType;
use Illuminate\Database\Seeder;

class PayrollDeductionTypeSeeder extends Seeder
{
    /**
     * Default deduction types per spec §10.4.
     */
    private const DEFAULTS = [
        ['code' => 'paye',                 'label' => 'PAYE',            'sars_source_code' => '4102', 'is_statutory' => true,  'is_system' => true,  'sort_order' => 1, 'is_active' => true],
        ['code' => 'uif_employee',         'label' => 'UIF',             'sars_source_code' => '4141', 'is_statutory' => true,  'is_system' => true,  'sort_order' => 2, 'is_active' => true],
        ['code' => 'cellphone_deduction',  'label' => 'Cellphone',       'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 3, 'is_active' => true],
        ['code' => 'loan_repayment',       'label' => 'Loan Repayment',  'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 4, 'is_active' => true],
        ['code' => 'garnishee',            'label' => 'Garnishee Order', 'sars_source_code' => null,   'is_statutory' => false, 'is_system' => false, 'sort_order' => 5, 'is_active' => true],
    ];

    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();
        $count = 0;

        foreach ($agencies as $agency) {
            $this->seedForAgency($agency);
            $count++;
        }

        $this->command->info("Seeded deduction types for {$count} agencies (" . count(self::DEFAULTS) . ' types each).');
    }

    /**
     * Seed default deduction types for a single agency.
     * Safe to call multiple times — uses firstOrCreate on (agency_id, code).
     *
     * // TODO: Call this from AgencyService or AgencyObserver on agency creation
     * // so new agencies get default deduction types automatically.
     */
    public function seedForAgency(Agency $agency): void
    {
        foreach (self::DEFAULTS as $type) {
            PayrollDeductionType::withoutGlobalScopes()->firstOrCreate(
                [
                    'agency_id' => $agency->id,
                    'code'      => $type['code'],
                ],
                $type
            );
        }
    }
}
