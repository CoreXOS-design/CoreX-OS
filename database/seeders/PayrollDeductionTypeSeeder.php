<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Payroll\PayrollDeductionType;
use Illuminate\Database\Seeder;

class PayrollDeductionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();
        $count = 0;

        foreach ($agencies as $agency) {
            $this->seedForAgency($agency);
            $count++;
        }

        $this->command->info("Seeded deduction types for {$count} agencies (" . count(PayrollDeductionType::DEFAULTS) . ' types each).');
    }

    /**
     * Seed default deduction types for a single agency. Idempotent — delegates to
     * PayrollDeductionType::seedDefaultsFor() (the single source of truth for the
     * default set), which is also wired into AgencyObserver (new agencies) and the
     * payroll:seed-default-types command (existing agencies).
     */
    public function seedForAgency(Agency $agency): void
    {
        PayrollDeductionType::seedDefaultsFor((int) $agency->id);
    }
}
