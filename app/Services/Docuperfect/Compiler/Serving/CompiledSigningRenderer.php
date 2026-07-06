<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Serving;

use App\Models\Docuperfect\CompiledTemplate;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderer;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Field;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * AT-177 / WS6 — produces the interactive signing-view HTML for a compiled-serving template,
 * straight from the published compiled CDS (spec §6, §8.3).
 *
 * This is the COMPILED REPLACEMENT for the legacy serving chain — it produces the same two
 * outputs SigningController needs ($webTemplateHtml + $editableFields) with NONE of the
 * compensators: no merged_html snapshot (so no MergedHtmlFreshnessGuard), surfaces are compiled
 * not stamped (no SignatureSurfaceNormalizer), the letterhead is in the CDS (no LetterheadRefresher),
 * no `~~~~` markers (no InsertableBlockRenderer), role instances expand from declared topology
 * (no RoleBlock* / no LCA guessing), and the field→binding is canonical (no canonicalFieldMappings
 * / pruneOrphanFieldMappings). The compiled tree already IS the normalised surface.
 */
final class CompiledSigningRenderer
{
    public function __construct(
        private readonly CdsRenderer $renderer = new CdsRenderer(),
        private readonly CompiledServingResolver $resolver = new CompiledServingResolver(),
    ) {
    }

    /**
     * @param list<string>         $recipientPartyRoles every recipient's party_role (so all
     *                                                  signature surfaces render, others pending)
     * @param array<string,string> $fieldValues         field_id => stored value (blank = fillable)
     * @return array{0:string,1:list<string>} [signing-view HTML, editable field_ids for this signer]
     */
    public function renderForSigning(
        CompiledTemplate $compiled,
        string $signerPartyRole,
        array $recipientPartyRoles,
        array $fieldValues = [],
    ): array {
        $cds = $compiled->cds();
        $activeInstances = $this->activeInstances($recipientPartyRoles);

        $surface = $this->renderer->renderSigningView($cds, $signerPartyRole, $activeInstances, $fieldValues);

        return [$surface->html, $this->editableFieldIds($cds, $signerPartyRole)];
    }

    /**
     * Group recipients by role into instance keys: ['seller','seller','agent'] → ['seller_1','seller_2','agent_1'].
     *
     * @param list<string> $recipientPartyRoles
     * @return list<string>
     */
    private function activeInstances(array $recipientPartyRoles): array
    {
        $counts = [];
        $instances = [];
        foreach ($recipientPartyRoles as $role) {
            $base = PartyExpr::roleBase($role);
            $counts[$base] = ($counts[$base] ?? 0) + 1;
            $instances[] = $base . '_' . $counts[$base];
        }

        return $instances;
    }

    /**
     * The field_ids the given signer may edit — blocks whose declared editability applies to the
     * signer's role. Replaces WebTemplateFieldPartyMap / getEditableFieldsFromMappings.
     *
     * @return list<string>
     */
    private function editableFieldIds(Cds $cds, string $signerPartyRole): array
    {
        $ids = [];
        foreach ($cds->blocks() as $block) {
            /** @var Block $block */
            if (! $block->editability->appliesTo($signerPartyRole)) {
                continue;
            }
            foreach ($block->fields as $field) {
                /** @var Field $field */
                $ids[] = $field->fieldId;
            }
        }

        return $ids;
    }
}
