<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WebTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // E-sign capability (per .ai/specs/claude_esignature_v2_spec.md §3 + the
        // ESIGN WIZARD TEMPLATE INVESTIGATION):
        //  - is_esign=true is the hard gate the wizard Step 1 query requires.
        //  - signing_parties: JSON role tokens (owner_party = landlord/lessor,
        //    acquiring_party = tenant/lessee, agent). Stored json-encoded
        //    because this seeder uses raw DB::table() (mirrors the canonical
        //    migration 2026_04_29_123451_rebuild_template_116 precedent).
        //  - allowed_delivery_modes: set explicitly (incl. 'esign') for
        //    determinism even though the column default already covers it.
        $templates = [
            [
                'name'          => 'Letting Mandate V5',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-mandate-v5',
                'page_count'    => 1,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['owner_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
            [
                'name'          => 'Rental Application V8',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.rental-application-v8',
                'page_count'    => 2,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['acquiring_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
            [
                'name'          => 'Letting Mandatory Disclosure V7',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-mandatory-disclosure-v7',
                'page_count'    => 3,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['owner_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
            [
                'name'          => 'Letting Marketing Permission V7',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.letting-marketing-permission-v7',
                'page_count'    => 2,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['owner_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
            [
                'name'          => 'Lease Agreement POPI V8',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.lease-agreement-popi-v8',
                'page_count'    => 6,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['owner_party', 'acquiring_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
            [
                'name'          => 'Commercial Lease Agreement V5',
                'template_type' => 'rental',
                'render_type'   => 'web',
                'blade_view'    => 'docuperfect.web-templates.commercial-lease-agreement-v5',
                'page_count'    => 7,
                'fields_json'   => '[]',
                'is_global'     => true,
                'is_esign'      => true,
                'signing_parties' => json_encode(['owner_party', 'acquiring_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
            ],
        ];

        foreach ($templates as $tpl) {
            DB::table('docuperfect_templates')->updateOrInsert(
                ['name' => $tpl['name']],
                $tpl
            );
        }
    }
}
