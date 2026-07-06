<?php

namespace App\Services\Docuperfect\Compiler\Golden;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderObservation;
use App\Services\Docuperfect\Compiler\Contracts\GoldenRenderProbe;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\PartyExpr;
use Throwable;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness + CI gate).
 *
 * §7: for each compiled template version, auto-generate a fixture signing for every party
 * combination (derived from the CDS by {@see CombinationCatalog}) and certify it. A template
 * version cannot be published unless its full golden set is green.
 *
 * The harness is the SUPERSET of the WS1 linter: it runs the whole-template linter gate
 * (L1–L5, L7 — L6 is delegated to this harness's render tier), then materialises each NAMED
 * combination and asserts the combination-specific properties the whole-template pass cannot:
 *
 *   STRUCTURAL tier (runs now, no renderer — uses WS0's `Condition::evaluate()` +
 *   `PartyExpr::appliesTo()` as the canonical semantics):
 *     - every PRESENT party has a signing surface in a block that RENDERS in this combination
 *       (catches "seller_2's anchor lives in a 1-seller-only block");
 *     - every required field that renders in this combination is visible to ≥1 present party.
 *
 *   RENDER tier (integration-pending on WS2's {@see GoldenRenderProbe}, L6 pattern — never a
 *   silent green):
 *     - the bound fields of every rendered block actually populate in the body;
 *     - every present party gets a placed anchor;
 *     - web↔PDF parity holds; the completed-body hash is stable.
 *   With no probe wired the render tier is PENDING and BLOCKS certification.
 *
 * Pure and framework-free: the dictionary is reached only through {@see DataDictionaryResolver}
 * and the renderer only through {@see GoldenRenderProbe}. A malformed CDS is absorbed into a
 * blocking report rather than throwing (BUILD_STANDARD §3).
 */
final class CompiledTemplateGoldenHarness
{
    public function __construct(
        private readonly CompiledTemplateLinter $linter = new CompiledTemplateLinter(),
        private readonly CombinationCatalog $catalog = new CombinationCatalog(),
    ) {
    }

    /**
     * Certify a compiled template version across every party combination.
     */
    public function certify(
        Cds $cds,
        DataDictionaryResolver $dictionary,
        ?GoldenRenderProbe $probe = null,
        ?LinterContext $context = null,
    ): GoldenReport {
        $templateFindings = $this->wholeTemplateGate($cds, $dictionary, $context);

        $enum = $this->catalog->for($cds, $context?->maxInstancesPerParty ?? 2);
        if ($enum['capped']) {
            $templateFindings[] = GoldenFinding::structuralError('', 'combination_space_capped', '', sprintf('The combination space exceeded %d and was truncated; some combinations were not certified.', CombinationCatalog::MAX_COMBINATIONS));
        }

        $results = [];
        foreach ($enum['combinations'] as $combination) {
            $results[] = $this->certifyCombination($cds, $combination, $probe);
        }

        return new GoldenReport($templateFindings, $results);
    }

    /**
     * Run the whole-template linter gate, mapping its blocking structural findings (L1–L5, L7)
     * into golden findings. L6 (render parity) is intentionally excluded here — the harness's
     * render tier owns it.
     *
     * @return GoldenFinding[]
     */
    private function wholeTemplateGate(Cds $cds, DataDictionaryResolver $dictionary, ?LinterContext $context): array
    {
        $report = $this->linter->lint($cds, $dictionary, null, $context);

        $findings = [];
        foreach ($report->blocking() as $lf) {
            if ($lf->rule === 'L6') {
                continue; // render parity is the harness render tier's job.
            }
            $findings[] = GoldenFinding::structuralError('', 'linter_' . $lf->code, $lf->target, sprintf('[%s] %s', $lf->rule, $lf->message), $lf->context);
        }

        if ($findings === []) {
            $findings[] = GoldenFinding::pass('', 'structural', 'Whole-template linter gate clean (L1–L5, L7).');
        }

        return $findings;
    }

    private function certifyCombination(Cds $cds, GoldenCombination $combination, ?GoldenRenderProbe $probe): GoldenCombinationResult
    {
        $findings = array_merge(
            $this->structuralAssertions($cds, $combination),
            $this->renderAssertions($cds, $combination, $probe),
        );

        return new GoldenCombinationResult($combination, $findings);
    }

    /**
     * @return GoldenFinding[]
     */
    private function structuralAssertions(Cds $cds, GoldenCombination $combination): array
    {
        $findings = [];
        $scenario = $combination->scenario();
        $label = $combination->label;

        // Which blocks render in this combination?
        $renderedBlocks = array_values(array_filter($cds->blocks, static fn (Block $b): bool => $b->condition->evaluate($scenario)));

        // (1) Every present party has a signing surface in a rendered block.
        $servedRoleBases = [];
        foreach ($renderedBlocks as $block) {
            foreach ($block->anchors as $anchor) {
                if ($anchor->kind->isSigningSurface() && $anchor->partyKey !== '') {
                    $servedRoleBases[PartyExpr::roleBase($anchor->partyKey)] = true;
                    $servedRoleBases[$anchor->partyKey] = true;
                }
            }
        }
        foreach ($combination->presentParties as $instance) {
            $base = PartyExpr::roleBase($instance);
            if (!isset($servedRoleBases[$instance]) && !isset($servedRoleBases[$base])) {
                $findings[] = GoldenFinding::structuralError(
                    $label,
                    'party_without_rendered_anchor',
                    $instance,
                    sprintf('In combination "%s", party "%s" is present but has no signing surface in any block that renders for this combination.', $label, $instance),
                    ['party' => $instance],
                );
            }
        }

        // (2) Every required field that renders is visible to ≥1 present party.
        foreach ($renderedBlocks as $block) {
            foreach ($block->fields as $field) {
                if (!$field->required) {
                    continue;
                }
                $seen = false;
                foreach ($combination->presentParties as $instance) {
                    if ($block->visibility->appliesTo($instance)) {
                        $seen = true;
                        break;
                    }
                }
                if (!$seen) {
                    $findings[] = GoldenFinding::structuralError(
                        $label,
                        'required_field_unseen_in_combination',
                        $block->blockId,
                        sprintf('In combination "%s", required field "%s" renders in block "%s" but no present party can see it.', $label, $field->fieldId, $block->blockId),
                        ['field_id' => $field->fieldId, 'block_id' => $block->blockId],
                    );
                }
            }
        }

        if ($findings === []) {
            $findings[] = GoldenFinding::pass($label, 'structural', 'Combination structurally sound.');
        }

        return $findings;
    }

    /**
     * @return GoldenFinding[]
     */
    private function renderAssertions(Cds $cds, GoldenCombination $combination, ?GoldenRenderProbe $probe): array
    {
        $label = $combination->label;

        if ($probe === null) {
            return [GoldenFinding::renderPending($label, sprintf('Render parity for combination "%s" is unproven: WS2 render probe not wired. The template cannot be certified until it lands.', $label))];
        }

        try {
            $observation = $probe->observe($cds, $combination->presentParties, $combination->fieldValues);
        } catch (Throwable $e) {
            return [GoldenFinding::renderError($label, 'render_probe_threw', '', sprintf('The render probe failed for combination "%s" (%s).', $label, $e->getMessage()))];
        }

        $findings = [];
        $scenario = $combination->scenario();
        $renderedBlocks = array_values(array_filter($cds->blocks, static fn (Block $b): bool => $b->condition->evaluate($scenario)));

        // Every bound field in a rendered block must actually populate in the body.
        foreach ($renderedBlocks as $block) {
            foreach ($block->fields as $field) {
                if ($field->isBound() && !$observation->rendersField($field->fieldId)) {
                    $findings[] = GoldenFinding::renderError($label, 'field_not_rendered', $field->fieldId, sprintf('In combination "%s", bound field "%s" did not populate in the rendered body.', $label, $field->fieldId), ['field_id' => $field->fieldId]);
                }
            }
        }

        // Every present party must get a placed anchor.
        foreach ($combination->presentParties as $instance) {
            if (!$observation->hasAnchorForParty($instance) && !$observation->hasAnchorForParty(PartyExpr::roleBase($instance))) {
                $findings[] = GoldenFinding::renderError($label, 'anchor_not_placed', $instance, sprintf('In combination "%s", no anchor was placed in the rendered body for present party "%s".', $label, $instance), ['party' => $instance]);
            }
        }

        // Web↔PDF parity + stable body hash.
        if (!$observation->webPdfParityHolds) {
            $findings[] = GoldenFinding::renderError($label, 'web_pdf_parity_mismatch', '', sprintf('Web and PDF renders diverge for combination "%s": %s', $label, $observation->differences === [] ? 'structural diff non-empty' : implode('; ', $observation->differences)));
        }
        if (trim($observation->bodyHash) === '') {
            $findings[] = GoldenFinding::renderError($label, 'unstable_body_hash', '', sprintf('Combination "%s" produced no stable completed-body hash.', $label));
        }

        if ($findings === []) {
            $findings[] = GoldenFinding::pass($label, 'render', 'Body rendered, anchors placed, parity holds.');
        }

        return $findings;
    }
}
