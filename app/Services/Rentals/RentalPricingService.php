<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.4 — the ONE place a line's SELLING price is
 * resolved from its COST (and the ONLY place that knows the §17.4.3 rule order:
 * manual → line markup → card parts/labour % → card all-lines % → catalogue
 * default price → agency default markup).
 *
 * FOUNDATION SHELL (§17.21.1): the signatures below are final; BUILD 1 fills the
 * bodies. Until then the read methods are neutral and the write methods refuse
 * loudly, so a caller wired too early fails in its own test instead of silently
 * doing nothing. Nothing in the foundation calls this class.
 */
class RentalPricingService
{
    /**
     * What the selling price of this line should be right now, and which rule decided it.
     * Never writes. Foundation: reports the line's current stored price as `manual`.
     */
    public function resolveSelling(RentalJobCardLine $line, RentalJobCard $card): SellingResolution
    {
        return new SellingResolution(
            unitPrice: $line->unit_price !== null ? (float) $line->unit_price : null,
            lineTotal: $line->line_total !== null ? (float) $line->line_total : null,
            basis: RentalJobCardLine::BASIS_MANUAL,
        );
    }

    /** Recompute one non-manual line's selling from its cost and persist it. Build 1. */
    public function repriceLine(RentalJobCardLine $line): void
    {
        // Foundation: no-op (no line can have a non-manual basis yet).
    }

    /** Recompute every line that is not `manual` / `line_markup`; never touches those two. Build 1. */
    public function repriceCard(RentalJobCard $card): void
    {
        // Foundation: no-op.
    }

    /**
     * Set (or clear with null) a card-level markup — $scope is `all`, `parts` or `labour` — reprice, and log
     * one history row ("Parts markup set to 20 %"). Build 1.
     */
    public function applyJobMarkup(RentalJobCard $card, string $scope, ?float $percent, ?User $by): void
    {
        throw new \LogicException('RentalPricingService::applyJobMarkup() lands in Build 1 (§17.21.2).');
    }

    /**
     * Cost, selling and margin for a card on the EXCL-VAT basis (§17.4.5). `linesWithoutCost` is the count of
     * accepted lines with no cost recorded, so a partial margin is never presented as complete. Build 1.
     *
     * @return array{costExcl: float, sellingExcl: float, marginExcl: float, marginPct: ?float, linesWithoutCost: int}
     */
    public function marginFor(RentalJobCard $card): array
    {
        return ['costExcl' => 0.0, 'sellingExcl' => 0.0, 'marginExcl' => 0.0, 'marginPct' => null, 'linesWithoutCost' => 0];
    }
}
