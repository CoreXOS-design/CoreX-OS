<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Global performance_settings — key/value pairs read by Deal,
 * WorksheetController, MatchPropertyJob and AgentPerformanceService via
 * PerformanceSetting::get($key, $default). The runtime defaults if a key
 * is missing, so absence wasn't UI-breaking — but the Admin → Performance
 * Settings screen renders blank without these rows.
 *
 * Values captured verbatim from local nexus_os (8 rows). Idempotent:
 * updateOrInsert keyed on `key`.
 */
class PerformanceSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'vat_rate'           => '15',
            'listings_per_sale'  => '5',
            'company_name'       => 'Home Finders Coastal',
            'company_address'    => 'The Emporium Shop 5, Shelly Beach, Margate',
            'company_tel'        => '(039) 315 0857',
            'company_ffc'        => '2023116041',
            'company_logo_url'   => '/storage/company/hfc-logo.jpg',
            'marketing_enabled'  => '0',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('performance_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value'      => $value,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->command?->info('Seeded ' . count($defaults) . ' performance_settings rows.');
    }
}
