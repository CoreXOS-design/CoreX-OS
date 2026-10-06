<?php

namespace App\Services\Rentals;

use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rentals-reports.md — AT-443. One service, one method per report,
 * so the Blade screen, the print view, the PDF, the export, and the JSON API
 * all read from the SAME query/row-building logic and can never drift.
 *
 * Every report method returns a plain array:
 *   ['columns' => [key => label], 'rows' => Collection<array>, 'totals' => [key => number],
 *    'groupKey' => ?string, 'meta' => [...]]
 * rows carry a '_group' key when a group-by is active (blank string otherwise), already
 * sorted group-then-within-group, so the Blade/export layer never re-derives grouping.
 *
 * Scoping: every report reuses the SAME per-entity scopeVisibleTo() each entity's own list
 * screen already uses (Lease/RentalFaultReport/RentalWorkOrder/RentalInspection) — never a
 * re-derived predicate — except Lease Status, which the task brief requires to read
 * RentalCommandCentreService directly so the two screens can never disagree (AT-441).
 */
class RentalReportService
{
    // Property History is NOT listed here — it is a single-property timeline
    // (own screen/route, picks a property first), not a flat-grid report
    // dispatched by RentalReportController::runReport(). Listing it here
    // previously rendered a second, dead "Property history" entry in the
    // picker that silently fell back to the Fault Reports grid when clicked
    // (AT-443 tidy, 2026-10-05) — the real entry is the dedicated link to
    // corex.rentals.reports.property-history, rendered separately in the view.
    public const REPORTS = [
        'fault-reports' => 'Fault reports',
        'work-orders' => 'Work orders',
        // AT-442 landed on origin/QA1 partway through this build — per the task
        // brief's own condition ("build ONLY if AT-442 is on origin/QA1 when you
        // land"), this report is built, not deferred. See the spec's §0 update.
        'job-cards' => 'Job cards',
        'lease-status' => 'Lease status',
        'lease-expiries' => 'Lease expiries',
        'inspections' => 'Inspections',
    ];

    public const FAULT_BUCKETS = [
        // "every non-terminal status" per the task brief — reported, awaiting_approval,
        // approved, work_order_raised, owner_handling. Overlaps with the two named buckets
        // below on purpose: ticking "Outstanding" alone already includes awaiting-landlord
        // and approved rows; the two narrower buckets exist so a user can isolate just one
        // of those sub-states without also seeing "reported"/"work_order_raised"/"owner_handling".
        'outstanding' => ['label' => 'Outstanding', 'statuses' => [
            RentalFaultReport::STATUS_REPORTED,
            RentalFaultReport::STATUS_AWAITING_APPROVAL,
            RentalFaultReport::STATUS_APPROVED,
            RentalFaultReport::STATUS_WORK_ORDER_RAISED,
            RentalFaultReport::STATUS_OWNER_HANDLING,
        ]],
        'awaiting_landlord' => ['label' => 'Awaiting landlord', 'statuses' => [RentalFaultReport::STATUS_AWAITING_APPROVAL]],
        'approved' => ['label' => 'Approved', 'statuses' => [RentalFaultReport::STATUS_APPROVED]],
        'resolved' => ['label' => 'Resolved', 'statuses' => [RentalFaultReport::STATUS_RESOLVED]],
        'declined' => ['label' => 'Declined', 'statuses' => [RentalFaultReport::STATUS_DECLINED]],
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [RentalFaultReport::STATUS_CANCELLED]],
    ];

    public const WORK_ORDER_BUCKETS = [
        'outstanding' => ['label' => 'Outstanding', 'statuses' => [
            RentalWorkOrder::STATUS_REPORTED,
            RentalWorkOrder::STATUS_ORDERED,
            RentalWorkOrder::STATUS_IN_PROGRESS,
        ]],
        'in_progress' => ['label' => 'In progress', 'statuses' => [RentalWorkOrder::STATUS_IN_PROGRESS]],
        // "overdue" is not a status — it's computed (open + reported_at older than the
        // agency's own overdue_reminder_days setting, RentalWorkOrder::scopeOverdue()).
        'overdue' => ['label' => 'Overdue', 'statuses' => [], 'overdue' => true],
        'completed' => ['label' => 'Completed', 'statuses' => [RentalWorkOrder::STATUS_COMPLETED]],
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [RentalWorkOrder::STATUS_CANCELLED]],
    ];

    public const INSPECTION_BUCKETS = [
        // "due"/"overdue" are not raw statuses — RentalInspection has no due-date status,
        // only scheduled_for. Derived against today's date, open statuses only.
        'due' => ['label' => 'Due', 'derived' => 'due'],
        'overdue' => ['label' => 'Overdue', 'derived' => 'overdue'],
        'awaiting_signature' => ['label' => 'Awaiting signature', 'statuses' => [RentalInspection::STATUS_AWAITING_SIGNATURE]],
        'completed' => ['label' => 'Completed', 'statuses' => [RentalInspection::STATUS_COMPLETED]],
        // Not named in the task brief's tick list — added, default OFF, so the full status
        // enum stays individually tickable per BUILD_STANDARD §1b/the master spec's shared
        // shell ("every status individually tickable, not a single on/off toggle").
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [RentalInspection::STATUS_CANCELLED], 'default_off' => true],
    ];

    public const JOB_CARD_BUCKETS = [
        'outstanding' => ['label' => 'Outstanding', 'statuses' => [
            RentalJobCard::STATUS_DRAFT,
            RentalJobCard::STATUS_QUOTED,
            RentalJobCard::STATUS_APPROVED,
            RentalJobCard::STATUS_SCHEDULED,
            RentalJobCard::STATUS_IN_PROGRESS,
        ]],
        'in_progress' => ['label' => 'In progress', 'statuses' => [RentalJobCard::STATUS_IN_PROGRESS]],
        // RentalJobCard::scopeOverdue() — same "open + past due" convention as
        // RentalWorkOrder::scopeOverdue(), reused rather than re-derived.
        'overdue' => ['label' => 'Overdue', 'statuses' => [], 'overdue' => true],
        'completed' => ['label' => 'Completed', 'statuses' => [RentalJobCard::STATUS_COMPLETED]],
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [RentalJobCard::STATUS_CANCELLED]],
    ];

    // ───────────────────────── Scope (shared shell §1.1 / brief: "Own | Branch | All") ─────────────────────────

    public function scopeOptionsFor(User $user): array
    {
        $maxScope = PermissionService::getDataScope($user, 'rental_reports');

        return match ($maxScope) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            'own' => ['own'],
            default => [],
        };
    }

    public function resolveScope(User $user, ?string $requested): string
    {
        $maxScope = PermissionService::getDataScope($user, 'rental_reports');

        return PermissionService::clampScope($requested, $maxScope ?? 'own');
    }

    /**
     * §9 of the spec — "the report's scope control can only narrow, never widen, past the
     * user's real ceiling" on the underlying entity. A user whose rental_fault_reports.view
     * ceiling is 'own' sees only 'own' fault-report rows in this report regardless of what
     * they pick in the report's OWN scope control — so every report clamps a second time,
     * against the owning entity's OWN permission module, not just 'rental_reports'.
     */
    private function clampToEntityCeiling(User $user, string $requestedReportScope, string $entityModule): string
    {
        $entityCeiling = PermissionService::getDataScope($user, $entityModule) ?? 'own';

        return PermissionService::clampScope($requestedReportScope, $entityCeiling);
    }

    // ───────────────────────── Period resolution ─────────────────────────

    /** Backward-looking presets (reported_at/raised_at style date fields). */
    public function resolvePeriod(array $params): array
    {
        $preset = $params['period'] ?? 'this_month';
        $today = Carbon::today();

        return match ($preset) {
            'last_month' => ['from' => $today->copy()->subMonthNoOverflow()->startOfMonth(), 'to' => $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'last_30' => ['from' => $today->copy()->subDays(30), 'to' => $today->copy()],
            'last_60' => ['from' => $today->copy()->subDays(60), 'to' => $today->copy()],
            'last_90' => ['from' => $today->copy()->subDays(90), 'to' => $today->copy()],
            'this_year' => ['from' => $today->copy()->startOfYear(), 'to' => $today->copy()->endOfYear()],
            'custom' => [
                'from' => !empty($params['date_from']) ? Carbon::parse($params['date_from'])->startOfDay() : null,
                'to' => !empty($params['date_to']) ? Carbon::parse($params['date_to'])->endOfDay() : null,
            ],
            'any' => ['from' => null, 'to' => null],
            default => ['from' => $today->copy()->startOfMonth(), 'to' => $today->copy()->endOfMonth()],
        };
    }

    /** Forward-looking presets — Lease Expiries' own period control (end_date is in the future). */
    public function resolveForwardPeriod(array $params): array
    {
        $preset = $params['period'] ?? 'next_30';
        $today = Carbon::today();

        return match ($preset) {
            'next_60' => ['from' => $today->copy(), 'to' => $today->copy()->addDays(60)],
            'next_90' => ['from' => $today->copy(), 'to' => $today->copy()->addDays(90)],
            'this_month' => ['from' => $today->copy(), 'to' => $today->copy()->endOfMonth()],
            'custom' => [
                'from' => !empty($params['date_from']) ? Carbon::parse($params['date_from'])->startOfDay() : $today->copy(),
                'to' => !empty($params['date_to']) ? Carbon::parse($params['date_to'])->endOfDay() : null,
            ],
            'any' => ['from' => null, 'to' => null],
            default => ['from' => $today->copy(), 'to' => $today->copy()->addDays(30)],
        };
    }

    /** Resolves ticked bucket keys to the underlying raw status list (union). Empty selection = every bucket (sensible default: show everything in the period). */
    public function resolveBucketStatuses(array $buckets, array $selectedKeys): array
    {
        $selected = empty($selectedKeys) ? array_keys($buckets) : array_intersect($selectedKeys, array_keys($buckets));
        $statuses = [];
        foreach ($selected as $key) {
            $statuses = array_merge($statuses, $buckets[$key]['statuses'] ?? []);
        }

        return array_values(array_unique($statuses));
    }

    public function defaultBucketKeys(array $buckets): array
    {
        return array_keys(array_filter($buckets, fn ($b) => empty($b['default_off'])));
    }

    // ───────────────────────── 4.1 Fault reports ─────────────────────────

    public function faultReports(User $user, array $params): array
    {
        $reportScope = $this->resolveScope($user, $params['scope'] ?? null);
        $scope = $this->clampToEntityCeiling($user, $reportScope, 'rental_fault_reports');
        $period = $this->resolvePeriod($params);
        $selectedBuckets = $params['buckets'] ?? [];
        $hasOverdue = false; // no 'overdue' bucket on this report

        $query = RentalFaultReport::query()
            ->visibleTo($user, $scope)
            ->with(['property', 'lease.tenants.contact', 'faultType', 'workOrder']);

        if ($period['from']) {
            $query->where('reported_at', '>=', $period['from']);
        }
        if ($period['to']) {
            $query->where('reported_at', '<=', $period['to']);
        }

        $statuses = $this->resolveBucketStatuses(self::FAULT_BUCKETS, $selectedBuckets);
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

        if ($propertyId = $params['property_id'] ?? null) {
            $query->where('property_id', $propertyId);
        }

        if ($search = trim((string) ($params['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('faultType', fn ($f) => $f->where('name', 'like', "%{$search}%"))
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $reports = $query->get();

        $groupBy = $params['group_by'] ?? null;
        $groupLabel = match ($groupBy) {
            'property' => fn (RentalFaultReport $r) => $r->property?->buildDisplayAddress() ?? 'Unknown property',
            'landlord' => fn (RentalFaultReport $r) => $r->property?->sellerOwnerContact()?->full_name ?? 'No landlord on record',
            'agent' => fn (RentalFaultReport $r) => $r->property?->agent?->name ?? 'Unassigned',
            'fault_type' => fn (RentalFaultReport $r) => $r->faultType?->name ?? 'Unspecified',
            default => null,
        };

        $rows = $reports->map(function (RentalFaultReport $r) {
            $daysOpen = $r->reported_at
                ? ($r->resolved_at ?? $r->cancelled_at ?? now())->diffInDays($r->reported_at)
                : null;

            return [
                '_model' => $r,
                'date_reported' => optional($r->reported_at)->format('Y-m-d'),
                'property' => $r->property?->buildDisplayAddress() ?? '—',
                'tenant' => $r->lease?->tenants->map(fn ($t) => $t->contact?->full_name)->filter()->implode(', ') ?: '—',
                'fault' => $r->faultType?->name ?? $r->title,
                // RentalFaultType carries the urgency, not the fault report itself.
                'urgency' => $r->faultType?->urgency ? ucfirst($r->faultType->urgency) : '—',
                'status' => $this->humanize($r->status),
                'outcome' => $r->outcome ? $this->humanize($r->outcome) : '—',
                'days_open' => $daysOpen,
                'linked_work_order' => $r->rental_work_order_id ? ('WO-' . $r->rental_work_order_id) : '—',
                'linked_work_order_id' => $r->rental_work_order_id,
            ];
        });

        $sort = $params['sort'] ?? 'date_reported';
        $direction = ($params['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $this->sortRows($rows, $sort, $direction, 'date_reported');

        [$rows, $groups] = $this->applyGrouping($rows, $groupBy, $groupLabel);

        return [
            'columns' => [
                'date_reported' => 'Date reported', 'property' => 'Property', 'tenant' => 'Tenant',
                'fault' => 'Fault', 'urgency' => 'Urgency', 'status' => 'Status', 'outcome' => 'Outcome',
                'days_open' => 'Days open', 'linked_work_order' => 'Linked work order',
            ],
            'sumKeys' => ['days_open'],
            'rows' => $rows,
            'groups' => $groups,
            'count' => $reports->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => self::FAULT_BUCKETS,
            'selectedBuckets' => empty($selectedBuckets) ? $this->defaultBucketKeys(self::FAULT_BUCKETS) : $selectedBuckets,
        ];
    }

    // ───────────────────────── 4.2 Work orders ─────────────────────────

    public function workOrders(User $user, array $params): array
    {
        $reportScope = $this->resolveScope($user, $params['scope'] ?? null);
        $scope = $this->clampToEntityCeiling($user, $reportScope, 'rental_work_orders');
        $period = $this->resolvePeriod($params);
        $selectedBuckets = $params['buckets'] ?? [];
        $overdueDays = RentalWorkOrderSetting::overdueReminderDaysFor($user->effectiveAgencyId());

        $query = RentalWorkOrder::query()
            ->visibleTo($user, $scope)
            ->with(['property', 'lease.tenants.contact', 'supplier', 'quotes']);

        if ($period['from']) {
            $query->where('reported_at', '>=', $period['from']);
        }
        if ($period['to']) {
            $query->where('reported_at', '<=', $period['to']);
        }

        $selected = empty($selectedBuckets) ? array_keys(self::WORK_ORDER_BUCKETS) : array_intersect($selectedBuckets, array_keys(self::WORK_ORDER_BUCKETS));
        $wantsOverdue = in_array('overdue', $selected, true);
        $nonOverdueKeys = array_values(array_diff($selected, ['overdue']));
        // NOT resolveBucketStatuses() here — that method treats an empty
        // selection as "default to every bucket," which is correct for the
        // top-level "nothing ticked" case but wrong here: $nonOverdueKeys
        // can legitimately be empty when "Overdue" is the ONLY bucket
        // ticked, and defaulting to "every status" in that case would
        // silently widen the filter back to everything.
        $statuses = [];
        foreach ($nonOverdueKeys as $key) {
            $statuses = array_merge($statuses, self::WORK_ORDER_BUCKETS[$key]['statuses'] ?? []);
        }
        $statuses = array_values(array_unique($statuses));

        if (count($selected) < count(self::WORK_ORDER_BUCKETS)) {
            // At least one bucket was deliberately excluded — build the OR across whatever remains selected.
            $query->where(function (Builder $q) use ($statuses, $wantsOverdue, $overdueDays) {
                if ($statuses) {
                    $q->orWhereIn('status', $statuses);
                }
                if ($wantsOverdue) {
                    $q->orWhere(fn ($qq) => $qq->overdue($overdueDays));
                }
                if (!$statuses && !$wantsOverdue) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        // "done-by" (supplier vs own team) — AT-442 landed mid-build, so this is now
        // built per the brief's own condition. A work order is "own team" when it has
        // a linked RentalJobCard (1:1, rental_work_order_id); "supplier" when it has
        // agency_service_provider_id set directly. The two are mutually exclusive in
        // practice (a job card IS the own-team path, replacing the supplier field).
        if ($doneBy = $params['done_by'] ?? null) {
            if ($doneBy === 'own_team') {
                $query->whereHas('jobCard');
            } elseif ($doneBy === 'supplier') {
                $query->whereNotNull('agency_service_provider_id');
            }
        }
        if ($supplierId = $params['supplier_id'] ?? null) {
            $query->where('agency_service_provider_id', $supplierId);
        }
        if ($tradeType = $params['trade_type'] ?? null) {
            $query->where('trade_type', $tradeType);
        }
        if ($propertyId = $params['property_id'] ?? null) {
            $query->where('property_id', $propertyId);
        }

        if ($search = trim((string) ($params['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('lease.tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $orders = $query->get();

        $groupLabel = match ($params['group_by'] ?? null) {
            'property' => fn (RentalWorkOrder $w) => $w->property?->buildDisplayAddress() ?? 'Unknown property',
            'supplier' => fn (RentalWorkOrder $w) => $w->supplier?->name ?? 'No supplier assigned',
            'trade' => fn (RentalWorkOrder $w) => $w->trade_type ? ucfirst($w->trade_type) : 'Unspecified trade',
            default => null,
        };

        $rows = $orders->map(function (RentalWorkOrder $w) {
            $selectedQuote = $w->quotes->firstWhere('is_selected', true);
            $amount = $selectedQuote?->amount ?? $w->cost_amount;
            $daysOpen = $w->reported_at
                ? ($w->completed_at ?? $w->cancelled_at ?? now())->diffInDays($w->reported_at)
                : null;

            return [
                '_model' => $w,
                'date_raised' => optional($w->reported_at)->format('Y-m-d'),
                'property' => $w->property?->buildDisplayAddress() ?? '—',
                'supplier' => $w->supplier?->name ?? '—',
                'trade' => $w->trade_type ? ucfirst($w->trade_type) : '—',
                'quoted_amount' => $amount !== null ? (float) $amount : null,
                'status' => $this->humanize($w->status),
                'paid_by' => $w->paid_by ? $this->humanize($w->paid_by) : '—',
                'days_open' => $daysOpen,
            ];
        });

        $sort = $params['sort'] ?? 'date_raised';
        $direction = ($params['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $this->sortRows($rows, $sort, $direction, 'date_raised');

        [$rows, $groups] = $this->applyGrouping($rows, $params['group_by'] ?? null, $groupLabel);

        return [
            'columns' => [
                'date_raised' => 'Date raised', 'property' => 'Property', 'supplier' => 'Supplier',
                'trade' => 'Trade', 'quoted_amount' => 'Quoted amount', 'status' => 'Status',
                'paid_by' => 'Paid by', 'days_open' => 'Days open',
            ],
            'sumKeys' => ['quoted_amount', 'days_open'],
            'rows' => $rows,
            'groups' => $groups,
            'count' => $orders->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => self::WORK_ORDER_BUCKETS,
            'selectedBuckets' => empty($selectedBuckets) ? $this->defaultBucketKeys(self::WORK_ORDER_BUCKETS) : $selectedBuckets,
        ];
    }

    // ───────────────────────── 4.3 Job cards for a period (AT-442, landed mid-build) ─────────────────────────

    /**
     * One row per job card (same granularity as every other report here —
     * the spec's own "by labour/part item" grouping would need one row per
     * LINE instead, which doesn't fit this report's per-record column shape
     * (date/property/crew/total cost); not built, reported as a deviation).
     * "Labour hours"/"parts used" are aggregates over the job card's own
     * lines, computed via eager-loaded relations (bounded by this one
     * job card's own line count, not a correlated subquery per row — the
     * line counts here are small, matching RentalWorkOrder's own quotes
     * eager-load precedent elsewhere in this service).
     */
    public function jobCards(User $user, array $params): array
    {
        $reportScope = $this->resolveScope($user, $params['scope'] ?? null);
        $scope = $this->clampToEntityCeiling($user, $reportScope, 'rental_job_cards');
        $period = $this->resolvePeriod($params);
        $selectedBuckets = $params['buckets'] ?? [];

        $query = RentalJobCard::query()
            ->visibleTo($user, $scope)
            ->with(['property', 'assignedUser', 'lines.vatType']);

        if ($period['from']) {
            $query->where(fn ($q) => $q->where('scheduled_at', '>=', $period['from'])->orWhere('completed_at', '>=', $period['from']));
        }
        if ($period['to']) {
            $query->where(fn ($q) => $q->where('scheduled_at', '<=', $period['to'])->orWhere('completed_at', '<=', $period['to']));
        }

        $selected = empty($selectedBuckets) ? array_keys(self::JOB_CARD_BUCKETS) : array_intersect($selectedBuckets, array_keys(self::JOB_CARD_BUCKETS));
        $wantsOverdue = in_array('overdue', $selected, true);
        $nonOverdueKeys = array_values(array_diff($selected, ['overdue']));
        $statuses = [];
        foreach ($nonOverdueKeys as $key) {
            $statuses = array_merge($statuses, self::JOB_CARD_BUCKETS[$key]['statuses'] ?? []);
        }
        $statuses = array_values(array_unique($statuses));

        if (count($selected) < count(self::JOB_CARD_BUCKETS)) {
            $query->where(function (Builder $q) use ($statuses, $wantsOverdue) {
                $any = false;
                if ($statuses) {
                    $q->orWhereIn('status', $statuses);
                    $any = true;
                }
                if ($wantsOverdue) {
                    $q->orWhere(fn ($qq) => $qq->overdue());
                    $any = true;
                }
                if (!$any) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        if ($propertyId = $params['property_id'] ?? null) {
            $query->where('property_id', $propertyId);
        }
        if ($crewUserId = $params['crew_user_id'] ?? null) {
            $query->where('assigned_user_id', $crewUserId);
        }

        if ($search = trim((string) ($params['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('assignedUser', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        $cards = $query->get();

        // Agency VAT set-up — one agency per report run (the user's own),
        // so this resolves once rather than per row.
        $agency = \App\Models\Agency::withoutGlobalScopes()->find($user->effectiveAgencyId());
        $vatRegistered = (bool) $agency?->vat_registered;
        $vatService = $vatRegistered ? app(RentalJobCardVatService::class) : null;

        $groupLabel = match ($params['group_by'] ?? null) {
            'crew' => fn (RentalJobCard $c) => $c->assignedUser?->name ?? 'Unassigned',
            'property' => fn (RentalJobCard $c) => $c->property?->buildDisplayAddress() ?? 'Unknown property',
            default => null,
        };

        // §17.17 — cost, margin and the "lines without cost" count exist ONLY for a user who may see them; without
        // `rental_job_cards.view_costs` those columns are absent from the report entirely (so from every export/print variant).
        $canViewCosts = $user->hasPermission('rental_job_cards.view_costs');
        $pricing = $canViewCosts ? app(RentalPricingService::class) : null;
        $costVat = $canViewCosts ? ($vatService ?? app(RentalJobCardVatService::class)) : null;

        $rows = $cards->map(function (RentalJobCard $c) use ($vatService, $vatRegistered, $canViewCosts, $pricing, $costVat) {
            // §17.4.6 — a crew's pending line is not work done and not money: ACCEPTED lines only.
            $lines = $c->lines->filter(fn ($l) => $l->isAccepted());
            $labourHours = (float) $lines->where('type', \App\Models\RentalCatalogueItem::TYPE_LABOUR)->sum('quantity');
            $partsUsed = $lines->where('type', \App\Models\RentalCatalogueItem::TYPE_PART)
                ->map(fn ($l) => $l->description . ($l->quantity ? " ({$l->quantity})" : ''))
                ->implode(', ');

            $row = [
                '_model' => $c,
                'date' => optional($c->scheduled_at ?? $c->completed_at)->format('Y-m-d'),
                'property' => $c->property?->buildDisplayAddress() ?? '—',
                'crew_member' => $c->assignedUser?->name ?? '—',
                'labour_hours' => $labourHours > 0 ? $labourHours : null,
                'parts_used' => $partsUsed !== '' ? $partsUsed : '—',
                'status' => $this->humanize($c->status),
                // "never a forced zero" (spec §4.3) — null (not 0) when the
                // agency hasn't priced this card yet, so the Blade/export
                // layer renders it blank rather than "R 0.00".
                // §17.17 — this is SELLING (what the owner is charged), formerly mislabelled "Total cost". The agency's own cost is
                // `total_cost` below, view_costs only.
                'total_selling' => $c->total_amount !== null ? (float) $c->total_amount : null,
            ];

            if ($canViewCosts) {
                $m = $pricing->marginFor($c);
                $hasCost = $m['marginableLines'] > 0 || ($costVat->costBreakdown($c)['costedLines'] ?? 0) > 0;
                $row['total_cost'] = $hasCost ? $m['costExcl'] : null;
                $row['total_margin'] = $m['marginableLines'] > 0 ? $m['marginExcl'] : null;
                $row['margin_pct'] = $m['marginPct'] !== null ? number_format($m['marginPct'], 1) . ' %' : '—';
                $row['lines_without_cost'] = $m['linesWithoutCost'];
            }

            if ($vatRegistered) {
                $breakdown = $vatService->breakdown($c);
                $row['total_excl'] = $breakdown['registered'] ? (float) $breakdown['subtotalExcl'] : null;
                $row['total_vat'] = $breakdown['registered'] ? (float) $breakdown['totalVat'] : null;
                $row['total_incl'] = $breakdown['registered'] ? (float) $breakdown['totalIncl'] : null;
            }

            return $row;
        });

        $sort = $params['sort'] ?? 'date';
        $direction = ($params['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $this->sortRows($rows, $sort, $direction, 'date');

        [$rows, $groups] = $this->applyGrouping($rows, $params['group_by'] ?? null, $groupLabel);

        $columns = [
            'date' => 'Date', 'property' => 'Property', 'crew_member' => 'Crew member',
            'labour_hours' => 'Labour hours', 'parts_used' => 'Parts used', 'status' => 'Status',
        ];
        $sumKeys = ['labour_hours'];
        if ($vatRegistered) {
            // For a VAT-registered agency the three VAT columns ARE the selling price; no separate "as captured" column.
            $columns += ['total_excl' => 'Selling (excl VAT)', 'total_vat' => 'VAT', 'total_incl' => 'Selling (incl VAT)'];
            $sumKeys = array_merge($sumKeys, ['total_excl', 'total_vat', 'total_incl']);
        } else {
            $columns += ['total_selling' => 'Selling'];
            $sumKeys[] = 'total_selling';
        }
        if ($canViewCosts) {
            $columns += [
                'total_cost' => $vatRegistered ? 'Cost (excl VAT)' : 'Cost',
                'total_margin' => 'Margin (R, excl VAT)',
                'margin_pct' => 'Margin %',
                'lines_without_cost' => 'Lines without cost',
            ];
            $sumKeys = array_merge($sumKeys, ['total_cost', 'total_margin', 'lines_without_cost']);
        }

        return [
            'columns' => $columns,
            'sumKeys' => $sumKeys,
            'rows' => $rows,
            'groups' => $groups,
            'count' => $cards->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => self::JOB_CARD_BUCKETS,
            'selectedBuckets' => empty($selectedBuckets) ? $this->defaultBucketKeys(self::JOB_CARD_BUCKETS) : $selectedBuckets,
        ];
    }

    // ───────────────────────── 4.4 Lease status — delegates to RentalCommandCentreService ─────────────────────────

    /**
     * Per the task brief: "Use the SAME definitions as RentalCommandCentreService — call its
     * service/scopes rather than re-deriving." One row per rental PROPERTY (matching the
     * command centre's own property-first model, not a per-lease row) — the report and the
     * command centre read the identical derived query, so they can never disagree.
     */
    public function leaseStatus(User $user, array $params, RentalCommandCentreService $cc): array
    {
        $scope = $cc->resolveScope($user, $params['scope'] ?? null);
        $tileFilter = $params['buckets'][0] ?? null; // single-select tile, same as the command centre's own ?tile=

        $query = $cc->tableQuery($user, $scope, [
            'q' => $params['q'] ?? null,
            'status' => null,
            'agent_id' => $params['agent_id'] ?? null,
            'branch_id' => $params['branch_id'] ?? null,
            'date_from' => null,
            'date_to' => null,
            'tile' => in_array($tileFilter, array_keys(RentalCommandCentreService::TILES), true) ? $tileFilter : null,
        ]);

        $properties = $query->with('agent')->get();

        $leaseIds = $properties->pluck('active_lease_id')->filter()->unique()->values();
        $tenantNames = $leaseIds->isEmpty() ? collect() : DB::table('lease_tenants')
            ->join('contacts', 'contacts.id', '=', 'lease_tenants.contact_id')
            ->whereIn('lease_tenants.lease_id', $leaseIds)
            ->selectRaw("lease_tenants.lease_id, GROUP_CONCAT(DISTINCT TRIM(CONCAT(contacts.first_name, ' ', COALESCE(contacts.last_name, ''))) SEPARATOR ', ') as names")
            ->groupBy('lease_tenants.lease_id')
            ->pluck('names', 'lease_id');

        $today = now()->toDateString();

        $groupLabel = match ($params['group_by'] ?? null) {
            'branch' => fn ($p) => Branch::find($p->branch_id)?->name ?? 'No branch',
            'agent' => fn ($p) => $p->agent?->name ?? 'Unassigned',
            default => null,
        };

        $rows = $properties->map(function ($p) use ($tenantNames, $today) {
            $occupied = $p->active_lease_id !== null;
            $bucket = !$occupied ? 'Unoccupied'
                : ($p->active_end_date && $p->active_end_date >= $today && $p->active_month_to_month ? 'Occupied'
                    : (!$p->active_end_date && $p->active_month_to_month ? 'Month-to-month' : 'Occupied'));

            $daysLeft = ($occupied && $p->active_end_date) ? Carbon::today()->diffInDays(Carbon::parse($p->active_end_date), false) : null;

            return [
                '_model' => $p,
                'property' => $p->buildDisplayAddress(),
                'tenant' => $p->active_lease_id ? ($tenantNames[$p->active_lease_id] ?? '—') : '—',
                'status_bucket' => $bucket,
                'rent' => $p->active_rental_amount !== null ? (float) $p->active_rental_amount : null,
                // active_start_date/active_end_date are raw SELECT-aliased
                // strings, not Carbon instances (Property::$casts has no
                // entry for these alias names) — optional($string)->format()
                // silently returns null for ANY non-null value, since
                // Optional::__call only forwards to is_object($value). That
                // made Term/Lease end print "open"/"—" even when a real end
                // date existed, while Days left (below, parsed directly with
                // Carbon::parse()) was already correct. Fixed here by parsing
                // before formatting (AT-443 tidy, 2026-10-05).
                'term' => $p->active_lease_id
                    ? ($p->active_month_to_month ? 'Month-to-month' : (($p->active_start_date ? Carbon::parse($p->active_start_date)->format('Y-m-d') : '—') . ' – ' . ($p->active_end_date ? Carbon::parse($p->active_end_date)->format('Y-m-d') : 'open')))
                    : '—',
                'lease_end' => $p->active_end_date ? Carbon::parse($p->active_end_date)->format('Y-m-d') : '—',
                'days_left' => $daysLeft,
            ];
        });

        $sort = $params['sort'] ?? 'property';
        $direction = ($params['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $rows = $this->sortRows($rows, $sort, $direction, 'property');

        [$rows, $groups] = $this->applyGrouping($rows, $params['group_by'] ?? null, $groupLabel);

        return [
            'columns' => [
                'property' => 'Property', 'tenant' => 'Tenant', 'status_bucket' => 'Status',
                'rent' => 'Rent', 'term' => 'Term', 'lease_end' => 'Lease end', 'days_left' => 'Days left',
            ],
            'sumKeys' => ['rent'],
            'rows' => $rows,
            'groups' => $groups,
            'count' => $properties->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => RentalCommandCentreService::TILES,
            // No ?buckets[]= in the request leaves $tileFilter null, which
            // applyTile() already treats as "no filter" (its switch's default
            // case, same as an explicit 'all') — i.e. every property is shown,
            // same as the "Rental properties" tile. The tick must say so: it
            // previously rendered every tile unticked on first load while the
            // full, unfiltered list was what was actually on screen (AT-443
            // tidy, 2026-10-05).
            'selectedBuckets' => [$tileFilter ?? 'all'],
        ];
    }

    // ───────────────────────── 4.5 Lease expiries ─────────────────────────

    public function leaseExpiries(User $user, array $params): array
    {
        $reportScope = $this->resolveScope($user, $params['scope'] ?? null);
        $scope = $this->clampToEntityCeiling($user, $reportScope, 'leases');
        $period = $this->resolveForwardPeriod($params);

        $query = Lease::query()
            ->visibleTo($user, $scope)
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->with(['property', 'tenants.contact']);

        if ($period['from']) {
            $query->where('end_date', '>=', $period['from']->toDateString());
        }
        if ($period['to']) {
            $query->where('end_date', '<=', $period['to']->toDateString());
        }

        if ($search = trim((string) ($params['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('tenants.contact', fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $leases = $query->get();

        $groupLabel = match ($params['group_by'] ?? null) {
            'month' => fn (Lease $l) => optional($l->end_date)->format('Y-m') ?: 'Unknown',
            default => null,
        };

        $today = Carbon::today();
        $rows = $leases->map(function (Lease $l) use ($today) {
            return [
                '_model' => $l,
                'property' => $l->property?->buildDisplayAddress() ?? '—',
                'tenant' => $l->tenants->map(fn ($t) => $t->contact?->full_name)->filter()->implode(', ') ?: '—',
                'landlord' => $l->property?->sellerOwnerContact()?->full_name ?? '—',
                'rent' => $l->rental_amount !== null ? (float) $l->rental_amount : null,
                'end_date' => optional($l->end_date)->format('Y-m-d'),
                'days_left' => $today->diffInDays($l->end_date, false),
            ];
        });

        $sort = $params['sort'] ?? 'end_date';
        $direction = ($params['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $rows = $this->sortRows($rows, $sort, $direction, 'end_date');

        [$rows, $groups] = $this->applyGrouping($rows, $params['group_by'] ?? null, $groupLabel);

        return [
            'columns' => [
                'property' => 'Property', 'tenant' => 'Tenant', 'landlord' => 'Landlord',
                'rent' => 'Rent', 'end_date' => 'End date', 'days_left' => 'Days left',
            ],
            'sumKeys' => ['rent'],
            'rows' => $rows,
            'groups' => $groups,
            'count' => $leases->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => [],
            'selectedBuckets' => [],
        ];
    }

    // ───────────────────────── 4.6 Inspections ─────────────────────────

    public function inspections(User $user, array $params): array
    {
        $reportScope = $this->resolveScope($user, $params['scope'] ?? null);
        $scope = $this->clampToEntityCeiling($user, $reportScope, 'rental_inspections');
        $period = $this->resolvePeriod($params);
        $selectedBuckets = $params['buckets'] ?? [];
        $today = Carbon::today()->toDateString();

        $query = RentalInspection::query()
            ->visibleTo($user, $scope)
            ->with(['property', 'lease.tenants.contact', 'createdBy'])
            ->withCount([]);

        // Each inspection's "signed date(s)" — one aggregate subselect, no N+1.
        $query->addSelect('rental_inspections.*')
            ->selectSub(
                DB::table('rental_inspection_signatures')
                    ->selectRaw('MAX(disposition_recorded_at)')
                    ->whereColumn('rental_inspection_id', 'rental_inspections.id')
                    ->where('disposition', 'signed')
                    ->whereNull('superseded_at'),
                'last_signed_at'
            );

        if ($period['from']) {
            $query->where(fn ($q) => $q->where('scheduled_for', '>=', $period['from'])->orWhere('completed_at', '>=', $period['from']));
        }
        if ($period['to']) {
            $query->where(fn ($q) => $q->where('scheduled_for', '<=', $period['to'])->orWhere('completed_at', '<=', $period['to']));
        }

        $selected = empty($selectedBuckets) ? $this->defaultBucketKeys(self::INSPECTION_BUCKETS) : $selectedBuckets;
        $openStatuses = [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS, RentalInspection::STATUS_AWAITING_SIGNATURE];

        $query->where(function (Builder $q) use ($selected, $today) {
            $any = false;
            if (in_array('due', $selected, true)) {
                $q->orWhere(fn ($qq) => $qq->whereIn('status', [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS])->where('scheduled_for', '>=', $today));
                $any = true;
            }
            if (in_array('overdue', $selected, true)) {
                $q->orWhere(fn ($qq) => $qq->whereIn('status', [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS])->where('scheduled_for', '<', $today));
                $any = true;
            }
            if (in_array('awaiting_signature', $selected, true)) {
                $q->orWhere('status', RentalInspection::STATUS_AWAITING_SIGNATURE);
                $any = true;
            }
            if (in_array('completed', $selected, true)) {
                $q->orWhere('status', RentalInspection::STATUS_COMPLETED);
                $any = true;
            }
            if (in_array('cancelled', $selected, true)) {
                $q->orWhere('status', RentalInspection::STATUS_CANCELLED);
                $any = true;
            }
            if (!$any) {
                $q->whereRaw('1 = 0');
            }
        });

        if ($type = $params['type'] ?? null) {
            $query->where('type', $type);
        }

        if ($search = trim((string) ($params['q'] ?? ''))) {
            $query->whereHas('property', fn ($p) => $p->searchAddress($search));
        }

        $items = $query->get();

        $groupLabel = match ($params['group_by'] ?? null) {
            'property' => fn (RentalInspection $i) => $i->property?->buildDisplayAddress() ?? 'Unknown property',
            'type' => fn (RentalInspection $i) => ucfirst(str_replace('_', ' ', $i->type)),
            'agent' => fn (RentalInspection $i) => $i->createdBy?->name ?? 'Unassigned',
            default => null,
        };

        $rows = $items->map(function (RentalInspection $i) use ($today) {
            $isOverdue = in_array($i->status, [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS], true)
                && $i->scheduled_for && $i->scheduled_for->toDateString() < $today;

            return [
                '_model' => $i,
                'property' => $i->property?->buildDisplayAddress() ?? '—',
                'lease' => $i->lease_id ? ('Lease #' . $i->lease_id) : '—',
                'type' => ucfirst(str_replace('_', ' ', $i->type)),
                'scheduled_for' => optional($i->scheduled_for)->format('Y-m-d') ?: '—',
                'status' => $isOverdue ? 'Overdue' : $this->humanize($i->status),
                'signed_date' => $i->last_signed_at ? Carbon::parse($i->last_signed_at)->format('Y-m-d') : '—',
            ];
        });

        $sort = $params['sort'] ?? 'scheduled_for';
        $direction = ($params['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $this->sortRows($rows, $sort, $direction, 'scheduled_for');

        [$rows, $groups] = $this->applyGrouping($rows, $params['group_by'] ?? null, $groupLabel);

        return [
            'columns' => [
                'property' => 'Property', 'lease' => 'Lease', 'type' => 'Type',
                'scheduled_for' => 'Due / scheduled date', 'status' => 'Status', 'signed_date' => 'Signed date',
            ],
            'sumKeys' => [],
            'rows' => $rows,
            'groups' => $groups,
            'count' => $items->count(),
            'sort' => $sort,
            'direction' => $direction,
            'buckets' => self::INSPECTION_BUCKETS,
            'selectedBuckets' => $selected,
        ];
    }

    // ───────────────────────── 4.7 Property history (brief: "pick a property", whole history) ─────────────────────────

    /**
     * Deliberately a single-property timeline, per the task brief — this DIFFERS from the
     * original rentals-reports.md §4.7 wording ("covering every property in the selected
     * scope/period at once"); the brief explicitly wins and this deviation is reported.
     * Grouped by lease (chronological), each lease's own faults/work-orders/inspections
     * nested inside its span, plus a trailing "Between tenancies" group for property-level
     * (lease_id = null) fault/work-order rows — inspections are never property-level
     * (rental_inspections.lease_id is a required FK).
     */
    public function propertyHistory(User $user, Property $property): array
    {
        $leases = Lease::query()->withTrashed()
            ->where('property_id', $property->id)
            ->with(['tenants.contact'])
            ->orderByDesc('start_date')
            ->get();

        $faults = RentalFaultReport::query()->where('property_id', $property->id)->get();
        $workOrders = RentalWorkOrder::query()->where('property_id', $property->id)->get();
        $inspections = RentalInspection::query()->where('property_id', $property->id)->get();

        $timeline = [];
        foreach ($leases as $lease) {
            $timeline[] = [
                'group' => 'Lease #' . $lease->id . ' — ' . optional($lease->start_date)->format('Y-m-d')
                    . ' to ' . (optional($lease->end_date)->format('Y-m-d') ?: (($lease->is_month_to_month ? 'month-to-month' : 'open'))),
                'tenant' => $lease->tenants->map(fn ($t) => $t->contact?->full_name)->filter()->implode(', ') ?: '—',
                'start' => optional($lease->start_date)->format('Y-m-d'),
                'end' => optional($lease->end_date)->format('Y-m-d') ?: '—',
                'rent' => $lease->rental_amount,
                'events' => $this->eventsForLease($lease, $faults, $workOrders, $inspections),
            ];
        }

        $betweenTenancies = $this->eventsForLease(null, $faults, $workOrders, $inspections);
        if ($betweenTenancies->isNotEmpty()) {
            $timeline[] = [
                'group' => 'Between tenancies (no lease on record)',
                'tenant' => '—', 'start' => '—', 'end' => '—', 'rent' => null,
                'events' => $betweenTenancies,
            ];
        }

        return [
            'property' => $property,
            'timeline' => $timeline,
        ];
    }

    private function eventsForLease(?Lease $lease, Collection $faults, Collection $workOrders, Collection $inspections): Collection
    {
        $leaseId = $lease?->id;

        $events = collect();
        foreach ($faults->where('lease_id', $leaseId) as $f) {
            $events->push(['date' => $f->reported_at, 'type' => 'Fault', 'description' => ($f->faultType?->name ?? $f->title) . ' — ' . $this->humanize($f->status)]);
        }
        foreach ($workOrders->where('lease_id', $leaseId) as $w) {
            $events->push(['date' => $w->reported_at, 'type' => 'Work order', 'description' => ($w->trade_type ? ucfirst($w->trade_type) . ' — ' : '') . $this->humanize($w->status)]);
        }
        if ($leaseId !== null) {
            foreach ($inspections->where('lease_id', $leaseId) as $i) {
                $events->push(['date' => $i->scheduled_for ?? $i->completed_at, 'type' => 'Inspection', 'description' => ucfirst(str_replace('_', ' ', $i->type)) . ' — ' . $this->humanize($i->status)]);
            }
        }

        return $events->sortBy('date')->values();
    }

    // ───────────────────────── Single-record print: Landlord property activity report ─────────────────────────

    /**
     * §5 of the spec — "run for a specific property," property-wide (not per-tenancy),
     * bounded to a period, for the landlord. Only reachable from THIS Reports screen in
     * this build — the Property Rental tab entry point named in the spec is NOT added here
     * (that file belongs to the Properties module, out of this ticket's own file scope).
     */
    public function landlordPropertyActivity(Property $property, array $params): array
    {
        $period = $this->resolvePeriod($params);

        $faults = RentalFaultReport::query()->where('property_id', $property->id)
            ->when($period['from'], fn ($q) => $q->where('reported_at', '>=', $period['from']))
            ->when($period['to'], fn ($q) => $q->where('reported_at', '<=', $period['to']))
            ->with('faultType')->orderBy('reported_at')->get();

        $workOrders = RentalWorkOrder::query()->where('property_id', $property->id)
            ->when($period['from'], fn ($q) => $q->where('reported_at', '>=', $period['from']))
            ->when($period['to'], fn ($q) => $q->where('reported_at', '<=', $period['to']))
            ->with(['supplier', 'quotes'])->orderBy('reported_at')->get();

        $inspections = RentalInspection::query()->where('property_id', $property->id)
            ->when($period['from'], fn ($q) => $q->where('scheduled_for', '>=', $period['from']))
            ->when($period['to'], fn ($q) => $q->where('scheduled_for', '<=', $period['to']))
            ->orderBy('scheduled_for')->get();

        return [
            'property' => $property,
            'period' => $period,
            'faults' => $faults,
            'workOrders' => $workOrders,
            'inspections' => $inspections,
            'totalWorkOrderAmount' => $workOrders->sum(fn ($w) => $w->quotes->firstWhere('is_selected', true)?->amount ?? $w->cost_amount ?? 0),
        ];
    }

    // ───────────────────────── Shared helpers ─────────────────────────

    private function sortRows(Collection $rows, string $sort, string $direction, string $fallback): Collection
    {
        $key = $rows->first() && array_key_exists($sort, $rows->first()) ? $sort : $fallback;
        $sorted = $rows->sortBy(fn ($r) => $r[$key], SORT_REGULAR, $direction === 'desc');

        return $sorted->values();
    }

    /**
     * Injects a '_group' key (blank when no group-by is active) and returns rows re-ordered
     * group-then-within-group, plus a per-group subtotal/row-count map keyed by group label,
     * in first-seen order. One shared implementation so every report's grouping/subtotal
     * behaviour is identical.
     */
    private function applyGrouping(Collection $rows, ?string $groupBy, ?callable $labelFor): array
    {
        if (!$groupBy || !$labelFor) {
            return [$rows->map(fn ($r) => $r + ['_group' => null])->values(), []];
        }

        $withGroup = $rows->map(function ($r) use ($labelFor) {
            $r['_group'] = $labelFor($r['_model'] ?? null) ?? 'Unspecified';

            return $r;
        });

        $grouped = $withGroup->groupBy('_group');
        $ordered = collect();
        $groupMeta = [];
        foreach ($grouped as $label => $groupRows) {
            $ordered = $ordered->concat($groupRows->values());
            $groupMeta[$label] = $groupRows->count();
        }

        return [$ordered->values(), $groupMeta];
    }

    private function humanize(?string $value): string
    {
        return $value ? ucfirst(str_replace('_', ' ', $value)) : '—';
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
