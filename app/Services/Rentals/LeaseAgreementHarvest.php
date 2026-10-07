<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;

/**
 * .ai/specs/leases.md §15.7.2 (Build L1 — shell). At completion, and when the agent confirms a change,
 * reads the document's printed values through LeaseAgreementValuesReader, parses them into typed values
 * and updates the lease's lease_agreement_terms row (source = esign_harvest / confirmed) — so whatever
 * was corrected in Fill & review reaches the next renewal. The real body is Build L3b.
 */
class LeaseAgreementHarvest
{
    public function fromDocument(Lease $lease, Document $document): ?LeaseAgreementTerms
    {
        throw new \LogicException('LeaseAgreementHarvest::fromDocument() is built in Build L3b (leases.md §15.21).');
    }
}
