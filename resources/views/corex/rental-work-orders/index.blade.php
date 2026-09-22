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
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Work Orders</h1>
        @permission('rental_work_orders.create')
        <a href="{{ route('corex.rental-work-orders.create') }}" class="corex-btn-primary text-xs">New Work Order</a>
        @endpermission
    </div>

    <form method="GET" action="{{ route('corex.rental-work-orders.index') }}" class="flex flex-wrap items-end gap-3">
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
        @if(request()->hasAny(['q', 'status', 'trade_type', 'priority', 'paid_by', 'date_from', 'date_to', 'overdue']))
            <a href="{{ route('corex.rental-work-orders.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

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
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($workOrder->status) }}">{{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}</span></td>
                    <td class="px-4 py-2">{{ $workOrder->priority ? ucfirst($workOrder->priority) : '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->supplier?->name ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->paid_by ? ucfirst(str_replace('_', ' ', $workOrder->paid_by)) : '—' }}</td>
                    <td class="px-4 py-2">{{ $workOrder->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-work-orders.show', $workOrder) }}" class="corex-btn-outline text-xs">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(!$hasAnyWorkOrders)
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
