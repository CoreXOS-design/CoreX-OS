<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalWorkOrderSetting;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.2 - the ONE place the owner's per-property authority settings are READ.
 *
 * The three settings belong to the owner-settings work (cc4: their storage, evidence, the owner's email, dispute). Nothing else in the rentals
 * code reads their columns; everything asks here, so when that work lands only the bodies of the two nullable readers change.
 *   - repairLimit:      the most the agent may spend on this property without asking the owner (today: the property's no-approval limit).
 *   - emergencyLimit:   the most the agent may authorise as a declared emergency. null = NOT SET = ask the owner.
 *   - calloutAllowance: the most the agent may pay for a contractor's call-out without asking the owner. null = NOT SET = ask the owner.
 */
class OwnerAuthoritySettings
{
    public function repairLimit(Property $property): float
    {
        return RentalWorkOrderSetting::thresholdFor($property);
    }

    public function emergencyLimit(Property $property): ?float
    {
        return null;   // not set until the owner's settings exist - every emergency goes to the owner
    }

    public function calloutAllowance(Property $property): ?float
    {
        return null;   // not set until the owner's settings exist - every call-out goes to the owner
    }
}
