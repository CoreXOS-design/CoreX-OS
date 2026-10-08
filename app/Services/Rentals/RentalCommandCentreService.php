<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\PermissionService;
use App\Models\RentalInspectionPlannedDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-command-centre.md — AT-441. Single engine behind the
 * web screen, the JSON API, and the print view, so all three can never
 * drift out of step on scope, tile definitions, or queue rules.
 *
 * Deliberately does NOT call Lease::scopeVisibleTo() / RentalFaultReport::
 * scopeVisibleTo() / RentalWorkOrder::scopeVisibleTo() — those resolve
 * 'own' as "created_by_user_id", which is the wrong concept on a screen
 * whose every row is a PROPERTY. This screen's own scope is resolved once
 * against the 'rental_command_centre' permission module and applied
 * identically (own = properties.agent_id, branch = properties.branch_id)
 * across properties, leases, fault reports, and work orders — the single
 * predicate the master spec (rentals-rebuild.md §1.1) requires so a join
 * can never silently widen visibility past what the properties-level
 * scope already restricted. The hard agency boundary (BelongsToAgency's
 * global scope) is untouched and applies automatically on every query
 * below — nothing here ever calls withoutGlobalScopes().
 *
 * derivedPropertyQuery() wraps the join+correlated-subquery query as a
 * FROM-subquery (fromSub) aliased back to 'properties', deliberately —
 * MySQL (like every SQL dialect) resolves WHERE before the SELECT list,
 * so a correlated-subquery column alias (open_faults_count, etc.) is NOT
 * visible to a WHERE clause in the SAME query. Wrapping it as a derived
 * table makes every alias a real, filterable/sortable column one level
 * up, which is what every tile/filter/sort below needs.
 */
class RentalCommandCentreService
{
    public const TILES = [
        'all' => 'Rental properties',
        'occupied' => 'Occupied',
        'unoccupied' => 'Unoccupied',
        // Round 7 (2026-10-05, Johan) — the remainder that keeps the tiles
        // adding up: total = occupied + unoccupied + inactive. Everything
        // with no active lease that ISN'T "active rental stock" per
        // LeaseSetting::activeRentalStatusesFor() — withdrawn, expired,
        // draft, prospecting, sold, let-out-elsewhere, etc.
        'inactive' => 'Inactive / off market',
        'expiring' => 'Expiring in window',
        'notice_given' => 'Notice given',
        'renewals_in_progress' => 'Renewals in progress',
        'month_to_month' => 'Month-to-month',
        'open_faults' => 'Open faults',
        'open_work_orders' => 'Open work orders',
        'inspections_due' => 'Inspections due',
    ];

    public const SORT_COLUMNS = ['address', 'lease_end', 'status', 'open_faults', 'open_work_orders', 'open_total', 'last_inspection'];

    /**
     * Needs-action queue controls (2026-10-05 fix round). 'none' is the
     * EXISTING flat urgency-then-age ordering — kept as the default per
     * Johan's own instruction to keep any existing grouping too.
     */
    public const QUEUE_GROUP_BY_OPTIONS = ['none', 'property', 'date'];
    public const QUEUE_SORT_OPTIONS = ['urgency', 'date', 'property'];

    private const RENTAL_LISTING_TYPES = ['rental', 'to_let', 'to-let', 'lease'];

    /**
     * THE ONE definition of "open" for a fault report, matching
     * RentalFaultReportController::index()'s own "open_no_work_order"
     * exception tile (§39) exactly — resolved/cancelled/declined are the
     * only statuses that screen treats as closed. Used here for the row
     * column, the tile total, AND the tile's table filter, so none of the
     * three can drift from the other or from that screen's own vocabulary.
     */
    public const FAULT_OPEN_STATUSES_EXCLUDED = [
        RentalFaultReport::STATUS_RESOLVED,
        RentalFaultReport::STATUS_CANCELLED,
        RentalFaultReport::STATUS_DECLINED,
    ];

    /**
     * THE ONE definition of "open" for a work order, matching
     * RentalWorkOrderController::index()'s own per-status tiles (reported/
     * ordered/in_progress are everything completed/cancelled is not).
     */
    public const WORK_ORDER_OPEN_STATUSES_EXCLUDED = [
        RentalWorkOrder::STATUS_COMPLETED,
        RentalWorkOrder::STATUS_CANCELLED,
    ];

    /**
     * The user's real ceiling for this screen — identical mechanism to
     * every other rentals list (PermissionService::getDataScope() +
     * clampScope(), the DeedsCaptureController reference pattern).
     */
    public function scopeOptionsFor(User $user): array
    {
        $maxScope = PermissionService::getDataScope($user, 'rental_command_centre');

        return match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            'own' => ['own'],
            default => [],
        };
    }

    public function resolveScope(User $user, ?string $requested): string
    {
        $maxScope = PermissionService::getDataScope($user, 'rental_command_centre');

        return PermissionService::clampScope($requested, $maxScope ?? 'own');
    }

    /**
     * Plain scoped set of rental properties — no join, no derived columns.
     * Used where only the base property set is needed (e.g. the status
     * filter's distinct-values list).
     */
    public function basePropertyQuery(User $user, string $scope): Builder
    {
        $query = Property::query()->whereRaw(
            'LOWER(TRIM(properties.listing_type)) IN (' . implode(',', array_fill(0, count(self::RENTAL_LISTING_TYPES), '?')) . ')',
            self::RENTAL_LISTING_TYPES
        );

        return $this->applyDirectPropertyScope($query, $user, $scope);
    }

    private function applyDirectPropertyScope(Builder $query, User $user, string $scope): Builder
    {
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('properties.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            return $query->whereIn('properties.agent_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Applies the SAME own/branch/all predicate to any OTHER table that
     * carries a property_id column (leases, fault reports, work orders) —
     * the mechanism the master spec's scoping paragraph requires, so a
     * join/union can never widen visibility past the properties-level
     * scope.
     */
    public function applyPropertyIdScope(Builder $query, User $user, string $scope, string $propertyIdColumn): Builder
    {
        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            return $query->whereIn($propertyIdColumn, fn ($q) => $q->select('id')->from('properties')->where('branch_id', $branchId));
        }

        if ($scope === 'own') {
            $ids = $user->dataIdentityIds();
            return $query->whereIn($propertyIdColumn, fn ($q) => $q->select('id')->from('properties')->whereIn('agent_id', $ids));
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Would opening this named route pass its own `permission:` middleware for $user? A needs-action row whose action the viewer's role
     * cannot open shows no button (it would only answer 403). Group and route middleware both count; several keys in one
     * `permission:` entry are OR, separate entries are AND — exactly CheckPermission.
     */
    public function canOpenRoute(?User $user, string $routeName): bool
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
        if (! $user || ! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }
            $keys = array_map('trim', explode(',', substr($middleware, strlen('permission:'))));
            if (! collect($keys)->contains(fn (string $key) => $user->hasPermission($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * "Only what the command centre counted" — limits a fault / work-order list query to properties inside the command centre's
     * own property set for $requestedScope (rental listings, own/branch/all clamped to the viewer's command-centre ceiling), the
     * exact set the open-faults / open-work-orders tiles add up. A viewer with no command-centre access gets the query back unchanged.
     */
    public function limitToCommandCentreProperties(Builder $query, User $user, ?string $requestedScope, string $propertyIdColumn): Builder
    {
        if (PermissionService::getDataScope($user, 'rental_command_centre') === null) {
            return $query;
        }

        return $query->whereIn($propertyIdColumn, $this->basePropertyQuery($user, $this->resolveScope($user, $requestedScope))->select('properties.id'));
    }

    /**
     * The viewer's own/branch/agency data scope for a record type, as an `AND …` fragment + bindings for a correlated
     * sub-select over that record's table (alias $alias, joined to the outer `properties` row). Mirrors
     * RentalFaultReport::scopeVisibleTo / RentalWorkOrder::scopeVisibleTo exactly: all = nothing, branch = the property's branch,
     * own = records the viewer created.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function recordScopeSql(User $user, string $module, string $alias): array
    {
        // No scope row for the module = no access to that list: its count is 0 (never a 403 from a count).
        $scope = PermissionService::getDataScope($user, $module);

        return match ($scope) {
            'all' => ['', []],
            'branch' => [' AND properties.branch_id = ?', [$user->effectiveBranchId()]],
            'own' => (function () use ($user, $alias) {
                $ids = array_values(array_filter($user->dataIdentityIds()));

                return $ids === [] ? [' AND 1 = 0', []] : [" AND {$alias}.created_by_user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
            })(),
            default => [' AND 1 = 0', []],
        };
    }


    /**
     * The derived, FILTERABLE property set — the active-lease join (one
     * row max per property, leases.md's one-active-lease-per-property
     * invariant, enforced by LeaseActivationService::activate()) plus
     * every scalar aggregate the tiles/table need, wrapped as a derived
     * table so every alias below is a real column one level up. No N+1:
     * these are correlated subqueries evaluated once per property row
     * inside ONE query, never a separate query per row.
     */
    public function derivedPropertyQuery(User $user, string $scope): Builder
    {
        return Property::query()->fromSub($this->buildDerivedInnerQuery($user, $scope), 'properties');
    }

    private function buildDerivedInnerQuery(User $user, string $scope): QueryBuilder
    {
        $faultScope = $this->recordScopeSql($user, 'rental_fault_reports', 'rfr');
        $woScope = $this->recordScopeSql($user, 'rental_work_orders', 'rwo');

        $query = DB::table('properties')
            ->whereRaw(
                'LOWER(TRIM(properties.listing_type)) IN (' . implode(',', array_fill(0, count(self::RENTAL_LISTING_TYPES), '?')) . ')',
                self::RENTAL_LISTING_TYPES
            )
            ->leftJoin('leases as active_lease', function ($join) {
                $join->on('active_lease.property_id', '=', 'properties.id')
                    ->where('active_lease.status', '=', Lease::STATUS_ACTIVE)
                    ->whereNull('active_lease.deleted_at');
            })
            ->select('properties.*')
            ->addSelect([
                'active_lease.id as active_lease_id',
                'active_lease.rental_amount as active_rental_amount',
                'active_lease.start_date as active_start_date',
                'active_lease.end_date as active_end_date',
                'active_lease.is_month_to_month as active_month_to_month',
                // AT-444 follow-up (2026-10-05) — "Notice given" tile. Mirrors
                // Lease::hasActiveNotice()'s own definition (notice_date !==
                // null) exactly; see tileCounts()/applyTile() below.
                'active_lease.notice_date as active_notice_date',
            ])
            // The F / WO counts follow the viewer's OWN fault / work-order data scope (the same one those lists apply), so the
            // "N F" / "N WO" link lands on exactly the rows it counted (rentals cross-cut, 8 Oct 2026).
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_fault_reports rfr WHERE rfr.property_id = properties.id '
                . 'AND rfr.deleted_at IS NULL AND rfr.status NOT IN (?, ?, ?)' . $faultScope[0] . ') as open_faults_count',
                array_merge(self::FAULT_OPEN_STATUSES_EXCLUDED, $faultScope[1])
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_work_orders rwo WHERE rwo.property_id = properties.id '
                . 'AND rwo.deleted_at IS NULL AND rwo.status NOT IN (?, ?)' . $woScope[0] . ') as open_work_orders_count',
                array_merge(self::WORK_ORDER_OPEN_STATUSES_EXCLUDED, $woScope[1])
            )
            ->selectRaw(
                '(SELECT MAX(ri.completed_at) FROM rental_inspections ri WHERE ri.property_id = properties.id '
                . 'AND ri.deleted_at IS NULL AND ri.status = ?) as last_inspection_at',
                [RentalInspection::STATUS_COMPLETED]
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_inspections ri2 WHERE ri2.lease_id = active_lease.id '
                . 'AND ri2.deleted_at IS NULL AND ri2.type = ? AND ri2.status = ?) as active_lease_completed_in_inspections',
                [RentalInspection::TYPE_IN, RentalInspection::STATUS_COMPLETED]
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_inspections ri3 WHERE ri3.property_id = properties.id '
                . 'AND ri3.deleted_at IS NULL AND ri3.status NOT IN (?, ?)) as open_inspections_count',
                [RentalInspection::STATUS_COMPLETED, RentalInspection::STATUS_CANCELLED]
            )
            // "Renewals in progress" (§3.1) — a DRAFT lease already chained
            // (previous_lease_id) to the CURRENTLY active lease, not yet
            // activated. Deliberately NOT "active lease has previous_lease_id
            // set" — LeaseActivationService::activate() leaves that set
            // permanently on every renewed-in lease, so that check would
            // count every past renewal forever, not just the ones still
            // pending signature. Also deliberately NOT "active lease's own
            // renewed_lease_id is set" — that column is only written at
            // ACTIVATION time, by which point this lease is already expired,
            // never active, so that check could never fire here. This raw
            // correlated subquery is the SQL-level mirror of the single
            // canonical definition on the model, Lease::renewalDrafts() /
            // ::hasPendingRenewalDraft() (AT-444 follow-up, 2026-10-05) —
            // same table, same previous_lease_id column, same STATUS_DRAFT
            // constant — so the two can never drift apart.
            ->selectRaw(
                '(SELECT COUNT(*) FROM leases pl WHERE pl.previous_lease_id = active_lease.id '
                . 'AND pl.status = ? AND pl.deleted_at IS NULL) as pending_renewal_draft_count',
                [Lease::STATUS_DRAFT]
            )
            // "Cancel renewal draft" row action — the draft's own id, so the
            // row can link straight to it without a second query per row.
            // Same WHERE as pending_renewal_draft_count above; only the
            // SELECT differs.
            ->selectRaw(
                '(SELECT pl2.id FROM leases pl2 WHERE pl2.previous_lease_id = active_lease.id '
                . 'AND pl2.status = ? AND pl2.deleted_at IS NULL ORDER BY pl2.id DESC LIMIT 1) as pending_renewal_draft_lease_id',
                [Lease::STATUS_DRAFT]
            );

        if ($scope === 'branch') {
            $query->where('properties.branch_id', $user->effectiveBranchId());
        } elseif ($scope === 'own') {
            $query->whereIn('properties.agent_id', $user->dataIdentityIds());
        } elseif ($scope !== 'all') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /**
     * Round 7 (2026-10-05, Johan) — "the tile's number and the list it
     * opens come from ONE shared query definition, so they can never
     * disagree." A fault report/work order with real data (lease #..,
     * property #..) confirmed exactly the bug class this guards against:
     * the OLD tileCounts() hand-rolled its own PHP boolean per tile in a
     * foreach loop, duplicating — not sharing — the SQL predicate applyTile()
     * built separately for the ?tile= click. The two could drift (and did:
     * open_faults/open_work_orders counted RECORDS here, "properties that
     * have ≥1" there). Every predicate now lives ONCE, in tilePredicateSql()
     * below, used verbatim by both applyTile()'s WHERE and this method's
     * CASE WHEN — textually identical SQL, not independently-maintained
     * logic that merely happens to agree today.
     */
    private const TILE_KEYS_FOR_AGGREGATE = [
        'occupied', 'unoccupied', 'inactive', 'expiring', 'notice_given',
        'renewals_in_progress', 'month_to_month', 'open_faults', 'open_work_orders',
        'inspections_due',
    ];

    /**
     * The ONE predicate per tile, as a raw SQL boolean expression over the
     * derived table's own columns (see buildDerivedInnerQuery()) plus its
     * bindings. Returns null for a tile with no predicate ('all', or an
     * unknown key) — the caller treats null as "always true."
     */
    private function tilePredicateSql(string $tile, string $today, string $windowEnd, array $activeStatuses, array $dueNowPropertyIds = []): ?array
    {
        $activeStatusesLower = array_map(fn ($s) => strtolower(trim((string) $s)), $activeStatuses) ?: ['__none__'];
        $activeStatusPlaceholders = implode(',', array_fill(0, count($activeStatusesLower), '?'));

        return match ($tile) {
            'occupied' => ['active_lease_id IS NOT NULL', []],
            // Round 7 — was simply "active_lease_id IS NULL" (every rental
            // property with no active lease, including withdrawn/expired/
            // draft/prospecting/sold/let-out-elsewhere ones). Restricted to
            // properties whose STATUS this agency considers active rental
            // stock (LeaseSetting::activeRentalStatusesFor()) — the
            // "inactive" tile below is the restriction's own complement, so
            // occupied + unoccupied + inactive always equals 'all'.
            'unoccupied' => ["active_lease_id IS NULL AND LOWER(COALESCE(status, '')) IN ({$activeStatusPlaceholders})", $activeStatusesLower],
            // COALESCE: a NULL status must land in exactly one of unoccupied / inactive, never neither.
            'inactive' => ["active_lease_id IS NULL AND LOWER(COALESCE(status, '')) NOT IN ({$activeStatusPlaceholders})", $activeStatusesLower],
            'expiring' => ['active_lease_id IS NOT NULL AND active_end_date BETWEEN ? AND ?', [$today, $windowEnd]],
            'notice_given' => ['active_lease_id IS NOT NULL AND active_notice_date IS NOT NULL', []],
            'renewals_in_progress' => ['active_lease_id IS NOT NULL AND pending_renewal_draft_count > 0', []],
            'month_to_month' => ['active_lease_id IS NOT NULL AND active_end_date IS NULL AND active_month_to_month = 1', []],
            // Round 7 — the LIST side of these two tiles has always meant
            // (and still means) "properties with ≥1 open record"; see
            // tileCounts()'s own 'records' figure below for the total
            // record count shown alongside this property count.
            'open_faults' => ['open_faults_count > 0', []],
            'open_work_orders' => ['open_work_orders_count > 0', []],
            // §45.7 (Build I-5) — "inspections due" is: something already open, OR a property with an In/Out inspection
            // due or overdue (worked out from the leases, tenancy-chain aware), OR an open loaded interim date within its
            // lead window or overdue. Never a future one. The property ids come from RentalInspectionDueService — the
            // one definition shared with the needs-action queue and the Due tab — so the tile, its list and the queue agree.
            'inspections_due' => $dueNowPropertyIds === []
                ? ['(open_inspections_count > 0)', []]
                : ['(open_inspections_count > 0 OR id IN (' . implode(',', array_fill(0, count($dueNowPropertyIds), '?')) . '))', array_values($dueNowPropertyIds)],
            default => null,
        };
    }

    /**
     * §3.1 — all eleven tiles, computed from ONE query (one aggregate pass
     * over the scoped property universe — see tilePredicateSql() above for
     * why this can never disagree with what clicking a tile lists).
     * open_faults/open_work_orders are record-based: Johan — "show the
     * record count as the big number with the property count beside it."
     * Returned as ['records' => N, 'properties' => M] instead of a plain
     * int; every other tile stays a plain int. 'records' is SUM() of the
     * exact same open_*_count column the list's own "Open" cell displays
     * per row, over the exact same property set the tile's click opens —
     * by construction, that sum can never diverge from what the opened
     * list adds up to.
     */
    public function tileCounts(User $user, string $scope): array
    {
        $agencyId = $user->effectiveAgencyId();
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);
        $today = now()->toDateString();
        $windowEnd = now()->addDays($windowDays)->toDateString();
        $activeStatuses = LeaseSetting::activeRentalStatusesFor($agencyId);

        $selects = ['COUNT(*) as agg_all'];
        $bindings = [];
        $dueNowPropertyIds = app(RentalInspectionDueService::class)->dueNowPropertyIds((int) $agencyId);

        foreach (self::TILE_KEYS_FOR_AGGREGATE as $key) {
            [$sql, $predicateBindings] = $this->tilePredicateSql($key, $today, $windowEnd, $activeStatuses, $dueNowPropertyIds);
            $selects[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as agg_{$key}";
            $bindings = array_merge($bindings, $predicateBindings);
        }
        $selects[] = 'SUM(open_faults_count) as agg_open_faults_records';
        $selects[] = 'SUM(open_work_orders_count) as agg_open_work_orders_records';

        $row = $this->derivedPropertyQuery($user, $scope)
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        $counts = ['all' => (int) ($row->agg_all ?? 0)];
        foreach (self::TILE_KEYS_FOR_AGGREGATE as $key) {
            $counts[$key] = (int) ($row->{'agg_' . $key} ?? 0);
        }

        $counts['open_faults'] = ['properties' => $counts['open_faults'], 'records' => (int) ($row->agg_open_faults_records ?? 0)];
        $counts['open_work_orders'] = ['properties' => $counts['open_work_orders'], 'records' => (int) ($row->agg_open_work_orders_records ?? 0)];

        return $counts;
    }

    /**
     * §3.3 — the full table. One scoped+derived query, filtered/searched/
     * sorted/paginated. Tile clicks (?tile=) layer an extra where onto the
     * SAME base the tile count used, so a tile can never show a count the
     * table underneath can't produce.
     */
    public function tableQuery(User $user, string $scope, array $filters): Builder
    {
        $query = $this->derivedPropertyQuery($user, $scope);

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->searchAddress($search)
                    ->orWhere('erf_number', 'like', "%{$search}%")
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->selectRaw(1)->from('lease_tenants')
                            ->join('contacts', 'contacts.id', '=', 'lease_tenants.contact_id')
                            ->whereColumn('lease_tenants.lease_id', 'properties.active_lease_id')
                            ->whereNull('contacts.deleted_at')
                            ->where(function ($c) use ($search) {
                                // first, last, or the full name together ("John Smith")
                                $c->where('contacts.first_name', 'like', "%{$search}%")
                                    ->orWhere('contacts.last_name', 'like', "%{$search}%")
                                    ->orWhereRaw("CONCAT(contacts.first_name, ' ', COALESCE(contacts.last_name, '')) LIKE ?", ["%{$search}%"]);
                            });
                    })
                    // Landlord — same source Property::sellerOwnerContact()
                    // uses (contact_property pivot, seller-side role), since
                    // Lease has no landlord accessor of its own yet.
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->selectRaw(1)->from('contact_property')
                            ->join('contacts', 'contacts.id', '=', 'contact_property.contact_id')
                            ->whereColumn('contact_property.property_id', 'properties.id')
                            ->whereNull('contact_property.deleted_at')
                            ->whereIn('contact_property.role', ['seller', 'owner', 'landlord', 'lessor'])
                            ->where(function ($c) use ($search) {
                                $c->where('contacts.first_name', 'like', "%{$search}%")
                                    ->orWhere('contacts.last_name', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($agentId = $filters['agent_id'] ?? null) {
            $query->where('agent_id', $agentId);
        }

        if ($branchId = $filters['branch_id'] ?? null) {
            $query->where('branch_id', $branchId);
        }

        if ($dateFrom = $filters['date_from'] ?? null) {
            $query->where('active_end_date', '>=', $dateFrom);
        }

        if ($dateTo = $filters['date_to'] ?? null) {
            $query->where('active_end_date', '<=', $dateTo);
        }

        $this->applyTile($query, $user, $scope, $filters['tile'] ?? null);

        return $query;
    }

    /**
     * Round 7 (2026-10-05) — the WHERE applied here is tilePredicateSql()'s
     * own SQL string, verbatim — the exact same text tileCounts()'s CASE
     * WHEN uses for this tile. See that method's docblock for why.
     */
    public function applyTile(Builder $query, User $user, string $scope, ?string $tile): Builder
    {
        if (!$tile || $tile === 'all') {
            return $query;
        }

        $agencyId = $user->effectiveAgencyId();
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);
        $today = now()->toDateString();
        $windowEnd = now()->addDays($windowDays)->toDateString();
        $activeStatuses = LeaseSetting::activeRentalStatusesFor($agencyId);

        $predicate = $this->tilePredicateSql(
            $tile, $today, $windowEnd, $activeStatuses,
            $tile === 'inspections_due' ? app(RentalInspectionDueService::class)->dueNowPropertyIds((int) $agencyId) : []
        );
        if ($predicate) {
            [$sql, $bindings] = $predicate;
            $query->whereRaw($sql, $bindings);
        }

        return $query;
    }

    /**
     * Default sort is 'lease_end' ascending (leases ending soonest first),
     * with a NULL end_date (vacant/month-to-month) always LAST regardless
     * of direction — never a plain `ORDER BY active_end_date` on its own,
     * which would put every vacant unit FIRST under MySQL's default
     * NULL-sorts-first ascending behaviour, exactly the bug this guards
     * against.
     */
    public function applySort(Builder $query, ?string $sort, ?string $direction): Builder
    {
        $sort = in_array($sort, self::SORT_COLUMNS, true) ? $sort : 'lease_end';
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        // 'open_total' — the merged "Open" column's own sort (faults +
        // work orders combined); a computed sum has no single column
        // name, so it is ordered via raw SQL rather than the plain
        // orderBy() every other column uses.
        if ($sort === 'open_total') {
            return $query->orderByRaw('(open_faults_count + open_work_orders_count) ' . $direction)->orderBy('id', $direction);
        }

        $column = match ($sort) {
            'lease_end' => 'active_end_date',
            'status' => 'status',
            'open_faults' => 'open_faults_count',
            'open_work_orders' => 'open_work_orders_count',
            'last_inspection' => 'last_inspection_at',
            default => 'title',
        };

        if ($column === 'active_end_date') {
            $query->orderByRaw('active_end_date IS NULL');
        }

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * §3.2 — the needs-action queue. One row per ITEM (a specific lease, a
     * specific fault report, a specific work order) needing one specific
     * action — never collapsed per property. Five independent rule
     * queries, merged and sorted urgency-then-age in PHP (the result sets
     * are bounded by the agency's own open/expiring book, never by total
     * property count).
     */
    /**
     * 2026-10-05 fix round — $propertyId/$dateFrom/$dateTo narrow the FIVE
     * underlying rule queries themselves (never a post-fetch PHP filter),
     * so the new controls sit on top of the SAME query-layer own/branch/all
     * scoping ($this->applyPropertyIdScope() below, unchanged) rather than
     * beside or instead of it. $dateFrom/$dateTo apply against each rule's
     * own existing date column (the same one already used for age_days) —
     * end_date for the two lease-expiry rules, reported_at for faults,
     * updated_at for overdue work orders, start_date for start-inspection —
     * there is no single universal "due date" across five different models,
     * so each rule's own already-displayed age-basis date is reused rather
     * than inventing a new one. $sort orders the merged result; 'urgency'
     * is the EXISTING default (kept, per Johan's instruction not to drop
     * it), 'date' and 'property' are new.
     */
    public function queueItems(
        User $user,
        string $scope,
        ?int $propertyId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $sort = 'urgency'
    ): Collection {
        $agencyId = $user->effectiveAgencyId();
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);
        $overdueDays = RentalWorkOrderSetting::overdueReminderDaysFor($agencyId);
        $today = now()->startOfDay();
        $items = collect();
        $applyQueueFilters = function (Builder $query, string $dateColumn) use ($propertyId, $dateFrom, $dateTo) {
            $query->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
                ->when($dateFrom, fn (Builder $q) => $q->whereDate($dateColumn, '>=', $dateFrom))
                ->when($dateTo, fn (Builder $q) => $q->whereDate($dateColumn, '<=', $dateTo));

            return $query;
        };

        // A — lease expiring in window, no renewal outcome. "No outcome"
        // is vacuous today (leases.md has no outcome-recording field yet,
        // so this fires for every expiring lease — honest, not a bug: it
        // is exactly the set that genuinely needs a human decision).
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                Lease::query()->where('status', Lease::STATUS_ACTIVE)->whereNotNull('end_date')
                    ->whereBetween('end_date', [$today->toDateString(), $today->copy()->addDays($windowDays)->toDateString()]),
                'end_date'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->with('tenants.contact')->get()->each(function (Lease $lease) use (&$items, $today) {
            $ageDays = -1 * (int) abs($today->diffInDays($lease->end_date));
            $base = [
                'urgency' => 3,
                // Negative-and-hidden by design: a future deadline is not
                // "N days old" (the blade's age badge only shows when
                // age_days > 0), but still needs a signed value so the
                // within-tier sort below puts the SOONEST deadline first.
                'age_days' => $ageDays,
                'item_date' => $lease->end_date,
                'property' => $lease->property,
                'lease' => $lease,
                'route' => 'corex.leases.show',
                // AT-444 follow-up (2026-10-05) — opens the Lease Hub's
                // "Renew lease" dialog directly (LeaseActionDialogResolver),
                // rather than landing the agent on the hub with one more
                // click still needed.
                'route_params' => ['lease' => $lease->id, 'action' => 'renew'],
            ];

            // rental-renewals.md §21 — an agent already started a renewal
            // for this lease via "Renew lease" (Lease::hasPendingRenewalDraft()
            // is the SAME definition the "Renewals in progress" tile uses, so
            // the two can never drift).
            if ($lease->hasPendingRenewalDraft()) {
                $draft = $lease->renewalDrafts()->first();
                $items->push($base + [
                    'type' => 'renewal_draft_ready',
                    'label' => 'Renewal draft ready',
                    'detail' => 'R' . number_format((float) $draft->rental_amount, 2) . '/mo — ready to review and send',
                ]);

                return;
            }

            // A lease with an outcome already on file (notice either side,
            // month-to-month) is not renewing at all — querying its
            // eligibility here would be wasted work and could mislabel it
            // "missing info".
            if (!$lease->hasActiveNotice() && !$lease->is_month_to_month) {
                $agent = $lease->createdByUser;
                $decision = $agent ? app(RenewalDraftEligibilityService::class)->decide($lease, $agent) : null;

                if ($decision && $decision['outcome'] === 'insufficient_info') {
                    $items->push($base + [
                        'type' => 'review_renewal',
                        'label' => 'Review renewal',
                        'detail' => 'Missing: ' . implode(', ', $decision['missing']),
                    ]);

                    return;
                }
            }

            $items->push($base + [
                'type' => 'review_renewal',
                'label' => 'Review renewal',
                'detail' => 'Tenant: ' . $lease->tenantNames(),
            ]);
        });

        // B — lease past end date, still active, no outcome recorded.
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                Lease::query()->where('status', Lease::STATUS_ACTIVE)->whereNotNull('end_date')
                    ->where('end_date', '<', $today->toDateString()),
                'end_date'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->with('tenants.contact')->get()->each(function (Lease $lease) use (&$items, $today) {
            $items->push([
                'type' => 'record_outcome',
                'urgency' => 1,
                'age_days' => (int) abs($today->diffInDays($lease->end_date)),
                'item_date' => $lease->end_date,
                'property' => $lease->property,
                'lease' => $lease,
                'label' => 'Record outcome',
                'detail' => 'Tenant: ' . $lease->tenantNames(),
                'route' => 'corex.leases.show',
                'route_params' => ['lease' => $lease->id],
            ]);
        });

        // C — fault report awaiting owner approval. Names the specific
        // fault (title) so two rows on the same property are distinguishable
        // — the bug this fix exists for (two identical "Fault awaiting
        // owner approval" rows, same property, no way to tell them apart).
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                RentalFaultReport::query()->where('status', RentalFaultReport::STATUS_AWAITING_APPROVAL),
                'reported_at'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->get()->each(function (RentalFaultReport $fault) use (&$items, $today) {
            $items->push([
                'type' => 'fault_awaiting_approval',
                'urgency' => 2,
                'age_days' => $fault->reported_at ? (int) abs($today->diffInDays($fault->reported_at)) : 0,
                'item_date' => $fault->reported_at,
                'property' => $fault->property,
                'lease' => null,
                'label' => 'Open',
                'detail' => $fault->title,
                'route' => 'corex.rental-fault-reports.show',
                'route_params' => ['rentalFaultReport' => $fault->id],
            ]);
        });

        // C2 — a fault nobody has looked at yet (Johan, 8 Oct 2026): status "reported" = no agent has reviewed it, prepared the owner's
        // version, sent it, recorded a decision or raised a work order. It is "Review fault" until the agent moves it on (any other
        // status leaves this queue by itself - there is no separate flag to clear). Same own / branch / agency scoping as every rule here.
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                RentalFaultReport::query()->where('status', RentalFaultReport::STATUS_REPORTED),
                'reported_at'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->get()->each(function (RentalFaultReport $fault) use (&$items, $today) {
            $items->push([
                'type' => 'fault_to_review',
                'urgency' => 2,
                'age_days' => $fault->reported_at ? (int) abs($today->diffInDays($fault->reported_at)) : 0,
                'item_date' => $fault->reported_at,
                'property' => $fault->property,
                'lease' => null,
                'label' => 'Review fault',
                'detail' => $fault->title,
                'route' => 'corex.rental-fault-reports.show',
                'route_params' => ['rentalFaultReport' => $fault->id],
            ]);
        });

        // C3 - a tenant said the finished work is NOT complete (work order status "disputed", spec 17.10.6). The agent gets an in-app
        // note when it happens, but the tenant is waiting and nothing else puts it in front of anyone: it stays here until the work is
        // reported done again (status leaves "disputed"). Same own / branch / agency scoping as every rule here.
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                RentalWorkOrder::query()->where('status', RentalWorkOrder::STATUS_DISPUTED),
                'updated_at'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->get()->each(function (RentalWorkOrder $workOrder) use (&$items, $today) {
            $items->push([
                'type' => 'work_order_disputed',
                'urgency' => 1,
                'age_days' => $workOrder->updated_at ? (int) abs($today->diffInDays($workOrder->updated_at)) : 0,
                'item_date' => $workOrder->updated_at,
                'property' => $workOrder->property,
                'lease' => null,
                'label' => 'Resolve dispute',
                'detail' => $workOrder->title . ' - the tenant says it is not complete',
                'route' => 'corex.rental-work-orders.show',
                'route_params' => ['rentalWorkOrder' => $workOrder->id],
            ]);
        });

        // D — work order overdue. Reuses RentalWorkOrder::scopeOverdue()
        // directly — the SAME scope RentalWorkOrderController::index()'s own
        // "Overdue" tile and ?overdue=1 filter use (status IN
        // [ordered,in_progress], updated_at threshold) — NOT a hand-rolled
        // reported_at check. A merely "reported" (not yet ordered) work
        // order is open (counted in the open_work_orders tile) but is not
        // "overdue" in this codebase's own vocabulary; using a different
        // definition here than the list screen already uses is exactly the
        // kind of drift this fix round exists to close.
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                RentalWorkOrder::query()->overdue($overdueDays),
                'updated_at'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->get()->each(function (RentalWorkOrder $wo) use (&$items, $today) {
            $items->push([
                'type' => 'work_order_overdue',
                'urgency' => 1,
                'age_days' => $wo->updated_at ? (int) abs($today->diffInDays($wo->updated_at)) : 0,
                'item_date' => $wo->updated_at,
                'property' => $wo->property,
                'lease' => null,
                'label' => 'Open',
                'detail' => $wo->title,
                'route' => 'corex.rental-work-orders.show',
                'route_params' => ['rentalWorkOrder' => $wo->id],
            ]);
        });

        // E — inspections due (§45.7, Build I-5). The old rule ("active lease with no completed in-inspection") is now the
        // In rule below, plus a move-out rule and a loaded-interim-date rule. All three come from
        // RentalInspectionDueService, so the queue, the "inspections due" tile and the Due tab never disagree, and only
        // items that are DUE WITHIN THEIR LEAD WINDOW OR OVERDUE appear — never a future one. The viewer's own/branch/agency
        // scope is applied to the lease query itself (the property scope below), not filtered afterwards.
        $dueService = app(RentalInspectionDueService::class);
        $inRange = function ($date) use ($dateFrom, $dateTo) {
            $d = $date->toDateString();

            return (! $dateFrom || $d >= $dateFrom) && (! $dateTo || $d <= $dateTo);
        };
        $dueItems = $dueService->inOutItemsFor(
            $agencyId,
            function ($leaseQuery) use ($propertyId, $user, $scope) {
                $leaseQuery->when($propertyId, fn ($q) => $q->where('leases.property_id', $propertyId));
                $this->applyPropertyIdScope($leaseQuery, $user, $scope, 'leases.property_id');
            },
            $today
        )->filter(fn (array $i) => $i['state'] !== RentalInspectionDueService::STATE_UPCOMING && $inRange($i['due_on']));

        $dueItems->each(function (array $item) use (&$items, $today) {
            /** @var Lease $lease */
            $lease = $item['lease'];
            $isIn = $item['type'] === RentalInspectionDueService::TYPE_IN;
            $items->push([
                'type' => $isIn ? 'start_inspection' : 'start_out_inspection',
                'urgency' => $item['state'] === RentalInspectionDueService::STATE_OVERDUE ? 2 : 3,
                'age_days' => (int) abs($today->diffInDays($item['due_on'])),
                'item_date' => $item['due_on'],
                'property' => $lease->property,
                'lease' => $lease,
                'label' => $isIn ? 'Start inspection' : 'Start move-out inspection',
                'detail' => 'Tenant: ' . $lease->tenantNames(),
                // AT-444/AT-441 follow-up (2026-10-05) — RentalInspectionController::create() pre-selects from
                // lease_id/property_id (+ type); lease_id resolves through the controller's own Lease::visibleTo().
                'route' => 'corex.rental-inspections.create',
                'route_params' => ['property_id' => $lease->property_id, 'lease_id' => $lease->id, 'type' => $item['type']],
            ]);
        });

        $plannedQuery = $dueService->plannedDueNowQuery($agencyId, $today)
            ->when($propertyId, fn ($q) => $q->where('rental_inspection_planned_dates.property_id', $propertyId))
            ->when($dateFrom, fn ($q) => $q->whereDate('rental_inspection_planned_dates.planned_on', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('rental_inspection_planned_dates.planned_on', '<=', $dateTo))
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes(), 'lease' => fn ($q) => $q->withoutGlobalScopes()->with('tenants.contact')]);
        $this->applyPropertyIdScope($plannedQuery, $user, $scope, 'rental_inspection_planned_dates.property_id');
        $plannedQuery->get()->each(function (RentalInspectionPlannedDate $date) use (&$items, $today) {
            // Properties are loaded withoutGlobalScopes() above (so the row can still name them);
            // an ARCHIVED property is not stock any more and must not raise a needs-action row —
            // the tiles and the In/Out rule already exclude it.
            if (! $date->property || $date->property->trashed()) {
                return;
            }
            $items->push([
                'type' => 'interim_inspection_due',
                'urgency' => $date->planned_on->lt($today) ? 2 : 3,
                'age_days' => (int) abs($today->diffInDays($date->planned_on)),
                'item_date' => $date->planned_on,
                'property' => $date->property,
                'lease' => $date->lease,
                'label' => $date->status === RentalInspectionPlannedDate::STATUS_BOOKED ? 'Interim booked' : 'Interim inspection',
                'detail' => 'Loaded date ' . $date->planned_on->format('j M Y') . ($date->note ? ' — ' . $date->note : ''),
                'route' => 'corex.rental-inspections.due',
                'route_params' => [],
            ]);
        });

        // I — an active lease whose notice terms no agent has confirmed against the signed lease (leases.md §18.7). The portal
        // states nothing about notice until they are; the row opens the lease, whose card has the one-click "Confirm terms".
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                Lease::query()->where('status', Lease::STATUS_ACTIVE)->noticeTermsUnconfirmed(),
                'start_date'
            )->with(['property', 'agreementTerms']),
            $user,
            $scope,
            'property_id'
        )->with('tenants.contact')->get()->each(function (Lease $lease) use (&$items) {
            $held = $lease->agreementTerms?->notice_terms_source;
            $items->push([
                'type' => 'confirm_notice_terms',
                'urgency' => 3,
                'age_days' => 0,
                'item_date' => $lease->start_date,
                'property' => $lease->property,
                'lease' => $lease,
                'label' => 'Confirm notice terms',
                'detail' => ($held === 'agency_default' ? 'Filled from the agency defaults — check against the signed lease' : ($held ? 'Not yet confirmed against the signed lease' : 'No notice terms on record'))
                    . ' · Tenant: ' . $lease->tenantNames(),
                'route' => 'corex.leases.show',
                'route_params' => ['lease' => $lease->id],
            ]);
        });

        return match ($sort) {
            // Nulls (no date on the item) sort last regardless of direction
            // — same "unknown sorts last, never first" rule the table's own
            // default sort (§10.5) already uses for a vacant property.
            'date' => $items->sortBy(fn (array $i) => $i['item_date']
                ? \Illuminate\Support\Carbon::parse($i['item_date'])->timestamp
                : PHP_INT_MAX)->values(),
            'property' => $items->sortBy(fn (array $i) => $i['property']?->buildDisplayAddress() ?? "\u{10FFFF}")->values(),
            // 'urgency' — the EXISTING default, unchanged.
            default => $items->sortBy([
                ['urgency', 'asc'],
                ['age_days', 'desc'],
            ])->values(),
        };
    }

    /**
     * Needs-action queue — group-by control (2026-10-05 fix round).
     * 'none' returns $items unchanged (the existing flat ordering, kept as
     * the default). 'property' buckets items under the property they
     * belong to, so "what does THIS property need" reads as one list
     * rather than scattered rows. 'date' buckets by each item's own
     * item_date (see queueItems() doc) into "Overdue" / a real calendar
     * date / "No date", ascending, overdue first.
     */
    public function groupQueueItems(Collection $items, string $groupBy): Collection
    {
        if ($groupBy === 'property') {
            return $items->groupBy(fn (array $i) => $i['property']?->id ?? 0)
                ->map(function (Collection $group) {
                    $property = $group->first()['property'];

                    return [
                        // Round 6 (2026-10-05) — per-group collapse state (Johan)
                        // persists by this KEY, never by the display heading —
                        // a renamed/re-addressed property must not silently
                        // lose its remembered collapsed state, and two
                        // properties can legitimately share a heading string
                        // (buildDisplayAddress() isn't guaranteed unique).
                        'key' => 'property:' . ($property?->id ?? 0),
                        'heading' => $property?->buildDisplayAddress() ?? 'Unknown property',
                        'property' => $property,
                        'items' => $group->values(),
                    ];
                })
                ->sortBy('heading')
                ->values();
        }

        if ($groupBy === 'date') {
            $today = now()->startOfDay();

            return $items->groupBy(function (array $i) use ($today) {
                if (!$i['item_date']) {
                    return 'none';
                }

                return \Illuminate\Support\Carbon::parse($i['item_date'])->startOfDay()->lt($today)
                    ? 'overdue'
                    : \Illuminate\Support\Carbon::parse($i['item_date'])->toDateString();
            })->map(function (Collection $group, string $key) {
                return [
                    // Same reasoning as the property branch above — the
                    // bucket key ('overdue'/'none'/a real Y-m-d string) is
                    // already stable and locale-independent, unlike the
                    // formatted heading.
                    'key' => 'date:' . $key,
                    'heading' => match (true) {
                        $key === 'overdue' => 'Overdue',
                        $key === 'none' => 'No date',
                        default => \Illuminate\Support\Carbon::parse($key)->format('D, j M Y'),
                    },
                    // Sorts "Overdue" first, "No date" last, real dates in
                    // between in calendar order — plain string comparison
                    // works because real keys are already Y-m-d.
                    'sort_key' => match (true) {
                        $key === 'overdue' => '0000-00-00',
                        $key === 'none' => '9999-99-99',
                        default => $key,
                    },
                    'items' => $group->values(),
                ];
            })->sortBy('sort_key')->values();
        }

        return $items;
    }

    public function paginateCollection(Collection $items, int $perPage, int $page, string $pageName): LengthAwarePaginator
    {
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($slice, $items->count(), $perPage, $page, [
            'pageName' => $pageName,
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }
}
