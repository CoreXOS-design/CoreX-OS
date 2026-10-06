<?php

namespace App\Services\Rentals;

use App\Models\RentalWorkOrderVariation;

/**
 * .ai/specs/rental-work-orders.md §17.6.3 — what RentalApprovalGateService
 * decided, and the term it relied on. Pure value object; the matching
 * `rental_approval_decisions` row (when the gate actually decided something)
 * is written by the service, not here.
 */
final class GateDecision
{
    public function __construct(
        /** May the work proceed / may the amount stand without asking the owner? */
        public readonly bool $authorised,
        /** One of RentalApprovalDecision::DECISION_* or 'no_change'. */
        public readonly string $decision,
        /** One of RentalWorkOrder::BASIS_* (null when no basis applies, e.g. no_change / needs_owner). */
        public readonly ?string $basis = null,
        /** One of RentalApprovalDecision::TERM_* */
        public readonly ?string $termKey = null,
        public readonly ?float $termValue = null,
        /** One of RentalApprovalDecision::SOURCE_* */
        public readonly ?string $termSource = null,
        public readonly ?float $amountTested = null,
        public readonly ?float $baselineAmount = null,
        public readonly ?float $limitAmount = null,
        /** The decision in plain words ("R620 is within the owner's no-approval limit of R800 (set on this property)"). */
        public readonly string $note = '',
        public readonly ?RentalWorkOrderVariation $variation = null,
    ) {
    }

    /** "Nothing the gate needs to decide" (e.g. a decrease): writes no decision row. */
    public static function noChange(string $note = ''): self
    {
        return new self(authorised: true, decision: 'no_change', note: $note);
    }
}
