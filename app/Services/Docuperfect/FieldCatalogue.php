<?php

namespace App\Services\Docuperfect;

use Illuminate\Support\Collection;

/**
 * The curated list of named fields the template builder offers (config/docuperfect-field-catalogue.php).
 *
 * The raw table has a row for every database column; a person building a document wants the few that mean something on a page.
 * This marks which rows are those. Nothing is hidden for good: the builder's "Show every database field" switch lists all rows
 * again, and a field a template is already mapped to is always listed.
 */
class FieldCatalogue
{
    /** Whether a named field (source_type + source_column) is on the curated list. */
    public function isCurated(?string $sourceType, ?string $sourceColumn): bool
    {
        $sourceType = (string) $sourceType;
        if (in_array($sourceType, (array) config('docuperfect-field-catalogue.whole', []), true)) {
            return true;
        }

        return in_array((string) $sourceColumn, (array) config('docuperfect-field-catalogue.' . $sourceType, []), true);
    }

    /**
     * The ids of the curated rows: one row per (source, contact role, column) - the table holds a few twins (two "complex_name"
     * rows), the lowest id is the one offered. The twin that a mapping already uses is still listed by the builder.
     *
     * @param Collection<int, object> $namedFields rows with id, source_type, source_column, source_contact_type
     * @return array<int, true> id => true
     */
    public function curatedIds(Collection $namedFields): array
    {
        $seen = [];
        $ids = [];
        foreach ($namedFields->sortBy('id') as $nf) {
            if (! $this->isCurated($nf->source_type, $nf->source_column)) {
                continue;
            }
            $key = $nf->source_type . '|' . ($nf->source_contact_type ?? '') . '|' . $nf->source_column;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ids[(int) $nf->id] = true;
        }

        return $ids;
    }
}
