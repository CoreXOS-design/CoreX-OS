<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 — Build 1's plug-in slot in the crew
 * view: the "Parts & labour" panel — the price-request banner, the crew's own
 * lines with their state chips, and the "Send to office" action (§17.5).
 *
 * FOUNDATION: returns [] (nothing to show). Build 1 fills it. It must only ever
 * return plain values — never a model — and NEVER selling, markup, margin or an
 * owner amount (guarded by CrewPayloadNeverCarriesSellingTest).
 * Rendered by rentals/crew-link/_block-pricing.blade.php.
 */
final class CrewPricingBlock
{
    /** @return array<string, mixed> */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        return [];
    }
}
