<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseAgreementTerms;

/**
 * .ai/specs/leases.md §15.7.3 (Build L1). What a renewal pre-fills from: the previous term's
 * lease_agreement_terms row when it has one. The lazy fallback for a lease e-signed before this series
 * (harvest from its source document through the map of the template that produced it, then write the
 * row) arrives with Build L2, together with LeaseAgreementHarvest; until then a term with no row simply
 * has nothing on record — the reader never guesses a field name.
 */
class PreviousTermValuesReader
{
    public function for(Lease $previous): ?LeaseAgreementTerms
    {
        return $previous->agreementTerms()->first();
    }
}
