<?php

namespace App\Http\Controllers;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
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
            'property', 'lease.tenants.contact', 'previousInspection', 'createdBy',
            'observations.item.room', 'observations.photos',
            'signatures.partyContact',
            'roomNotes',
        ]);

        $agencyId = $inspection->property?->agency_id;
        $conditionStates = collect(RentalInspectionSetting::conditionStatesFor($agencyId))->keyBy('key');

        $items = RentalInspectionItem::where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        // AT-433, §20.22 — a photo-anchor row (CONDITION_PENDING) is not an
        // assessment; excluded before the latest-per-item pick, the same
        // rule RentalInspectionReportPdfService::generate() already applies.
        $observationsByItem = $inspection->observations->groupBy('rental_inspection_item_id');
        $currentByItem = $observationsByItem
            ->map(fn ($group) => $group->where('condition', '!=', RentalInspectionObservation::CONDITION_PENDING)->sortByDesc('created_at')->first())
            ->filter();

        $rows = $items
            ->map(function (RentalInspectionItem $item) use ($currentByItem, $observationsByItem, $conditionStates) {
                $observation = $currentByItem->get($item->id);
                if (! $observation) {
                    return null;
                }

                // Every photo tagged to THIS item across the whole
                // inspection, not just the latest observation row — a photo
                // uploaded before the final condition was recorded anchors
                // to an EARLIER observation row (AT-433, §20.22), and the
                // live recording screen's own itemPhotosForInspection()
                // already flatMaps across every row for exactly this reason.
                $photos = ($observationsByItem->get($item->id) ?? collect())
                    ->flatMap(fn ($o) => $o->photos);

                $state = $conditionStates->get($observation->condition);

                return (object) [
                    'item' => $item,
                    'room' => $item->room,
                    'observation' => $observation,
                    'photos' => $photos,
                    'condition_label' => $state['label'] ?? ucfirst(str_replace('_', ' ', $observation->condition)),
                    'severity' => $state['severity'] ?? 'red',
                ];
            })
            ->filter()
            ->groupBy(fn ($row) => $row->room?->id ?? 'general');

        $roomNotes = $inspection->roomNotes
            ->groupBy('property_room_id')
            ->map(fn ($notes) => $notes->sortByDesc('created_at')->first());

        return view('rental-inspections.public.show', [
            'inspection' => $inspection,
            'rows' => $rows,
            'roomNotes' => $roomNotes,
            'signatureRows' => $inspection->signatureSummaryRows(),
            'refusalReasonLabels' => collect(RentalInspectionSetting::refusalReasonPresetsFor($agencyId))->pluck('label', 'key'),
            'severityColors' => RentalInspectionSetting::SEVERITY_COLORS,
        ]);
    }
}
