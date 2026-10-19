<?php

namespace Tests\Support;

use App\Models\RentalWorkOrder;

/** The quote an owner is looking at, as the portal hands it to them and as their decision must carry it back (audit fixes D26). */
final class OwnerQuoteSnapshot
{
    /** @return array<string, mixed> */
    public static function for(RentalWorkOrder $wo): array
    {
        $q = $wo->quotes()->where('is_selected', true)->whereNull('declined_at')->first();

        // a work order with no selected quote has nothing to snapshot (the endpoint then asks for none)
        return $q ? ['quote_id' => $q->id, 'quote_revision' => (int) $q->revision, 'quote_amount' => round((float) $q->ownerFacingAmount(), 2)] : [];
    }
}
