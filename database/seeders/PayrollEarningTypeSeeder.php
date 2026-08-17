<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Payroll\PayrollEarningType;
use Illuminate\Database\Seeder;

class PayrollEarningTypeSeeder extends Seeder
{
    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();
        $count = 0;

        foreach ($agencies as $agency) {
            $this->seedForAgency($agency);
            $count++;
        }

        $this->command->info("Seeded earning types for {$count} agencies (" . count(PayrollEarningType::DEFAULTS) . ' types each).');
    }

    /**
     * Seed default earning types for a single agency. Idempotent — delegates to
     * PayrollEarningType::seedDefaultsFor() (the single source of truth for the
     * default set), which is also wired into AgencyObserver (new agencies) and the
     * payroll:seed-default-types command (existing agencies).
     */
    public function seedForAgency(Agency $agency): void
    {
        PayrollEarningType::seedDefaultsFor((int) $agency->id);
    }
}
