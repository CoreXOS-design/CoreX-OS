<?php

namespace App\Services\Rentals;

/**
 * .ai/specs/rental-work-orders.md §17.4.3 — the result of resolving one job card
 * line's SELLING price: what it is and which rule produced it. Pure value object.
 * `unitPrice` / `lineTotal` are null when the line has no cost and no manual price yet
 * (a blank, never a 0).
 */
final class SellingResolution
{
    public function __construct(
        public readonly ?float $unitPrice,
        public readonly ?float $lineTotal,
        /** One of RentalJobCardLine::BASIS_* */
        public readonly string $basis,
        /** The markup percentage applied (or null when none / an amount markup). */
        public readonly ?float $markupPercent = null,
        /** True when the line is priced at cost because no markup applied (§17.4.3 — shown as "no markup applied"). */
        public readonly bool $noMarkupApplied = false,
    ) {
    }
}
