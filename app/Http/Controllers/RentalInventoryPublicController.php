<?php

namespace App\Http\Controllers;

use App\Models\RentalInventory;
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
            'signatures.partyContact',
            'roomMarks.room',
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
        ]);
    }
}
