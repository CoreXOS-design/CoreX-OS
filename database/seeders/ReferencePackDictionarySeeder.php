<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Docuperfect\DataDictionaryEntry;
use Illuminate\Database\Seeder;

/**
 * AT-177 / WS5 — the six CoreX-standard Data Dictionary entries the reference-proof
 * hand-compile of template 116 (HFC Marketing Permission) SURFACED as missing from the WS0
 * seed (`DataDictionarySeeder`). GLOBAL reference rows (agency_id NULL) at dictionary version 1.
 *
 * These are legitimate SA real-estate concepts the marketing-permission intake needs — suburb,
 * district, contact cell/email, the marketing transaction type, and commission percentage.
 * WS0's `DataType` enum has no phone/email/percentage type yet, so they seed as `text` (binding
 * resolution + L5 coherence hold); finer format types are a follow-up for the canonical WS0
 * dictionary (cc2's open offer). Kept as a SEPARATE, additive, idempotent seeder so WS5 stays
 * self-contained; flagged to cc2 for consolidation into `DataDictionarySeeder`.
 *
 * Idempotent (updateOrCreate on agency_id NULL + key + version) and registered in
 * `deploy:sync-reference-data`, so the rows travel with a git-pull deploy (AT-162).
 */
class ReferencePackDictionarySeeder extends Seeder
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
                    'format' => null,
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
        return [
            ['key' => 'suburb', 'category' => 'property', 'label' => 'Suburb / Complex', 'data_type' => 'text', 'default_source' => 'auto', 'validation' => ['max' => 200]],
            ['key' => 'district', 'category' => 'property', 'label' => 'District', 'data_type' => 'text', 'default_source' => 'auto', 'validation' => ['max' => 200]],
            ['key' => 'contact_cell', 'category' => 'party', 'label' => 'Contact Cell Number', 'data_type' => 'text', 'default_source' => 'party_input', 'validation' => ['max' => 40], 'description' => 'SA cell number (text until a phone data_type exists).'],
            ['key' => 'contact_email', 'category' => 'party', 'label' => 'Contact Email', 'data_type' => 'text', 'default_source' => 'party_input', 'validation' => ['max' => 200], 'description' => 'Email address (text until an email data_type exists).'],
            ['key' => 'marketing_transaction_type', 'category' => 'property', 'label' => 'Marketing Transaction Type', 'data_type' => 'text', 'default_source' => 'agent_input', 'validation' => ['options' => ['sale', 'lease']], 'description' => 'Whether the marketing permission authorises a Sale or a Letting.'],
            ['key' => 'commission_pct', 'category' => 'money', 'label' => 'Commission Percentage', 'data_type' => 'text', 'default_source' => 'agent_input', 'validation' => ['max' => 10], 'description' => 'Agreed commission as a percentage (text until a percentage data_type exists).'],
        ];
    }
}
