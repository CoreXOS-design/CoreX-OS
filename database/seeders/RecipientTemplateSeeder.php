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
 * Every slot's "kind" is declared here, up front — never inferred, never
 * added later. The deceased/company/trust slot is "named": rendered as
 * text, never a recipient, never signs. The executor/director/trustee slot
 * is "signing": binds to an actual recipient row, signs exactly as any
 * recipient does today.
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
                    ['key' => 'deceased', 'label' => 'Deceased', 'kind' => RecipientTemplate::KIND_NAMED],
                    ['key' => 'executor', 'label' => 'Executor', 'kind' => RecipientTemplate::KIND_SIGNING],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'company_directors',
                'name' => 'Company — Director(s)',
                'text_template' => '{company}, herein represented by {director}',
                'party_slots' => [
                    ['key' => 'company', 'label' => 'Company', 'kind' => RecipientTemplate::KIND_NAMED],
                    ['key' => 'director', 'label' => 'Director', 'kind' => RecipientTemplate::KIND_SIGNING],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'cc_members',
                'name' => 'Close Corporation — Member(s)',
                'text_template' => '{cc}, herein represented by {member}',
                'party_slots' => [
                    ['key' => 'cc', 'label' => 'Close Corporation', 'kind' => RecipientTemplate::KIND_NAMED],
                    ['key' => 'member', 'label' => 'Member', 'kind' => RecipientTemplate::KIND_SIGNING],
                ],
            ],
            [
                'role_token' => 'seller',
                'key' => 'trust_trustees',
                'name' => 'Trust — Trustee(s)',
                'text_template' => "The Trustees for the time being of {trust}, herein represented by {trustee}",
                'party_slots' => [
                    ['key' => 'trust', 'label' => 'Trust', 'kind' => RecipientTemplate::KIND_NAMED],
                    ['key' => 'trustee', 'label' => 'Trustee', 'kind' => RecipientTemplate::KIND_SIGNING],
                ],
            ],
        ];
    }
}
