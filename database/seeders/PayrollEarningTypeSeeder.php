<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Payroll\PayrollEarningType;
use Illuminate\Database\Seeder;

class PayrollEarningTypeSeeder extends Seeder
{
    /**
     * Default earning types per spec §10.3.
     * Keyed by code for firstOrCreate idempotency.
     */
    private const DEFAULTS = [
        ['code' => 'basic',                 'label' => 'Basic Salary',         'sars_source_code' => '3601', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 1, 'is_system' => true,  'is_active' => true],
        ['code' => 'bonus',                 'label' => 'Bonus',                'sars_source_code' => '3605', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 2, 'is_system' => false, 'is_active' => true],
        ['code' => 'overtime',              'label' => 'Overtime',             'sars_source_code' => '3607', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 3, 'is_system' => false, 'is_active' => true],
        ['code' => 'cell_allowance',        'label' => 'Cell Allowance',       'sars_source_code' => '3713', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 4, 'is_system' => false, 'is_active' => true],
        ['code' => 'fuel_allowance',        'label' => 'Fuel Allowance',       'sars_source_code' => '3713', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 5, 'is_system' => false, 'is_active' => true],
        ['code' => 'travel_allowance_fixed','label' => 'Travel Allowance',     'sars_source_code' => '3701', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 6, 'is_system' => false, 'is_active' => true],
        ['code' => 'travel_reimbursive',    'label' => 'Reimbursive Travel',   'sars_source_code' => '3703', 'is_taxable' => false, 'is_fringe_benefit' => false, 'affects_uif_remuneration' => false, 'affects_sdl_remuneration' => false, 'sort_order' => 7, 'is_system' => false, 'is_active' => true],
        ['code' => 'subsistence',           'label' => 'Subsistence',          'sars_source_code' => '3714', 'is_taxable' => false, 'is_fringe_benefit' => false, 'affects_uif_remuneration' => false, 'affects_sdl_remuneration' => false, 'sort_order' => 8, 'is_system' => false, 'is_active' => true],
        ['code' => 'commission_earnings',   'label' => 'Commission (tax-only)','sars_source_code' => '3606', 'is_taxable' => true,  'is_fringe_benefit' => false, 'affects_uif_remuneration' => true,  'affects_sdl_remuneration' => true,  'sort_order' => 9, 'is_system' => true,  'is_active' => true],
    ];

    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();
        $count = 0;

        foreach ($agencies as $agency) {
            $this->seedForAgency($agency);
            $count++;
        }

        $this->command->info("Seeded earning types for {$count} agencies (" . count(self::DEFAULTS) . ' types each).');
    }

    /**
     * Seed default earning types for a single agency.
     * Safe to call multiple times — uses firstOrCreate on (agency_id, code).
     *
     * // TODO: Call this from AgencyService or AgencyObserver on agency creation
     * // so new agencies get default earning types automatically.
     */
    public function seedForAgency(Agency $agency): void
    {
        foreach (self::DEFAULTS as $type) {
            PayrollEarningType::withoutGlobalScopes()->firstOrCreate(
                [
                    'agency_id' => $agency->id,
                    'code'      => $type['code'],
                ],
                $type
            );
        }
    }
}
