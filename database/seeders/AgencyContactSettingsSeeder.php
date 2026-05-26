<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use Illuminate\Database\Seeder;

class AgencyContactSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();

        foreach ($agencies as $agency) {
            AgencyContactSettings::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agency->id],
                [
                    // HFC (agency_id=1) gets 'open' to preserve existing behaviour
                    'sharing_mode' => $agency->id === 1 ? 'open' : 'branch',
                    'duplicate_mode' => 'soft_warn',
                    'duplicate_match_fields' => ['phone', 'email', 'id_number'],
                    'buyer_warm_days' => 14,
                    'buyer_cold_days' => 30,
                    'buyer_lost_days' => 60,
                    'contact_retention_years' => 5,
                    'consent_retention_years' => 5,
                    'access_log_retention_years' => 5,
                ]
            );
        }
    }
}
