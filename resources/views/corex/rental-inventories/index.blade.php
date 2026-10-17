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

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Inventories</h1>
                <p class="text-xs" style="color: var(--text-muted);">Room-by-room counts of what is in a furnished rental, recorded at move-in and checked again at move-out.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rental-inventories.index', array_merge(request()->except('page'), ['archived' => $archived ? null : 1])) }}"
                   class="corex-btn-outline text-xs {{ $archived ? 'corex-tab-active' : '' }}">
                    {{ $archived ? 'Hide archived' : 'Show archived' }}
                </a>
                @permission('rental_inventories.create')
                <a href="{{ route('corex.rental-inventories.create') }}" class="corex-btn-primary text-xs">Start Inventory</a>
                @endpermission
            </div>
        </div>
    </div>

    @if(!$hasAnyInventories && !$archived && !request()->hasAny(['q', 'status', 'date_from', 'date_to']))
        {{--
            .ai/specs/rental-inventory.md §7.1 — the day-one empty state. A
            sentence bolted above a blank table does not teach an agency
            anything; this is the ONLY place a new agent learns what an
            inventory is for before ever seeing one. Screen-space
            discipline: every line here is either the explanation or the
            control, nothing decorative.
        --}}
        <div class="rounded-md p-8 text-center space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">No inventories yet</h2>
            <p class="text-xs max-w-md mx-auto" style="color: var(--text-muted);">
                An inventory is a counted list of a furnished property's contents, room by room —
                what's there, how many, and what condition. Recorded once at move-in, it's what a
                move-out comparison is checked against.
            </p>
            @permission('rental_inventories.create')
            <a href="{{ route('corex.rental-inventories.create') }}" class="corex-btn-primary text-xs inline-block">Start Inventory</a>
            @endpermission
        </div>
    @else
    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-inventories.index') }}" class="flex flex-wrap items-end gap-3">
        @if($archived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, tenant or agent name" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ filled($filters['status'] ?? null) ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                @foreach(['draft', 'awaiting_signature', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Created from</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Created to</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_to'] ?? '') ? 'is-active' : '' }}">
        </div>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        @if(request()->hasAny(['q', 'status', 'date_from', 'date_to']))
            <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
            <div class="flex-1"></div>
        <span class="text-xs font-semibold px-2.5 py-1 rounded-md whitespace-nowrap"
              style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 20%, transparent);">
            {{ number_format($inventories->total()) }} {{ \Illuminate\Support\Str::plural('inventory', $inventories->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Tenant(s)</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('created_at') }}">Started{{ $sortIndicator('created_at') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">{{ $archived ? 'Archived' : 'Lines' }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($inventories as $inventory)
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-secondary);" data-qa="rental-inventory-row-{{ $inventory->id }}">
                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $inventory->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-3">{{ $inventory->lease?->tenantNames() ?? '—' }}</td>
                    <td class="px-4 py-3"><span class="ds-badge {{ $statusBadgeClass($inventory->status) }}">{{ ucfirst(str_replace('_', ' ', $inventory->status)) }}</span></td>
                    <td class="px-4 py-3">{{ $inventory->created_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if($archived)
                            {{ $inventory->archivedBy?->name ?? 'Unknown' }} — {{ $inventory->deleted_at?->format('Y-m-d') }}
                        @else
                            {{ $inventory->lines()->count() }}
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
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
                <tr><td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
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
        @if($inventories->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $inventories->links() }}
        </div>
        @endif
    </div>
    @endif
</div>
@endsection
