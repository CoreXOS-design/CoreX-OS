<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\CdsInspector;
use App\Support\Docuperfect\Cds\Cds;

/**
 * L1 — All fields bound (§4).
 *
 * "Zero unbound fill-points." Every {@see \App\Support\Docuperfect\Cds\Field} must be
 * bound ({@see \App\Support\Docuperfect\Cds\Field::isBound()}). This rule owns binding
 * PRESENCE only; L2 owns binding RESOLUTION — the partition keeps one defect from firing
 * two rules.
 */
final class AllFieldsBoundRule implements LintRule
{
    public function code(): string
    {
        return 'L1';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $findings = [];

        foreach ((new CdsInspector($cds))->fields() as $entry) {
            $field = $entry['field'];
            if (!$field->isBound()) {
                $findings[] = LintFinding::error(
                    rule: 'L1',
                    target: $entry['blockId'],
                    code: 'field_unbound',
                    message: sprintf(
                        'Field "%s" in block "%s" has no data-dictionary binding. Every fill-point must bind to a typed dictionary entry before publish.',
                        $field->fieldId !== '' ? $field->fieldId : '(no field_id)',
                        $entry['blockId'] !== '' ? $entry['blockId'] : '(no block_id)'
                    ),
                    context: ['field_id' => $field->fieldId],
                );
            }
        }

        if ($findings === []) {
            $findings[] = LintFinding::pass('L1', 'All fields declare a binding.');
        }

        return $findings;
    }
}
