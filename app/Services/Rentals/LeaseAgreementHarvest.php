<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\RentalLeaseTemplate;

/**
 * .ai/specs/leases.md §15.7.2 (Build L3b). At completion — and later, when the agent confirms a change (L3c) — reads
 * the PRINTED values of the lease's agreement through LeaseAgreementValuesReader, parses them into typed values and
 * writes them to the lease's `lease_agreement_terms` row, so whatever was corrected in Fill & review reaches the next
 * renewal.
 *
 * What it writes: the agreement-term fields (adults, pets, escalation, conditions …) and the agency's own extra keys
 * (kept in `extra`). What it never writes: the lease's own columns (rent, dates, deposit — the lease record changes
 * only on the agent's explicit confirmation, §15.7.4), the people (names, IDs and addresses belong to the contact
 * records) and the calculated values (recomputed, never typed). Nothing is guessed: a field the agency's map does not
 * name is not read, and a value that is not on the page or cannot be parsed leaves the stored value alone.
 */
class LeaseAgreementHarvest
{
    public function __construct(private readonly LeaseAgreementValuesReader $reader) {}

    /**
     * @param  string  $source  LeaseAgreementTerms::SOURCE_ESIGN_HARVEST at completion, SOURCE_CONFIRMED after the agent confirms
     */
    public function fromDocument(Lease $lease, Document $document, string $source = LeaseAgreementTerms::SOURCE_ESIGN_HARVEST): ?LeaseAgreementTerms
    {
        $map = $this->mapFor($lease, $document);
        if ($map === []) {
            return null; // no map known for this document — never guess field names
        }

        $registry = (array) config('lease-agreement-fields.fields', []);
        $terms = LeaseAgreementTerms::forLease($lease);
        $extra = (array) ($terms->extra ?? []);
        $wrote = false;

        foreach ($this->reader->read($document, $map) as $key => $read) {
            $parsed = $read['parsed'];
            if ($read['printed'] === null || $read['printed'] === '' || $parsed === null) {
                continue;
            }

            $base = preg_replace('/_\d+$/', '', $key) ?? $key;
            $def = $registry[$key] ?? ($base !== $key && ! empty($registry[$base]['indexed']) ? $registry[$base] : null);

            if ($def === null) {
                $extra[$key] = $parsed; // an agency-specific extra: plain text
                $wrote = true;
                continue;
            }

            if (($def['side'] ?? null) !== 'terms' || $base !== $key) {
                continue; // lease columns, people and calculated values are not the harvest's to write
            }

            if (! empty($def['column'])) {
                $terms->{$def['column']} = $parsed;
            } else {
                $extra[$key] = $parsed;
            }
            $wrote = true;
        }

        if (! $wrote) {
            return null;
        }

        $terms->extra = $extra === [] ? null : $extra;
        $terms->source = $source;
        $terms->save();

        return $terms;
    }

    /**
     * The field map of the lease agreement this document was made from: the agency's linked row for the lease's own
     * agreement template, else for the document's own template. Archived rows still count — an agreement archived
     * after it was used still tells how to read what it produced. Empty when none is known.
     *
     * @return array<string,mixed>
     */
    public function mapFor(Lease $lease, ?Document $document = null): array
    {
        $templateIds = array_values(array_unique(array_filter([
            (int) $lease->agreement_template_id,
            (int) ($document?->template_id ?? 0),
        ])));

        foreach ($templateIds as $templateId) {
            $row = RentalLeaseTemplate::withoutGlobalScopes()->withTrashed()
                ->where('agency_id', $lease->agency_id)
                ->where('docuperfect_template_id', $templateId)
                ->whereNotNull('field_map')
                ->orderByDesc('id')
                ->first();

            if ($row && (array) $row->field_map !== []) {
                return (array) $row->field_map;
            }
        }

        return [];
    }
}
