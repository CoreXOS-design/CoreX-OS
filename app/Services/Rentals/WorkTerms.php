<?php

namespace App\Services\Rentals;

/**
 * .ai/specs/rental-work-orders.md §17.6.1 — the work term in force for ONE
 * rental property, with where each value came from (so every decision can cite
 * its term, §17.6.4). Pure value object.
 */
final class WorkTerms
{
    public const SOURCE_PROPERTY = 'property';
    public const SOURCE_AGENCY_DEFAULT = 'agency_default';
    public const SOURCE_CONSTANT = 'constant';

    public function __construct(
        /** Term (i): work up to this owner-facing amount (per JOB, never per line) needs no owner authorisation. */
        public readonly float $noApprovalLimit,
        public readonly string $limitSource,
    ) {
        // (the old term (ii), a variation tolerance %, is gone: section 22.6 - the authorised amount is a hard lock, so nothing is read for it any more)
    }
}
