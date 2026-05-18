<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Marketing Permission V6 — sales mandate CDS/web template.
 *
 * Source: resources/docs/source/HFC_Marketing_Permission_V6.docx
 * Blade : resources/views/docuperfect/web-templates/marketing-permission-v6.blade.php
 *
 * Field mechanism (per the ESIGN WIZARD TEMPLATE / CHOICE-FIELD audit):
 *  - render_type='web' + blade_view + is_esign=true → appears in the
 *    e-sign wizard Step 1.
 *  - fields_json drives the wizard Fill step. field_mappings is left
 *    NULL on purpose: a web template WITH field_mappings makes
 *    ESignWizardController rebuild fields via buildFieldsFromMappings()
 *    which DROPS `options`, breaking choice fields. With no
 *    field_mappings the wizard uses fields_json directly and
 *    $allWizardFields preserves every key (incl. `options`).
 *  - Choice fields use type:'selection' + options[]  →  wizard
 *    fieldInputType() returns 'select' → interactive <select>.
 *  - Text fields use type:'input' → 'text'.
 * Idempotent: updateOrInsert keyed by name.
 */
class MarketingPermissionV6Seeder extends Seeder
{
    public const TEMPLATE_NAME = 'Marketing Permission V6';

    public function run(): void
    {
        $text = fn (string $id, string $label) => [
            'id' => $id, 'field_name' => $id, 'name' => $id,
            'label' => $label, 'type' => 'input', 'tag_type' => 'input',
            'assignedTo' => 'agent', 'render_type' => 'web',
        ];
        $choice = fn (string $id, string $label, array $opts) => [
            'id' => $id, 'field_name' => $id, 'name' => $id,
            'label' => $label, 'type' => 'selection', 'tag_type' => 'selection',
            'options' => $opts, 'assignedTo' => 'agent', 'render_type' => 'web',
        ];

        $fields = [
            $text('seller1_name', 'Seller 1 Name'),
            $text('seller1_id', 'Seller 1 ID / Entity No'),
            $text('seller2_name', 'Seller 2 Name'),
            $text('seller2_id', 'Seller 2 ID / Entity No'),
            $choice('marital_status', 'Marital Status', [
                'Unmarried', 'In Community of Property', 'Out of Community (ANC)', 'Other',
            ]),
            $choice('sa_resident', 'SA Resident', ['Yes', 'No']),
            $text('contact_tel_email', 'Contact (Tel / Email)'),
            $text('property_address', 'Property Address'),
            $text('erf_unit_no', 'Erf / Unit No'),
            $text('complex_estate', 'Complex / Estate'),
            $text('township_suburb', 'Township / Suburb'),
            $text('district', 'District'),
            $text('asking_price', 'Asking Price (R)'),
            $text('amount_in_words', 'Amount in Words'),
            $choice('price_basis', 'Price is', [
                'Inclusive of our professional fee', 'Exclusive of our professional fee',
            ]),
            $text('signed_at_location', 'Signed at (location)'),
            $text('signed_day', 'Signed day'),
            $text('signed_month', 'Signed month'),
            $text('signed_year', 'Signed year (20__)'),
        ];

        DB::table('docuperfect_templates')->updateOrInsert(
            ['name' => self::TEMPLATE_NAME],
            [
                'template_type'          => 'sales',
                'render_type'            => 'web',
                'blade_view'             => 'docuperfect.web-templates.marketing-permission-v6',
                'page_count'             => 1,
                'fields_json'            => json_encode($fields),
                'field_mappings'         => null,
                'is_global'              => true,
                'is_esign'               => true,
                'signing_parties'        => json_encode(['owner_party', 'agent']),
                'allowed_delivery_modes' => 'esign,wet_ink,download',
                'updated_at'             => now(),
            ]
        );

        // Signature-block parties for agency 1 (spec §3 "Signature Block
        // Parties" = agency_signing_parties). Seller covers Seller 1/2;
        // Witness for the witness block. Mirrors the rebuild_template_116
        // precedent. Idempotent.
        foreach ([['Seller', 4], ['Witness', 6]] as [$name, $sort]) {
            $exists = DB::table('agency_signing_parties')
                ->where('agency_id', 1)->where('name', $name)->exists();
            if (! $exists) {
                DB::table('agency_signing_parties')->insert([
                    'agency_id' => 1, 'name' => $name, 'sort_order' => $sort,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
