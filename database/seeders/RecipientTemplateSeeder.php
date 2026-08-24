<?php

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Models\RecipientTemplate;
use Illuminate\Database\Seeder;

/**
 * Johan, 2026-08-24 — CoreX-standard defaults for the recipient template
 * library. GLOBAL reference rows (agency_id NULL). An agency overrides any
 * of these by adding its own row via the settings UI; this seeder never
 * touches agency rows.
 *
 * PROVISIONAL WORDING: draft sentences pending Elize's actual list of the
 * firm's standard phrasings — Johan is correcting the words, not the
 * mechanism. Deceased estate is closest to final (matches his own literal
 * example); company/close-corporation/trust are lower-confidence drafts.
 *
 * Slots are just names for a template's sentence to fill in — no "kind"
 * (Elize's rule, 2026-08-24: display-vs-signing is never a template
 * decision, it's computed per recipient from is_deceased/is_proxy).
 *
 * Idempotent (updateOrCreate keyed on agency_id NULL + role_token + key) —
 * safe to re-run on every deploy, never a blind insert.
 */
class RecipientTemplateSeeder extends Seeder implements SyncableReferenceSeeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $row) {
            RecipientTemplate::query()->updateOrCreate(
                [
                    'agency_id' => null,
                    'role_token' => $row['role_token'],
                    'key' => $row['key'],
                ],
                [
                    'name' => $row['name'],
                    'text_template' => $row['text_template'],
                    'party_slots' => $row['party_slots'],
                    'is_default' => true,
                ],
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaults(): array
    {
        return [
            [
                'role_token' => 'seller',
                'key' => 'deceased_estate_executor',
                'name' => 'Deceased Estate — Executor',
                'text_template' => 'The late estate of {deceased}, herein represented by {executor} in the capacity of Executor',
                'party_slots' => [
                    ['key' => 'deceased', 'label' => 'Deceased'],
                    ['key' => 'executor', 'label' => 'Executor'],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'company_directors',
                'name' => 'Company — Director(s)',
                'text_template' => '{company}, herein represented by {director}',
                'party_slots' => [
                    ['key' => 'company', 'label' => 'Company'],
                    ['key' => 'director', 'label' => 'Director'],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'cc_members',
                'name' => 'Close Corporation — Member(s)',
                'text_template' => '{cc}, herein represented by {member}',
                'party_slots' => [
                    ['key' => 'cc', 'label' => 'Close Corporation'],
                    ['key' => 'member', 'label' => 'Member'],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'trust_trustees',
                'name' => 'Trust — Trustee(s)',
                'text_template' => "The Trustees for the time being of {trust}, herein represented by {trustee}",
                'party_slots' => [
                    ['key' => 'trust', 'label' => 'Trust'],
                    ['key' => 'trustee', 'label' => 'Trustee'],
                ],
            ],
        ];
    }
}
