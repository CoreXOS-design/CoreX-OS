<?php

namespace App\Http\Controllers;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Johan, 2026-09-23, approved — the public destination for a completed
 * inspection report's QR code / link: "it shows the inspection and its
 * photos and NOTHING else — no other properties, no other tenancies, no
 * agency internals, no navigation into the rest of CoreX." Treat that
 * boundary as the security requirement it is.
 *
 * Deliberately a TOP-LEVEL controller (App\Http\Controllers, not
 * App\Http\Controllers\CoreX) — same reasoning as
 * RentalApplicationSigningController: this is reached by someone with no
 * CoreX session at all, so it must never sit behind CoreX's own auth
 * middleware group, and living outside that namespace keeps it visibly
 * separate from every authenticated controller.
 *
 * findByPublicToken() (RentalInspection's own method) is the ONLY lookup
 * used here — withoutGlobalScopes(), token-scoped, expiry-checked in the
 * query itself. No route-model-binding, no agency context, nothing this
 * controller could accidentally widen.
 */
class RentalInspectionPublicController extends Controller
{
    public function show(Request $request, string $token): View
    {
        $inspection = RentalInspection::findByPublicToken($token);

        if (! $inspection) {
            // Deliberately the SAME response whether the token never
            // existed, has expired, or was revoked (regenerated/cleared) —
            // never distinguishing "wrong" from "expired" to an
            // unauthenticated caller, same principle as a login failure
            // never confirming which half of a credential pair was wrong.
            return view('rental-inspections.public.unavailable');
        }

        $inspection->load([
            'property', 'lease.tenants.contact', 'previousInspection',
            'observations.item.room', 'observations.photos',
            'signatures.partyContact',
        ]);

        $items = RentalInspectionItem::where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        $currentByItem = $inspection->observations->groupBy('rental_inspection_item_id')
            ->map(fn ($group) => $group->sortByDesc('created_at')->first());

        $rows = $items
            ->map(function (RentalInspectionItem $item) use ($currentByItem) {
                $observation = $currentByItem->get($item->id);
                if (! $observation) {
                    return null;
                }

                return (object) ['item' => $item, 'room' => $item->room, 'observation' => $observation];
            })
            ->filter()
            ->groupBy(fn ($row) => $row->room?->id ?? 'general');

        return view('rental-inspections.public.show', [
            'inspection' => $inspection,
            'rows' => $rows,
        ]);
    }
}
