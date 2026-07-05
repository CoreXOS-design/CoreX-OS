<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Docuperfect\DataDictionaryEntry;
use Illuminate\Database\Seeder;

/**
 * AT-177 / WS0 — the CoreX-standard SA real-estate Data Dictionary seed (spec §2.1, §12
 * ruling 1). GLOBAL reference rows (agency_id NULL) at dictionary version 1.
 *
 * Idempotent (updateOrCreate keyed on agency_id NULL + key + version) and registered in
 * `deploy:sync-reference-data`, so these rows travel with a `git pull` deploy (seeders do
 * NOT run on deploy — AT-162 / BUILD_STANDARD §8). Agencies override any key by inserting a
 * row with their agency_id set; the model resolver prefers the override.
 */
class DataDictionarySeeder extends Seeder
{
    public const DICTIONARY_VERSION = 1;

    public function run(): void
    {
        foreach ($this->entries() as $entry) {
            DataDictionaryEntry::query()->updateOrCreate(
                [
                    'agency_id' => null,
                    'key' => $entry['key'],
                    'version' => self::DICTIONARY_VERSION,
                ],
                [
                    'category' => $entry['category'],
                    'label' => $entry['label'],
                    'data_type' => $entry['data_type'],
                    'validation' => $entry['validation'] ?? null,
                    'format' => $entry['format'] ?? null,
                    'default_source' => $entry['default_source'] ?? 'agent_input',
                    'description' => $entry['description'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function entries(): array
    {
        $maritalOptions = [
            'options' => [
                'Single',
                'Married in community of property',
                'Married out of community of property (ANC with accrual)',
                'Married out of community of property (ANC without accrual)',
                'Married by foreign law',
                'Divorced',
                'Widowed',
                'Life partner',
            ],
        ];

        return [
            // ── Money ──────────────────────────────────────────────────────────
            ['key' => 'purchase_price', 'category' => 'money', 'label' => 'Purchase Price', 'data_type' => 'zar_money', 'default_source' => 'agent_input', 'description' => 'Agreed purchase price in ZAR.'],
            ['key' => 'deposit', 'category' => 'money', 'label' => 'Deposit', 'data_type' => 'zar_money', 'default_source' => 'agent_input'],
            ['key' => 'commission_incl_vat', 'category' => 'money', 'label' => 'Commission (incl. VAT)', 'data_type' => 'zar_money', 'default_source' => 'auto', 'description' => 'Gross commission including 15% VAT.'],
            ['key' => 'commission_excl_vat', 'category' => 'money', 'label' => 'Commission (excl. VAT)', 'data_type' => 'zar_money', 'default_source' => 'auto'],
            ['key' => 'monthly_rental', 'category' => 'money', 'label' => 'Monthly Rental', 'data_type' => 'zar_money', 'default_source' => 'agent_input'],

            // ── Identity ───────────────────────────────────────────────────────
            ['key' => 'seller_id_number', 'category' => 'identity', 'label' => 'Seller ID Number', 'data_type' => 'sa_id', 'default_source' => 'auto', 'description' => 'Seller 13-digit SA ID (Luhn-validated).'],
            ['key' => 'buyer_id_number', 'category' => 'identity', 'label' => 'Buyer ID Number', 'data_type' => 'sa_id', 'default_source' => 'party_input'],

            // ── Property ───────────────────────────────────────────────────────
            ['key' => 'erf_number', 'category' => 'property', 'label' => 'Erf Number', 'data_type' => 'erf_number', 'default_source' => 'auto'],
            ['key' => 'title_deed_no', 'category' => 'property', 'label' => 'Title Deed Number', 'data_type' => 'title_deed', 'default_source' => 'auto'],
            ['key' => 'scheme_name', 'category' => 'property', 'label' => 'Sectional Scheme Name', 'data_type' => 'scheme_name', 'default_source' => 'auto', 'description' => 'Sectional-title scheme name (if applicable).'],
            ['key' => 'unit_no', 'category' => 'property', 'label' => 'Unit Number', 'data_type' => 'unit_no', 'default_source' => 'auto'],
            ['key' => 'gps', 'category' => 'property', 'label' => 'GPS Coordinates', 'data_type' => 'gps', 'default_source' => 'auto', 'description' => 'Latitude, longitude.'],
            ['key' => 'property_address', 'category' => 'property', 'label' => 'Property Address', 'data_type' => 'text', 'default_source' => 'auto', 'validation' => ['min' => 3, 'max' => 500]],

            // ── Practitioner ───────────────────────────────────────────────────
            ['key' => 'agent_ppra_no', 'category' => 'practitioner', 'label' => 'Agent PPRA Number', 'data_type' => 'ppra_no', 'default_source' => 'auto'],
            ['key' => 'agent_ffc', 'category' => 'practitioner', 'label' => 'Agent FFC Number', 'data_type' => 'ffc_no', 'default_source' => 'auto', 'description' => 'Fidelity Fund Certificate number.'],
            ['key' => 'designation', 'category' => 'practitioner', 'label' => 'Practitioner Designation', 'data_type' => 'text', 'default_source' => 'auto', 'validation' => ['max' => 120]],

            // ── Dates ──────────────────────────────────────────────────────────
            ['key' => 'offer_date', 'category' => 'date', 'label' => 'Offer Date', 'data_type' => 'date', 'default_source' => 'auto'],
            ['key' => 'transfer_date', 'category' => 'date', 'label' => 'Transfer Date', 'data_type' => 'date', 'default_source' => 'agent_input'],
            ['key' => 'occupation_date', 'category' => 'date', 'label' => 'Occupation Date', 'data_type' => 'date', 'default_source' => 'agent_input', 'validation' => ['not_before_field' => 'transfer_date'], 'description' => 'Occupation must be on or after transfer (linter L5 cross-field ordering).'],
            ['key' => 'expiry_date', 'category' => 'date', 'label' => 'Expiry Date', 'data_type' => 'date', 'default_source' => 'agent_input'],

            // ── Parties ────────────────────────────────────────────────────────
            ['key' => 'seller_full_name', 'category' => 'party', 'label' => 'Seller Full Name', 'data_type' => 'full_name', 'default_source' => 'auto'],
            ['key' => 'buyer_full_name', 'category' => 'party', 'label' => 'Buyer Full Name', 'data_type' => 'full_name', 'default_source' => 'party_input'],
            ['key' => 'seller_marital_status', 'category' => 'party', 'label' => 'Seller Marital Status', 'data_type' => 'marital_status', 'default_source' => 'auto', 'validation' => $maritalOptions],
            ['key' => 'buyer_marital_status', 'category' => 'party', 'label' => 'Buyer Marital Status', 'data_type' => 'marital_status', 'default_source' => 'party_input', 'validation' => $maritalOptions],
        ];
    }
}
