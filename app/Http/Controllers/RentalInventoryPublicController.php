<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\RentalInventory;
use App\Models\RentalInventoryBuyerAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §41-follow-up (Job 3, 2026-09-28) — the public destination for a
 * completed inventory's report link, mirroring
 * RentalInspectionPublicController exactly (same boundary: reached by
 * someone with no CoreX session at all — a seller or tenant — so it lives
 * at the top level, never behind CoreX's own auth middleware group, and
 * shows this inventory and its photos and NOTHING else).
 *
 * findByPublicToken() (RentalInventory's own method) is the ONLY lookup
 * used here — withoutGlobalScopes(), token-scoped, expiry-checked in the
 * query itself.
 */
class RentalInventoryPublicController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $inventory = RentalInventory::findByPublicToken($token);

        if (! $inventory) {
            // Same response whether the token never existed, expired, or was
            // revoked — same reasoning as RentalInspectionPublicController::show().
            // Reuses the existing generic view directly; nothing inspection-
            // specific in it.
            return view('rental-inspections.public.unavailable');
        }

        $inventory->load([
            'property', 'lease.tenants.contact',
            'lines.room', 'lines.moveInPhotos',
            'signatures.partyContact', 'signatures.recordedByUser',
            'roomMarks.room',
            'buyerAcceptances.buyerContact',
        ]);

        $linesByRoom = $inventory->lines
            ->sortBy(fn ($line) => [$line->room?->sort_order ?? PHP_INT_MAX, $line->room?->id ?? 0, $line->sort_order])
            ->groupBy(fn ($line) => $line->room?->id ?? 'general');

        // Report-fixes, 2026-09-28 (Johan) — same fix as
        // RentalInventoryReportPdfService::generate(): a room explicitly
        // marked "nothing in this room" has no lines, so it never appeared
        // on the public page at all — indistinguishable from a room nobody
        // ever checked, exactly the distinction the mark exists to prove.
        $emptyRooms = $inventory->roomMarks
            ->filter(fn ($mark) => $mark->room && ! $linesByRoom->has($mark->room->id))
            ->sortBy(fn ($mark) => [$mark->room->sort_order ?? PHP_INT_MAX, $mark->room->id])
            ->pluck('room')
            ->unique('id');

        return view('rental-inventories.public.show', [
            'inventory' => $inventory,
            'linesByRoom' => $linesByRoom,
            'emptyRooms' => $emptyRooms,
            // §24 ruling — every buyer who has not yet recorded acceptance,
            // rendered as its own signing block; empty (and the block
            // hidden entirely) unless buyerAcceptanceOfferedFor() is true.
            'outstandingBuyers' => $inventory->outstandingBuyerAcceptances(),
        ]);
    }

    /**
     * POST /rental-inventory-report/{token}/buyer-acceptance — §24 ruling,
     * the buyer's own capture: on-screen (a canvas signature, same as every
     * other party's) or wet-ink (an uploaded photo/scan of a paper
     * signature) — Johan's own words, "on screen or wet-ink." No CoreX
     * login; the SAME token that opened the page is the only credential.
     * Never touches RentalInventory::assertEditable() — the completed
     * record is never reopened by this write.
     */
    public function storeBuyerAcceptance(Request $request, string $token): JsonResponse
    {
        $inventory = RentalInventory::findByPublicToken($token);
        if (! $inventory) {
            return response()->json(['message' => 'This link is no longer available.'], 404);
        }

        $validated = $request->validate([
            'buyer_contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'disposition' => ['required', 'string', 'in:' . implode(',', [
                RentalInventoryBuyerAcceptance::DISPOSITION_SIGNED,
                RentalInventoryBuyerAcceptance::DISPOSITION_WET_INK,
            ])],
            'signature_image' => ['nullable', 'string'],
            'wet_ink_file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,heic'],
        ]);

        // withoutGlobalScopes() — this is an unauthenticated public route
        // with no "current agency" to scope against, same as
        // RentalInventory::findByPublicToken() above. The explicit
        // agency_id match below is the real boundary: it stops the
        // buyer_contact_id parameter being used to probe a DIFFERENT
        // agency's contact record now that scoping is bypassed.
        $buyer = Contact::withoutGlobalScopes()->find($validated['buyer_contact_id']);
        if (! $buyer || (int) $buyer->agency_id !== (int) $inventory->agency_id) {
            return response()->json(['message' => 'Buyer not found.'], 404);
        }

        $attributes = [];
        if (! empty($validated['signature_image'])) {
            $attributes['party_signature_path'] = RentalInventoryBuyerAcceptance::storeCanvasImage($validated['signature_image'], $inventory->property_id);
        }
        if ($request->hasFile('wet_ink_file')) {
            $attributes['wet_ink_upload_path'] = RentalInventoryBuyerAcceptance::storeWetInkUpload($request->file('wet_ink_file'), $inventory->property_id);
        }

        try {
            $acceptance = RentalInventoryBuyerAcceptance::capture($inventory, $buyer, $validated['disposition'], $attributes);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($acceptance, 201);
    }
}
