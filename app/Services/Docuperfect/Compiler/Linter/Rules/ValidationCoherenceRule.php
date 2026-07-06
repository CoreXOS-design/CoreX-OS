<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Contracts\DictionaryEntry;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\CdsInspector;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Field;

/**
 * L5 — Validation coherence (§4).
 *
 * "Each field's validation is the dictionary entry's, optionally tightened, never
 * loosened." A field may omit its `validation_override` (it inherits the entry's) or set
 * one, but an override must only NARROW the accepted input space:
 *
 *   required   : entry true  → field may not be optional                (loosen)
 *   type       : must equal the entry's type                            (change = conflict)
 *   max_length : field ≤ entry ; min_length/min : field ≥ entry ; max : field ≤ entry ;
 *   decimals   : field ≤ entry ; enum : field ⊆ entry
 *   regex      : any change → advisory WARNING (subset undecidable statically)
 *
 * Fields with an absent binding (L1) or an unresolved binding (L2) are skipped — those
 * rules own the defect.
 */
final class ValidationCoherenceRule implements LintRule
{
    public function code(): string
    {
        return 'L5';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $findings = [];
        $dictVersion = $cds->dataDictionaryVersion;

        foreach ((new CdsInspector($cds))->fields() as $entry) {
            $field = $entry['field'];
            if (!$field->isBound()) {
                continue; // L1 owns unbound fields.
            }
            $dictEntry = $dictionary->get(trim($field->binding), $dictVersion);
            if (!$dictEntry instanceof DictionaryEntry) {
                continue; // L2 owns unresolved bindings.
            }

            $findings = array_merge($findings, $this->compare($entry['blockId'], $field, $dictEntry));
        }

        if ($this->noErrors($findings)) {
            $findings[] = LintFinding::pass('L5', 'Field validations tighten (never loosen) their dictionary entries.');
        }

        return $findings;
    }

    /** @return LintFinding[] */
    private function compare(string $blockId, Field $field, DictionaryEntry $entry): array
    {
        $findings = [];
        $base = $entry->validation;
        $override = $field->validationOverride ?? [];
        $binding = trim($field->binding);
        $label = $field->fieldId !== '' ? $field->fieldId : '(no field_id)';

        $loosen = function (string $axis, string $detail) use (&$findings, $blockId, $label, $binding): void {
            $findings[] = LintFinding::error('L5', $blockId, 'validation_loosened', sprintf('Field "%s" (binding "%s") loosens the dictionary validation on "%s": %s.', $label, $binding, $axis, $detail), ['field_id' => $label, 'binding' => $binding, 'axis' => $axis]);
        };

        // required — entry requires a value; neither the field flag nor the override may drop it.
        $effectiveRequired = array_key_exists('required', $override) ? (bool) $override['required'] : $field->required;
        if (($base['required'] ?? false) === true && $effectiveRequired === false) {
            $loosen('required', 'the entry requires a value but the field marks it optional');
        }

        // type — must match exactly (change is a conflict, neither tighten nor loosen).
        if (array_key_exists('type', $override) && $entry->type !== '' && (string) $override['type'] !== $entry->type) {
            $findings[] = LintFinding::error('L5', $blockId, 'validation_type_conflict', sprintf('Field "%s" (binding "%s") declares type "%s" but the dictionary entry is type "%s". A field may not change its entry\'s type.', $label, $binding, (string) $override['type'], $entry->type), ['field_id' => $label, 'binding' => $binding, 'field_type' => (string) $override['type'], 'entry_type' => $entry->type]);
        }

        // "no larger" axes.
        foreach (['max_length', 'max', 'decimals'] as $axis) {
            if (array_key_exists($axis, $override) && array_key_exists($axis, $base) && $this->num($override[$axis]) > $this->num($base[$axis])) {
                $loosen($axis, sprintf('field allows %s, entry caps at %s', $this->str($override[$axis]), $this->str($base[$axis])));
            }
        }

        // "no smaller" axes.
        foreach (['min_length', 'min'] as $axis) {
            if (array_key_exists($axis, $override) && array_key_exists($axis, $base) && $this->num($override[$axis]) < $this->num($base[$axis])) {
                $loosen($axis, sprintf('field allows %s, entry floors at %s', $this->str($override[$axis]), $this->str($base[$axis])));
            }
        }

        // enum — field values must be a subset of the entry's.
        if (array_key_exists('enum', $override) && is_array($base['enum'] ?? null)) {
            $extra = array_diff(array_map('strval', (array) $override['enum']), array_map('strval', $base['enum']));
            if ($extra !== []) {
                $loosen('enum', 'field adds values not permitted by the entry: ' . implode(', ', $extra));
            }
        }

        // regex — a change cannot be statically proven a subset; advisory only.
        if (array_key_exists('regex', $override) && array_key_exists('regex', $base) && (string) $override['regex'] !== (string) $base['regex']) {
            $findings[] = LintFinding::warning('L5', $blockId, 'validation_regex_changed', sprintf('Field "%s" (binding "%s") changes the dictionary regex. Verify manually that the new pattern only tightens the entry\'s.', $label, $binding), ['field_id' => $label, 'binding' => $binding]);
        }

        return $findings;
    }

    private function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : (string) json_encode($v);
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
