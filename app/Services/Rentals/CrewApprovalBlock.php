<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 — Build 2's plug-in slot in the crew
 * view: the approval chips — "Approved to proceed (emergency)", per-line
 * "Approved / Awaiting owner — do not start / Declined by owner" (§17.7, §17.8.4).
 *
 * FOUNDATION: returns [] (nothing to show). Build 2 fills it. Plain values only;
 * the crew sees the STATE, never an owner's name, contact, an approval amount or
 * selling (guarded by CrewPayloadNeverCarriesSellingTest).
 * Rendered by rentals/crew-link/_block-approval.blade.php.
 */
final class CrewApprovalBlock
{
    /** @return array<string, mixed> */
    public static function for(RentalJobCard $card, CrewViewContext $ctx): array
    {
        return [];
    }
}
