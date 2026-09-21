@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §7 — the tracked/searchable list of every
    inventory. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search
    (property address, tenant name, agent name), sort (default: created,
    most-recent-first), filter (status, date range), pagination, real empty
    state, OWN/BRANCH/AGENCY scoping (RentalInventory::scopeVisibleTo()).
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-inventories.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'awaiting_signature' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Inventories</h1>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.rental-inventories.index', array_merge(request()->except('page'), ['archived' => $archived ? null : 1])) }}"
               class="corex-btn-outline text-xs {{ $archived ? 'corex-tab-active' : '' }}">
                {{ $archived ? 'Hide archived' : 'Show archived' }}
            </a>
            @permission('rental_inventories.create')
            <a href="{{ route('corex.rental-inventories.create') }}" class="corex-btn-primary text-xs">Start Inventory</a>
            @endpermission
        </div>
    </div>

    <form method="GET" action="{{ route('corex.rental-inventories.index') }}" class="flex flex-wrap items-end gap-3">
        @if($archived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, tenant or agent name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['draft', 'awaiting_signature', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Created from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Created to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
            <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('created_at') }}" style="color: var(--text-muted);">Started{{ $sortIndicator('created_at') }}</a></th>
                    <th class="text-left px-4 py-2">{{ $archived ? 'Archived' : 'Lines' }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($inventories as $inventory)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="rental-inventory-row-{{ $inventory->id }}">
                    <td class="px-4 py-2">{{ $inventory->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $inventory->lease?->tenantNames() ?? '—' }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($inventory->status) }}">{{ ucfirst(str_replace('_', ' ', $inventory->status)) }}</span></td>
                    <td class="px-4 py-2">{{ $inventory->created_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if($archived)
                            {{ $inventory->archivedBy?->name ?? 'Unknown' }} — {{ $inventory->deleted_at?->format('Y-m-d') }}
                        @else
                            {{ $inventory->lines()->count() }}
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        @if($archived)
                            @permission('rental_inventories.create')
                            <form method="POST" action="{{ route('corex.rental-inventories.restore', $inventory->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                            @endpermission
                        @else
                            <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="corex-btn-outline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if($archived)
                        No archived inventories on this agency.
                    @elseif(!$hasAnyInventories)
                        No inventories yet on this agency. Click "Start Inventory" above.
                    @else
                        No inventories match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $inventories->links() }}
</div>
@endsection
