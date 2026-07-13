<?php

namespace Database\Seeders;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DealStageDocumentRuleSeeder — the DR2 distribution matrix "sensible default"
 * the deal-register-v2 spec §8.1 mandates ("No hardcoded process … a sensible
 * default shipped in a seeder"). A rule = pipeline_step × document_type ×
 * party_role → { delivery_mode, auto_on_stage_tick }.
 *
 * QA-only config: DR2 is not live, so a live→qa1 sync wipes these. This seeder
 * is idempotent (updateOrCreate on the dsdr_unique key) and is invoked by the
 * qa1 sync's post-load reseed step so the matrix survives every refresh.
 *
 * Mapping is by STEP NAME (steps carry no doc-type/type column). The headline
 * is the "COC killer": at each COC stage a coc_request auto-fires (secure_link,
 * auto_on_stage_tick) to the appointed provider role. Buyer/seller document
 * rules are manual (auto=false) by default. Fully agency-configurable after.
 */
class DealStageDocumentRuleSeeder extends Seeder
{
    /** step name => [doc_type slug, party_role, delivery_mode, auto_on_stage_tick] (one or more rules). */
    private const MATRIX = [
        // ── COC killer — request the certificate from the appointed provider, auto on tick ──
        'Electrical COC'               => [['coc_request', 'electrician_coc', 'secure_link', true]],
        'Electric Fence COC'           => [['coc_request', 'service_provider', 'secure_link', true]],
        'Gas COC'                      => [['coc_request', 'service_provider', 'secure_link', true]],
        'Beetle Certificate'           => [['coc_request', 'entomologist', 'secure_link', true]],
        // ── Bond / originator ──
        'Bond Application Submitted'   => [['bank_statement', 'bond_originator', 'secure_link', false]],
        'Bond Approved'                => [['other', 'bond_attorney', 'secure_link', false]],
        'Guarantees Issued'            => [['other', 'bond_attorney', 'secure_link', false]],
        'Bond Cancellation Figures'    => [['other', 'bond_attorney', 'secure_link', false]],
        // ── Transfer / conveyancing ──
        'Transfer Duty / SARS Receipt' => [['tax_clearance', 'transfer_attorney', 'secure_link', false]],
        'Rates Clearance'              => [['rates_taxes', 'transfer_attorney', 'secure_link', false]],
        'Levy / HOA Consent'           => [['levy_statement', 'transfer_attorney', 'secure_link', false]],
        // ── Buyer / seller documents ──
        'OTP Signed'                   => [['otp', 'buyer', 'secure_link', false], ['otp', 'seller', 'secure_link', false]],
        'Documents Signed'            => [['sale_agreement', 'buyer', 'secure_link', false], ['sale_agreement', 'seller', 'secure_link', false]],
        'FICA Completed (Buyer)'       => [['fica', 'buyer', 'secure_link', false]],
        'FICA Completed (Seller)'      => [['fica', 'seller', 'secure_link', false]],
    ];

    public function run(): void
    {
        // slug => id lookup (shared doc-type list; global)
        $docTypeId = DocumentType::pluck('id', 'slug');
        $createdById = (User::where('is_admin', true)->first() ?? User::first())?->id;

        $made = 0;
        // Every agency that has DR2 pipeline templates gets the default matrix
        // for the steps present in those templates.
        foreach (DealPipelineTemplate::query()->pluck('agency_id')->unique() as $agencyId) {
            $steps = DealPipelineStep::query()
                ->whereHas('template', fn ($q) => $q->where('agency_id', $agencyId))
                ->get(['id', 'name']);

            foreach ($steps as $step) {
                $rules = self::MATRIX[trim($step->name)] ?? null;
                if (! $rules) {
                    continue;
                }
                foreach ($rules as [$slug, $partyRole, $mode, $auto]) {
                    $dtId = $docTypeId[$slug] ?? null;
                    if (! $dtId) {
                        continue; // doc type absent on this box — skip, never orphan
                    }
                    DealStageDocumentRule::withTrashed()->updateOrCreate(
                        [
                            'agency_id'        => $agencyId,
                            'pipeline_step_id' => $step->id,
                            'document_type_id' => $dtId,
                            'party_role'       => $partyRole,
                        ],
                        [
                            'delivery_mode'      => $mode,
                            'auto_on_stage_tick' => $auto,
                            'is_active'          => true,
                            'created_by_id'      => $createdById,
                            'deleted_at'         => null, // resurrect if previously soft-deleted
                        ]
                    );
                    $made++;
                }
            }
        }

        $this->command?->info("DealStageDocumentRuleSeeder — {$made} rule(s) upserted across DR2 templates.");
    }
}
