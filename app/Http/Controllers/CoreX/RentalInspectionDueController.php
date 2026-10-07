<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\ExportsRentalList;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalDataScope;
use App\Services\Rentals\RentalInspectionDueService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspections.md §45.7 items 2, 5, 8 (Build I-5) — the "Due" tab of the Rental Inspections list: the
 * computed In/Out due items and the interim dates the AGENCY loaded, side by side, plus full CRUD on the loaded dates.
 *
 * LIST STANDARD (BUILD_STANDARD §1b)
 *   Search   property address, tenant name, agent name, note.
 *   Sort     due date (default, soonest first), property, type, status, days overdue.
 *   Filter   type, status (open / overdue / due now / upcoming / booked / done / skipped / lease ended / all), due-date
 *            range, agent, own/branch/agency pill, archived toggle.
 *   Pages    25 / 50 / 100.   Empty states: nothing at all yet vs no match.   Print + CSV/XLSX on the SAME scoped rows.
 *
 * SCOPING (§1c) — at the query layer, per screen: the loaded dates through RentalInspectionPlannedDate::scopeVisibleTo(),
 * the In/Out items through the leases' property (own = the property's agent or the lease creator, branch = the property's
 * branch), both under the `rental_inspections` data scope. Every action on a date resolves it by route binding through the
 * same scope — a direct URL by id to someone else's date is a 404, not merely an unlinked button.
 *
 * Nothing here computes an interim date (Johan, 6 Oct 2026, Q6) — interim dates exist only because an agent loaded them.
 */
class RentalInspectionDueController extends Controller
{
    use ExportsRentalList;

    private const PER_PAGE_OPTIONS = [25, 50, 100];

    private const SORTS = ['due', 'property', 'type', 'status', 'overdue'];

    public const TYPE_LABELS = [
        RentalInspection::TYPE_IN => 'Move-in',
        RentalInspection::TYPE_OUT => 'Move-out',
        RentalInspection::TYPE_INTERIM => 'Interim',
    ];

    public const STATE_LABELS = [
        'overdue' => 'Overdue',
        'due' => 'Due now',
        'upcoming' => 'Upcoming',
        'booked' => 'Booked',
        'done' => 'Done',
        'skipped' => 'Skipped',
        'lease_ended' => 'Lease ended',
        'archived' => 'Archived',
    ];

    public function __construct(private RentalInspectionDueService $due) {}

    // ── The tab ────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        [$maxScope, $resolvedScope, $scopeOptions] = $this->scopes($request);

        $base = $this->baseRows($request, $user);
        $rows = $this->applyFilters($request, $base);
        $rows = $this->sorted($request, $rows);

        $perPage = (int) $request->get('per_page', 25);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 25;
        }
        $page = max(1, (int) $request->get('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $agencyId = $user->effectiveAgencyId();
        $canManage = $user->hasPermission('rental_inspections.manage_planned_dates');

        return view('corex.rental-inspections.due', [
            'rows' => $paginator,
            'tileCounts' => $this->tileCounts($base),
            'hasAnything' => $base->isNotEmpty(),
            'archived' => $request->boolean('archived'),
            'filters' => $request->only(['q', 'type', 'status', 'date_from', 'date_to', 'agent_id']),
            'sort' => $this->sortKey($request),
            'direction' => $this->direction($request),
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'resolvedScope' => $resolvedScope,
            'scopeOptions' => $scopeOptions,
            'agentOptions' => User::where('agency_id', $agencyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage,
            // Only what the "Load interim dates" form needs, and only when the user may use it.
            'leaseOptions' => $canManage ? $this->loadableLeases($user) : collect(),
            'plannedLead' => RentalInspectionSetting::plannedDateLeadDaysFor($agencyId),
            'typeLabels' => self::TYPE_LABELS,
            'stateLabels' => self::STATE_LABELS,
        ]);
    }

    public function printList(Request $request): View
    {
        $rows = $this->sorted($request, $this->applyFilters($request, $this->baseRows($request, $request->user())));

        return view('corex.rental-inspections.due-print', [
            'rows' => $rows,
            'typeLabels' => self::TYPE_LABELS,
            'stateLabels' => self::STATE_LABELS,
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->sorted($request, $this->applyFilters($request, $this->baseRows($request, $request->user())));

        $headers = ['Type', 'Property', 'Tenant(s)', 'Due date', 'Status', 'Agent', 'Note'];
        $data = $rows->map(fn (array $r) => [
            self::TYPE_LABELS[$r['type']] ?? $r['type'],
            $r['property_address'],
            $r['tenants'],
            $r['due_on']?->format('Y-m-d') ?? '',
            self::STATE_LABELS[$r['state']] ?? $r['state'],
            $r['agent_name'] ?? '',
            $r['note'] ?? '',
        ]);

        $filename = 'due-inspections-' . now()->format('Y-m-d');

        return $request->get('format') === 'csv'
            ? $this->streamRentalListCsv($filename . '.csv', $headers, $data)
            : $this->streamRentalListXlsx($filename . '.xlsx', $headers, $data);
    }

    // ── Loaded dates: create / move / skip / reopen / archive / restore ────

    /** Load one or several interim dates against a lease. A repeat is refused with a plain message; an archived twin is restored. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lease_id' => ['required', 'integer'],
            'dates' => ['required', 'array', 'min:1', 'max:24'],
            'dates.*' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'dates.required' => 'Add at least one date.',
            'dates.min' => 'Add at least one date.',
        ]);

        $user = $request->user();
        $lease = $this->loadableLeases($user)->firstWhere('id', (int) $validated['lease_id']);
        if (! $lease) {
            // Same refusal whether the lease is another agency's, outside this user's scope, or no longer active.
            return back()->withInput()->withErrors(['lease_id' => 'Choose one of your active tenancies — that one is not available.']);
        }

        $dates = collect($validated['dates'])->filter()->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())->unique()->values();
        if ($dates->isEmpty()) {
            return back()->withInput()->withErrors(['dates' => 'Add at least one date.']);
        }

        $created = 0;
        $restored = 0;
        $duplicates = [];
        foreach ($dates as $date) {
            $existing = RentalInspectionPlannedDate::withTrashed()
                ->where('lease_id', $lease->id)->where('type', RentalInspectionPlannedDate::TYPE_INTERIM)
                ->whereDate('planned_on', $date)->first();

            if ($existing && ! $existing->trashed()) {
                $duplicates[] = \Illuminate\Support\Carbon::parse($date)->format('j M Y');

                continue;
            }
            if ($existing) {
                // Create -> archive -> load again: bring the archived row back instead of colliding with it (BUILD_STANDARD §5a).
                $existing->restore();
                $restored++;

                continue;
            }

            RentalInspectionPlannedDate::create([
                'agency_id' => $lease->agency_id,
                'lease_id' => $lease->id,
                'property_id' => $lease->property_id,
                'type' => RentalInspectionPlannedDate::TYPE_INTERIM,
                'planned_on' => $date,
                'note' => $validated['note'] ?? null,
                'status' => RentalInspectionPlannedDate::STATUS_PLANNED,
                'created_by_user_id' => $user->id,
            ]);
            $created++;
        }

        $message = trim(($created ? "{$created} date" . ($created === 1 ? '' : 's') . ' loaded. ' : '')
            . ($restored ? "{$restored} archived date" . ($restored === 1 ? '' : 's') . ' brought back. ' : ''));
        $redirect = redirect()->route('corex.rental-inspections.due');

        if ($duplicates !== []) {
            $dupMsg = 'Already loaded for this tenancy, so not added again: ' . implode(', ', $duplicates) . '.';
            if ($message === '') {
                return $redirect->withErrors(['dates' => $dupMsg]);
            }

            return $redirect->with('success', $message)->with('warning', $dupMsg);
        }

        return $redirect->with('success', $message);
    }

    /** Move a date (only while it is still just "planned") and/or edit its note. */
    public function update(Request $request, RentalInspectionPlannedDate $plannedDate): RedirectResponse
    {
        $this->assertActionable($plannedDate);

        $validated = $request->validate([
            'planned_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $attrs = [];
        if (! empty($validated['planned_on'])) {
            if ($plannedDate->status !== RentalInspectionPlannedDate::STATUS_PLANNED) {
                return back()->withErrors(['planned_on' => 'Only a date that has not been booked yet can be moved. Reschedule the booked inspection instead.']);
            }
            $newDate = \Illuminate\Support\Carbon::parse($validated['planned_on'])->toDateString();
            $clash = RentalInspectionPlannedDate::withTrashed()
                ->where('lease_id', $plannedDate->lease_id)->where('type', $plannedDate->type)
                ->whereDate('planned_on', $newDate)->where('id', '!=', $plannedDate->id)->exists();
            if ($clash) {
                return back()->withErrors(['planned_on' => 'This tenancy already has a date loaded for ' . \Illuminate\Support\Carbon::parse($newDate)->format('j M Y') . '.']);
            }
            $attrs['planned_on'] = $newDate;
        }
        if (array_key_exists('note', $validated)) {
            $attrs['note'] = $validated['note'];
        }
        if ($attrs !== []) {
            $plannedDate->update($attrs);
        }

        return redirect()->route('corex.rental-inspections.due')->with('success', 'Date updated.');
    }

    public function skip(Request $request, RentalInspectionPlannedDate $plannedDate): RedirectResponse
    {
        $this->assertActionable($plannedDate);
        $validated = $request->validate(['skipped_reason' => ['required', 'string', 'max:500']], [
            'skipped_reason.required' => 'Say why this inspection is being skipped.',
        ]);

        if ($plannedDate->status !== RentalInspectionPlannedDate::STATUS_PLANNED) {
            return back()->withErrors(['skipped_reason' => 'Only a date that has not been booked yet can be skipped. Cancel the booked inspection first and the date returns to planned.']);
        }

        $plannedDate->update(['status' => RentalInspectionPlannedDate::STATUS_SKIPPED, 'skipped_reason' => trim($validated['skipped_reason'])]);

        return redirect()->route('corex.rental-inspections.due')->with('success', 'Date skipped — no more reminders for it.');
    }

    /** The reverse of skip — a skipped date goes back to planned. */
    public function reopen(Request $request, RentalInspectionPlannedDate $plannedDate): RedirectResponse
    {
        $this->assertActionable($plannedDate);
        if ($plannedDate->status === RentalInspectionPlannedDate::STATUS_SKIPPED) {
            $plannedDate->update(['status' => RentalInspectionPlannedDate::STATUS_PLANNED, 'skipped_reason' => null]);
        }

        return redirect()->route('corex.rental-inspections.due')->with('success', 'Date is planned again.');
    }

    public function archive(Request $request, RentalInspectionPlannedDate $plannedDate): RedirectResponse
    {
        if ($plannedDate->trashed()) {
            return redirect()->route('corex.rental-inspections.due')->with('success', 'Already archived.');
        }
        $plannedDate->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $plannedDate->delete();

        return redirect()->route('corex.rental-inspections.due')->with('success', 'Date archived — it can be restored from "Show archived".');
    }

    public function restore(Request $request, RentalInspectionPlannedDate $plannedDate): RedirectResponse
    {
        if ($plannedDate->trashed()) {
            // A restored date must not collide with a live twin that was loaded since it was archived.
            $twin = RentalInspectionPlannedDate::where('lease_id', $plannedDate->lease_id)->where('type', $plannedDate->type)
                ->whereDate('planned_on', $plannedDate->planned_on->toDateString())->exists();
            if ($twin) {
                return back()->withErrors(['dates' => 'This tenancy already has a live date for ' . $plannedDate->planned_on->format('j M Y') . ' — nothing to restore.']);
            }
            $plannedDate->restore();
        }

        return redirect()->route('corex.rental-inspections.due', ['archived' => 1])->with('success', 'Date restored.');
    }

    // ── Rows ───────────────────────────────────────────────────────────────

    /** An archived date is read-only until restored. */
    private function assertActionable(RentalInspectionPlannedDate $plannedDate): void
    {
        abort_if($plannedDate->trashed(), 422, 'This date is archived — restore it first.');
    }

    /** @return array{0:string, 1:string, 2:array<int,string>} */
    private function scopes(Request $request): array
    {
        $max = RentalDataScope::ceiling($request->user(), 'rental_inspections');
        $resolved = PermissionService::clampScope($request->get('scope'), $max);
        $options = match ($max) {
            'all' => ['own', 'branch', 'all'],
            'branch' => ['own', 'branch'],
            default => ['own'],
        };

        return [$max, $resolved, $options];
    }

    /** ACTIVE leases whose property the user may act on under their inspections scope — the "Load interim dates" picker and its server-side check. */
    private function loadableLeases(User $user): Collection
    {
        return Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereHas('property', fn (Builder $p) => $this->propertyScope($p, $user, null))
            ->with(['property', 'tenants.contact'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /** own = the property's agent (or whoever loaded/created it), branch = the property's branch, all = the agency. */
    private function propertyScope(Builder $propertyQuery, User $user, ?string $requested): Builder
    {
        $max = RentalDataScope::ceiling($user, 'rental_inspections');
        $scope = PermissionService::clampScope($requested, $max);

        return match ($scope) {
            'all' => $propertyQuery,
            'branch' => $propertyQuery->where('properties.branch_id', $user->effectiveBranchId()),
            'own' => $propertyQuery->whereIn('properties.agent_id', $user->dataIdentityIds()),
            default => $propertyQuery->whereRaw('1 = 0'),
        };
    }

    /**
     * Every row for the scope the viewer chose — In/Out items and loaded dates — BEFORE search/status/date filters, so the
     * tiles never drift from "All" on that scope. In archived mode only archived loaded dates are shown.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function baseRows(Request $request, User $user): Collection
    {
        $agencyId = $user->effectiveAgencyId();
        $requestedScope = $request->get('scope');
        $today = now()->startOfDay();
        $archived = $request->boolean('archived');
        $rows = collect();

        if (! $archived) {
            $leaseConstraint = function ($q) use ($user, $requestedScope) {
                $max = RentalDataScope::ceiling($user, 'rental_inspections');
                $scope = PermissionService::clampScope($requestedScope, $max);
                if ($scope === 'all') {
                    return;
                }
                if ($scope === 'branch') {
                    $q->whereHas('property', fn ($p) => $p->withoutGlobalScopes()->where('properties.branch_id', $user->effectiveBranchId()));

                    return;
                }
                if ($scope === 'own') {
                    $ids = $user->dataIdentityIds();
                    $q->where(fn ($w) => $w->whereHas('property', fn ($p) => $p->withoutGlobalScopes()->whereIn('properties.agent_id', $ids))
                        ->orWhereIn('leases.created_by_user_id', $ids));

                    return;
                }
                $q->whereRaw('1 = 0');
            };

            foreach ($this->due->inOutItemsFor($agencyId, $leaseConstraint, $today) as $item) {
                $lease = $item['lease'];
                $rows[] = [
                    'key' => 'io-' . $item['lease_id'] . '-' . $item['type'],
                    'source' => 'due_list',
                    'type' => $item['type'],
                    'due_on' => $item['due_on'],
                    'state' => $item['state'],
                    'days_overdue' => $item['state'] === 'overdue' ? (int) $item['due_on']->diffInDays($today) : 0,
                    'lease' => $lease,
                    'lease_id' => $lease->id,
                    'property' => $lease->property,
                    'property_id' => $lease->property_id,
                    'planned' => null,
                    'note' => null,
                    'reason' => $item['reason'],
                    'agent_id' => $this->due->responsibleAgentId($lease),
                ];
            }
        }

        $planned = RentalInspectionPlannedDate::query()
            ->when($archived, fn ($q) => $q->onlyTrashed())
            ->visibleTo($user, $requestedScope)
            ->with(['lease' => fn ($q) => $q->withoutGlobalScopes(), 'property' => fn ($q) => $q->withoutGlobalScopes(), 'inspection'])
            ->get();
        $lead = RentalInspectionSetting::plannedDateLeadDaysFor($agencyId);

        foreach ($planned as $date) {
            $lease = $date->lease;
            $leaseEnded = ! $lease || $lease->status !== Lease::STATUS_ACTIVE || $lease->deleted_at !== null;
            $state = match (true) {
                $date->trashed() => 'archived',
                $date->status === RentalInspectionPlannedDate::STATUS_DONE => 'done',
                $date->status === RentalInspectionPlannedDate::STATUS_SKIPPED => 'skipped',
                $leaseEnded => 'lease_ended',
                $date->status === RentalInspectionPlannedDate::STATUS_BOOKED => 'booked',
                default => $this->due->state($date->planned_on, $lead, $today),
            };
            $rows[] = [
                'key' => 'pd-' . $date->id,
                'source' => 'planned',
                'type' => $date->type,
                'due_on' => $date->planned_on,
                'state' => $state,
                'days_overdue' => ($state === 'overdue') ? (int) $date->planned_on->diffInDays($today) : 0,
                'lease' => $lease,
                'lease_id' => $date->lease_id,
                'property' => $date->property,
                'property_id' => $date->property_id,
                'planned' => $date,
                'note' => $date->note,
                'reason' => $date->status === RentalInspectionPlannedDate::STATUS_SKIPPED ? $date->skipped_reason : null,
                'agent_id' => $lease ? $this->due->responsibleAgentId($lease) : $date->created_by_user_id,
            ];
        }

        // Names, resolved once for the whole set — never a query per row.
        $agents = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('agent_id')->filter()->unique())->pluck('name', 'id');
        $leaseIds = $rows->pluck('lease_id')->unique();
        $withTenants = Lease::withoutGlobalScopes()->whereIn('id', $leaseIds)->with('tenants.contact')->get()->keyBy('id');

        return $rows->map(function (array $r) use ($agents, $withTenants) {
            $r['agent_name'] = $r['agent_id'] ? ($agents[$r['agent_id']] ?? null) : null;
            $r['tenants'] = ($withTenants[$r['lease_id']] ?? null)?->tenantNames() ?: '';
            $r['property_address'] = $r['property']?->buildDisplayAddress() ?? 'Unknown property';

            return $r;
        })->values();
    }

    /** @return array{overdue:int, due:int, upcoming:int, booked:int} the four tiles — all open work, by state. */
    private function tileCounts(Collection $base): array
    {
        $count = fn (string $state) => $base->where('state', $state)->count();

        return [
            'overdue' => $count('overdue'),
            'due' => $count('due'),
            'upcoming' => $count('upcoming'),
            'booked' => $count('booked'),
        ];
    }

    private function applyFilters(Request $request, Collection $rows): Collection
    {
        if ($type = $request->get('type')) {
            $rows = $rows->where('type', $type);
        }

        $status = $request->get('status', 'open');
        $rows = match ($status) {
            'all' => $rows,
            'open' => $request->boolean('archived') ? $rows : $rows->whereIn('state', ['overdue', 'due', 'upcoming', 'booked']),
            default => $rows->where('state', $status),
        };

        if ($from = $request->get('date_from')) {
            $rows = $rows->filter(fn (array $r) => $r['due_on'] && $r['due_on']->toDateString() >= $from);
        }
        if ($to = $request->get('date_to')) {
            $rows = $rows->filter(fn (array $r) => $r['due_on'] && $r['due_on']->toDateString() <= $to);
        }
        if ($agentId = $request->get('agent_id')) {
            $rows = $rows->filter(fn (array $r) => (int) $r['agent_id'] === (int) $agentId);
        }
        if ($q = mb_strtolower(trim((string) $request->get('q', '')))) {
            $rows = $rows->filter(fn (array $r) => str_contains(mb_strtolower(
                ($r['property_address'] ?? '') . ' ' . ($r['tenants'] ?? '') . ' ' . ($r['agent_name'] ?? '') . ' ' . ($r['note'] ?? '')
            ), $q));
        }

        return $rows->values();
    }

    private function sortKey(Request $request): string
    {
        $sort = $request->get('sort', 'due');

        return in_array($sort, self::SORTS, true) ? $sort : 'due';
    }

    private function direction(Request $request): string
    {
        return $request->get('direction', $this->sortKey($request) === 'overdue' ? 'desc' : 'asc') === 'desc' ? 'desc' : 'asc';
    }

    private function sorted(Request $request, Collection $rows): Collection
    {
        $sort = $this->sortKey($request);
        $desc = $this->direction($request) === 'desc';

        $value = fn (array $r) => match ($sort) {
            'property' => mb_strtolower($r['property_address']),
            'type' => $r['type'],
            'status' => array_search($r['state'], array_keys(self::STATE_LABELS), true),
            'overdue' => $r['days_overdue'],
            default => $r['due_on']?->timestamp ?? PHP_INT_MAX,
        };

        // id-ish tiebreak so equal keys never reorder between page loads.
        $sorted = $rows->sort(function (array $a, array $b) use ($value, $desc) {
            $cmp = $value($a) <=> $value($b);
            if ($cmp === 0) {
                $cmp = strcmp($a['key'], $b['key']);
            }

            return $desc ? -$cmp : $cmp;
        });

        return $sorted->values();
    }
}
