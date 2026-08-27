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
 *   {entity_name}      — the party's name (entity_name, or full_name if
 *                         unset — so this also covers a NATURAL PERSON
 *                         party, e.g. the minor or the POA grantor).
 *   {party_id_number}  — the PARTY's (not the representative's) own ID
 *                         suffix, e.g. " (ID: 8811300456082)", or '' when
 *                         none on file. Self-contained — never wrap it in
 *                         literal parens in a template, it bakes its own.
 *                         Added 2026-08-25 to close exactly the gap noted
 *                         below; used by the POA and Minor presets.
 *   {rep_name}         — the representative/signer's name, with the same
 *                         " (ID: …)" suffix auto-appended when they have an
 *                         id_number on file.
 *   {capacity}         — free text (e.g. "Director"), collapses to '' when
 *                         unset.
 *   {entity_reg_no}    — the party's entity_reg_no field (registration /
 *                         Master's reference / estate number — company/CC/
 *                         trust/estate presets only, not a personal ID).
 *
 * {rep_name} and {party_id_number} both resolve through the SAME shared
 * rule — Contact::idNumberSuffix() — rather than each formatting the
 * bracket themselves, so the two never drift apart. That method's docblock
 * is also where RoleBlockExpansionService::composeEntityPartyText() (the
 * separate document-body clause composer, outside this seeder's model
 * layer and outside ESignWizardController.php, neither touched here) should
 * call the same helper once it renders a natural-person party — flagged to
 * Johan, who is routing it to cc5 directly, not guessed at here.
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
                'phrasing_template'        => '{entity_name} (Registration No. {entity_reg_no}), herein represented by {rep_name}, in the capacity of {capacity}, duly authorised thereto by resolution of the board of directors',
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
                // {party_id_number} — the GRANTOR's own ID (2026-08-25,
                // closing the token-vocabulary gap: the party displays in
                // full, name + ID, same as every other party, regardless of
                // who signs). Bakes its own bracket; never a dangling "( )".
                //
                // Ordinary (non-proxy) fallback — unlikely to be hit for this
                // preset's real use case (a natural person signing for
                // themselves does not go through entity expansion at all),
                // kept coherent and valid regardless.
                'phrasing_template'        => '{entity_name}{party_id_number}',
                'signature_caption'        => '{entity_name}{party_id_number}',
                'proxy_phrasing_template'  => '{entity_name}{party_id_number}, herein represented by {rep_name}, duly authorised representative acting under Power of Attorney ({capacity})',
                'proxy_signature_caption'  => 'for and on behalf of {entity_name}{party_id_number}, as duly authorised representative acting under Power of Attorney — {capacity}',
            ],
            $base + [
                'name'                     => 'CoreX Standard — Minor, assisted by parent/guardian',
                'applies_to'               => 'all',
                // {party_id_number} — the MINOR's own ID, same reasoning as
                // the POA preset above.
                'phrasing_template'        => '{entity_name}{party_id_number}, a minor, herein assisted by {rep_name}, in the capacity of {capacity}',
                'signature_caption'        => 'for and on behalf of {entity_name}{party_id_number}, a minor, assisted by {rep_name} — {capacity}',
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
