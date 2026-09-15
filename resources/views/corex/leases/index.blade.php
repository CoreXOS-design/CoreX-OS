@extends('layouts.corex')

{{--
    .ai/specs/leases.md §7 — the Leases list screen. CRUD/list-screen floor
    (BUILD_STANDARD §1a-§1d): search (property address, tenant name), sort
    (default: end date ascending — soonest to expire first), filter (status,
    date range, property, branch), pagination, real empty state,
    OWN/BRANCH/AGENCY scoping enforced at the query layer (Lease::scopeVisibleTo()).
--}}

@php
    $sortLink = fn ($col) => route('corex.leases.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'active' => 'ds-badge-success',
        'draft' => 'ds-badge-muted',
        'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-info',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Leases</h1>
        @permission('leases.create')
        <a href="{{ route('corex.leases.create') }}" class="corex-btn-primary text-xs">New Lease</a>
        @endpermission
    </div>

    <form method="GET" action="{{ route('corex.leases.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address or tenant name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['draft', 'active', 'expired', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">End date from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">End date to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
            <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('start_date') }}" style="color: var(--text-muted);">Start{{ $sortIndicator('start_date') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('end_date') }}" style="color: var(--text-muted);">End{{ $sortIndicator('end_date') }}</a></th>
                    <th class="text-left px-4 py-2">Rent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leases as $lease)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="lease-row-{{ $lease->id }}">
                    <td class="px-4 py-2">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $lease->tenantNames() }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($lease->status) }}">{{ ucfirst($lease->status) }}</span></td>
                    <td class="px-4 py-2">{{ $lease->start_date?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2">{{ $lease->end_date?->format('Y-m-d') ?? ($lease->is_month_to_month ? 'Month-to-month' : '—') }}</td>
                    <td class="px-4 py-2">R{{ number_format((float) $lease->rental_amount, 2) }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(!$hasAnyLeases)
                        No leases yet on this agency. Every rental tenancy starts here — click "New Lease" to add the first one.
                    @else
                        No leases match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $leases->links() }}
</div>
@endsection
