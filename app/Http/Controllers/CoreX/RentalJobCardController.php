<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Concerns\SearchesQualifyingRentalProperties;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardListQuery;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalJobCardVatService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14 (AT-442), rebuilt 2026-10-05 after
 * Johan rejected the original design on QA1. ONE screen for create and
 * edit — create() renders the SAME view as show(), with rentalJobCard
 * null and a draft context (property/lease/fault report/work order/
 * pre-seeded task descriptions) for the page's own client-side builder;
 * nothing is persisted until store(), which creates the card and every
 * task/line it was given in one transaction. store() NEVER creates a
 * RentalWorkOrder any more — it only links to one that already exists
 * (RentalJobCardService::createStandalone()).
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
    use SearchesQualifyingRentalProperties;

    /**
     * .ai/specs/rental-work-orders.md §14.23 — search, sort, filters, tiles, scope
     * counts and paging all come from RentalJobCardListQuery, the same object
     * printList() uses, so the screen and the printout can never disagree.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $list = $this->listQuery($request);

        $propertyId = $request->get('property_id');

        return view('corex.rental-job-cards.index', [
            'jobCards' => $list->paginate(max(1, (int) $request->get('page', 1)))->withQueryString(),
            'list' => $list,
            'sort' => $list->sort(),
            'direction' => $list->direction(),
            'hasAny' => $list->hasAny(),
            'showArchived' => $request->boolean('archived'),
            'crews' => RentalCrew::query()->active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['q', 'status', 'rental_crew_id', 'property_id', 'date_from', 'date_to', 'overdue']),
            'filteredProperty' => $propertyId ? Property::find($propertyId) : null,
            'tileCounts' => $list->tileCounts(),
            'resolvedScope' => $list->resolvedScope(),
            'scopeOptions' => RentalJobCardListQuery::scopeOptionsFor($user),
            'scopeCounts' => $list->scopeCounts(),
        ]);
    }

    /** One place the request becomes a list query — index() and printList() both call it. */
    private function listQuery(Request $request): RentalJobCardListQuery
    {
        $user = $request->user();

        // §14.22 — the filter's dates are parsed/validated like the schedule's, and
        // "to" is the END of that day (a bare date compared against a datetime
        // column meant midnight, so cards due later on the "to" day were dropped).
        $filterErrors = [];
        $filterTz = $this->agencyTimezone($user->effectiveAgencyId() ? \App\Models\Agency::find($user->effectiveAgencyId()) : null);
        $dateFrom = $this->parseDateInput($request->get('date_from'), 'From date', $filterTz, $filterErrors, 'date_from');
        $dateTo = $this->parseDateInput($request->get('date_to'), 'To date', $filterTz, $filterErrors, 'date_to');
        if ($filterErrors) {
            throw ValidationException::withMessages($filterErrors);
        }

        return new RentalJobCardListQuery($user, $request->get('scope'), [
            'q' => $request->get('q'),
            'status' => $request->get('status'),
            'overdue' => $request->boolean('overdue'),
            'rental_crew_id' => $request->get('rental_crew_id'),
            'property_id' => $request->get('property_id'),
            'from' => $dateFrom,
            'to' => $dateTo?->copy()->setTimezone($filterTz)->endOfDay()->setTimezone(config('app.timezone') ?: $filterTz),
            'archived' => $request->boolean('archived'),
            'sort' => $request->get('sort'),
            'direction' => $request->get('direction'),
        ]);
    }

    /**
     * The LIST screen's own property-filter picker — only properties that
     * actually have a job card visible to this user, never every rental
     * property. Distinct from the create screen's own inline property
     * `<select>` (rental-job-cards/create.blade.php), which deliberately
     * keeps offering every rental property, unchanged.
     */
    public function searchProperties(Request $request): JsonResponse
    {
        return $this->searchQualifyingRentalProperties($request, RentalJobCard::class);
    }

    /**
     * REBUILD, 2026-10-05 — ONE screen. Renders the exact same view
     * show() does, with jobCard null and a draft context: property, a
     * searchable picker when nothing names one; lease (tenant+landlord);
     * the fault report / work order / inspection follow-up observations
     * this draft started from, each turned into a pre-seeded (but still
     * editable, nothing saved yet) task description. Reachable from a
     * property, a lease, an already-approved fault report, an existing
     * work order, or inspection follow-up observations — any combination,
     * or none at all ("No source — created directly").
     *
     * AT-442 fix #1 (class of bug) — lease_id WINS and derives the
     * property, same guard as RentalWorkOrderController::create() — a
     * property_id and a lease_id resolved independently is exactly how a
     * job card can end up pointed at another property's lease. Resolved
     * only inside the user's own scope.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        $lease = $request->get('lease_id') ? Lease::query()->visibleTo($user, null)->find($request->get('lease_id')) : null;
        $faultReport = $request->get('fault_report_id') ? RentalFaultReport::findOrFail($request->get('fault_report_id')) : null;
        $workOrder = $request->get('rental_work_order_id')
            ? \App\Models\RentalWorkOrder::query()->visibleTo($user, null)->find($request->get('rental_work_order_id'))
            : null;
        $inspection = $request->get('rental_inspection_id')
            ? \App\Models\RentalInspection::query()->visibleTo($user, null)->find($request->get('rental_inspection_id'))
            : null;
        $lease = $lease ?? $faultReport?->lease ?? $workOrder?->lease ?? $inspection?->lease;

        $property = $lease
            ? $lease->property
            : ($faultReport?->property ?? $workOrder?->property ?? $inspection?->property
                ?? ($request->get('property_id') ? Property::visibleTo($user)->find($request->get('property_id')) : null));

        $observationIds = array_filter((array) $request->get('observation_ids', []));
        $observations = $observationIds
            ? \App\Models\RentalInspectionObservation::query()->whereIn('id', $observationIds)->with(['item.room'])->get()
            : collect();

        $draftTasks = [];
        if ($faultReport) {
            $draftTasks[] = $faultReport->title;
        }
        if ($workOrder && !$faultReport) {
            $draftTasks[] = $workOrder->title;
        }
        foreach ($observations as $observation) {
            $room = $observation->item?->room?->label ?? 'General';
            $item = $observation->item?->label ?? 'Unknown item';
            $desc = "{$room} — {$item} (" . ucfirst(str_replace('_', ' ', $observation->condition)) . ')';
            if ($observation->notes) {
                $desc .= ' — ' . $observation->notes;
            }
            $draftTasks[] = $desc;
        }

        // A job card is always created within the AUTHENTICATED user's own
        // agency, regardless of which property ends up picked (the
        // searchable picker resolves client-side, so a fresh /create with
        // no prefill has no $property yet at all) — resolve pricesOn/VAT
        // off the user's own agency, not $property?->agency, so the draft
        // screen's catalogue/VAT controls are correct from the first paint
        // instead of silently empty until a property happens to be chosen.
        $agency = $property?->agency ?? $user->agency;

        return view('corex.rental-job-cards.show', [
            'jobCard' => null,
            'property' => $property,
            'lease' => $lease,
            'faultReport' => $faultReport,
            'workOrder' => $workOrder,
            'draftTasks' => $draftTasks,
            'catalogueItems' => RentalCatalogueItem::query()->active()->with(['catalogueItemType', 'catalogueUnit'])->orderBy('sort_order')->get(),
            'catalogueItemTypes' => $agency ? RentalCatalogueItemType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect(),
            'catalogueUnits' => RentalCatalogueUnit::query()->active()->orderBy('sort_order')->get(),
            'pricesOn' => $agency ? RentalWorkOrderSetting::capturePricesOnJobCardsFor($agency->id) : true,
            'vatTypes' => $agency?->vat_registered
                ? RentalVatType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get()
                : collect(),
            'vatRegistered' => (bool) $agency?->vat_registered,
        ]);
    }

    public function store(Request $request, RentalJobCardService $service): RedirectResponse
    {
        $validated = $request->validate([
            'property_id' => ['required_without_all:fault_report_id,rental_work_order_id', 'nullable', 'exists:properties,id'],
            'lease_id' => ['nullable', 'exists:leases,id'],
            'rental_work_order_id' => ['nullable', 'exists:rental_work_orders,id'],
            'fault_report_id' => ['nullable', 'exists:rental_fault_reports,id'],
            'title' => ['required', 'string', 'max:191'],
            'access_notes' => ['nullable', 'string'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.description' => ['nullable', 'string', 'max:500'],
            'tasks.*.lines' => ['nullable', 'array'],
            'tasks.*.lines.*' => ['array'],
            'general_lines' => ['nullable', 'array'],
            'general_lines.*' => ['array'],
        ]);

        $user = $request->user();

        // Each line (both under a task and in the General group) is
        // validated against the SAME shape storeLine() already uses below
        // — a flat Rule::exists() can't be expressed against a
        // variable-depth nested array path in the top-level rule set above.
        foreach ($validated['tasks'] ?? [] as $i => $taskData) {
            $validated['tasks'][$i]['lines'] = array_map(
                fn (array $line) => $this->validatedLineAttributes($line),
                $taskData['lines'] ?? [],
            );
        }
        $validated['general_lines'] = array_map(
            fn (array $line) => $this->validatedLineAttributes($line),
            $validated['general_lines'] ?? [],
        );

        // AT-442 fix #1 (class of bug) — "the lease wins" (Johan's ruling):
        // never trust a property_id posted alongside a lease_id without
        // checking they agree. See the identical guard in
        // RentalWorkOrderController::store().
        if (!empty($validated['lease_id']) && !empty($validated['property_id'])) {
            $lease = Lease::findOrFail($validated['lease_id']);
            if ($lease->property_id !== (int) $validated['property_id']) {
                $validated['property_id'] = $lease->property_id;
            }
        }

        try {
            $jobCard = $service->createStandalone($validated, $user);
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
            'property', 'lease.tenants.contact', 'crew.members', 'assignedUser',
            'tasks.lines.catalogueItem', 'tasks.lines.vatType',
            'lines.catalogueItem', 'lines.vatType', // includes General (task-less) lines
            'rentalFaultReport',
            'photos.uploadedBy',
            // §15 (AT-447) — "From inspection <type> <date>" back-link,
            // reached through the linked work order (a job card has no
            // inspection FK of its own — rentals-rebuild.md §15.3).
            'workOrder.photos', 'workOrder.reportedInspectionObservation.inspection',
            'updates.createdByUser', 'createdByUser',
            'workerSignedOffByUser', 'agentSignedOffByUser', 'tenantConfirmedByUser',
        ]);

        // §14.21 — every revision of the quote sent from this card (current
        // first), which one is current, and whether the card has changed
        // since it was sent.
        $quoteRevisions = $rentalJobCard->quoteRevisions()->get();
        $currentQuote = $quoteRevisions->first(fn ($q) => $q->superseded_at === null);

        return view('corex.rental-job-cards.show', [
            'jobCard' => $rentalJobCard,
            'quoteRevisions' => $quoteRevisions,
            'currentQuote' => $currentQuote,
            'quoteChanged' => $rentalJobCard->quoteChangedSinceSent($currentQuote),
            'generalLines' => $rentalJobCard->generalLines()->with(['catalogueItem', 'vatType'])->get(),
            'pricesOn' => RentalWorkOrderSetting::capturePricesOnJobCardsFor($rentalJobCard->agency_id),
            // AT-442 fix #6 — same figure RentalWorkOrderController::show() already surfaces.
            'noApprovalThreshold' => RentalWorkOrderSetting::thresholdFor($rentalJobCard->property),
            'crews' => RentalCrew::query()->active()->orderBy('name')->get(),
            'catalogueItems' => RentalCatalogueItem::query()->active()->with(['catalogueItemType', 'catalogueUnit'])->orderBy('sort_order')->get(),
            'catalogueItemTypes' => RentalCatalogueItemType::active()->where('agency_id', $rentalJobCard->agency_id)->orderBy('sort_order')->get(),
            'catalogueUnits' => RentalCatalogueUnit::query()->active()->orderBy('sort_order')->get(),
            'archivedTasks' => $rentalJobCard->tasks()->onlyTrashed()->get(),
            'archivedLines' => $rentalJobCard->lines()->onlyTrashed()->get(),
            // Agency VAT set-up (2026-10-05) — the totals block and per-line
            // VAT type picker. vatTypes empty when the agency isn't VAT
            // registered: the view renders no selector at all in that case.
            'vat' => app(RentalJobCardVatService::class)->breakdown($rentalJobCard),
            'vatTypes' => $rentalJobCard->agency?->vat_registered
                ? RentalVatType::active()->where('agency_id', $rentalJobCard->agency_id)->orderBy('sort_order')->get()
                : collect(),
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

    /** 2026-10-05 — assigns a RentalCrew, never a User (Johan's ruling). Only ACTIVE crews of this job card's own agency may be newly picked. */
    public function assignCrew(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate([
            // Rule::exists() queries the table directly, NOT through Eloquent
            // — SoftDeletes scoping is never automatic here. An archived
            // (soft-deleted) crew still has is_active=true (archive() only
            // sets deleted_at), so whereNull('deleted_at') is the only thing
            // actually excluding it from being newly picked.
            'rental_crew_id' => ['required', Rule::exists('rental_crews', 'id')->where('agency_id', $rentalJobCard->agency_id)->where('is_active', true)->whereNull('deleted_at')],
        ]);
        $crew = RentalCrew::findOrFail($validated['rental_crew_id']);

        try {
            $rentalJobCard->assignCrew($crew, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Crew assigned.');
    }

    public function schedule(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        // §14.22 — RentalJobCard::schedule() takes DATE OBJECTS; the form posts
        // strings. Parse + validate both here, in the agency timezone, so a bad
        // value is a clear validation message and never a TypeError/500.
        $request->validate([
            'scheduled_at' => ['nullable', 'string', 'max:40'],
            'due_at' => ['nullable', 'string', 'max:40'],
        ]);
        $tz = $this->agencyTimezone($rentalJobCard->agency);
        $errors = [];
        $scheduledAt = $this->parseDateInput($request->input('scheduled_at'), 'Scheduled', $tz, $errors, 'scheduled_at');
        $dueAt = $this->parseDateInput($request->input('due_at'), 'Due', $tz, $errors, 'due_at');
        if (! $errors && $scheduledAt && $dueAt && $dueAt->lt($scheduledAt)) {
            $errors['due_at'] = 'Due can’t be earlier than Scheduled.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $rentalJobCard->schedule($scheduledAt, $dueAt, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Job card scheduled.');
    }

    /** The timezone this card's agency works in (same source as the outreach window; one place to change when a per-agency column lands). */
    private function agencyTimezone(?\App\Models\Agency $agency): string
    {
        return $agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    }

    /**
     * §14.22 — one posted string → one Carbon, or null when left blank (blank
     * clears the date). Only real calendar values in a known format are
     * accepted (what <input type=datetime-local>/<input type=date> post, with
     * or without seconds); relative words, overflow dates ("2026-02-31") and
     * junk are refused. Read in the agency timezone, returned in the
     * application timezone — what every datetime column here stores. A refusal
     * is added to $errors[$errorKey] and null is returned.
     *
     * @param array<string,string> $errors
     */
    private function parseDateInput(mixed $raw, string $label, string $tz, array &$errors, string $errorKey): ?Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $raw, $tz);
            } catch (\Throwable) {
                continue;
            }
            $problems = Carbon::getLastErrors();
            if ($parsed !== false && (! $problems || (($problems['warning_count'] ?? 0) + ($problems['error_count'] ?? 0)) === 0)) {
                if (! str_contains($format, 'H')) {
                    $parsed = $parsed->startOfDay();
                }
                if ($parsed->year >= 2000 && $parsed->year <= 2100) {
                    return $parsed->setTimezone(config('app.timezone') ?: $tz);
                }
                break;
            }
        }

        $errors[$errorKey] = "{$label} isn’t a valid date and time (use the date picker).";

        return null;
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

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        $validated = $request->validate(['description' => ['required', 'string', 'max:500']]);
        try {
            $task = $service->addTask($rentalJobCard, $validated['description'], $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        // jc_focus_task — the show screen brings the changed task into view (same idea as jc_focus_line).
        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task added.')->with('jc_focus_task', $task->id);
    }

    /** "add / rename / reorder / archive tasks" — rename, new in the 2026-10-05 rebuild. */
    public function updateTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        $validated = $request->validate(['description' => ['required', 'string', 'max:500']]);
        try {
            $service->renameTask($rentalJobCard, $task, $validated['description'], $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task renamed.')->with('jc_focus_task', $task->id);
    }

    public function toggleTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        try {
            $service->toggleTask($rentalJobCard, $task, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task updated.')->with('jc_focus_task', $task->id);
    }

    public function reorderTasks(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): JsonResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['ordered_ids' => ['required', 'array'], 'ordered_ids.*' => ['integer']]);
        try {
            $service->reorderTasks($rentalJobCard, $validated['ordered_ids'], $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Reordered.']);
    }

    public function destroyTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardTask $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        try {
            $service->archiveTask($rentalJobCard, $task, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task archived.');
    }

    public function restoreTask(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, int $task): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        try {
            $service->restoreTask($rentalJobCard, $task, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Task restored.')->with('jc_focus_task', $task);
    }

    public function storeLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        $validated = $request->validate([
            'rental_job_card_task_id' => ['nullable', Rule::exists('rental_job_card_tasks', 'id')->where('rental_job_card_id', $rentalJobCard->id)],
            'rental_catalogue_item_id' => ['nullable', 'exists:rental_catalogue_items,id'],
            'type' => ['nullable', 'in:labour,part'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'rental_vat_type_id' => ['nullable', Rule::exists('rental_vat_types', 'id')->where('agency_id', $rentalJobCard->agency_id)],
            'custom_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (empty($validated['rental_catalogue_item_id']) && empty($validated['description'])) {
            return back()->withErrors(['rental_job_card' => 'Pick a catalogue item or type a description.']);
        }

        $task = !empty($validated['rental_job_card_task_id']) ? RentalJobCardTask::find($validated['rental_job_card_task_id']) : null;
        try {
            $line = $service->addLine($rentalJobCard, $validated, $request->user(), $task);
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        // jc_focus_line — the show screen scrolls the changed line into view
        // after restoring the panel's scroll position (see show.blade.php).
        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line added.')->with('jc_focus_line', $line->id);
    }

    public function updateLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardLine $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        // A closed card's lines never change (completed figures are frozen,
        // cancelled is a dead record). The edit control isn't rendered there
        // either; this is the server-side guard behind the hidden link
        // (RentalJobCardService enforces the same rule a second time).
        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        // quantity is `sometimes` — an agency with prices off renders no
        // quantity/unit/price/VAT fields at all, so none are posted.
        $validated = $request->validate([
            'rental_catalogue_item_id' => ['nullable', Rule::exists('rental_catalogue_items', 'id')->where('agency_id', $rentalJobCard->agency_id)],
            'type' => ['nullable', 'in:labour,part'],
            'description' => ['required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'rental_vat_type_id' => ['nullable', Rule::exists('rental_vat_types', 'id')->where('agency_id', $rentalJobCard->agency_id)],
            'custom_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $service->updateLine($rentalJobCard, $line, $validated, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line updated.')->with('jc_focus_line', $line->id);
    }

    public function destroyLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, RentalJobCardLine $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        try {
            $service->archiveLine($rentalJobCard, $line, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line archived.');
    }

    public function restoreLine(Request $request, RentalJobCardService $service, RentalJobCard $rentalJobCard, int $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($refusal = $this->refuseIfClosed($rentalJobCard)) {
            return $refusal;
        }

        try {
            $service->restoreLine($rentalJobCard, $line, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['rental_job_card' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line restored.')->with('jc_focus_line', $line);
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

        $revision = $rentalJobCard->quoteRevisions()->max('revision');

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
            ->with('success', $revision > 1 ? "Revised quote (Rev {$revision}) sent to the owner — it replaces the earlier one." : 'Quote sent to the owner.');
    }

    /**
     * §14.21 — view any revision's stored PDF (current or superseded). Same
     * private-disk, re-check-ownership discipline as
     * RentalWorkOrderQuoteController::download(); the quote must belong to
     * THIS card, and the card passes the usual scope guard.
     */
    public function downloadQuote(Request $request, RentalJobCard $rentalJobCard, int $quote)
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $quoteModel = $rentalJobCard->quoteRevisions()->withTrashed()->findOrFail($quote);
        abort_unless($quoteModel->document_storage_path, 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($quoteModel->document_storage_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $quoteModel->document_storage_path,
            'Quote Rev ' . $quoteModel->revision . ' - Job card ' . $rentalJobCard->id . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function workerSignOff(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $validated = $request->validate(['worker_sign_off_name' => ['nullable', 'string', 'max:191']]);

        try {
            $rentalJobCard->workerSignOff($request->user(), $validated['worker_sign_off_name'] ?? null);
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

    /** req #7 — print the (filtered, sorted) list itself. Same RentalJobCardListQuery as index(), so the same scoping and filters; all matching rows, no paging. */
    public function printList(Request $request): View
    {
        $list = $this->listQuery($request);

        return view('corex.rental-job-cards.print-list', ['jobCards' => $list->all(), 'list' => $list]);
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

    /**
     * §14.21 — a closed (completed/cancelled) card's lines and tasks never
     * change. Checked FIRST in every line/task action so the refusal is a
     * clear message with no validation noise and no change at all; the
     * service enforces the same rule again (assertContentEditable()).
     */
    private function refuseIfClosed(RentalJobCard $rentalJobCard): ?RedirectResponse
    {
        if (! $rentalJobCard->isClosed()) {
            return null;
        }

        return back()->withErrors(['rental_job_card' => 'This job card is closed — its lines and tasks can no longer be changed.']);
    }

    /**
     * store()'s one-screen Save posts a nested tasks[]/general_lines[]
     * structure before any job card (and so any agency_id to scope a
     * Rule::exists() against) exists — validated here per line, against
     * the AUTHENTICATED USER's own agency, same shape storeLine() already
     * validates once a card exists. Unknown/malformed line entries are
     * dropped silently (RentalJobCardService::lineHasContent() then skips
     * anything left with neither a catalogue item nor a description) —
     * never a 500 on a stray empty row the client-side builder emitted.
     */
    private function validatedLineAttributes(array $line): array
    {
        $agencyId = auth()->user()->agency_id;

        $validator = \Illuminate\Support\Facades\Validator::make($line, [
            'rental_catalogue_item_id' => ['nullable', Rule::exists('rental_catalogue_items', 'id')->where('agency_id', $agencyId)],
            'type' => ['nullable', 'in:labour,part'],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:30'],
            'quantity' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'rental_vat_type_id' => ['nullable', Rule::exists('rental_vat_types', 'id')->where('agency_id', $agencyId)],
            'custom_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $validator->valid();
    }
}
