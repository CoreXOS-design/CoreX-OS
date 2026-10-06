<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 — Build 3's plug-in slot in the crew
 * view: the dispute banner — the tenant's note and photos, and "Report fixed"
 * while the job is disputed (§17.10.6).
 *
 * FOUNDATION: returns [] (nothing to show). Build 3 fills it. Plain values only
 * (guarded by CrewPayloadNeverCarriesSellingTest). The tenant's DISPUTE photos
 * (RentalWorkOrder::PHOTO_DISPUTE) belong here and nowhere else in the crew
 * payload — Build 3 must also exclude them from the general `photos` list.
 * Rendered by rentals/crew-link/_block-dispute.blade.php.
 */
final class CrewDisputeBlock
{
    /** @return array<string, mixed> */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        return [];
    }
}
