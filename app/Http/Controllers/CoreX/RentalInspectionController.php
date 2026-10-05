<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Services\Rentals\RentalInspectionFormPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspections.md §5 — the tracked/searchable list of every
 * inspection. Recording observations/photos/signatures HAPPENS on the
 * property's Rental Images tab (§1/§4, a separate controller); this
 * controller is the agency-level Read + administrative-lifecycle surface —
 * search, sort, filter, create (a picker that hands off to the same
 * RentalInspection::start() the tab's own AJAX flow calls — no second
 * implementation), cancel, archive, restore.
 */
class RentalInspectionController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * 2026-09-20 — the list screen had no way to start an inspection at all;
     * an agent had to already know to go to a property's Rental Images tab.
     * This is an ADDITIONAL entry point, not a replacement — the tab's own
     * "Start In/Out-Inspection" buttons keep working exactly as they did.
     * Only properties with an active lease are offered: RentalInspection::
     * start() hard-requires one, so listing properties without one would be
     * a guaranteed dead end.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $properties = Property::where('listing_type', 'rental')
            ->whereIn('id', Lease::where('status', Lease::STATUS_ACTIVE)->pluck('property_id'))
            ->orderBy('title')
            ->limit(500)
            ->get();

        // AT-439 Part 3 — pre-select from a lease_id/property_id query param:
        // the Lease Hub's "Start in-inspection" next-step action
        // (LeaseHubService::nextStep()) passes lease_id; the Command
        // Centre's "Start inspection" action passes property_id. Both are
        // resolved against the SAME own/branch/agency ceiling index() uses
        // (Lease::visibleTo()) rather than a raw findOrFail() — a request
        // for a lease/property outside the user's scope silently falls back
        // to "no pre-selection" instead of a 403/500 (BUILD_STANDARD §3,
        // absorb rather than break on a param nobody is forced to supply).
        $selectedPropertyId = null;
        if ($leaseId = $request->get('lease_id')) {
            $selectedPropertyId = Lease::query()->visibleTo($user, null)->find($leaseId)?->property_id;
        }
        if (!$selectedPropertyId && ($propertyId = $request->get('property_id'))) {
            $selectedPropertyId = $properties->firstWhere('id', (int) $propertyId)?->id;
        }

        return view('corex.rental-inspections.create', [
            'properties' => $properties,
            'selectedPropertyId' => $selectedPropertyId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        $property = Property::findOrFail($validated['property_id']);

        try {
            $inspection = RentalInspection::start($property, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return back()->withInput()->withErrors(['rental_inspection' => $e->getMessage()]);
        }

        // Recording (observations/photos/signatures) only happens on the
        // property's Inspections tab (§1/§4, renamed 2026-09-22 — label-
        // level only, same tab, same routes underneath) — this screen's own
        // show() page is read-only, so land the agent where they can
        // actually start working, not on a dead end they'd have to navigate
        // away from immediately.
        return redirect()->route('corex.properties.show', ['property' => $inspection->property_id, 'tab' => 'inspections'])
            ->with('success', ucfirst($validated['type']) . '-inspection started.');
    }

    /**
     * Search: property address, tenant name, agent name (creator). Sort:
     * scheduled_for (default, most-recent-first), property address, status,
     * type. Filter: status, type, date range, has-unresolved-discrepancy.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // AT-439 — own/branch/all "Showing:" control, same pattern as
        // RentalApplicationController::index()/LeaseController::index().
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_inspections');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'scheduled_for');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['scheduled_for', 'property', 'status', 'type'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'scheduled_for';
        }
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $archived = $request->boolean('archived');
        $scheduled = $request->boolean('scheduled');

        $query = $this->filteredInspectionsQuery($request, $archived);

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_inspections.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_inspections.*');
        } else {
            $query->orderBy("rental_inspections.{$sort}", $direction);
        }

        $hasAnyInspections = RentalInspection::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->exists();

        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $inspections = $query->paginate($perPage)->withQueryString();

        // §39, 2026-09-28 — Johan: a summary tiles row, same reused pattern
        // as FICA/rental-applications (compliance/fica/index.blade.php,
        // corex.rental-applications.index) — every tile's count comes from
        // the SAME own/branch/agency-scoped, archived-aware base query the
        // list itself uses, cloned before any OTHER ad-hoc filter (search,
        // status, date range) is applied, so a tile count never drifts from
        // what "All" on this exact same scope would show. Johan's own named
        // list, verbatim: Draft, In progress, Awaiting signature, Completed,
        // Unresolved discrepancies, Scheduled (upcoming).
        $tileBase = fn () => RentalInspection::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'total' => $tileBase()->count(),
            'draft' => $tileBase()->where('rental_inspections.status', RentalInspection::STATUS_DRAFT)->count(),
            'in_progress' => $tileBase()->where('rental_inspections.status', RentalInspection::STATUS_IN_PROGRESS)->count(),
            'awaiting_signature' => $tileBase()->where('rental_inspections.status', RentalInspection::STATUS_AWAITING_SIGNATURE)->count(),
            'completed' => $tileBase()->where('rental_inspections.status', RentalInspection::STATUS_COMPLETED)->count(),
            'unresolved_discrepancies' => $tileBase()->withUnresolvedDiscrepancy()->count(),
            'scheduled' => $tileBase()->where('rental_inspections.scheduled_for', '>=', now())->count(),
        ];

        return view('corex.rental-inspections.index', [
            'inspections' => $inspections,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyInspections' => $hasAnyInspections,
            'archived' => $archived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'tileCounts' => $tileCounts,
            'scheduled' => $scheduled,
            'filters' => $request->only(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy']),
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
        ]);
    }

    /**
     * Shared scoped+filtered query, reused by index()/printList()/export().
     * Returns an UNSORTED, UNPAGINATED builder.
     */
    private function filteredInspectionsQuery(Request $request, bool $archived = false)
    {
        $user = $request->user();

        $query = RentalInspection::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'createdBy', 'archivedBy']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                    $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                })->orWhereHas('createdBy', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($status = $request->get('status')) {
            $query->where('rental_inspections.status', $status);
        }

        if ($type = $request->get('type')) {
            $query->where('rental_inspections.type', $type);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_inspections.scheduled_for', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_inspections.scheduled_for', '<=', $dateTo);
        }

        if ($request->boolean('has_unresolved_discrepancy')) {
            $query->withUnresolvedDiscrepancy();
        }

        if ($request->boolean('scheduled')) {
            $query->where('rental_inspections.scheduled_for', '>=', now());
        }

        return $query;
    }

    private function activeInspectionFiltersSummary(Request $request): array
    {
        $out = [];
        if ($q = $request->get('q')) {
            $out['Search'] = $q;
        }
        if ($status = $request->get('status')) {
            $out['Status'] = ucfirst(str_replace('_', ' ', $status));
        }
        if ($type = $request->get('type')) {
            $out['Type'] = ucfirst($type);
        }
        if ($df = $request->get('date_from')) {
            $out['Scheduled from'] = $df;
        }
        if ($dt = $request->get('date_to')) {
            $out['Scheduled to'] = $dt;
        }
        if ($request->boolean('has_unresolved_discrepancy')) {
            $out['Unresolved discrepancy'] = 'Yes';
        }
        if ($request->boolean('scheduled')) {
            $out['Scheduled (upcoming)'] = 'Yes';
        }
        if ($request->boolean('archived')) {
            $out['Archived'] = 'Yes';
        }
        $out['Scope'] = ucfirst(\App\Services\PermissionService::clampScope(
            $request->get('scope'),
            \App\Services\PermissionService::getDataScope($request->user(), 'rental_inspections')
        ));

        return $out;
    }

    /** req — print the current filtered list, same scoping as index(), filters shown in the header. */
    public function printList(Request $request): View
    {
        $inspections = $this->filteredInspectionsQuery($request, $request->boolean('archived'))
            ->orderBy('rental_inspections.scheduled_for', 'desc')
            ->get();

        return view('corex.rental-inspections.print-list', [
            'inspections' => $inspections,
            'printFilters' => $this->activeInspectionFiltersSummary($request),
        ]);
    }

    /** req — export the current filtered list as xlsx/csv, same scoping as index(). */
    public function export(Request $request)
    {
        $inspections = $this->filteredInspectionsQuery($request, $request->boolean('archived'))
            ->orderBy('rental_inspections.scheduled_for', 'desc')
            ->get();

        $headers = ['Property', 'Tenant(s)', 'Type', 'Status', 'Scheduled'];
        $rows = $inspections->map(fn (RentalInspection $i) => [
            $i->property?->buildDisplayAddress() ?? 'Unknown property',
            $i->lease?->tenantNames() ?? '',
            ucfirst(str_replace('_', '-', $i->type)),
            ucfirst(str_replace('_', ' ', $i->status)),
            $i->scheduled_for?->format('Y-m-d') ?? '',
        ]);

        $filename = 'rental-inspections-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $rows)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $rows);
    }

    public function show(Request $request, RentalInspection $rentalInspection): View
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->load([
            'property', 'lease.tenants.contact',
            'observations.item', 'observations.observedByUser', 'observations.observedByContact', 'observations.photos',
            'discrepancies.observations', 'discrepancies.resolvedBy', 'discrepancies.acceptedObservation',
            'signatures.partyContact', 'signatures.recordedByUser', 'signatures.supersededBy', 'createdBy', 'cancelledBy',
            // 2026-09-23 — the chain. previousInspection loaded one level
            // deep with its own observations so the comparison row below
            // needs no per-item query; nextInChain so the screen can hide
            // "Next inspection" once one already exists (the unique index
            // means there can only ever be at most one).
            'previousInspection.observations.item',
            'nextInChain',
        ]);

        return view('corex.rental-inspections.show', [
            'inspection' => $rentalInspection,
            // §15.5/§15.8 — refusal_reason_preset stores a KEY; this maps it
            // to the agency's own current label for display. A key an
            // agency has since removed/renamed still shows the key itself
            // (never blank) via the blade's own fallback.
            'refusalReasonPresets' => \App\Models\RentalInspectionSetting::refusalReasonPresetsFor($rentalInspection->agency_id),
            // Johan's ruling, 2026-09-23 — §2/§3 of the approved proposal:
            // the predecessor-vs-current row set, and each item's full run
            // for the on-demand history popover. Null when this is the
            // first inspection in its chain (§6 — must render gracefully).
            'comparisonRows' => $this->buildComparisonRows($rentalInspection),
        ]);
    }

    /**
     * Johan's ruling, 2026-09-23 — §2 (predecessor left/read-only, current
     * right/editable — this screen is read-only on BOTH sides, so "editable"
     * here just means "this inspection's own recorded value") and §3
     * (row shows the immediate predecessor's value; the full run — "Good,
     * Good, Damaged" — is available on demand, not cluttering the row).
     *
     * Grouped by room (items are property-scoped, not inspection-scoped —
     * §20.15.4's own alignment-is-automatic reasoning applies here
     * identically). No query per item: $rentalInspection->previousInspection
     * is already eager-loaded with its own observations by show() above, so
     * every lookup here filters an already-loaded collection in memory.
     * historyFor() does issue one query per link per item beyond the
     * predecessor (walks previousInspection() further back) — acceptable
     * for a read-only detail page opened one inspection at a time, not the
     * tab's own high-frequency recording surface.
     */
    private function buildComparisonRows(RentalInspection $rentalInspection): ?\Illuminate\Support\Collection
    {
        if (! $rentalInspection->previousInspection) {
            return null;
        }

        $items = RentalInspectionItem::where('property_id', $rentalInspection->property_id)
            ->where('is_retired', false)
            ->with('room')
            ->get();

        $currentByItem = $rentalInspection->observations->groupBy('rental_inspection_item_id')
            ->map(fn ($group) => $group->sortByDesc('created_at')->first());
        $predecessorByItem = $rentalInspection->previousInspection->observations->groupBy('rental_inspection_item_id')
            ->map(fn ($group) => $group->sortByDesc('created_at')->first());

        return $items
            ->map(function (RentalInspectionItem $item) use ($rentalInspection, $currentByItem, $predecessorByItem) {
                $current = $currentByItem->get($item->id);
                $predecessor = $predecessorByItem->get($item->id);
                if (! $current && ! $predecessor) {
                    return null; // nothing to show on EITHER side — same screen-space convention the tab already follows.
                }
                $history = $rentalInspection->previousInspection->historyFor($item);

                return (object) [
                    'item' => $item,
                    'room' => $item->room,
                    'current' => $current,
                    'predecessor' => $predecessor,
                    'history' => $history, // every link BEFORE this one; current's own value is the run's next/latest entry.
                ];
            })
            ->filter()
            ->groupBy(fn ($row) => $row->room?->id ?? 'general');
    }

    /**
     * GET /corex/rental-inspections/{rentalInspection}/form — the
     * printable tick-box form. Generates (or reuses the current version of,
     * if nothing about the room/item/condition shape has changed since it
     * was last generated) the PDF, then streams it. Same query-layer
     * scoping as show() — route-model-binding + the global AgencyScope; a
     * user who cannot open this inspection cannot download its form
     * either, by construction, since both resolve the identical bound
     * model the identical way.
     */
    public function form(Request $request, RentalInspection $rentalInspection, RentalInspectionFormPdfService $service)
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->loadMissing(['property', 'lease.tenants.contact', 'createdBy']);

        $form = $service->generate($rentalInspection, $request->user());

        abort_unless(Storage::disk('local')->exists($form->pdf_storage_path), 404);

        return Storage::disk('local')->download($form->pdf_storage_path, $service->filenameFor($form));
    }

    /**
     * Johan, 2026-09-23, approved — the COMPLETED inspection report: no
     * photos (they live behind the public link, printed as a QR + URL
     * instead), predecessor-vs-current comparison, signatures. A
     * completely different document from form() above, which is the
     * BLANK OMR capture form generated BEFORE an inspection happens —
     * same DomPDF machinery, unrelated purpose, never confused with each
     * other in either direction.
     */
    public function report(Request $request, RentalInspection $rentalInspection, \App\Services\Rentals\RentalInspectionReportPdfService $service)
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->loadMissing([
            'property', 'lease.tenants.contact', 'previousInspection', 'createdBy',
            'observations.item.room', 'observations.item', 'signatures.partyContact',
        ]);

        // Audit M3 — a GET must never change state. The PDF carries the QR /
        // link only when a live public link ALREADY exists; creating one is
        // the explicit POST generatePublicLink() action (permission-gated).
        // The report service omits the QR block when there is no token.

        $pdf = $service->generate($rentalInspection);

        return $pdf->download($service->filenameFor($rentalInspection));
    }

    /**
     * GET /corex/rental-inspections/{inspection}/print-for-signature —
     * conductor brief 2026-09-29. Same report, plus blank signature blocks
     * for every outstanding party — the agent prints this and hands/sends
     * it to whoever still needs to sign on paper. Mirrors report() exactly,
     * including the same "ensure a live public link before printing a QR"
     * step, so the same PDF can also stand in as the completed record once
     * every party has signed (nobody needs to distinguish the two by URL).
     */
    public function printForSignature(Request $request, RentalInspection $rentalInspection, \App\Services\Rentals\RentalInspectionReportPdfService $service)
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->loadMissing([
            'property', 'lease.tenants.contact', 'previousInspection', 'createdBy',
            'observations.item.room', 'observations.item', 'signatures.partyContact',
        ]);

        if (! $rentalInspection->publicLinkIsValid()) {
            $rentalInspection->generatePublicLink();
        }

        $pdf = $service->generateForSignature($rentalInspection);

        return $pdf->download($service->filenameForSignatureFor($rentalInspection));
    }

    /**
     * Johan's ruling, 2026-09-23 — "Next inspection" from any inspection:
     * the deliberate action that records the chain (RentalInspection::
     * startNext(), see its own docblock). Lands the agent on the new
     * inspection's own show() page — the read-only agency-level screen,
     * deliberately NOT the property tab's live recording surface — where
     * §2 of the approved proposal's predecessor/current comparison renders.
     */
    public function next(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', [RentalInspection::TYPE_OUT, RentalInspection::TYPE_AD_HOC])],
        ]);

        try {
            $next = RentalInspection::startNext($rentalInspection, $validated['type'], $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_inspection' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inspections.show', $next)
            ->with('success', ucfirst($validated['type']) . '-inspection started, compared against this one.');
    }

    /**
     * Johan, 2026-09-23, approved — "signed, expiring, read-only... it
     * must work for someone with NO CoreX login... revocable." Generates
     * (or regenerates — see RentalInspection::generatePublicLink()'s own
     * docblock) the token and shows the agent the resulting link.
     */
    public function generatePublicLink(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        // Audit L2/M3 — no live link for a cancelled or archived inspection.
        if ($rentalInspection->status === RentalInspection::STATUS_CANCELLED || $rentalInspection->trashed()) {
            return redirect()->route('corex.rental-inspections.show', $rentalInspection)
                ->withErrors(['rental_inspection' => 'A public link cannot be created for a cancelled inspection.']);
        }

        $rentalInspection->generatePublicLink();

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)
            ->with('success', 'Public link generated — any previous link for this inspection has stopped working.');
    }

    public function revokePublicLink(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->revokePublicLink();

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)
            ->with('success', 'Public link revoked.');
    }

    public function cancel(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $rentalInspection->cancel($request->user(), $validated['cancel_reason']);
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            return redirect()->route('corex.rental-inspections.show', $rentalInspection)
                ->withErrors(['rental_inspection' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inspections.show', $rentalInspection)->with('success', 'Inspection cancelled.');
    }

    /**
     * 2026-09-20 — a real QA1 walk found no supported way to archive an
     * inspection once it had any recorded observations: this used to refuse
     * with "cancel it instead", but cancel() only flips status without
     * hiding the record, a dead end for an agent who started one on the
     * wrong property. delete() here is already a SOFT delete (softDeletes()
     * column) with restore() already existing — archiving never destroys
     * the evidence, it only hides it from the working list, exactly as
     * every other entity's archive/restore floor already works.
     */
    public function destroy(Request $request, RentalInspection $rentalInspection): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $rentalInspection->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $rentalInspection->delete();

        return redirect()->route('corex.rental-inspections.index')->with('success', 'Inspection archived.');
    }

    public function restore(Request $request, int $rentalInspection): RedirectResponse
    {
        $inspection = RentalInspection::withTrashed()->findOrFail($rentalInspection);
        $this->guardRentalRecordScope($inspection, 'rental_inspections', $inspection->property?->branch_id);

        $inspection->restore();
        $inspection->forceFill(['archived_by_user_id' => null])->save();

        return redirect()->route('corex.rental-inspections.show', $inspection)->with('success', 'Inspection restored.');
    }

    /**
     * Audit M4 — serve a signature image / wet-ink upload from the private
     * disk. Route-bound inspection is already agency- and own/branch-scoped
     * (RentalInspection::resolveRouteBinding); the file must belong to it.
     */
    public function signatureFile(Request $request, RentalInspection $rentalInspection, \App\Models\RentalInspectionSignature $signature, string $kind)
    {
        abort_unless(in_array($kind, ['signature', 'wet-ink'], true), 404);
        abort_unless((int) $signature->rental_inspection_id === (int) $rentalInspection->id, 404);

        return $signature->fileResponse($kind);
    }
}
