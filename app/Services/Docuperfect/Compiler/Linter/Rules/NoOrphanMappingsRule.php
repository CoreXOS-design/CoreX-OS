<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\CdsInspector;
use App\Support\Docuperfect\Cds\Cds;

/**
 * L2 — Zero orphan mappings (§4).
 *
 * Referential integrity of the field/binding space against the pinned dictionary version
 * ({@see Cds::$dataDictionaryVersion}, §2.1 — the pin that makes a published template
 * immune to later dictionary edits):
 *   - no duplicate `field_id` across the structure (`duplicate_field_id`);
 *   - every PRESENT binding resolves to a live entry in that dictionary version
 *     ("no field points at a missing dictionary entry" → `binding_unresolved`).
 *
 * Obsoletes `pruneOrphanFieldMappings` (§9): orphans are UNPUBLISHABLE, not pruned after
 * the fact. Empty bindings are L1's concern and are skipped here so one defect fires one
 * rule.
 */
final class NoOrphanMappingsRule implements LintRule
{
    public function code(): string
    {
        return 'L2';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $findings = [];
        $dictVersion = $cds->dataDictionaryVersion;

        $seenFieldIds = [];

        foreach ((new CdsInspector($cds))->fields() as $entry) {
            $blockId = $entry['blockId'];
            $field = $entry['field'];
            $fieldId = $field->fieldId;

            if ($fieldId !== '') {
                if (isset($seenFieldIds[$fieldId])) {
                    $findings[] = LintFinding::error(
                        rule: 'L2',
                        target: $blockId,
                        code: 'duplicate_field_id',
                        message: sprintf('Field id "%s" is declared more than once. Field ids must be unique across the compiled structure.', $fieldId),
                        context: ['field_id' => $fieldId],
                    );
                }
                $seenFieldIds[$fieldId] = true;
            }

            // Resolution is only meaningful for a present binding (L1 owns absence).
            if ($field->isBound() && !$dictionary->has(trim($field->binding), $dictVersion)) {
                $findings[] = LintFinding::error(
                    rule: 'L2',
                    target: $blockId,
                    code: 'binding_unresolved',
                    message: sprintf(
                        'Field "%s" binds to "%s", which does not exist in the pinned data dictionary (version %d).',
                        $fieldId !== '' ? $fieldId : '(no field_id)',
                        trim($field->binding),
                        $dictVersion
                    ),
                    context: ['field_id' => $fieldId, 'binding' => trim($field->binding), 'dictionary_version' => $dictVersion],
                );
            }
        }

        if ($findings === []) {
            $findings[] = LintFinding::pass('L2', 'No orphan mappings; every binding resolves against the pinned dictionary version.');
        }

        return $findings;
    }
}
