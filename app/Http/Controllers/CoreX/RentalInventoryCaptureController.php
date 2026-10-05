<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventorySetting;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\Images\PropertyImageStorer;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    use AuthorizesPropertyAccess;
    use EnforcesRecordVisibility;

    /**
     * GET /corex/properties/{property}/inventory — resolves or transparently
     * starts the property's current inventory (RentalInventory::
     * resolveOrStartFor()), loads its rooms, lines, and photos, and renders
     * the capture surface. §0a/§15 — a sale property (or any property with no
     * active lease) gets a property-level inventory (lease_id null), never a
     * dead end — resolveOrStartFor() never returns null for a real Property.
     */
    public function show(Request $request, Property $property): View
    {
        $this->authorizeProperty($property, false);

        // §13.10 — the property shell (header + tab bar) reused verbatim
        // from PropertyController::show(), not a second computation:
        // partials/_property-shell-header.blade.php and
        // _property-shell-tabs.blade.php need exactly these three the same
        // way that controller already builds them.
        $property->load(['agent', 'branch', 'notes.user', 'files.user', 'contacts.type']);
        $readinessReport = app(MarketingReadinessService::class)->statusFor($property);
        try {
            $allDriveDocs = $property->documents()->with(['documentType', 'contacts'])->get();
        } catch (\Exception $e) {
            $allDriveDocs = collect();
        }
        $coreMatches = app(MatchingService::class)->matchesForProperty($property);

        // Read-only: a GET (view permission) never creates an inventory
        // (audit M4). With none yet, the view offers a "Start inventory"
        // button that POSTs to start() below (needs rental_inventories.create).
        $inventory = RentalInventory::findCurrentFor($property);

        $rooms = PropertyRoom::where('property_id', $property->id)
            ->where('is_retired', false)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $linesForJs = collect();
        $photosForJs = collect();
        $roomMarksForJs = collect();

        if ($inventory) {
            $inventory->load([
                'lines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'lines.photos',
                'photos' => fn ($q) => $q->orderByDesc('created_at'),
                'photos.uploadedBy',
                'roomMarks',
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
                'condition_key' => $l->condition_key,
                'photos' => $l->photos->pluck('id'),
            ])->values();

            $photosForJs = $inventory->photos->map(fn ($p) => [
                'id' => $p->id,
                'property_room_id' => $p->property_room_id,
                'storage_path' => $p->storage_path,
                'lines' => $p->lines->pluck('id'),
            ])->values();

            // §12 — the completion gate's "nothing in this room" state,
            // surfaced here so the capture screen can show it and offer the
            // mark-empty control per room.
            $roomMarksForJs = $inventory->roomMarks->map(fn ($m) => ['property_room_id' => $m->property_room_id])->values();
        }

        $roomsForJs = $rooms->map(fn ($r) => ['id' => $r->id, 'label' => $r->label])->values();

        // §13 — "Copy from last inventory" only ever shows when a real prior
        // inventory exists for this property; the server-side endpoint is
        // the actual gate (404s with a plain message otherwise), this is
        // just so the button isn't offered when it can only ever fail.
        $hasPriorInventory = $inventory && $inventory->priorInventory() !== null;

        return view('corex.rental-inventories.capture', [
            'property' => $property,
            'readinessReport' => $readinessReport,
            'allDriveDocs' => $allDriveDocs,
            'coreMatches' => $coreMatches,
            'inventory' => $inventory,
            'canStartInventory' => $request->user()->hasPermission('rental_inventories.create'),
            // A completed inventory is a signed legal record and a
            // cancelled one is closed — neither may be edited from this
            // screen. Drives whether the capture markup (Add space, room
            // lines, marks, photo controls) renders at all; see
            // RentalInventory::isDraft()/assertEditable() for the matching
            // server-side 409 guard on every write endpoint.
            'isDraft' => $inventory ? $inventory->isDraft() : false,
            'rooms' => $rooms,
            'roomsForJs' => $roomsForJs,
            'linesForJs' => $linesForJs,
            'photosForJs' => $photosForJs,
            'roomMarksForJs' => $roomMarksForJs,
            'hasPriorInventory' => $hasPriorInventory,
            // §13 — the capture-time condition chip vocabulary, agency-
            // configurable (RentalInventorySetting::conditionStatesFor()),
            // never hardcoded.
            'conditionStates' => $inventory ? RentalInventorySetting::conditionStatesFor($inventory->agency_id) : [],
            // Johan: "we specced inventory being blank then you can create
            // the spaces same as with inspections." Reuses
            // RentalInspectionRecordingController::storeItem() (kind=space)
            // directly — the SAME PropertyRoom write path inspections' own
            // "Space (new room)" control already uses, not a second space
            // model. A room created here is immediately visible to
            // inspections too, and vice versa, because it is the same
            // table, same query, same permission (access_properties).
            'spaceStoreUrl' => route('corex.properties.rental-inspection-items.store', $property),
            'spaceTypes' => config('property-spaces.all_space_types', []),
            // Johan, 2026-09-28, on 15726: "why ask the agent to retype
            // something we know" — a one-click way to seed spaces from the
            // listing instead of typing every bedroom/bathroom by hand.
            // Reuses the EXACT SAME endpoint/service the Inspections tab's
            // own "Build from advertising details" button already calls
            // (RentalInspectionFormSeeder::seedFromAdvertising(), via
            // RentalInspectionRecordingController::seedFromAdvertising()) —
            // not a second seeder built from beds/baths/garages counts. It
            // reads the property's own advertising Spaces (spaces_json),
            // which already carries every real unit — including a Flatlet
            // count no beds/baths/garages column would ever expose — and
            // creates one real PropertyRoom per unit, editable afterwards
            // exactly like a manually-added space. One-time per property
            // (the seeder's own 409 guard, shared with Inspections) and
            // never runs silently — always an explicit agent click.
            'seedSpacesFromListingUrl' => route('corex.properties.rental-inspection-items.seed-from-advertising', $property),
        ]);
    }

    /**
     * POST /corex/properties/{property}/inventory — explicit creation of the
     * property's inventory (audit M4: the GET must not create it). Runs under
     * a row lock on the property so two simultaneous clicks/tabs cannot each
     * start a draft; the second finds the first and just redirects.
     */
    public function start(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property, true);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $property) {
            Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();
            RentalInventory::resolveOrStartFor($property, $request->user());
        });

        return redirect()->route('corex.properties.inventory.show', $property);
    }

    /**
     * POST /corex/rental-inventories/{inventory}/photos — batched multi-file
     * upload, following the SAME client-batching contract already
     * established for property galleries/inspection photos this session
     * (client sends up to ~10 files per request, under PHP's
     * max_file_uploads=20): one request, N files, N results, each file
     * independently idempotent via client_idempotency_key so a retried
     * batch never double-uploads a file that actually landed.
     *
     * §13, Johan's approved mockup — "Photos are a horizontal strip on the
     * line, and a photo uploads THE MOMENT it is selected. No staging,
     * ever." `rental_inventory_line_id` is optional: when present (the
     * per-line "add photo" control), every photo in this batch is uploaded
     * AND tagged to that line in this same request — upload and tag are one
     * action, not upload-then-open-a-tagger. Absent (the per-room "Add
     * photo(s)" control), behaviour is unchanged from before this pass.
     */
    public function storePhotos(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $rentalInventory->assertEditable();

        $validated = $request->validate([
            'property_room_id' => ['nullable', 'integer', Rule::exists('property_rooms', 'id')->where('property_id', $rentalInventory->property_id)],
            'rental_inventory_line_id' => ['nullable', 'integer', 'exists:rental_inventory_lines,id'],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_keys' => ['nullable', 'array'],
            'client_idempotency_keys.*' => ['nullable', 'uuid'],
        ]);

        $line = null;
        if (! empty($validated['rental_inventory_line_id'])) {
            $line = RentalInventoryLine::find($validated['rental_inventory_line_id']);
            abort_if(! $line || (int) $line->rental_inventory_id !== (int) $rentalInventory->id, 404);
        }

        $storer = app(PropertyImageStorer::class);
        $created = [];

        foreach ($validated['photos'] as $i => $file) {
            $clientKey = $validated['client_idempotency_keys'][$i] ?? null;
            if ($clientKey) {
                $existing = RentalInventoryPhoto::where('client_idempotency_key', $clientKey)
                    ->where('rental_inventory_id', $rentalInventory->id)->first();
                if ($existing) {
                    $created[] = $existing;
                    continue;
                }
            }

            $url = $storer->store($file, $rentalInventory->property_id);

            $photo = RentalInventoryPhoto::create([
                'agency_id' => $rentalInventory->agency_id,
                'rental_inventory_id' => $rentalInventory->id,
                'property_room_id' => $validated['property_room_id'] ?? $line?->property_room_id,
                'storage_path' => $url,
                'file_size_bytes' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
                'client_idempotency_key' => $clientKey,
            ]);

            if ($line) {
                $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);
            }

            $created[] = $photo;
        }

        // §4a — the shared uploader (public/js/corex-photo-batch-uploader.js)
        // pushes this response straight into its own reactive `photos` array,
        // the same shape resolveOrStartFor()'s show() action already builds
        // ($photosForJs) — 'lines' must always be present, even empty, or
        // `photo.lines.length` on a freshly-uploaded tile throws client-side.
        return response()->json([
            'photos' => collect($created)->map(fn (RentalInventoryPhoto $p) => [
                'id' => $p->id,
                'property_room_id' => $p->property_room_id !== null ? (int) $p->property_room_id : null,
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
        $this->assertVisible($request, $rentalInventory);
        abort_if((int) $photo->rental_inventory_id !== (int) $rentalInventory->id, 404);
        $rentalInventory->assertEditable();

        $photo->archive($request->user());

        return response()->json(['message' => 'Photo archived.']);
    }

    /** POST /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo} — tag a line to a photo. Optional, never required (§0b). */
    public function attachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        abort_unless((int) $photo->rental_inventory_id === (int) $rentalInventory->id, 404);
        $rentalInventory->assertEditable();

        $line->photos()->syncWithoutDetaching([$photo->id => ['agency_id' => $line->agency_id]]);

        return response()->json(['message' => 'Tagged.']);
    }

    /** DELETE /corex/rental-inventories/{inventory}/lines/{line}/photos/{photo} — remove the tag. The line and the photo are both untouched. */
    public function detachLinePhoto(Request $request, RentalInventory $rentalInventory, RentalInventoryLine $line, RentalInventoryPhoto $photo): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        abort_unless((int) $line->rental_inventory_id === (int) $rentalInventory->id, 404);
        abort_unless((int) $photo->rental_inventory_id === (int) $rentalInventory->id, 404);
        $rentalInventory->assertEditable();

        $line->photos()->detach($photo->id);

        return response()->json(['message' => 'Untagged.']);
    }
}
