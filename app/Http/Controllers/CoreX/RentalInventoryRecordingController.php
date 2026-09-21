<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryLineDisposition;
use App\Models\RentalInventorySignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inventory.md §4/§5 — adding/retiring lines and capturing
 * signatures. Deliberately separate from RentalInventoryController, same
 * split RentalInspectionRecordingController already established between
 * Read/lifecycle and recording.
 */
class RentalInventoryRecordingController extends Controller
{
    /** POST /corex/rental-inventories/{inventory}/lines */
    public function storeLine(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $validated = $request->validate([
            'room_label' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0'],
            'description' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $line = RentalInventoryLine::create(array_merge($validated, [
            'agency_id' => $rentalInventory->agency_id,
            'rental_inventory_id' => $rentalInventory->id,
            'created_by_user_id' => $request->user()->id,
        ]));

        return response()->json($line, 201);
    }

    public function updateLine(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $validated = $request->validate([
            'room_label' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0'],
            'description' => ['required', 'string'],
        ]);

        $line->update($validated);

        return response()->json($line->fresh());
    }

    /** POST /corex/rental-inventories/{inventory}/lines/{line}/retire — §3.3-style, never a hard delete. */
    public function retireLine(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $line->retire();

        return response()->json(['message' => 'Line retired.']);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines/{line}/dispositions —
     * §8, the move-out finding. Append-only: this always CREATES a new row,
     * even if one already exists for this line (a correction is a fresh
     * row, never an edit — RentalInventoryLineDisposition has no update()).
     */
    public function storeLineDisposition(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $validated = $request->validate([
            'disposition_key' => ['required', 'string', 'max:60'],
            // §8 — genuinely optional. Absent/null means "not yet counted,"
            // never coerced to 0 by this validation or anywhere downstream.
            'quantity_found' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $disposition = RentalInventoryLineDisposition::record($line, $validated['disposition_key'], [
                'quantity_found' => $validated['quantity_found'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($disposition, 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/signatures — same shape and
     * invariants as RentalInspectionRecordingController::storeSignature()
     * (minus wet-ink, minus the sign_on_behalf-gated refusal-on-behalf
     * permission — not built here, see the migration's own docblock).
     */
    public function storeSignature(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:' . implode(',', [
                RentalInventorySignature::PARTY_TENANT,
                RentalInventorySignature::PARTY_LANDLORD,
                RentalInventorySignature::PARTY_AGENT,
            ])],
            'disposition' => ['required', 'string', 'in:' . implode(',', [
                RentalInventorySignature::DISPOSITION_SIGNED,
                RentalInventorySignature::DISPOSITION_REFUSED,
            ])],
            'party_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'signature_image' => ['nullable', 'string'],
            'refusal_reason_preset' => ['nullable', 'string', 'max:60'],
            'refusal_reason_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['party_role'] !== RentalInventorySignature::PARTY_AGENT) {
            $attributes = [
                'party_contact_id' => $validated['party_contact_id'] ?? null,
                'refusal_reason_preset' => $validated['refusal_reason_preset'] ?? null,
                'refusal_reason_note' => $validated['refusal_reason_note'] ?? null,
                'recorded_by_user_id' => $request->user()->id,
            ];
        } else {
            $attributes = ['recorded_by_user_id' => $request->user()->id];
        }

        if (!empty($validated['signature_image'])) {
            $attributes['party_signature_path'] = RentalInventorySignature::storeCanvasImage($validated['signature_image'], $rentalInventory->property_id);
        }

        try {
            $signature = RentalInventorySignature::capture($rentalInventory, $validated['party_role'], $validated['disposition'], $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($signature, 201);
    }

    public function complete(RentalInventory $rentalInventory): JsonResponse
    {
        try {
            $rentalInventory->markCompleted();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($rentalInventory->fresh());
    }
}
