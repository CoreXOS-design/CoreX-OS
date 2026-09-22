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
            'photos' => collect($created)->map(fn (RentalInventoryPhoto $p) => [
                'id' => $p->id,
                'property_room_id' => $p->property_room_id,
                'storage_path' => $p->storage_path,
                'lines' => $p->lines->pluck('id'),
            ])->values(),
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

    /** POST /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo} — tag a line to a photo. Optional, never required (§0b). */
    public function attachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        abort_unless((int) $photo->rental_inventory_id === (int) $rentalInventory->id, 404);

        $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);

        return response()->json(['message' => 'Tagged.']);
    }

    /** DELETE /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo} — remove the tag. The line and the photo are both untouched. */
    public function detachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);

        $line->photos()->detach($photo->id);

        return response()->json(['message' => 'Untagged.']);
    }
}
