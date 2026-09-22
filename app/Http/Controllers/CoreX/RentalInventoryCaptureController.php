<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inventory.md §0b — Johan, 2026-09-22, rebuilding the
 * capture surface after the standalone list/create screens turned out to be
 * the wrong shape: "selecting inventory from the property we already know
 * which property its for. done simple." One click from the property,
 * straight into a room-based, autosaving, mobile-ready surface — the SAME
 * PropertyRoom source and ordering the rental-inspections recording surface
 * already uses (PropertyRoom.sort_order), never a second room concept.
 *
 * Deliberately its own controller, not folded into RentalInventoryController
 * (the list/lifecycle/comparison surface, unchanged) or
 * RentalInventoryRecordingController (extended for property_room_id, not
 * rewritten) — this one owns exactly the property-embedded capture screen.
 */
class RentalInventoryCaptureController extends Controller
{
    /**
     * GET /corex/properties/{property}/inventory — resolves or transparently
     * starts the property's current inventory (RentalInventory::
     * resolveOrStartFor()), loads its rooms, lines, and photos, and renders
     * the capture surface. A sale property (or any property with no active
     * lease) gets an honest "no active lease" state, not a silent failure —
     * see .ai/specs/rental-inventory.md §0a for why that limitation is
     * reported, not fixed, in this pass.
     */
    public function show(Request $request, Property $property): View
    {
        $inventory = RentalInventory::resolveOrStartFor($property, $request->user());

        $rooms = PropertyRoom::where('property_id', $property->id)
            ->where('is_retired', false)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $linesForJs = collect();
        $photosForJs = collect();

        if ($inventory) {
            $inventory->load([
                'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'lines.photos',
                'photos' => fn ($q) => $q->orderByDesc('created_at'),
                'photos.uploadedBy',
            ]);

            // Built here, not inline inside @json() in the view: Blade's @json
            // compiler splits its argument on EVERY top-level comma (it treats
            // anything after the first comma as the $options/$depth args), so
            // an array literal with more than one key corrupts the compiled
            // PHP. Passing a single already-built variable sidesteps that
            // entirely — no comma for the naive split to trip on.
            $linesForJs = $inventory->lines->map(fn ($l) => [
                'id' => $l->id,
                'property_room_id' => $l->property_room_id,
                'quantity' => $l->quantity,
                'description' => $l->description,
                'photos' => $l->photos->pluck('id'),
            ])->values();

            $photosForJs = $inventory->photos->map(fn ($p) => [
                'id' => $p->id,
                'property_room_id' => $p->property_room_id,
                'storage_path' => $p->storage_path,
                'lines' => $p->lines->pluck('id'),
            ])->values();
        }

        $roomsForJs = $rooms->map(fn ($r) => ['id' => $r->id, 'label' => $r->label])->values();

        return view('corex.rental-inventories.capture', [
            'property' => $property,
            'inventory' => $inventory,
            'rooms' => $rooms,
            'roomsForJs' => $roomsForJs,
            'linesForJs' => $linesForJs,
            'photosForJs' => $photosForJs,
            // Johan: "we specced inventory being blank then you can create
            // the spaces same as with inspections." Reuses
            // RentalInspectionRecordingController::storeItem() (kind=space)
            // directly — the SAME PropertyRoom write path inspections' own
            // "Space (new room)" control uses, not a second space model. A
            // room created here is immediately visible to inspections too,
            // and vice versa, because it is the same table.
            'spaceStoreUrl' => route('corex.properties.rental-inspection-items.store', $property),
            'spaceTypes' => config('property-spaces.all_space_types', []),
        ]);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/photos — batched multi-file
     * upload, following the SAME client-batching contract already
     * established for property galleries/inspection photos this session
     * (client sends up to ~10 files per request, under PHP's
     * max_file_uploads=20): one request, N files, N results, each file
     * independently idempotent via client_idempotency_key so a retried
     * batch never double-uploads a file that actually landed.
     */
    public function storePhotos(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', 'exists:property_rooms,id'],
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

            $created[] = RentalInventoryPhoto::create([
                'agency_id' => $rentalInventory->agency_id,
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $validated['property_room_id'] ?? null,
                'storage_path' => $url,
                'file_size_bytes' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
                'client_idempotency_key' => $clientKey,
            ]);
        }

        // §4a — the shared uploader (public/js/corex-photo-batch-uploader.js)
        // pushes this response straight into its own reactive `photos` array,
        // the same shape resolveOrStartFor()'s show() action already builds
        // ($photosForJs) — 'lines' must always be present, even empty, or
        // `photo.lines.length` on a freshly-uploaded tile throws client-side.
        return response()->json([
            'photos' => collect($created)->map(fn (RentalInventoryPhoto $p) => $this->photoForJs($p))->values(),
        ], 201);
    }

    /**
     * DELETE /corex/rental-inventories/{inventory}/photos/{photo} — archived
     * (soft delete), never hard-deleted (non-negotiable #1). Mirrors
     * RentalInspectionRecordingController::archivePhoto() exactly (§4a).
     */
    public function archivePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_if((int) $photo->rental_inventory_id !== (int) $rentalInventory->id, 404);

        $photo->archive($request->user());

        return response()->json(['message' => 'Photo archived.']);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/photos/tag-bulk — the
     * untagged tray's own multi-select-then-tag action: many ids, one room,
     * one request. Mirrors RentalInspectionRecordingController::
     * tagPhotosBulk() (§20.13.2) — an id that doesn't belong to this
     * inventory is silently skipped rather than failing the whole batch.
     */
    public function tagPhotosBulk(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $validated = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1'],
            'photo_ids.*' => ['integer'],
            'property_room_id' => ['required', 'integer', 'exists:property_rooms,id'],
        ]);

        abort_unless(
            PropertyRoom::where('id', $validated['property_room_id'])->where('property_id', $rentalInventory->property_id)->exists(),
            404,
            'That room does not belong to this inventory\'s property.'
        );

        $photos = RentalInventoryPhoto::where('rental_inventory_id', $rentalInventory->id)
            ->whereIn('id', $validated['photo_ids'])
            ->get();

        $tagged = $photos->map(function (RentalInventoryPhoto $photo) use ($validated) {
            $photo->retagRoom((int) $validated['property_room_id']);

            return $this->photoForJs($photo);
        });

        return response()->json(['photos' => $tagged->values()]);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/photos/{photo}/untag —
     * back to the tray. Clears the room AND every line tag
     * (RentalInventoryPhoto::untagFromRoom()) — the single-destination move
     * used by BOTH "room → untagged" (no line tags to begin with) and
     * "line item → untagged" (the tile's own "all the way back" control),
     * matching the exact-reverse-of-every-tag-that-got-it-here rule §22.1
     * (rental-inspections.md) already established.
     */
    public function untagPhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_if((int) $photo->rental_inventory_id !== (int) $rentalInventory->id, 404);

        $photo->untagFromRoom();

        return response()->json($this->photoForJs($photo));
    }

    /**
     * POST /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo}
     * — tag a line to a photo. Optional, never required (§0b). The line's
     * OWN room always wins (RentalInventoryPhoto::retagRoom()) — mirrors
     * RentalInspectionPhoto::tagTo()'s "the item's own room resolved
     * server-side, never trusted from client" rule (rental-inspections.md
     * §20.13.1) — so this single endpoint correctly serves BOTH "room →
     * line item" (room unchanged, a no-op retag) and "untagged → line item"
     * (the photo's room is set for the first time, in the same call).
     */
    public function attachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        abort_unless((int) $photo->rental_inventory_id === (int) $rentalInventory->id, 404);

        $photo->retagRoom((int) $line->property_room_id);
        $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);

        return response()->json($this->photoForJs($photo));
    }

    /** DELETE /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo} — remove the tag. The line and the photo are both untouched. */
    public function detachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $line->photos()->detach($photo->id);

        return response()->json(['message' => 'Untagged.']);
    }

    /** The one JS-facing photo shape, used by every action that hands a photo back to the client (§4a). */
    private function photoForJs(RentalInventoryPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'property_room_id' => $photo->property_room_id,
            'storage_path' => $photo->storage_path,
            'lines' => $photo->lines()->pluck('id'),
        ];
    }
}
