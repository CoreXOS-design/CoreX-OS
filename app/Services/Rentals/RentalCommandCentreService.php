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
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_fault_reports rfr WHERE rfr.property_id = properties.id '
                . 'AND rfr.deleted_at IS NULL AND rfr.status NOT IN (?, ?, ?)) as open_faults_count',
                self::FAULT_OPEN_STATUSES_EXCLUDED
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM rental_work_orders rwo WHERE rwo.property_id = properties.id '
                . 'AND rwo.deleted_at IS NULL AND rwo.status NOT IN (?, ?)) as open_work_orders_count',
                self::WORK_ORDER_OPEN_STATUSES_EXCLUDED
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
     * §3.1 — all ten tiles, computed from ONE fetch of the scoped
     * property universe (not one query per tile, not one query per row).
     * For a given agency this is bounded by the agency's own rental book
     * size, which is exactly the "aggregate, not N+1" shape the ticket
     * asks for — see the controller's own query-count report for the
     * measured total.
     */
    public function tileCounts(User $user, string $scope): array
    {
        $agencyId = $user->effectiveAgencyId();
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);
        $today = now()->toDateString();
        $windowEnd = now()->addDays($windowDays)->toDateString();

        $rows = $this->derivedPropertyQuery($user, $scope)->get([
            'id',
            'active_lease_id',
            'active_end_date',
            'active_month_to_month',
            'active_notice_date',
            'pending_renewal_draft_count',
            'open_inspections_count',
            'active_lease_completed_in_inspections',
        ]);

        $counts = array_fill_keys(array_keys(self::TILES), 0);
        $counts['all'] = $rows->count();

        foreach ($rows as $row) {
            $occupied = $row->active_lease_id !== null;
            if ($occupied) {
                $counts['occupied']++;
            } else {
                $counts['unoccupied']++;
            }

            if ($occupied && $row->active_end_date && $row->active_end_date >= $today && $row->active_end_date <= $windowEnd) {
                $counts['expiring']++;
            }

            // AT-444 follow-up (2026-10-05) — leases.notice_date now exists
            // (was hardcoded 0 here before AT-444 shipped it). Mirrors
            // Lease::hasActiveNotice()'s own definition (notice_date !==
            // null) exactly — an active lease with notice on file.
            if ($occupied && $row->active_notice_date !== null) {
                $counts['notice_given']++;
            }

            if ($occupied && (int) $row->pending_renewal_draft_count > 0) {
                $counts['renewals_in_progress']++;
            }

            if ($occupied && !$row->active_end_date && $row->active_month_to_month) {
                $counts['month_to_month']++;
            }

            // "Inspections due" — a UNION (this property counted once),
            // never a sum of the two buckets, to avoid double-counting the
            // common case where a lease is both "has a scheduled, not yet
            // completed inspection" AND "has no completed in-inspection
            // yet" (the same tenancy, one action needed).
            $hasOpenInspection = (int) $row->open_inspections_count > 0;
            $missingCompletedInInspection = $occupied && (int) $row->active_lease_completed_in_inspections === 0;
            if ($hasOpenInspection || $missingCompletedInInspection) {
                $counts['inspections_due']++;
            }
        }

        // "Open faults"/"Open work orders" — the TOTAL count of open
        // records in scope (what Johan calls "the sum of the per-row
        // column"), never the count of PROPERTIES that have ≥1. Computed
        // as independent, direct counts against the same property-id
        // scope predicate every other source on this screen uses — not a
        // SUM() of the row-level column above, but mathematically
        // identical to one; a direct count is simpler to audit and avoids
        // re-deriving the open-status set in two places.
        $counts['open_faults'] = $this->applyPropertyIdScope(
            RentalFaultReport::query()->whereNotIn('status', self::FAULT_OPEN_STATUSES_EXCLUDED),
            $user,
            $scope,
            'property_id'
        )->count();

        $counts['open_work_orders'] = $this->applyPropertyIdScope(
            RentalWorkOrder::query()->whereNotIn('status', self::WORK_ORDER_OPEN_STATUSES_EXCLUDED),
            $user,
            $scope,
            'property_id'
        )->count();

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
                            ->where(function ($c) use ($search) {
                                $c->where('contacts.first_name', 'like', "%{$search}%")
                                    ->orWhere('contacts.last_name', 'like', "%{$search}%");
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

    public function applyTile(Builder $query, User $user, string $scope, ?string $tile): Builder
    {
        $agencyId = $user->effectiveAgencyId();
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);
        $today = now()->toDateString();
        $windowEnd = now()->addDays($windowDays)->toDateString();

        switch ($tile) {
            case 'occupied':
                $query->whereNotNull('active_lease_id');
                break;
            case 'unoccupied':
                $query->whereNull('active_lease_id');
                break;
            case 'expiring':
                $query->whereNotNull('active_lease_id')
                    ->whereBetween('active_end_date', [$today, $windowEnd]);
                break;
            case 'notice_given':
                // AT-444 follow-up (2026-10-05) — mirrors tileCounts()'s own
                // definition: occupied + Lease::hasActiveNotice() (notice_date
                // !== null) exactly, so the tile and this filter can't drift.
                $query->whereNotNull('active_lease_id')->whereNotNull('active_notice_date');
                break;
            case 'renewals_in_progress':
                $query->whereNotNull('active_lease_id')->where('pending_renewal_draft_count', '>', 0);
                break;
            case 'month_to_month':
                $query->whereNotNull('active_lease_id')->whereNull('active_end_date')->where('active_month_to_month', true);
                break;
            case 'open_faults':
                $query->where('open_faults_count', '>', 0);
                break;
            case 'open_work_orders':
                $query->where('open_work_orders_count', '>', 0);
                break;
            case 'inspections_due':
                $query->where(function (Builder $q) {
                    $q->where('open_inspections_count', '>', 0)
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('active_lease_id')->where('active_lease_completed_in_inspections', '=', 0);
                        });
                });
                break;
            default:
                break;
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

            // AT-444 follow-up 3 (2026-10-05) — §5: the scheduled
            // rentals:prepare-renewal-drafts command already drafted this
            // (Lease::hasPendingRenewalDraft() is the SAME definition the
            // "Renewals in progress" tile uses, so the two can never drift).
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
            // month-to-month) is never drafted by that command — querying
            // its eligibility here would be wasted work and could label a
            // lease that is not renewing at all as "missing info".
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

        // E — active lease with no completed in-inspection.
        $this->applyPropertyIdScope(
            $applyQueueFilters(
                Lease::query()->where('status', Lease::STATUS_ACTIVE)
                    ->whereNotExists(function ($sub) {
                        $sub->selectRaw(1)->from('rental_inspections')
                            ->whereColumn('rental_inspections.lease_id', 'leases.id')
                            ->whereNull('rental_inspections.deleted_at')
                            ->where('rental_inspections.type', RentalInspection::TYPE_IN)
                            ->where('rental_inspections.status', RentalInspection::STATUS_COMPLETED);
                    }),
                'start_date'
            )->with('property'),
            $user,
            $scope,
            'property_id'
        )->with('tenants.contact')->get()->each(function (Lease $lease) use (&$items, $today) {
            $items->push([
                'type' => 'start_inspection',
                'urgency' => 3,
                'age_days' => $lease->start_date ? (int) abs($today->diffInDays($lease->start_date)) : 0,
                'item_date' => $lease->start_date,
                'property' => $lease->property,
                'lease' => $lease,
                'label' => 'Start inspection',
                'detail' => 'Tenant: ' . $lease->tenantNames(),
                // AT-444/AT-441 follow-up (2026-10-05) — RentalInspectionController::
                // create() (cc1/AT-439) now pre-selects from lease_id/property_id
                // (landed on origin/QA1 the same day this follow-up was built).
                // Both passed: lease_id resolves via the controller's own
                // Lease::visibleTo() scoped lookup (preferred), property_id as
                // the fallback this queue already carried.
                'route' => 'corex.rental-inspections.create',
                'route_params' => ['property_id' => $lease->property_id, 'lease_id' => $lease->id],
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
