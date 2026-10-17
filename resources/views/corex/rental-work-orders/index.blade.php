@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §6 — the Rental Work Orders list screen.
    CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search (property
    address, tenant name, supplier name, title/description), sort (default:
    reported date, most recent first), filter (status, trade type, date
    range, property, paid_by, overdue), pagination, real empty state,
    OWN/BRANCH/AGENCY scoping enforced at the query layer
    (RentalWorkOrder::scopeVisibleTo()).
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-work-orders.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'completed' => 'ds-badge-success',
        'reported', 'ordered', 'in_progress' => 'ds-badge-info',
        'cancelled', 'disputed' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
@endphp

@section('corex-content')
<div class="w-full space-y-5" x-data="rentalWorkOrdersPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Work Orders</h1>
                <p class="text-xs" style="color: var(--text-muted);">Repairs and maintenance jobs on your rental properties: who is doing the work, who pays and where each job stands.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('rental_work_orders.create')
                {{-- AT-442 fix #3 — forward the active lease_id/property_id filter
                     (e.g. arrived here via the rental context bar's "Work orders"
                     chip) into the create screen, same as every other "New X"
                     button that respects an already-applied context filter. --}}
                <a href="{{ route('corex.rental-work-orders.create', request()->only(['property_id', 'lease_id'])) }}" class="corex-btn-primary text-xs">New Work Order</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. --}}
    @php
        $currentTile = null;
        foreach (['reported', 'ordered', 'in_progress', 'disputed', 'completed', 'cancelled'] as $s) {
            if (($filters['status'] ?? '') === $s) { $currentTile = $s; break; }
        }
        // Yes/no filters (not statuses). §17.17 added "Awaiting owner" and "Variation pending" beside "Overdue".
        $flagKeys = ['overdue', 'awaiting_owner', 'variation_pending', 'awaiting_check'];
        foreach ($flagKeys as $f) {
            if (!$currentTile && ($filters[$f] ?? false)) { $currentTile = $f; }
        }
        if (!$currentTile && !($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'reported' => ['label' => \App\Models\RentalWorkOrder::statusWord('reported'), 'params' => ['status' => 'reported']],
            'ordered' => ['label' => \App\Models\RentalWorkOrder::statusWord('ordered'), 'params' => ['status' => 'ordered']],
            'in_progress' => ['label' => 'In progress', 'params' => ['status' => 'in_progress']],
            'completed' => ['label' => 'Completed', 'params' => ['status' => 'completed']],
            'cancelled' => ['label' => 'Cancelled', 'params' => ['status' => 'cancelled']],
            // §17.10 — a tenant said finished work is not complete; the office must resolve it.
            'disputed' => ['label' => \App\Models\RentalWorkOrder::statusWord('disputed'), 'params' => ['status' => 'disputed']],
            'overdue' => ['label' => 'Overdue', 'params' => ['overdue' => 1]],
            // §17.17 — the owner owes an answer: the quote is out with him / extra work was put to him.
            'awaiting_owner' => ['label' => 'Awaiting owner', 'params' => ['awaiting_owner' => 1]],
            'variation_pending' => ['label' => 'Variation pending', 'params' => ['variation_pending' => 1]],
            // Ruling 14 Oct 2026: reported finished, waiting for the agent to check and close
            'awaiting_check' => ['label' => 'Finished - awaiting agent check', 'params' => ['awaiting_check' => 1]],
        ];
        $tileClearParams = ['status' => null, 'overdue' => null, 'awaiting_owner' => null, 'variation_pending' => null, 'awaiting_check' => null, 'page' => null];
        $tileHref = fn ($key, $def) => route('corex.rental-work-orders.index', array_merge(
            request()->except(array_keys($tileClearParams)),
            $key === 'total' || $currentTile === $key ? $tileClearParams : array_merge($tileClearParams, $def['params'])
        ));
        $tiles = collect($tileDefs)->map(fn ($def, $key) => [
            'key' => $key,
            'label' => $def['label'],
            'count' => $tileCounts[$key],
            'href' => $tileHref($key, $def),
            'active' => $currentTile === $key,
        ])->values()->all();
    @endphp
    <x-rental-list-controls
        :tiles="$tiles"
        :scope-options="$scopeOptions"
        :resolved-scope="$resolvedScope"
        route-name="corex.rental-work-orders.index"
        :per-page="$perPage"
        :per-page-options="$perPageOptions"
        :archivable="true"
        :archived="$showArchived"
        :print-url="route('corex.rental-work-orders.print-list', request()->query())"
        :export-xlsx-url="route('corex.rental-work-orders.export', array_merge(request()->query(), ['format' => 'xlsx']))"
        :export-csv-url="route('corex.rental-work-orders.export', array_merge(request()->query(), ['format' => 'csv']))"
    />

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-work-orders.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property, tenant, supplier, title" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ filled($filters['status'] ?? null) ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                @foreach(['reported', 'ordered', 'in_progress', 'disputed', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ \App\Models\RentalWorkOrder::statusWord($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1 relative">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Property</label>
            {{-- Only properties with a work order visible to this user —
                 never every rental property (that's the create form's own
                 job, unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties by address…"
                   class="list-header-filter text-sm" :class="{ 'is-active': propertyQuery }">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto" style="background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-xs" style="color:var(--text-primary);">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Trade type</label>
            <select name="trade_type" class="list-header-filter text-sm {{ filled($filters['trade_type'] ?? null) ? 'is-active' : '' }}">
                <option value="">All trades</option>
                @foreach(\App\Models\DealV2\AgencyServiceType::work()->orderBy('label')->get() as $type)
                    <option value="{{ $type->code }}" @selected(($filters['trade_type'] ?? '') === $type->code)>{{ $type->label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Priority</label>
            <select name="priority" class="list-header-filter text-sm {{ filled($filters['priority'] ?? null) ? 'is-active' : '' }}">
                <option value="">All priorities</option>
                @foreach(['low', 'normal', 'urgent'] as $p)
                    <option value="{{ $p }}" @selected(($filters['priority'] ?? '') === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Paid by</label>
            <select name="paid_by" class="list-header-filter text-sm {{ filled($filters['paid_by'] ?? null) ? 'is-active' : '' }}">
                <option value="">All payers</option>
                @foreach(['owner', 'tenant', 'deposit_deduction', 'not_yet_paid'] as $p)
                    <option value="{{ $p }}" @selected(($filters['paid_by'] ?? '') === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Who pays (ruling)</label>
            <select name="who_pays" class="list-header-filter text-sm {{ filled($filters['who_pays'] ?? null) ? 'is-active' : '' }}">
                <option value="">All rulings</option>
                @foreach(\App\Models\RentalPayerRuling::PAYERS as $p => $pl)
                    <option value="{{ $p }}" @selected(($filters['who_pays'] ?? '') === $p)>{{ $pl }}</option>
                @endforeach
                <option value="none" @selected(($filters['who_pays'] ?? '') === 'none')>Not ruled yet</option>
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Cause contains</label>
            <input type="text" name="cause" value="{{ $filters['cause'] ?? '' }}" maxlength="100" placeholder="e.g. roots" class="list-header-filter text-sm {{ filled($filters['cause'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Reported from</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Reported to</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_to'] ?? '') ? 'is-active' : '' }}">
        </div>
        @if(!empty($filters['cc_scope']))<input type="hidden" name="cc_scope" value="{{ $filters['cc_scope'] }}">@endif
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="open" value="1" @checked(($filters['open'] ?? false)) data-qa="wo-filter-open">
            Open only
        </label>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="overdue" value="1" @checked(($filters['overdue'] ?? false))>
            Overdue only
        </label>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="awaiting_owner" value="1" @checked(($filters['awaiting_owner'] ?? false))>
            Awaiting owner
        </label>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="variation_pending" value="1" @checked(($filters['variation_pending'] ?? false))>
            Variation pending
        </label>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="awaiting_check" value="1" @checked(($filters['awaiting_check'] ?? false))>
            Finished - awaiting agent check
        </label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        @if(request()->hasAny(['q', 'status', 'trade_type', 'priority', 'property_id', 'paid_by', 'who_pays', 'cause', 'date_from', 'date_to', 'overdue', 'awaiting_owner', 'variation_pending', 'awaiting_check', 'open']))
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
            <div class="flex-1"></div>
        <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
              style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
            {{ number_format($workOrders->total()) }} {{ \Illuminate\Support\Str::plural('work order', $workOrders->total()) }}
        </span>
    </form>
    </div>

    <script>
    function rentalWorkOrdersPropertyFilter(old) {
        old = old || {};
        return {
            propertyQuery: old.propertyLabel || '', propertyResults: [],
            selectedPropertyId: old.propertyId || '',
            async searchProperties() {
                if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
                const res = await fetch('{{ route('corex.rental-work-orders.search-filter-properties', ['archived' => $showArchived ? 1 : 0]) }}&q=' + encodeURIComponent(this.propertyQuery));
                this.propertyResults = await res.json();
            },
            selectProperty(p) {
                this.selectedPropertyId = p.id;
                this.propertyResults = [];
                this.propertyQuery = p.label;
            },
        };
    }
    </script>

    {{-- §"List screen gaps" — property_id/lease_id are reached via a link
         (the property tab, a lease's own page), never a dropdown of every
         property/lease in the agency. This is that filter's own visible,
         clearable control. --}}
    @if($filteredProperty || $filteredLease)
    <div class="text-xs flex items-center gap-2" style="color: var(--text-muted);">
        Filtered to:
        @if($filteredProperty)
            <span class="ds-badge ds-badge-info">{{ $filteredProperty->buildDisplayAddress() }}</span>
        @endif
        @if($filteredLease)
            <span class="ds-badge ds-badge-info">{{ $filteredLease->tenantNames() ?: ('Lease #' . $filteredLease->id) }}</span>
        @endif
        <a href="{{ route('corex.rental-work-orders.index', request()->except(['property_id', 'lease_id', 'page'])) }}" class="underline">Clear</a>
    </div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Title</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('priority') }}">Priority{{ $sortIndicator('priority') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Supplier</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Paid by</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Cause</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Who pays (ruling)</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('reported_at') }}">Reported{{ $sortIndicator('reported_at') }}</a></th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($workOrders as $workOrder)
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-secondary);" data-qa="work-order-row-{{ $workOrder->id }}">
                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $workOrder->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->title }}</td>
                    <td class="px-4 py-3">
                        @if($showArchived)
                            <span class="ds-badge ds-badge-muted">Archived {{ $workOrder->deleted_at?->format('Y-m-d') }}</span>
                        @else
                            <span class="ds-badge {{ $statusBadgeClass($workOrder->status) }}">{{ app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($workOrder) ? 'Finished - awaiting agent check' : $workOrder->statusLabel() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $workOrder->priority ? ucfirst($workOrder->priority) : '—' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->supplier?->displayName() ?? '—' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->paid_by ? ucfirst(str_replace('_', ' ', $workOrder->paid_by)) : '—' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->causeSummary() ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->whoPaysLabel() ?: '—' }}</td>
                    <td class="px-4 py-3">{{ $workOrder->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3 text-right">
                        @if($showArchived)
                            @permission('rental_work_orders.create')
                            <form method="POST" action="{{ route('corex.rental-work-orders.restore', $workOrder->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                            @endpermission
                        @else
                            <a href="{{ route('corex.rental-work-orders.show', $workOrder) }}" class="corex-btn-outline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="10" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                    @if($showArchived)
                        No archived work orders on this agency.
                    @elseif(!$hasAnyWorkOrders)
                        No work orders yet on this agency. Every repair starts here — click "New Work Order" to log the first one.
                    @else
                        No work orders match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
        @if($workOrders->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $workOrders->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
