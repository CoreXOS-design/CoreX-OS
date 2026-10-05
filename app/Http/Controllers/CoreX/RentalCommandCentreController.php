<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RentalCommandCentreUserPreference;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-441 — .ai/specs/rental-command-centre.md. Read-only cross-entity
 * dashboard; every action button navigates into the OWNING screen's own
 * permission-gated action (leases/fault-reports/work-orders/inspections)
 * — this controller never creates, edits, or archives anything itself.
 */
class RentalCommandCentreController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public function index(Request $request, RentalCommandCentreService $service): View
    {
        $data = $this->buildViewData($request, $service);

        return view('corex.rentals.command-centre.index', $data);
    }

    public function print(Request $request, RentalCommandCentreService $service): View
    {
        $data = $this->buildViewData($request, $service, forPrint: true);

        return view('corex.rentals.command-centre.print', $data);
    }

    /**
     * .ai/specs/rental-command-centre.md §10 — the needs-action queue's
     * own collapse/expand state, remembered per user, server-side. Same
     * shape as RentalInspectionRecordingController::updateScreenPreference().
     */
    public function updatePreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Round 6 (2026-10-05) — collapsed_queue_groups added for the
            // needs-action queue's per-group collapse state (Johan). Its
            // value is an array of group keys, not a boolean, so it's
            // handled separately below rather than forcing every key
            // through $request->boolean().
            'preference_key' => ['required', 'string', 'in:queue_collapsed,collapsed_queue_groups'],
            'value' => ['required'],
        ]);

        $key = $validated['preference_key'];
        $value = $key === 'collapsed_queue_groups'
            ? array_values(array_unique(array_map('strval', (array) $request->input('value'))))
            : $request->boolean('value');

        RentalCommandCentreUserPreference::setFor($request->user()->id, $key, $value);

        return response()->json(['ok' => true]);
    }

    /**
     * 2026-10-05 fix round — resolves the needs-action queue's sort/
     * group-by choice for THIS request: an explicit query-string value
     * wins and is persisted (write-through, same "remembered per user" as
     * queue_collapsed, just via the normal GET instead of a second async
     * call, since changing either already requires a server round trip to
     * re-group/re-sort); otherwise falls back to the user's last saved
     * choice, or the documented default.
     */
    private function resolveQueuePreference(Request $request, User $user, array $prefState, string $key, array $validOptions, string $default): string
    {
        $requested = $request->get($key);

        if ($requested !== null) {
            $value = in_array($requested, $validOptions, true) ? $requested : $default;
            RentalCommandCentreUserPreference::setFor($user->id, $key, $value);

            return $value;
        }

        $saved = $prefState[$key] ?? $default;

        return in_array($saved, $validOptions, true) ? $saved : $default;
    }

    private function buildViewData(Request $request, RentalCommandCentreService $service, bool $forPrint = false): array
    {
        $user = $request->user();
        $scope = $service->resolveScope($user, $request->get('scope'));
        $scopeOptions = $service->scopeOptionsFor($user);

        $tileCounts = $service->tileCounts($user, $scope);

        $tile = $request->get('tile');
        if ($tile && !array_key_exists($tile, RentalCommandCentreService::TILES)) {
            $tile = null;
        }

        $filters = [
            'q' => $request->get('q'),
            'status' => $request->get('status'),
            'agent_id' => $request->get('agent_id'),
            'branch_id' => $request->get('branch_id'),
            'date_from' => $request->get('date_from'),
            'date_to' => $request->get('date_to'),
            'tile' => $tile,
        ];

        // Default per the brief: lease end ascending, soonest first —
        // never "address" (which put vacant units, with no lease at all,
        // first under plain alphabetical ordering).
        $sort = $request->get('sort', 'lease_end');
        $direction = $request->get('direction', 'asc');

        $tableQuery = $service->tableQuery($user, $scope, $filters);
        $service->applySort($tableQuery, $sort, $direction);

        $queueGroupBy = 'none';
        $queueSort = 'urgency';
        $queuePropertyId = null;
        $queueDateFrom = null;
        $queueDateTo = null;
        $queuePropertyOptions = collect();
        $queueTotalCount = 0;
        $queue = collect();
        $queueGroups = null;

        if ($forPrint) {
            $properties = $tableQuery->with('agent')->limit(1000)->get();
        } else {
            $perPage = $this->resolvePerPage($request);
            $properties = $tableQuery->with('agent')->paginate($perPage)->withQueryString();

            // Fixes B1 (Johan, 2026-10-05) — sort + group-by + filter by
            // property and date range on the needs-action queue, enforced
            // at the query layer via the SAME own/branch/all scoping the
            // five rule queries already use (RentalCommandCentreService::
            // applyPropertyIdScope() — unchanged), and remembered per user.
            $prefState = RentalCommandCentreUserPreference::stateFor($user->id);
            $queueGroupBy = $this->resolveQueuePreference($request, $user, $prefState, 'queue_group_by', RentalCommandCentreService::QUEUE_GROUP_BY_OPTIONS, 'none');
            $queueSort = $this->resolveQueuePreference($request, $user, $prefState, 'queue_sort', RentalCommandCentreService::QUEUE_SORT_OPTIONS, 'urgency');
            $queuePropertyId = $request->filled('queue_property_id') ? (int) $request->get('queue_property_id') : null;
            $queueDateFrom = $request->get('queue_date_from');
            $queueDateTo = $request->get('queue_date_to');

            // Fetched WITHOUT the property filter so the "filter by
            // property" dropdown can offer every property that currently
            // has a needs-action item (date range + scope still applied) —
            // the property filter itself is then a plain narrowing of this
            // same already-scoped set, never a second, looser query.
            $queueItemsAll = $service->queueItems($user, $scope, null, $queueDateFrom, $queueDateTo, $queueSort);
            $queuePropertyOptions = $queueItemsAll->pluck('property')->filter()
                ->unique(fn ($p) => $p->id)
                ->sortBy(fn ($p) => $p->buildDisplayAddress())
                ->values();

            $queueItems = $queuePropertyId
                ? $queueItemsAll->filter(fn (array $i) => ($i['property']?->id) === $queuePropertyId)->values()
                : $queueItemsAll;
            $queueTotalCount = $queueItems->count();

            $queuePage = max(1, (int) $request->get('queue_page', 1));
            if ($queueGroupBy === 'none') {
                // 8/page — the queue sits in a ~40%-width column beside the
                // table on wide screens (approved mockup); 20 rows no longer
                // fits without pushing the table off screen.
                $queue = $service->paginateCollection($queueItems, 8, $queuePage, 'queue_page');
            } else {
                // Grouped views paginate by GROUP (8/page), not by item —
                // a group is a unit the agent reads together (one property,
                // or one date).
                $queueGroups = $service->paginateCollection(
                    $service->groupQueueItems($queueItems, $queueGroupBy),
                    8,
                    $queuePage,
                    'queue_page'
                );
            }
        }

        // Batched tenant-name lookup for the CURRENT page only — one extra
        // query total, never one per row (the N+1 the ticket explicitly
        // rules out).
        $propertyList = $forPrint ? $properties : collect($properties->items());
        $leaseIds = $propertyList->pluck('active_lease_id')->filter()->unique()->values();
        $tenantNamesByLeaseId = $leaseIds->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('lease_tenants')
            ->join('contacts', 'contacts.id', '=', 'lease_tenants.contact_id')
            ->whereIn('lease_tenants.lease_id', $leaseIds)
            ->selectRaw("lease_tenants.lease_id, GROUP_CONCAT(DISTINCT TRIM(CONCAT(contacts.first_name, ' ', COALESCE(contacts.last_name, ''))) SEPARATOR ', ') as names")
            ->groupBy('lease_tenants.lease_id')
            ->pluck('names', 'lease_id');

        $hasAnyRentalProperties = $tileCounts['all'] > 0;

        // Round 6 (2026-10-05, Johan) — per-group collapse state for the
        // needs-action queue, keyed by each group's stable 'key' (see
        // RentalCommandCentreService::groupQueueItems()), read once here
        // for whichever grouping is active.
        $collapsedQueueGroups = RentalCommandCentreUserPreference::stateFor($user->id)['collapsed_queue_groups'] ?? [];

        return [
            'scope' => $scope,
            'scopeOptions' => $scopeOptions,
            'tileCounts' => $tileCounts,
            'tiles' => RentalCommandCentreService::TILES,
            'activeTile' => $tile,
            'filters' => $filters,
            'sort' => in_array($sort, RentalCommandCentreService::SORT_COLUMNS, true) ? $sort : 'lease_end',
            'direction' => $direction === 'desc' ? 'desc' : 'asc',
            'properties' => $properties,
            'tenantNamesByLeaseId' => $tenantNamesByLeaseId,
            'queue' => $queue,
            'queueGroups' => $queueGroups,
            'queueGroupBy' => $queueGroupBy,
            'queueSort' => $queueSort,
            'queuePropertyId' => $queuePropertyId,
            'queueDateFrom' => $queueDateFrom,
            'queueDateTo' => $queueDateTo,
            'queuePropertyOptions' => $queuePropertyOptions,
            'queueTotalCount' => $queueTotalCount,
            'collapsedQueueGroups' => $collapsedQueueGroups,
            'hasAnyRentalProperties' => $hasAnyRentalProperties,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'agents' => $this->agencyAgents($user),
            // Multi-agency (non-negotiable #9) — status vocabulary is agency-
            // configurable (PropertySettingItem group='property_status'), so
            // the filter offers whatever values actually exist on THIS
            // agency's rental book today, never a hardcoded list.
            'statusOptions' => $service->basePropertyQuery($user, $scope)
                ->whereNotNull('properties.status')
                ->distinct()
                ->orderBy('properties.status')
                ->pluck('properties.status'),
            'agencyName' => $user->effectiveAgencyId()
                ? \App\Models\Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($user->effectiveAgencyId())?->name ?? 'Rentals'
                : 'Rentals',
            'generatedAt' => now(),
            'queueCollapsed' => (bool) RentalCommandCentreUserPreference::stateFor($user->id)['queue_collapsed'],
        ];
    }

    private function resolvePerPage(Request $request): int
    {
        $raw = $request->integer('per_page', 25);
        foreach (self::PER_PAGE_OPTIONS as $option) {
            if ($raw <= $option) {
                return $option;
            }
        }

        return self::PER_PAGE_OPTIONS[array_key_last(self::PER_PAGE_OPTIONS)];
    }

    /** Same pattern as SoldPropertyImportController::agencyAgents(). */
    private function agencyAgents(User $actor): \Illuminate\Support\Collection
    {
        $agencyId = $actor->effectiveAgencyId();
        $ownerRoles = User::ownerRoleNames();

        return User::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when(!empty($ownerRoles), fn ($q) => $q->whereNotIn('role', $ownerRoles))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->values();
    }
}
