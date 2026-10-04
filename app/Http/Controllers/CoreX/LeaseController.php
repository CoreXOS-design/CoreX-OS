<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\RentalApplication;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTimelineService;
use App\Services\Rentals\RentalDocumentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/leases.md — full CRUD + list screen per BUILD_STANDARD §1a-§1d.
 * A lease takes a Property, adds Tenant(s), carries terms. Kept small
 * deliberately — see the spec for what is explicitly NOT built here
 * (deposit-held tracking, one-click renewal's UI, CPA notice logic).
 */
class LeaseController extends Controller
{
    use AuthorizesRentalRecordScope;
    use ExportsRentalList;

    /** §39, 2026-09-28 — "Expiring soon" summary tile window; no agency-configurable setting exists for this yet (see index()'s own note). */
    private const LEASE_EXPIRING_SOON_DAYS = 60;

    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /**
     * The Leases list screen. Search: property address, tenant name(s).
     * Sort: end_date (default, ascending — soonest to expire first), start_date,
     * status, property. Filter: status, type of date range, property, branch.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // AT-439 — own/branch/all "Showing:" control, same pattern as
        // RentalApplicationController::index(). $query below still uses
        // $request->get('scope') directly (Lease::scopeVisibleTo() clamps
        // it internally against the same ceiling) — $resolvedScope/
        // $scopeOptions here exist only to drive the toggle UI.
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'leases');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'end_date');
        $direction = $request->get('direction', 'asc');
        $allowedSorts = ['end_date', 'start_date', 'status', 'property'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'end_date';
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $showArchived = $request->boolean('archived');
        $query = $this->filteredLeasesQuery($request, $showArchived);

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'leases.property_id')
                ->orderBy('properties.title', $direction)
                ->select('leases.*');
        } else {
            $query->orderBy("leases.{$sort}", $direction);
        }

        $hasAnyLeases = Lease::query()->visibleTo($user, $request->get('scope'))->exists();

        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }

        $leases = $query->paginate($perPage)->withQueryString();

        // §39, 2026-09-28 — Johan: a summary tiles row, the same reused
        // FICA/rental-applications pattern as rental-inspections (§39
        // there). Status tiles are the real enum (Lease::STATUS_*), not
        // invented. Exception tile: "Expiring soon" — an active lease
        // whose end_date falls within the next LEASE_EXPIRING_SOON_DAYS
        // days. No agency-configurable renewal-reminder-window setting
        // exists on this model today (checked — nothing to reuse); adding
        // one is a real setting (Non-negotiable #10a: Setup Wizard entry,
        // saver, the works) and out of scope for a tiles row. A fixed
        // 60-day default is used instead, same "sensible fixed default,
        // no new setting" call as any other unconfigured threshold —
        // flagged here for Johan if he wants it made configurable later.
        $leaseTileBase = fn () => Lease::query()->visibleTo($user, $request->get('scope'));
        $tileCounts = [
            'total' => $leaseTileBase()->count(),
            'draft' => $leaseTileBase()->where('leases.status', Lease::STATUS_DRAFT)->count(),
            'active' => $leaseTileBase()->where('leases.status', Lease::STATUS_ACTIVE)->count(),
            'expired' => $leaseTileBase()->where('leases.status', Lease::STATUS_EXPIRED)->count(),
            'cancelled' => $leaseTileBase()->where('leases.status', Lease::STATUS_CANCELLED)->count(),
            'expiring_soon' => $leaseTileBase()
                ->where('leases.status', Lease::STATUS_ACTIVE)
                ->whereBetween('leases.end_date', [now(), now()->addDays(self::LEASE_EXPIRING_SOON_DAYS)])
                ->count(),
        ];

        return view('corex.leases.index', [
            'leases' => $leases,
            'sort' => $sort,
            'direction' => $direction,
            'hasAnyLeases' => $hasAnyLeases,
            'showArchived' => $showArchived,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filters' => $request->only(['q', 'status', 'property_id', 'branch_id', 'date_from', 'date_to', 'expiring_soon']),
            'tileCounts' => $tileCounts,
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
        ]);
    }

    /**
     * Shared scoped+filtered query, reused by index()/printList()/export()
     * so the three never drift on what "the current filtered list" means.
     * Returns an UNSORTED, UNPAGINATED builder — callers apply their own
     * ordering/pagination on top.
     */
    private function filteredLeasesQuery(Request $request, bool $onlyArchived = false)
    {
        $user = $request->user();

        $query = Lease::query()
            ->visibleTo($user, $request->get('scope'))
            ->with(['property', 'tenants.contact']);

        if ($onlyArchived) {
            $query->onlyTrashed();
        }

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', function ($p) use ($search) {
                    $p->searchAddress($search);
                })->orWhereHas('tenants.contact', function ($c) use ($search) {
                    $c->where(function ($cc) use ($search) {
                        $cc->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                });
            });
        }

        if ($status = $request->get('status')) {
            $query->where('leases.status', $status);
        }

        if ($propertyId = $request->get('property_id')) {
            $query->where('leases.property_id', $propertyId);
        }

        if ($branchId = $request->get('branch_id')) {
            $query->where('leases.branch_id', $branchId);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('leases.end_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('leases.end_date', '<=', $dateTo);
        }

        // §39 — the summary tiles' own "Expiring soon" exception tile.
        if ($request->boolean('expiring_soon')) {
            $query->where('leases.status', Lease::STATUS_ACTIVE)
                ->whereBetween('leases.end_date', [now(), now()->addDays(self::LEASE_EXPIRING_SOON_DAYS)]);
        }

        return $query;
    }

    /** Human-readable active-filter summary for the print-list header/export filename — shared shape across all four rental lists. */
    private function activeLeaseFiltersSummary(Request $request): array
    {
        $out = [];
        if ($q = $request->get('q')) {
            $out['Search'] = $q;
        }
        if ($status = $request->get('status')) {
            $out['Status'] = ucfirst($status);
        }
        if ($request->boolean('expiring_soon')) {
            $out['Expiring soon'] = 'Yes';
        }
        if ($df = $request->get('date_from')) {
            $out['End date from'] = $df;
        }
        if ($dt = $request->get('date_to')) {
            $out['End date to'] = $dt;
        }
        if ($request->boolean('archived')) {
            $out['Archived'] = 'Yes';
        }
        $out['Scope'] = ucfirst(\App\Services\PermissionService::clampScope(
            $request->get('scope'),
            \App\Services\PermissionService::getDataScope($request->user(), 'leases')
        ));

        return $out;
    }

    /** req — print the current filtered list, same scoping as index(), filters shown in the header. */
    public function printList(Request $request): View
    {
        $leases = $this->filteredLeasesQuery($request, $request->boolean('archived'))
            ->orderBy('leases.end_date')
            ->get();

        return view('corex.leases.print-list', [
            'leases' => $leases,
            'printFilters' => $this->activeLeaseFiltersSummary($request),
        ]);
    }

    /** req — export the current filtered list as xlsx/csv, same scoping as index(). */
    public function export(Request $request)
    {
        $leases = $this->filteredLeasesQuery($request, $request->boolean('archived'))
            ->orderBy('leases.end_date')
            ->get();

        $headers = ['Property', 'Tenant(s)', 'Status', 'Start', 'End', 'Rent'];
        $rows = $leases->map(fn (Lease $lease) => [
            $lease->property?->buildDisplayAddress() ?? 'Unknown property',
            $lease->tenantNames(),
            ucfirst($lease->status),
            $lease->start_date?->format('Y-m-d') ?? '',
            $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : ''),
            number_format((float) $lease->rental_amount, 2),
        ]);

        $filename = 'leases-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $rows)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $rows);
    }

    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $rentalApplication = $request->get('rental_application_id')
            ? RentalApplication::findOrFail($request->get('rental_application_id'))
            : null;

        return view('corex.leases.create', [
            'property' => $property,
            'rentalApplication' => $rentalApplication,
            // .ai/specs/rental-property-tab.md §5, Part 4 — same agency-editable
            // list as the property screen's Lease Type select; one source of
            // truth for both, replacing this form's own hardcoded array.
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'exists:properties,id'],
            'rental_amount' => ['required', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
            'rental_application_id' => ['nullable', 'exists:rental_applications,id'],
            'tenant_contact_ids' => ['required', 'array', 'min:1'],
            'tenant_contact_ids.*' => ['exists:contacts,id'],
            'activate_immediately' => ['nullable', 'boolean'],
        ]);

        $property = Property::findOrFail($validated['property_id']);
        $user = $request->user();

        $lease = Lease::create([
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => $validated['rental_amount'],
            'deposit_amount' => $validated['deposit_amount'] ?? null,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_month_to_month' => (bool) ($validated['is_month_to_month'] ?? false),
            'lease_type' => $validated['lease_type'] ?? null,
            'source' => !empty($validated['rental_application_id']) ? 'rental_application' : 'manual',
            'rental_application_id' => $validated['rental_application_id'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        foreach ($validated['tenant_contact_ids'] as $index => $contactId) {
            LeaseTenant::create([
                'lease_id' => $lease->id,
                'contact_id' => $contactId,
                'is_primary' => $index === 0,
            ]);
        }

        if ($request->boolean('activate_immediately')) {
            app(LeaseActivationService::class)->activate($lease);
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease created.');
    }

    /**
     * .ai/specs/leases.md §12 — the lease detail becomes the tenancy file.
     * Johan, 4 Oct 2026: the one screen that answers "what has happened on
     * this tenancy, what needs to happen next, and is there anything open
     * right now" without checking four other screens.
     */
    public function show(Request $request, Lease $lease): View
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $lease->load(['property', 'tenants.contact', 'escalations.createdByUser', 'previousLease', 'renewedLease']);

        $hubService = app(LeaseHubService::class);
        $timelineService = app(LeaseTimelineService::class);

        $filters = $request->only(['q', 'type', 'date_from', 'date_to']);
        $types = array_filter((array) $request->get('type', []));
        $page = (array) $timelineService->paginatedFor(
            $lease,
            $request->get('q'),
            $types,
            $request->get('date_from'),
            $request->get('date_to'),
            50,
            (int) $request->get('page', 1)
        );

        return view('corex.leases.show', [
            'lease' => $lease,
            // .ai/specs/rental-property-tab.md §5, Part 4 — same list as create().
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
            // Johan, 2026-09-22 — agency-configurable, hidden by default.
            'showLeaseType' => \App\Models\LeaseSetting::showLeaseTypeFieldFor($lease->agency_id),
            'lifecycle' => $hubService->lifecycle($lease),
            'nextStep' => $hubService->nextStep($lease),
            'openItemCounts' => $hubService->openItemCounts($lease),
            'landlords' => $lease->landlordContacts(),
            'timelineEntries' => $page['entries'],
            'timelineTotal' => $page['total'],
            'timelineTypes' => LeaseTimelineService::TYPES,
            'timelineFilters' => $filters,
        ]);
    }

    /**
     * AT-440 — JSON tenancy-log endpoint, same scope guard and same
     * LeaseTimelineService the screen's own panel uses. Andre's mobile app
     * calls this same endpoint for a tenant/agent mobile tenancy view
     * (leases.md §12.4).
     */
    public function tenancyLog(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $timelineService = app(LeaseTimelineService::class);
        $types = array_filter((array) $request->get('type', []));
        $page = $timelineService->paginatedFor(
            $lease,
            $request->get('q'),
            $types,
            $request->get('date_from'),
            $request->get('date_to'),
            (int) $request->get('per_page', 50),
            (int) $request->get('page', 1)
        );

        return response()->json([
            'data' => $page['entries']->values(),
            'total' => $page['total'],
        ]);
    }

    /**
     * .ai/specs/leases.md §12.2 — "Print tenancy report" action in the
     * header. Same scope guard as show().
     */
    public function tenancyReportPdf(Lease $lease, RentalDocumentPdfService $service)
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $pdf = $service->leaseTenancyReportPdf($lease);

        return request()->boolean('dl')
            ? $pdf->download($service->leaseTenancyReportFilename($lease))
            : $pdf->stream($service->leaseTenancyReportFilename($lease));
    }

    /**
     * leases.md — full CRUD floor: deposit/end date/lease type editable
     * after creation. Rent amount and start date are deliberately NOT
     * editable here — see the view's own comment.
     *
     * Two footguns fixed here, both caught by walking the real form rather
     * than by a passing test: (1) 'after:start_date' referenced a request
     * field this form never submits (start_date isn't part of an edit) —
     * replaced with a literal comparison against the lease's own stored
     * start_date. (2) `$validated['x'] ?? $lease->x` treats an explicit
     * null/absent-checkbox the same as "field not submitted", so clearing
     * the deposit or unchecking month-to-month would have silently kept
     * the old value — replaced with array_key_exists()/has() checks so an
     * explicit clear actually clears.
     */
    public function update(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'end_date' => ['nullable', 'date', 'after:' . $lease->start_date->format('Y-m-d')],
            'is_month_to_month' => ['nullable', 'boolean'],
            'lease_type' => ['nullable', 'string', 'max:40'],
        ]);

        $lease->update([
            'deposit_amount' => array_key_exists('deposit_amount', $validated) ? $validated['deposit_amount'] : $lease->deposit_amount,
            'end_date' => array_key_exists('end_date', $validated) ? $validated['end_date'] : $lease->end_date,
            'is_month_to_month' => $request->boolean('is_month_to_month'),
            'lease_type' => $validated['lease_type'] ?? $lease->lease_type,
        ]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease updated.');
    }

    public function activate(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        try {
            app(LeaseActivationService::class)->activate($lease);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease activated.');
    }

    public function cancel(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        $lease->update([
            'status' => Lease::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $request->user()->id,
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Lease cancelled.');
    }

    /**
     * .ai/specs/leases.md §3.4 — escalation as a RATE, computed and stored
     * at entry time, never re-derived later.
     */
    public function escalate(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $validated = $request->validate([
            'effective_date' => ['required', 'date'],
            'new_rental_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $previousAmount = (float) $lease->rental_amount;
        $newAmount = (float) $validated['new_rental_amount'];

        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => $validated['effective_date'],
            'previous_rental_amount' => $previousAmount,
            'new_rental_amount' => $newAmount,
            'escalation_rate_percent' => LeaseEscalation::computeRatePercent($previousAmount, $newAmount),
            'note' => $validated['note'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        $lease->update(['rental_amount' => $newAmount]);

        return redirect()->route('corex.leases.show', $lease)->with('success', 'Escalation recorded.');
    }

    public function destroy(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        if (!$lease->isDeletable()) {
            return back()->withErrors(['lease' => 'This lease has escalation history and cannot be deleted — cancel it instead.']);
        }

        $lease->delete();

        return redirect()->route('corex.leases.index')->with('success', 'Lease archived.');
    }

    public function restore(Request $request, int $lease): RedirectResponse
    {
        $leaseModel = Lease::withTrashed()->findOrFail($lease);
        $this->guardRentalRecordScope($leaseModel, 'leases', $leaseModel->branch_id);

        $leaseModel->restore();

        return redirect()->route('corex.leases.show', $leaseModel)->with('success', 'Lease restored.');
    }
}
