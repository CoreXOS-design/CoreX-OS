<?php

namespace App\Services\Rentals;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Docuperfect\Template;

/**
 * .ai/specs/leases.md §15.4 step 4 — finds the e-sign wizard field an agency's field-map entry points at.
 *
 * An agency's map names a field the way its OWN document prints it (the `data-field` the field-map dropdown offers).
 * The wizard names the same box by its own rule: a box bound to a property column is called `property_<column>` unless
 * the wizard's name table has a special case for it (deposit has none there, so the document's `deposit_amount` is the
 * wizard's `property_deposit_amount`). Found on QA1 (23 Oct 2026, Mega lease): the deposit was never seeded and printed raw.
 *
 * Resolution, first hit wins: the exact name; the name with every non-word character made `_` (`property_address+suburb`
 * for the document's `property_address_suburb`); then the `property_` prefix added or removed. An exact name always
 * beats an alias, so a template that really has both `deposit_amount` and `property_deposit_amount` is not confused.
 */
class LeaseWizardFieldResolver
{
    /**
     * The template's fill-in fields the way the e-sign wizard lists them (ids are what Fill & review is keyed by).
     *
     * @return array<int|string, array<string,mixed>>
     */
    public function fieldsFor(Template $template): array
    {
        $fields = $template->fields_json ?? [];
        $skeletal = ! empty($fields) && empty($fields[0]['id'] ?? null) && empty($fields[0]['field_name'] ?? null);

        if (($template->render_type ?? 'pdf') === 'web' && ! empty($template->field_mappings)
            && (($template->template_type ?? '') === 'cds' || empty($fields) || $skeletal)) {
            $fields = app(ESignWizardController::class)->buildFieldsFromMappings($template->field_mappings);
        }

        return $fields;
    }

    /**
     * The wizard field a map entry's field name points at, or null when the template has no such field.
     *
     * @param array<int|string, array<string,mixed>> $fields
     * @return array<string,mixed>|null
     */
    public function find(array $fields, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $exact = [];
        $loose = [];
        $alias = [];
        foreach ($fields as $field) {
            $fieldName = (string) ($field['field_name'] ?? '');
            $id = $field['id'] ?? null;
            if ($fieldName === '' || $id === null || in_array($field['tag_type'] ?? $field['type'] ?? '', ['signature', 'initial'], true)) {
                continue;
            }
            $exact[$fieldName] ??= $field;
            $loose[$this->loose($fieldName)] ??= $field;
            foreach ($this->prefixAliases($fieldName) as $other) {
                $alias[$other] ??= $field;
            }
        }

        return $exact[$name]
            ?? $loose[$this->loose($name)]
            ?? $alias[$name]
            ?? $alias[$this->loose($name)]
            ?? null;
    }

    /**
     * Map entries (registry/agency key => field name) the launcher would seed that point at no field on the template -
     * the values it would silently have nowhere to put. Contact-side keys are left out (the wizard fills those from the
     * recipients, not from a seed). A template with no readable fields (a scanned PDF) says nothing: nothing to compare.
     *
     * @param array<string,array{field: string, required: bool, label: ?string}> $map the NORMALISED map
     * @return array<string,string> key => field name
     */
    public function unresolved(Template $template, array $map): array
    {
        $fields = $this->fieldsFor($template);
        if ($fields === []) {
            return [];
        }

        $registry = (array) config('lease-agreement-fields.fields', []);
        $out = [];
        foreach ($map as $key => $entry) {
            if (($registry[$key]['side'] ?? null) === 'contact' || preg_match('/^(.*)_\d+$/', (string) $key, $m) && ($registry[$m[1]]['side'] ?? null) === 'contact') {
                continue;
            }
            if ($this->find($fields, (string) $entry['field']) === null) {
                $out[(string) $key] = (string) $entry['field'];
            }
        }

        return $out;
    }

    private function loose(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    /** @return array<int,string> the other names one wizard field answers to: with / without the `property_` prefix */
    private function prefixAliases(string $fieldName): array
    {
        $loose = $this->loose($fieldName);
        $out = str_starts_with($loose, 'property_') ? [substr($loose, 9)] : ['property_' . $loose];
        if ($loose !== $fieldName) {
            $out[] = str_starts_with($fieldName, 'property_') ? substr($fieldName, 9) : 'property_' . $fieldName;
        }

        return array_values(array_filter(array_unique($out), fn ($n) => $n !== ''));
    }
}
