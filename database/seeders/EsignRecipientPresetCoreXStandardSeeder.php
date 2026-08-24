<?php

namespace Database\Seeders;

use App\Models\Docuperfect\EsignRecipientPreset;
use Illuminate\Database\Seeder;

/**
 * CoreX Standard recipient presets (Johan, 2026-08-25) — the "CoreX Standard
 * Templates" section of the e-sign recipient preset library, seeded with
 * baseline South African conveyancing phrasing so the container is never
 * empty on a fresh install, before any agency (or Elize's wording pass) has
 * configured its own. GLOBAL rows: agency_id = NULL, is_system = true — see
 * EsignRecipientPreset::scopeCoreXStandard() for why that is the actual
 * protection against an agency silently destroying one, and why this seeder
 * deliberately does not wire these into resolveFor()/defaultFor()'s
 * per-agency auto-selection.
 *
 * Idempotent: find-or-create by name among is_system rows (agency_id is
 * always NULL for these, so name is the natural key here — matches
 * HfcAddendumBEsignSeeder's find-or-create-by-name convention). Re-running
 * updates the wording on existing rows to whatever is defined below, so a
 * future revision of this seeder (e.g. once Elize's list replaces these) is
 * how the standard library gets updated, not a manual DB edit.
 *
 * Token vocabulary (EsignRecipientPreset::substitute(), the only tokens any
 * template here may use):
 *   {entity_name}   — the party's name (entity_name, or full_name if unset —
 *                      so this also covers a NATURAL PERSON party, e.g. the
 *                      minor or the POA grantor).
 *   {rep_name}      — the representative/signer's name, with " (ID: …)"
 *                      auto-appended when they have an id_number on file.
 *   {capacity}      — free text (e.g. "Director"), collapses to '' when unset.
 *   {entity_reg_no} — the party's entity_reg_no field.
 *
 * KNOWN GAP, surfaced rather than papered over: there is no token for the
 * PARTY's (not the representative's) own ID number. {rep_name} auto-suffixes
 * the REPRESENTATIVE's ID; {entity_name} does not, and {entity_reg_no} is a
 * registration-number field, not an ID field. For company/CC/trust/estate
 * presets that is fine (entity_reg_no legitimately holds a real registration
 * or Master's reference number). For the two NATURAL-PERSON-as-party
 * scenarios (Power of Attorney grantor, minor) it means this layer cannot by
 * itself render "{minor's ID}" or "{grantor's ID}" next to {entity_name} —
 * whatever satisfies "every party displays in full, including ID"
 * (Johan's rule) for THAT specific gap must be the separate party-display
 * path (_party_clause_text / EsignRecipientPreset::composePartyClause(),
 * consumed by RoleBlockExpansionService — both outside this seeder's model
 * layer and outside ESignWizardController.php, which this task does not
 * touch). Flagging for Johan's sign-off, not guessing at a fix.
 */
class EsignRecipientPresetCoreXStandardSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->presets() as $preset) {
            EsignRecipientPreset::withoutGlobalScopes()
                ->whereNull('agency_id')
                ->where('is_system', true)
                ->where('name', $preset['name'])
                ->first()
                ?->forceFill($preset)->save()
                ?? EsignRecipientPreset::withoutAgencyStamping(
                    fn () => EsignRecipientPreset::create($preset)
                );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function presets(): array
    {
        $base = [
            'agency_id'  => null,
            'is_system'  => true,
            'is_default' => false,
        ];

        return [
            $base + [
                'name'                     => 'CoreX Standard — Company (Pty) Ltd, single director',
                'applies_to'               => 'entity',
                'phrasing_template'        => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, in the capacity of {capacity}, being duly authorised thereto',
                'signature_caption'        => 'on behalf of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
                'proxy_phrasing_template'  => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, duly authorised representative ({capacity})',
                'proxy_signature_caption'  => 'as duly authorised representative of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Company (Pty) Ltd, multiple directors signing',
                'applies_to'               => 'entity',
                'phrasing_template'        => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, in the capacity of {capacity}, being one of the directors of the company duly authorised thereto',
                'signature_caption'        => 'on behalf of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
                'proxy_phrasing_template'  => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, duly authorised representative ({capacity})',
                'proxy_signature_caption'  => 'as duly authorised representative of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Close Corporation, member',
                'applies_to'               => 'entity',
                'phrasing_template'        => '{entity_name} (Registration No. {entity_reg_no}), a close corporation, herein represented by {rep_name}, in the capacity of {capacity}, being duly authorised thereto',
                'signature_caption'        => 'on behalf of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
                'proxy_phrasing_template'  => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, duly authorised representative ({capacity})',
                'proxy_signature_caption'  => 'as duly authorised representative of {entity_name} (Registration No. {entity_reg_no}) — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Trust, represented by trustees',
                'applies_to'               => 'entity',
                'phrasing_template'        => 'The Trustees for the time being of {entity_name} (Master\'s Reference {entity_reg_no}), herein represented by {rep_name}, in the capacity of {capacity}, being duly authorised thereto by resolution of the trustees',
                'signature_caption'        => 'on behalf of the Trustees for the time being of {entity_name} (Master\'s Reference {entity_reg_no}) — {capacity}',
                'proxy_phrasing_template'  => 'The Trustees for the time being of {entity_name} (Master\'s Reference {entity_reg_no}), herein represented by {rep_name}, duly authorised representative ({capacity})',
                'proxy_signature_caption'  => 'as duly authorised representative of the Trustees for the time being of {entity_name} (Master\'s Reference {entity_reg_no}) — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Late Estate, represented by executor',
                'applies_to'               => 'entity',
                'phrasing_template'        => 'The Estate Late {entity_name} (Estate No. {entity_reg_no}), herein represented by {rep_name}, in the capacity of {capacity}, being the duly appointed Executor thereof',
                'signature_caption'        => 'on behalf of the Estate Late {entity_name} (Estate No. {entity_reg_no}) — {capacity}',
                'proxy_phrasing_template'  => 'The Estate Late {entity_name} (Estate No. {entity_reg_no}), herein represented by {rep_name}, duly authorised representative ({capacity})',
                'proxy_signature_caption'  => 'as duly authorised representative of the Estate Late {entity_name} (Estate No. {entity_reg_no}) — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Natural person, signing under Power of Attorney',
                'applies_to'               => 'all',
                // Ordinary (non-proxy) fallback — unlikely to be hit for this
                // preset's real use case (a natural person signing for
                // themselves does not go through entity expansion at all),
                // kept coherent and valid regardless.
                'phrasing_template'        => '{entity_name}',
                'signature_caption'        => '{entity_name}',
                'proxy_phrasing_template'  => '{entity_name}, herein represented by {rep_name}, duly authorised representative acting under Power of Attorney ({capacity})',
                'proxy_signature_caption'  => 'for and on behalf of {entity_name}, as duly authorised representative acting under Power of Attorney — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Minor, assisted by parent/guardian',
                'applies_to'               => 'all',
                'phrasing_template'        => '{entity_name}, a minor, herein assisted by {rep_name}, in the capacity of {capacity}',
                'signature_caption'        => 'for and on behalf of {entity_name}, a minor, assisted by {rep_name} — {capacity}',
                // A minor's assistant is never a "duly authorised
                // representative under POA" — proxy wording intentionally
                // left null so it falls back to the ordinary phrasing above,
                // which already says "assisted by" (the correct SA term).
                'proxy_phrasing_template'  => null,
                'proxy_signature_caption'  => null,
            ],
        ];
    }
}
