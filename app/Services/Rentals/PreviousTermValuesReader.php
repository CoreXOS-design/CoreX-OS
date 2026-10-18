<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/leases.md §15.7.3 (Build L1, fallback Build L3b). What a renewal pre-fills from: the previous term's
 * lease_agreement_terms row when it has one.
 *
 * The lazy fallback is for a lease e-signed before this series, whose terms were never written: if the term has a
 * source document, its printed values are harvested through the map of the agreement that produced it and the row is
 * written (source = esign_harvest), so the next renewal — and every later one — finds it already there. When no map
 * is known for that document the reader returns nothing and every agreement field reads "not on record" — it never
 * guesses a field name, and a fault in the document never blocks the renewal screen.
 */
class PreviousTermValuesReader
{
    public function __construct(private readonly LeaseAgreementHarvest $harvest) {}

    public function for(Lease $previous): ?LeaseAgreementTerms
    {
        $terms = $previous->agreementTerms()->first();
        if ($terms || ! $previous->source_document_id) {
            return $terms;
        }

        try {
            $document = Document::withoutGlobalScopes()->find($previous->source_document_id);

            return $document ? $this->harvest->fromDocument($previous, $document) : null;
        } catch (\Throwable $e) {
            Log::warning('Lease renewal: could not read the previous agreement back', ['lease_id' => $previous->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
