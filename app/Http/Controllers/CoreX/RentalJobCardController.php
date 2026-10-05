<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442) — full CRUD + list screen
 * per BUILD_STANDARD §1a-§1d. A job card BUILDS its work order (§14) —
 * store() here always creates (or links to, from a fault report) the
 * underlying rental_work_orders row with assignment_type='internal'.
 *
 * guardRentalRecordScope() (AT-439's AuthorizesRentalRecordScope trait)
 * is called at the top of every action below that receives an existing
 * bound record — index()/create()/store() are the only exceptions (no
 * existing record to guard; index() already filters via
 * RentalJobCard::scopeVisibleTo()). Branch resolves via the record's
 * PROPERTY's branch_id, matching RentalFaultReport/RentalWorkOrder's own
 * scopeVisibleTo() — never the job card's own unused branch_id column.
 */
class RentalJobCardController extends Controller
{
    use AuthorizesRentalRecordScope;

    /**
     * Search: property address, tenant name, crew member, title.
     * Sort: due_at (default, ascending — what's due soonest is looked at
     * first), scheduled_at, status, property.
     * Filter: crew member, status, date range (minimum per §1b).
     * Status tiles (pstat-v2 style): Total, Draft, Quoted, Scheduled,
     * In progress, Overdue, Completed.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Req #7 — "Own | Branch | All switch (default widest permitted)."
        // Same pattern as RentalApplicationController::index() — a null
        // requested scope resolves to the user's own ceiling, not a
        // hardcoded 'own'; the toggle only ever offers what that ceiling
        // actually permits.
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_job_cards');
        $resolvedScope = \App\Services\PermissionService::clampScope($request->get('scope'), $maxScope);
        $scopeOptions = match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        $sort = $request->get('sort', 'due_at');
        $direction = $request->get('direction', 'asc');
        $allowedSorts = ['due_at', 'scheduled_at', 'status', 'property'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'due_at';
        }
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $base = fn () => RentalJobCard::query()->visibleTo($user, $request->get('scope'));

        $query = $base()->with(['property', 'lease.tenants.contact', 'assignedUser']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('rental_job_cards.title', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('rental_job_cards.status', $status);
        }
        if ($crewId = $request->get('assigned_user_id')) {
            $query->where('rental_job_cards.assigned_user_id', $crewId);
        }
        if ($dateFrom = $request->get('date_from')) {
            $query->where('rental_job_cards.due_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('rental_job_cards.due_at', '<=', $dateTo);
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        $showArchived = $request->boolean('archived');
        if ($showArchived) {
            $query->onlyTrashed();
        }

        if ($sort === 'property') {
            $query->join('properties', 'properties.id', '=', 'rental_job_cards.property_id')
                ->orderBy('properties.title', $direction)
                ->select('rental_job_cards.*');
        } else {
            $query->orderBy("rental_job_cards.{$sort}", $direction);
        }

        $hasAny = $base()->exists();
        $jobCards = $query->paginate(25)->withQueryString();

        $tileCounts = [
            'total' => $base()->count(),
            'draft' => $base()->where('rental_job_cards.status', RentalJobCard::STATUS_DRAFT)->count(),
            'quoted' => $base()->where('rental_job_cards.status', RentalJobCard::STATUS_QUOTED)->count(),
            'scheduled' => $base()->where('rental_job_cards.status', RentalJobCard::STATUS_SCHEDULED)->count(),
            'in_progress' => $base()->where('rental_job_cards.status', RentalJobCard::STATUS_IN_PROGRESS)->count(),
            'overdue' => $base()->overdue()->count(),
            'completed' => $base()->where('rental_job_cards.status', RentalJobCard::STATUS_COMPLETED)->count(),
        ];

        $crew = User::query()->orderBy('name')->get(['id', 'name']);

        return view('corex.rental-job-cards.index', [
            'jobCards' => $jobCards,
            'sort' => $sort,
            'direction' => $direction,
            'hasAny' => $hasAny,
            'showArchived' => $showArchived,
            'crew' => $crew,
            'filters' => $request->only(['q', 'status', 'assigned_user_id', 'date_from', 'date_to', 'overdue']),
            'tileCounts' => $tileCounts,
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
        ]);
    }

    /** req #1/#4 — reachable from a property, a lease, or (pre-filled) from an already-approved fault report. */
    public function create(Request $request): View
    {
        $property = $request->get('property_id') ? Property::findOrFail($request->get('property_id')) : null;
        $lease = $request->get('lease_id') ? Lease::findOrFail($request->get('lease_id')) : null;
        $faultReport = $request->get('fault_report_id') ? RentalFaultReport::findOrFail($request->get('fault_report_id')) : null;

        return view('corex.rental-job-cards.create', [
            'property' => $property ?? $faultReport?->property,
            'lease' => $lease ?? $faultReport?->lease,
            'faultReport' => $faultReport,
        ]);
    }

    public function store(Request $request, RentalJobCardService $service): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required_without:fault_report_id', 'nullable', 'exists:properties,id'],
            'lease_id' => ['nullable', 'exists:leases,id'],
            'rental_inspection_item_id' => ['nullable', 'exists:rental_inspection_items,id'],
            'fault_report_id' => ['nullable', 'exists:rental_fault_reports,id'],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'access_notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        try {
            if (!empty($validated['fault_report_id'])) {
                $faultReport = RentalFaultReport::findOrFail($validated['fault_report_id']);
                $jobCard = $service->createFromFaultReport($faultReport, $validated, $user);
            } else {
                $property = Property::findOrFail($validated['property_id']);
                $validated['description'] = $validated['description'] ?? $validated['title'];
                $jobCard = $service->createForProperty($property, $validated, $user);
            }
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $jobCard)->with('success', 'Job card created.');
    }

    public function show(Request $request, RentalJobCard $rentalJobCard): View
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $rentalJobCard->syncStatusFromWorkOrder();
        $rentalJobCard->load([
            'property', 'lease.tenants.contact', 'assignedUser', 'tasks', 'lines.catalogueItem',
            // §15 (AT-447) — "From inspection <type> <date>" back-link,
            // reached through the linked work order (a job card has no
            // inspection FK of its own — rentals-rebuild.md §15.3).
            'workOrder.photos', 'workOrder.reportedInspectionObservation.inspection',
            'updates.createdByUser', 'createdByUser',
            'workerSignedOffByUser', 'agentSignedOffByUser', 'tenantConfirmedByUser',
        ]);

        return view('corex.rental-job-cards.show', [
            'jobCard' => $rentalJobCard,
            'pricesOn' => RentalWorkOrderSetting::capturePricesOnJobCardsFor($rentalJobCard->agency_id),
            'crew' => User::query()->orderBy('name')->get(['id', 'name']),
            'catalogueItems' => RentalCatalogueItem::query()->active()->orderBy('sort_order')->get(),
            'archivedTasks' => $rentalJobCard->tasks()->onlyTrashed()->get(),
            'archivedLines' => $rentalJobCard->lines()->onlyTrashed()->get(),
        ]);
    }

    public function update(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        abort_unless($rentalJobCard->status === RentalJobCard::STATUS_DRAFT, 409, 'This job card has moved on and can no longer be edited here.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'access_notes' => ['nullable', 'string'],
        ]);

        $rentalJobCard->update($validated);

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Job card updated.');
    }

    public function assignCrew(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['assigned_user_id' => ['required', 'exists:users,id']]);
        $crewMember = User::findOrFail($validated['assigned_user_id']);

        try {
            $rentalJobCard->assignCrew($crewMember, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Crew member assigned.');
    }

    public function schedule(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
        ]);

        try {
            $rentalJobCard->schedule(
                $validated['scheduled_at'] ?? null,
                $validated['due_at'] ?? null,
                $request->user(),
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Job card scheduled.');
    }

    public function start(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $rentalJobCard->start($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Marked in progress.');
    }

    public function storeTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['description' => ['required', 'string', 'max:500']]);
        $service->addTask($rentalJobCard, $validated['description'], $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task added.');
    }

    public function toggleTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $service->toggleTask($rentalJobCard, $task, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task updated.');
    }

    public function reorderTasks(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['ordered_ids' => ['required', 'array'], 'ordered_ids.*' => ['integer']]);
        $service->reorderTasks($rentalJobCard, $validated['ordered_ids'], $request->user());

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroyTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $service->archiveTask($rentalJobCard, $task, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task archived.');
    }

    public function restoreTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, int $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $service->restoreTask($rentalJobCard, $task, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task restored.');
    }

    public function storeLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            'rental_catalogue_item_id' => ['nullable', 'exists:rental_catalogue_items,id'],
            'type' => ['nullable', 'in:labour,part'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($validated['rental_catalogue_item_id']) && empty($validated['description'])) {
            return back()->withErrors(['rental_job_card' => 'Pick a catalogue item or type a description.']);
        }

        $service->addLine($rentalJobCard, $validated, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line added.');
    }

    public function updateLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardLine $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $service->updateLine($rentalJobCard, $line, $validated, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line updated.');
    }

    public function destroyLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardLine $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $service->archiveLine($rentalJobCard, $line, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line archived.');
    }

    public function restoreLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, int $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $service->restoreLine($rentalJobCard, $line, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line restored.');
    }

    /** req #5 — "Send to owner as quote." */
    public function sendQuote(Request $request, RentalJobCardService $service, RentalDocumentPdfService $pdfService, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $service->sendToOwnerAsQuote($rentalJobCard, $request->user(), $pdfService);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Quote sent to the owner.');
    }

    public function workerSignOff(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $rentalJobCard->workerSignOff($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Worker sign-off recorded.');
    }

    public function agentSignOff(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $rentalJobCard->agentSignOff($request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Agent sign-off recorded.');
    }

    /** Recorded by the agent for now (tenant login is AT-445) — the brief's own wording. */
    public function tenantConfirm(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['tenant_confirmation_note' => ['nullable', 'string', 'max:1000']]);

        $rentalJobCard->tenantConfirm($validated['tenant_confirmation_note'] ?? null, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Tenant confirmation recorded.');
    }

    public function complete(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $service->complete($rentalJobCard, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Job card completed.');
    }

    public function cancel(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $rentalJobCard->cancel($request->user(), $validated['cancel_reason']);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Job card cancelled.');
    }

    public function destroy(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if (!$rentalJobCard->isDeletable()) {
            return back()->withErrors(['rental_job_card' => 'This job card has tasks, lines or history logged and cannot be deleted — cancel it instead.']);
        }

        $rentalJobCard->archive($request->user());

        return redirect()->route('corex.rental-job-cards.index')->with('success', 'Job card archived.');
    }

    public function restore(Request $request, int $rentalJobCard): RedirectResponse
    {
        $jobCard = RentalJobCard::withTrashed()->findOrFail($rentalJobCard);
        $this->guardRentalRecordScope($jobCard, 'rental_job_cards', $jobCard->property?->branch_id);

        $jobCard->restoreRecord($request->user());

        return redirect()->route('corex.rental-job-cards.show', $jobCard)->with('success', 'Job card restored.');
    }

    /** req #6 — printable job card PDF. */
    public function print(RentalJobCard $rentalJobCard, RentalDocumentPdfService $service)
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $pdf = $service->jobCardPrintPdf($rentalJobCard);

        return request()->boolean('dl')
            ? $pdf->download($service->jobCardFilename($rentalJobCard))
            : $pdf->stream($service->jobCardFilename($rentalJobCard));
    }

    /** req #7 — print the (filtered) list itself as a simple PDF-friendly page. No per-record guard — same list-level query-layer scoping as index(). */
    public function printList(Request $request): View
    {
        $user = $request->user();
        $jobCards = RentalJobCard::query()->visibleTo($user, $request->get('scope'))
            ->with(['property', 'lease.tenants.contact', 'assignedUser'])
            ->orderBy('due_at')
            ->get();

        return view('corex.rental-job-cards.print-list', ['jobCards' => $jobCards]);
    }

    /** req #10 — same photo pipeline as the linked work order, reused, not duplicated. */
    public function storePhoto(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'photo_type' => ['required', 'in:reported,in_progress,completed'],
            'client_idempotency_key' => 'nullable|uuid',
        ]);

        $photo = $service->storePhoto($rentalJobCard, $request->file('photo'), $validated['photo_type'], $request->user(), $validated['client_idempotency_key'] ?? null);

        return response()->json($photo, 201);
    }
}
