<?php

namespace Database\Seeders;

use App\Models\Compliance\AgencyDocumentTypeConfig;
use Illuminate\Database\Seeder;

class AgencyDocumentTypeConfigSeeder extends Seeder
{
    public function run(): void
    {
        $agencyId = 1; // HFC agency

        $types = [
            [
                'name'                   => 'FFC Certificate',
                'slug'                   => 'ffc_certificate',
                'has_expiry'             => true,
                'renewal_days'           => 60,
                'required'               => true,
                'sort_order'             => 1,
            ],
            [
                'name'                   => 'Bank Confirmation Letter',
                'slug'                   => 'bank_confirmation',
                'has_expiry'             => true,
                'renewal_days'           => 14,
                'required'               => true,
                'sort_order'             => 2,
            ],
            [
                'name'                   => 'BEE Certificate',
                'slug'                   => 'bee_certificate',
                'has_expiry'             => true,
                'renewal_days'           => 30,
                'required'               => false,
                'sort_order'             => 3,
            ],
            [
                'name'                   => 'Company Registration (CIPC)',
                'slug'                   => 'cipc_registration',
                'has_expiry'             => false,
                'renewal_days'           => null,
                'required'               => true,
                'sort_order'             => 4,
            ],
            [
                'name'                   => 'VAT Registration Certificate',
                'slug'                   => 'vat_certificate',
                'has_expiry'             => false,
                'renewal_days'           => null,
                'required'               => false,
                'sort_order'             => 5,
            ],
        ];

        foreach ($types as $type) {
            AgencyDocumentTypeConfig::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agencyId, 'slug' => $type['slug']],
                array_merge($type, ['agency_id' => $agencyId, 'is_active' => true])
            );
        }
    }
}
