<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\CdsInspector;
use App\Services\Docuperfect\Compiler\Linter\Support\PartyCombinationEnumerator;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Condition;
use App\Support\Docuperfect\Cds\PartyExpr;

/**
 * L4 — Every conditional / party combination resolves (§4).
 *
 * "Enumerate the party-cardinality × conditional space; each combination must produce a
 * valid, fully-bound render (no dangling block, no unreachable required field)." Because
 * topology is DECLARED and WS0 supplies the canonical semantics
 * ({@see Condition::evaluate()}, {@see PartyExpr::appliesTo()}), this is a pure static
 * analysis over the enumerated scenario space:
 *
 *  1. Reference integrity — every party/field a condition, visibility or editability
 *     references must be declared.
 *  2. editability ⊆ visibility — a party that may edit a block must also see it.
 *  3. No dangling block — a block that renders in NO enumerated scenario.
 *  4. No unreachable required field — a required field that in NO scenario both renders
 *     and is visible to a present party.
 */
final class ConditionalCombinationsResolveRule implements LintRule
{
    public function __construct(
        private readonly PartyCombinationEnumerator $enumerator = new PartyCombinationEnumerator(),
    ) {
    }

    public function code(): string
    {
        return 'L4';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $findings = [];
        $inspector = new CdsInspector($cds);
        $blocks = $cds->blocks;

        // Lift the enumerator's high representative to cover the largest party_count_gte(n)
        // any condition references, so a "count ≥ n" block is never falsely flagged dangling.
        $minHigh = 0;
        foreach ($blocks as $block) {
            if ($block->condition->kind === Condition::PARTY_COUNT_GTE) {
                $minHigh = max($minHigh, (int) $block->condition->value);
            }
        }
        $enum = $this->enumerator->enumerate($cds->parties, $context->maxInstancesPerParty, $minHigh);
        $combos = $enum['combos'];
        if ($enum['capped']) {
            $findings[] = LintFinding::warning('L4', '', 'combination_space_capped', sprintf(
                'The party-combination space exceeded %d and was truncated; satisfiability was checked over a representative subset.',
                PartyCombinationEnumerator::MAX_COMBINATIONS
            ));
        }

        foreach ($blocks as $block) {
            $findings = array_merge(
                $findings,
                $this->checkReferenceIntegrity($block, $inspector),
                $this->checkEditabilityWithinVisibility($block, $inspector),
                $this->checkDanglingAndReachability($block, $combos),
            );
        }

        if ($this->noErrors($findings)) {
            $findings[] = LintFinding::pass('L4', 'Every conditional and party combination resolves; no dangling blocks; required fields reachable.');
        }

        return $findings;
    }

    /** @return LintFinding[] */
    private function checkReferenceIntegrity(Block $block, CdsInspector $inspector): array
    {
        $findings = [];
        $blockId = $block->blockId;

        // visibility / editability party-key references.
        foreach (['visibility' => $block->visibility, 'editability' => $block->editability] as $axis => $expr) {
            foreach ($expr->partyKeys as $key) {
                if ($inspector->partyByKeyOrRoleBase($key) === null) {
                    $findings[] = LintFinding::error('L4', $blockId, $axis . '_unknown_party', sprintf('Block "%s" %s references undeclared party "%s".', $blockId, $axis, $key), ['party_key' => $key]);
                }
            }
        }

        // condition references.
        $cond = $block->condition;
        if (in_array($cond->kind, [Condition::PARTY_PRESENT, Condition::PARTY_ABSENT, Condition::PARTY_COUNT_GTE], true)) {
            if ($cond->partyKey === null || $inspector->partyByKeyOrRoleBase($cond->partyKey) === null) {
                $findings[] = LintFinding::error('L4', $blockId, 'condition_unknown_party', sprintf('Block "%s" condition references undeclared party "%s".', $blockId, (string) $cond->partyKey), ['party_key' => $cond->partyKey]);
            }
        }
        if (in_array($cond->kind, [Condition::FIELD_TRUTHY, Condition::FIELD_EQUALS], true)) {
            if ($cond->fieldId === null || !$inspector->hasFieldId($cond->fieldId)) {
                $findings[] = LintFinding::error('L4', $blockId, 'condition_unknown_field', sprintf('Block "%s" condition references undeclared field "%s".', $blockId, (string) $cond->fieldId), ['field_id' => $cond->fieldId]);
            }
        }

        return $findings;
    }

    /** @return LintFinding[] */
    private function checkEditabilityWithinVisibility(Block $block, CdsInspector $inspector): array
    {
        $vis = $block->visibility;
        $edit = $block->editability;
        $offenders = [];

        // Representatives: the base key and an instance key, so both base-named and
        // instance-named PartyExprs are compared correctly.
        foreach ($inspector->partyKeys() as $key) {
            foreach ([$key, $key . '_1'] as $rep) {
                if ($edit->appliesTo($rep) && !$vis->appliesTo($rep)) {
                    $offenders[$key] = true;
                }
            }
        }

        if ($offenders === []) {
            return [];
        }

        return [LintFinding::error(
            'L4',
            $block->blockId,
            'editability_exceeds_visibility',
            sprintf('Block "%s" grants edit rights to parties who cannot see it: %s. A party may only edit a block it is shown.', $block->blockId, implode(', ', array_keys($offenders))),
            ['parties' => array_keys($offenders)],
        )];
    }

    /**
     * @param array<int,array{present_parties:string[],party_counts:array<string,int>,field_values:array<string,mixed>}> $combos
     * @return LintFinding[]
     */
    private function checkDanglingAndReachability(Block $block, array $combos): array
    {
        $findings = [];
        $renderScenarios = $this->scenariosWhereRenders($block, $combos);

        // 3. Dangling — the block renders in no scenario at all.
        if ($renderScenarios === []) {
            $findings[] = LintFinding::error('L4', $block->blockId, 'dangling_block', sprintf('Block "%s" has a condition that can never be satisfied by any valid party combination — it would never render.', $block->blockId));

            // A dangling block also strands any required field, but the dangling finding is
            // the root cause — don't double-report.
            return $findings;
        }

        // 4. Unreachable required field — a required field never rendered-and-visible.
        $requiredFieldIds = [];
        foreach ($block->fields as $field) {
            if ($field->required) {
                $requiredFieldIds[] = $field->fieldId;
            }
        }
        if ($requiredFieldIds === []) {
            return $findings;
        }

        $reachable = false;
        foreach ($renderScenarios as $scenario) {
            foreach ($scenario['present_parties'] as $inst) {
                if ($block->visibility->appliesTo($inst)) {
                    $reachable = true;
                    break 2;
                }
            }
        }

        if (!$reachable) {
            foreach ($requiredFieldIds as $fieldId) {
                $findings[] = LintFinding::error('L4', $block->blockId, 'unreachable_required_field', sprintf('Required field "%s" in block "%s" is never reachable — no valid party combination both renders the block and shows it to a party.', $fieldId !== '' ? $fieldId : '(no field_id)', $block->blockId), ['field_id' => $fieldId]);
            }
        }

        return $findings;
    }

    /**
     * The scenarios in which a block renders. Data-gated conditions (field_truthy /
     * field_equals) are treated as renderable (their runtime truth is data-dependent, so
     * some data makes them true); party/always conditions are evaluated concretely via
     * WS0's canonical {@see Condition::evaluate()}.
     *
     * @param  array<int,array{present_parties:string[],party_counts:array<string,int>,field_values:array<string,mixed>}> $combos
     * @return array<int,array{present_parties:string[],party_counts:array<string,int>,field_values:array<string,mixed>}>
     */
    private function scenariosWhereRenders(Block $block, array $combos): array
    {
        $cond = $block->condition;

        if ($cond->kind === Condition::ALWAYS
            || $cond->kind === Condition::FIELD_TRUTHY
            || $cond->kind === Condition::FIELD_EQUALS) {
            return $combos;
        }

        return array_values(array_filter($combos, static fn (array $scenario): bool => $cond->evaluate($scenario)));
    }

    /** @param LintFinding[] $findings */
    private function noErrors(array $findings): bool
    {
        foreach ($findings as $f) {
            if ($f->blocksPublish()) {
                return false;
            }
        }

        return true;
    }
}
