<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryLineDisposition;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventoryRoomMark;
use App\Models\RentalInventorySignature;
use App\Services\Images\PropertyImageStorer;
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
    /**
     * POST /corex/rental-inventories/{inventory}/lines — §0b: the capture
     * surface sends property_room_id (the property's own PropertyRoom, the
     * agent never retypes a room name); room_label is derived from it for
     * back-compat display. room_label alone still validates on its own for
     * any caller that hasn't moved to room-based capture yet.
     */
    public function storeLine(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', 'exists:property_rooms,id'],
            'room_label' => ['nullable', 'required_without:property_room_id', 'string', 'max:100'],
            // Johan, 2026-10-02: a new line's qty starts blank, not 1 — "lets
            // get that to null and it will work perfect." Blank stays blank;
            // never silently defaulted.
            'quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['required', 'string'],
            // §13 — the move-in condition chip. Optional: the lazy-but-valid
            // shortcut (type quantity + description, move on) must still
            // work end to end, so a line is never blocked on picking one.
            'condition_key' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! empty($validated['property_room_id']) && empty($validated['room_label'])) {
            $validated['room_label'] = \App\Models\PropertyRoom::find($validated['property_room_id'])?->label;
        }

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
            'property_room_id' => ['nullable', 'integer', 'exists:property_rooms,id'],
            'room_label' => ['nullable', 'required_without:property_room_id', 'string', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'description' => ['required', 'string'],
            'condition_key' => ['nullable', 'string', 'max:60'],
        ]);

        if (! empty($validated['property_room_id']) && empty($validated['room_label'])) {
            $validated['room_label'] = \App\Models\PropertyRoom::find($validated['property_room_id'])?->label;
        }

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
     * POST /corex/rental-inventories/{inventory}/rooms/{room}/mark-empty —
     * §12: the completion gate needs to tell "nobody opened this room" apart
     * from "this room genuinely has nothing in it" — the same distinction
     * rental-inspections' markRoomNa() makes, but recorded as its own row
     * (RentalInventoryRoomMark) rather than a per-item observation, because
     * an inventory room has no checklist items to write one against.
     * Idempotent: marking an already-marked room just refreshes who/when.
     */
    public function markRoomEmpty(Request $request, RentalInventory $rentalInventory, PropertyRoom $room): JsonResponse
    {
        abort_if((int) $room->property_id !== (int) $rentalInventory->property_id, 404);

        $mark = RentalInventoryRoomMark::updateOrCreate(
            [
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $room->id,
            ],
            [
                'agency_id' => $rentalInventory->agency_id,
                'marked_empty_by_user_id' => $request->user()->id,
                'marked_empty_at' => now(),
            ]
        );

        return response()->json($mark, 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/copy-from-last — §13,
     * Johan's approved mockup: "a furnished flat is re-let with the same
     * contents, and re-typing forty lines is the work we are supposed to be
     * doing for them." Copies every active line from the property's most
     * recent OTHER inventory (RentalInventory::priorInventory()) into this
     * one. 404s with a plain message when no prior inventory exists — this
     * control is only ever shown on the capture screen when one does, but
     * the server is the real gate, not the button being hidden.
     */
    public function copyFromLastInventory(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $prior = $rentalInventory->priorInventory();
        if (! $prior) {
            return response()->json(['message' => 'This property has no earlier inventory to copy from.'], 404);
        }

        $lines = $rentalInventory->copyLinesFrom($prior, $request->user());

        return response()->json(['lines' => $lines->values()], 201);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines/{line}/move-out-photos
     * — §14, Johan's approved comparison mockup: "Where there is no photo on
     * the current side, SHOW that rather than hiding it. A claim with no
     * photo is a weak claim and the agent should see it while they can
     * still take one." Uploads AND tags to this line in ONE request, same
     * "no staging, ever" contract as the capture screen's own per-line
     * strip (§13.3) — the SAME PropertyImageStorer pipeline, SAME
     * client-batching contract, just `side = move_out` and no dependency on
     * a disposition row existing yet (a disposition is recorded separately,
     * via storeLineDisposition() above; a photo is evidence taken the
     * moment the agent is standing there, whether or not they've typed a
     * note yet).
     */
    public function storeLineMoveOutPhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $validated = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_keys' => ['nullable', 'array'],
            'client_idempotency_keys.*' => ['nullable', 'uuid'],
        ]);

        $storer = app(PropertyImageStorer::class);
        $created = [];

        foreach ($validated['photos'] as $i => $file) {
            $clientKey = $validated['client_idempotency_keys'][$i] ?? null;
            if ($clientKey) {
                $existing = RentalInventoryPhoto::where('client_idempotency_key', $clientKey)->first();
                if ($existing) {
                    $created[] = $existing;
                    continue;
                }
            }

            $url = $storer->store($file, $rentalInventory->property_id);

            $photo = RentalInventoryPhoto::create([
                'agency_id' => $rentalInventory->agency_id,
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $line->property_room_id,
                'side' => RentalInventoryPhoto::SIDE_MOVE_OUT,
                'storage_path' => $url,
                'file_size_bytes' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
                'client_idempotency_key' => $clientKey,
            ]);

            $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);

            $created[] = $photo;
        }

        return response()->json([
            'photos' => collect($created)->map(fn (RentalInventoryPhoto $p) => [
                'id' => $p->id,
                'storage_path' => $p->storage_path,
            ])->values(),
        ], 201);
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
