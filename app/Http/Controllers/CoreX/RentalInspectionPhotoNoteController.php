<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoNote;
use App\Models\RentalInspectionSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-inspections.md §25 — AT-433 Part C. The photo-level
 * note (Defect / Wear and tear / Reference), distinct from
 * RentalInspectionRecordingController's item-comment/observation surface.
 * Deliberately its own controller — the note is scoped to ONE photo, never
 * to an observation or a room, so it doesn't belong on that controller's
 * item/room-shaped actions.
 *
 * Full CRUD, soft delete only. At most one live note per photo — store()
 * refuses a second one (use update() instead); every mutating action is
 * blocked once the inspection is signed (== completed, RentalInspection
 * PhotoNote::assertMutable()'s own docblock).
 */
class RentalInspectionPhotoNoteController extends Controller
{
    /**
     * POST /corex/rental-inspections/{rentalInspection}/photos/{photo}/notes
     */
    public function store(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo): JsonResponse
    {
        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);

        try {
            RentalInspectionPhotoNote::assertMutable($rentalInspection);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (RentalInspectionPhotoNote::liveFor($photo)) {
            return response()->json(['message' => 'This photo already has a note — edit it instead.'], 422);
        }

        $classifications = RentalInspectionSetting::photoNoteClassificationsFor($rentalInspection->agency_id);
        $validated = $request->validate([
            'classification_key' => ['required', 'string', Rule::in(array_column($classifications, 'key'))],
            'note' => ['required', 'string', 'max:4000'],
        ]);

        $note = RentalInspectionPhotoNote::create([
            'agency_id' => $rentalInspection->agency_id,
            'rental_inspection_id' => $rentalInspection->id,
            'rental_inspection_photo_id' => $photo->id,
            'classification_key' => $validated['classification_key'],
            'note' => $validated['note'],
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->json($note, 201);
    }

    /**
     * PATCH /corex/rental-inspections/{rentalInspection}/photos/{photo}/notes/{note}
     * — edit-in-place, per Johan's Full CRUD ruling (never an archive+recreate
     * for a plain correction).
     */
    public function update(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo, RentalInspectionPhotoNote $note): JsonResponse
    {
        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        abort_if((int) $note->rental_inspection_photo_id !== (int) $photo->id, 404);

        try {
            RentalInspectionPhotoNote::assertMutable($rentalInspection);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $classifications = RentalInspectionSetting::photoNoteClassificationsFor($rentalInspection->agency_id);
        $validated = $request->validate([
            'classification_key' => ['required', 'string', Rule::in(array_column($classifications, 'key'))],
            'note' => ['required', 'string', 'max:4000'],
        ]);

        $note->forceFill(array_merge($validated, [
            'updated_by_user_id' => $request->user()->id,
        ]))->save();

        return response()->json($note->fresh());
    }

    /**
     * DELETE /corex/rental-inspections/{rentalInspection}/photos/{photo}/notes/{note}
     * — archived (soft delete), never hard-deleted (non-negotiable #1).
     */
    public function archive(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo, RentalInspectionPhotoNote $note): JsonResponse
    {
        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        abort_if((int) $note->rental_inspection_photo_id !== (int) $photo->id, 404);

        try {
            RentalInspectionPhotoNote::assertMutable($rentalInspection);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $note->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $note->delete();

        return response()->json(['message' => 'Photo note archived.']);
    }

    /**
     * POST /corex/rental-inspections/{rentalInspection}/photos/{photo}/notes/{note}/restore
     * — the exact reverse of archive(). Refused if the photo has picked up
     * a different live note in the meantime (store() only ever allows one).
     */
    public function restore(Request $request, RentalInspection $rentalInspection, RentalInspectionPhoto $photo, RentalInspectionPhotoNote $note): JsonResponse
    {
        // The route uses ->withTrashed(), which bypasses RentalInspection's own
        // visibleTo route binding — re-apply the own/branch scope explicitly.
        abort_unless(
            RentalInspection::withTrashed()->visibleTo($request->user())->whereKey($rentalInspection->id)->exists(),
            404
        );
        abort_if((int) $photo->rental_inspection_id !== (int) $rentalInspection->id, 404);
        abort_if((int) $note->rental_inspection_photo_id !== (int) $photo->id, 404);

        try {
            RentalInspectionPhotoNote::assertMutable($rentalInspection);
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        if (RentalInspectionPhotoNote::liveFor($photo)) {
            return response()->json(['message' => 'This photo already has a live note — archive it first.'], 422);
        }

        $note->restore();

        return response()->json($note->fresh());
    }
}
