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
    public function show(Request $request, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $inspection = RentalInspection::findByPublicToken($token);

        if (! $inspection) {
            // Deliberately the SAME response whether the token never
            // existed, has expired, or was revoked (regenerated/cleared) —
            // never distinguishing "wrong" from "expired" to an
            // unauthenticated caller, same principle as a login failure
            // never confirming which half of a credential pair was wrong.
            return $this->privateHeaders(response()->view('rental-inspections.public.unavailable'));
        }

        // Audit L1 — every relation is loaded scope-free: the token is the
        // authority here. A logged-in agent of ANOTHER agency opening a
        // forwarded link would otherwise get AgencyScope-filtered nulls
        // (property = null) and a 500.
        $unscoped = fn ($q) => $q->withoutGlobalScopes();
        $agencyOnly = fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class);
        $inspection->load([
            'property' => $unscoped,
            'lease' => $unscoped,
            'lease.tenants' => $unscoped,
            'lease.tenants.contact' => $unscoped,
            'previousInspection' => $unscoped,
            'createdBy' => $unscoped,
            'observations' => $unscoped,
            'observations.item' => $unscoped,
            'observations.item.room' => $unscoped,
            'observations.photos' => $unscoped,
            'signatures' => $unscoped,
            'signatures.partyContact' => $unscoped,
            'roomNotes' => $unscoped,
            // §45.5 (Build I-3) — preloaded scope-free for signatureSummaryRows(), and the inspector so the
            // report names who attended (falling back to the creator).
            'attendances' => $unscoped,
            'inspector' => $unscoped,
            // §45.3 (Build I-1) — room-level ("general") photos and every photo's own note. Only the
            // AGENCY scope is lifted (an unauthenticated caller has no agency context); SoftDeletes
            // stays on, so an archived photo — e.g. one removed as "wrong property" — never appears here.
            'photos' => $agencyOnly,
            'photos.note' => $agencyOnly,
            'observations.photos.note' => $agencyOnly,
        ]);

        $agencyId = $inspection->property?->agency_id;
        $conditionStates = collect(RentalInspectionSetting::conditionStatesFor($agencyId))->keyBy('key');

        $items = RentalInspectionItem::withoutGlobalScopes()->where('property_id', $inspection->property_id)
            ->where('is_retired', false)
            ->with(['room' => $unscoped])
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

        // §45.3 — a room's general condition photos (tagged to the room, no single item). A room with
        // ONLY such photos and/or a room note, but no graded item, must still appear on the report.
        $roomPhotos = $inspection->photos
            ->filter(fn ($p) => $p->property_room_id !== null && $p->rental_inspection_observation_id === null)
            ->groupBy('property_room_id');

        $extraRoomIds = $roomPhotos->keys()->merge($roomNotes->keys())
            ->filter(fn ($id) => $id !== null && ! $rows->has($id))
            ->unique()->values();
        $extraRooms = $extraRoomIds->isEmpty()
            ? collect()
            : \App\Models\PropertyRoom::withoutGlobalScopes()->whereIn('id', $extraRoomIds)->get()->keyBy('id');
        foreach ($extraRoomIds as $extraId) {
            // An empty row list is a real, renderable section (heading + photos + note) — see the view.
            $rows->put($extraId, collect());
        }

        // §45.5 — the attendance board is built from scope-aware queries; the one caller it cannot serve is a
        // logged-in user of ANOTHER agency opening a forwarded link (their AgencyScope would hide every row),
        // who simply gets the report without the attendance block rather than a wrong one.
        $viewer = auth()->user();
        $attendanceService = app(\App\Services\Rentals\RentalInspectionAttendanceService::class);
        $attendanceBoard = (! $viewer || (int) $viewer->effectiveAgencyId() === (int) $agencyId || $viewer->isOwnerRole())
            ? $attendanceService->board($inspection)
            : null;

        return $this->privateHeaders(response()->view('rental-inspections.public.show', [
            'inspection' => $inspection,
            'rows' => $rows,
            'roomNotes' => $roomNotes,
            'attendanceBoard' => $attendanceBoard,
            'attendanceService' => $attendanceService,
            'attendedAsLabels' => RentalInspectionSetting::attendedAsLabelsFor($agencyId),
            'roomPhotos' => $roomPhotos,
            'extraRooms' => $extraRooms,
            'photoNoteLabels' => collect(RentalInspectionSetting::photoNoteClassificationsFor($agencyId))->pluck('label', 'key'),
            'signatureRows' => $inspection->signatureSummaryRows(),
            'refusalReasonLabels' => collect(RentalInspectionSetting::refusalReasonPresetsFor($agencyId))->pluck('label', 'key'),
            'severityColors' => RentalInspectionSetting::SEVERITY_COLORS,
        ]));
    }

    /**
     * Audit L2 — the token lives in the URL: keep the page out of search
     * indexes and shared caches, and stop the token leaking in a Referer
     * header when a photo / signature link is opened.
     */
    private function privateHeaders(\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Audit M4 — signature / wet-ink files are on the private disk; this is
     * the token-authorised way the public page (and only it) may fetch one.
     * Same uniform 404 as the page itself for a bad/expired/revoked token,
     * and the file must belong to THAT inspection.
     */
    public function signatureFile(Request $request, string $token, int $signature, string $kind)
    {
        abort_unless(in_array($kind, ['signature', 'wet-ink'], true), 404);

        $inspection = RentalInspection::findByPublicToken($token);
        abort_if(! $inspection, 404);

        $row = \App\Models\RentalInspectionSignature::withoutGlobalScopes()
            ->where('id', $signature)
            ->where('rental_inspection_id', $inspection->id)
            ->whereNull('superseded_at')
            ->first();
        abort_if(! $row, 404);

        return $row->fileResponse($kind);
    }
}
