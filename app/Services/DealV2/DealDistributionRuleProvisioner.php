<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use Illuminate\Support\Facades\Log;

/**
 * AT-225 · DR2 §8.1 — provisions an agency's DEFAULT distribution matrix
 * (`deal_stage_document_rules`): stage (pipeline_step) × document_type × party_role
 * → { delivery_mode, auto_on_stage_tick }.
 *
 * Same discipline as {@see DealPipelineTemplateProvisioner}: idempotent,
 * additive, agency-scoped (match-or-create per agency on the table's unique key),
 * and GRACEFUL — a default that references a step/doc-type/role an agency does not
 * have is skipped, never fatal. Rules key on the agency's TEMPLATE steps (resolved
 * by name), so they attach regardless of how a given deal's pipeline is anchored.
 *
 * The headline default is the §8.1 "red-button" rule: at the Electrical COC stage,
 * the COC request auto-goes to the appointed electrician via a secure link.
 */
class DealDistributionRuleProvisioner
{
    /**
     * Sensible defaults (spec §8.1). Each: [stepName, docSlug, partyRole, deliveryMode, autoOnStageTick].
     * Provider-role rows (electrician/entomologist) are the auto "COC request" rules; party rows
     * (buyer/seller/attorney) distribute the signed OTP + FICA and stay manual (agent reviews).
     */
    private const DEFAULTS = [
        // Red-button: appointed provider gets the COC request automatically when the stage ticks.
        // Party roles are the canonical DealV2 PARTY_ROLES vocabulary (the electrician
        // provider party is attached as 'electrician_coc', NOT the 'electrician' specialty).
        ['Electrical COC',    'coc_request', 'electrician_coc',   DealStageDocumentRule::MODE_SECURE_LINK, true],
        ['Beetle Certificate','coc_request', 'entomologist',      DealStageDocumentRule::MODE_SECURE_LINK, true],
        // Signed OTP distributed to the parties + transferring attorney (manual — agent confirms).
        ['OTP Signed',        'otp',         'buyer',             DealStageDocumentRule::MODE_SECURE_LINK, false],
        ['OTP Signed',        'otp',         'seller',            DealStageDocumentRule::MODE_SECURE_LINK, false],
        ['OTP Signed',        'otp',         'transfer_attorney', DealStageDocumentRule::MODE_SECURE_LINK, false],
        // FICA to the transferring attorney once documents are signed.
        ['Documents Signed',  'fica',        'transfer_attorney', DealStageDocumentRule::MODE_SECURE_LINK, false],
    ];

    /**
     * @return array{created:int, skipped:int, skips:array<int,string>}
     */
    public function provisionDefaultsForAgency(int $agencyId, ?int $createdById = null): array
    {
        // Resolve this agency's template steps, GROUPED by name (an agency can have
        // several templates — bond/cash/… — each with its own "Electrical COC" /
        // "OTP Signed" step). A default rule is seeded for EVERY matching step, so a
        // deal on any template gets its distribution defaults.
        $stepsByName = DealPipelineStep::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->get(['id', 'name'])
            ->groupBy(fn ($s) => mb_strtolower(trim((string) $s->name)));

        // Document types are GLOBAL (no agency_id); resolve by slug.
        $typesBySlug = DocumentType::query()->get(['id', 'slug'])
            ->keyBy(fn ($t) => (string) $t->slug);

        $created = 0;
        $skipped = 0;
        $skips   = [];

        foreach (self::DEFAULTS as [$stepName, $docSlug, $partyRole, $mode, $auto]) {
            $steps = $stepsByName->get(mb_strtolower(trim($stepName)), collect());
            $type  = $typesBySlug->get($docSlug);

            if ($steps->isEmpty()) {
                $skipped++;
                $skips[] = "no step \"{$stepName}\"";
                continue;
            }
            if (!$type) {
                $skipped++;
                $skips[] = "no document_type \"{$docSlug}\"";
                continue;
            }

            foreach ($steps as $step) {
                // Idempotent on the table's unique key (agency, step, doc_type, party_role).
                // Never overwrites an agency's own customisation once it exists — only
                // fills the defaults where absent.
                $rule = DealStageDocumentRule::withoutGlobalScopes()->firstOrNew([
                    'agency_id'        => $agencyId,
                    'pipeline_step_id' => $step->id,
                    'document_type_id' => $type->id,
                    'party_role'       => $partyRole,
                ]);

                if ($rule->exists) {
                    $skipped++;
                    $skips[] = "exists: {$stepName}#{$step->id}/{$docSlug}/{$partyRole}";
                    continue;
                }

                $rule->fill([
                    'delivery_mode'      => $mode,
                    'auto_on_stage_tick' => (bool) $auto,
                    'is_active'          => true,
                    'created_by_id'      => $createdById,
                ])->save();
                $created++;
            }
        }

        Log::info('DealDistributionRuleProvisioner: provisioned defaults', [
            'agency_id' => $agencyId, 'created' => $created, 'skipped' => $skipped, 'skips' => $skips,
        ]);

        return ['created' => $created, 'skipped' => $skipped, 'skips' => $skips];
    }
}
