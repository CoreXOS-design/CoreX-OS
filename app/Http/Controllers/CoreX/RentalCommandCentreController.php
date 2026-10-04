<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
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

        if ($forPrint) {
            $properties = $tableQuery->with('agent')->limit(1000)->get();
            $queue = collect();
        } else {
            $perPage = $this->resolvePerPage($request);
            $properties = $tableQuery->with('agent')->paginate($perPage)->withQueryString();

            // 8/page — the queue sits in a ~40%-width column beside the
            // table on wide screens (approved mockup); 20 rows no longer
            // fits without pushing the table off screen.
            $queueItems = $service->queueItems($user, $scope);
            $queue = $service->paginateCollection($queueItems, 8, max(1, (int) $request->get('queue_page', 1)), 'queue_page');
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
