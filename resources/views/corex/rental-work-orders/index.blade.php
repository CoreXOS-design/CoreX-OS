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
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 space-y-4" x-data="rentalWorkOrdersPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Work Orders</h1>
        @permission('rental_work_orders.create')
        {{-- AT-442 fix #3 — forward the active lease_id/property_id filter
             (e.g. arrived here via the rental context bar's "Work orders"
             chip) into the create screen, same as every other "New X"
             button that respects an already-applied context filter. --}}
        <a href="{{ route('corex.rental-work-orders.create', request()->only(['property_id', 'lease_id'])) }}" class="corex-btn-primary text-xs">New Work Order</a>
        @endpermission
    </div>

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. --}}
    @php
        $currentTile = null;
        foreach (['reported', 'ordered', 'in_progress', 'completed', 'cancelled'] as $s) {
            if (($filters['status'] ?? '') === $s) { $currentTile = $s; break; }
        }
        if (!$currentTile && ($filters['overdue'] ?? false)) { $currentTile = 'overdue'; }
        if (!$currentTile && !($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'reported' => ['label' => 'Reported', 'params' => ['status' => 'reported']],
            'ordered' => ['label' => 'Ordered', 'params' => ['status' => 'ordered']],
            'in_progress' => ['label' => 'In progress', 'params' => ['status' => 'in_progress']],
            'completed' => ['label' => 'Completed', 'params' => ['status' => 'completed']],
            'cancelled' => ['label' => 'Cancelled', 'params' => ['status' => 'cancelled']],
            'overdue' => ['label' => 'Overdue', 'params' => ['overdue' => 1]],
        ];
        $tileClearParams = ['status' => null, 'overdue' => null, 'page' => null];
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

    <form method="GET" action="{{ route('corex.rental-work-orders.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property, tenant, supplier, title" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['reported', 'ordered', 'in_progress', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="relative">
            <label class="text-xs" style="color: var(--text-muted);">Property</label><br>
            {{-- Only properties with a work order visible to this user —
                 never every rental property (that's the create form's own
                 job, unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties by address…"
                   class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto bg-white" style="border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="block w-full text-left px-3 py-2 text-xs hover:bg-slate-50">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Trade type</label><br>
            <select name="trade_type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(\App\Models\DealV2\AgencyServiceType::orderBy('label')->get() as $type)
                    <option value="{{ $type->code }}" @selected(($filters['trade_type'] ?? '') === $type->code)>{{ $type->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Priority</label><br>
            <select name="priority" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['low', 'normal', 'urgent'] as $p)
                    <option value="{{ $p }}" @selected(($filters['priority'] ?? '') === $p)>{{ ucfirst($p) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Paid by</label><br>
            <select name="paid_by" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['owner', 'tenant', 'deposit_deduction', 'not_yet_paid'] as $p)
                    <option value="{{ $p }}" @selected(($filters['paid_by'] ?? '') === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Reported from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Reported to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <label class="flex items-center gap-1 text-xs pb-2">
            <input type="checkbox" name="overdue" value="1" @checked(($filters['overdue'] ?? false))>
            Overdue only
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'trade_type', 'priority', 'property_id', 'paid_by', 'date_from', 'date_to', 'overdue']))
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

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

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Title</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('priority') }}" style="color: var(--text-muted);">Priority{{ $sortIndicator('priority') }}</a></th>
                    <th class="text-left px-4 py-2">Supplier</th>
                    <th class="text-left px-4 py-2">Paid by</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('reported_at') }}" style="color: var(--text-muted);">Reported{{ $sortIndicator('reported_at') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($workOrders as $workOrder)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="work-order-row-{{ $workOrder->id }}">
                    <td class="px-4 py-2">{{ $workOrder->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->title }}</td>
                    <td class="px-4 py-2">
                        @if($showArchived)
                            <span class="ds-badge ds-badge-muted">Archived {{ $workOrder->deleted_at?->format('Y-m-d') }}</span>
                        @else
                            <span class="ds-badge {{ $statusBadgeClass($workOrder->status) }}">{{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2">{{ $workOrder->priority ? ucfirst($workOrder->priority) : '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->paid_by ? ucfirst(str_replace('_', ' ', $workOrder->paid_by)) : '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 text-right">
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
                <tr><td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
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

    {{ $workOrders->links() }}
</div>
@endsection
