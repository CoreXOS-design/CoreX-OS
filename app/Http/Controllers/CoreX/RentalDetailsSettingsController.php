<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PropertyRentalDetailsCustomField;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-property-tab.md §8, Part 1 — "under settings rentals
 * agencies can add anything to this screen they desire" (Johan). This page
 * is where the agency-defined field list (Part 1) lives; the rental price
 * type list (Part 3) and the lease type list (Part 4) join it on the same
 * page as they're built, following the same settings-hub pattern as the
 * sibling Rental Applications/Inspections/Work Orders pages.
 */
class RentalDetailsSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        return view('corex.settings.rental-details', [
            'customFields' => PropertyRentalDetailsCustomField::allFor($agencyId),
            'fieldTypes' => PropertyRentalDetailsCustomField::FIELD_TYPES,
        ]);
    }
}
