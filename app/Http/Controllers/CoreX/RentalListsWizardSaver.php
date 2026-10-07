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

    /** §45.5 item (Build I-3) — the agency's words for how someone attended; same no-op-unless-marker discipline. */
    public function inspectionAttendedAsLabels(Request $request): void
    {
        if ($request->has('attended_as_labels_submitted')) {
            app(RentalInspectionSettingsController::class)->updateAttendedAsLabels($request);
        }
    }

    /** §45.14 — the agency's words for the three move-out classifications; same no-op-unless-marker discipline. */
    public function inspectionMoveOutClassificationLabels(Request $request): void
    {
        if ($request->has('move_out_classification_labels_submitted')) {
            app(RentalInspectionSettingsController::class)->updateMoveOutClassificationLabels($request);
        }
    }

    /** §45.4 item 3 (Build I-2) — the agency's own room types; same no-op-unless-marker discipline as the lists above. */
    public function inspectionCustomRoomTypes(Request $request): void
    {
        if ($request->has('custom_room_types_submitted')) {
            app(RentalInspectionSettingsController::class)->updateCustomRoomTypes($request);
        }
    }

    public function inventoryConditionStates(Request $request): void
    {
        if ($request->has('inventory_condition_states_submitted')) {
            app(RentalInventorySettingsController::class)->updateConditionStates($request);
        }
    }
}
