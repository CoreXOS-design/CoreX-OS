<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Setup Wizard savers for the Rentals step's repeater lists (inspection
 * condition states, photo-note classifications, inventory condition states).
 *
 * The canonical savers these delegate to treat a missing `_submitted` marker
 * as a FAILED save (they redirect with an error) — correct on their own
 * settings pages, but on a shared wizard step a post that simply never
 * rendered the list (agency-onboarding-setup.md §6.1: a step posts a SUBSET
 * of fields) must leave the stored list alone, not fail the whole step. So
 * each method here is a no-op unless the step's own marker is present, and
 * otherwise hands straight to the canonical saver — no write logic lives here.
 */
class RentalListsWizardSaver extends Controller
{
    public function inspectionConditionStates(Request $request): void
    {
        if ($request->has('condition_states_submitted')) {
            app(RentalInspectionSettingsController::class)->updateConditionStates($request);
        }
    }

    public function inspectionPhotoNoteClassifications(Request $request): void
    {
        if ($request->has('photo_note_classifications_submitted')) {
            app(RentalInspectionSettingsController::class)->updatePhotoNoteClassifications($request);
        }
    }

    public function inventoryConditionStates(Request $request): void
    {
        if ($request->has('inventory_condition_states_submitted')) {
            app(RentalInventorySettingsController::class)->updateConditionStates($request);
        }
    }
}
