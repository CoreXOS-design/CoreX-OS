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

    {{-- AT-439 — own/branch/all "Showing:" control, same component/markup as
         rental-applications' own (corex/rental-applications/index.blade.php) —
         options built from $scopeOptions (the user's real ceiling; a wider
         pill never renders), highlighted from $resolvedScope. --}}
    @if(count($scopeOptions) > 1)
    <div class="flex items-center gap-2">
        <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
        <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
            @foreach($scopeOptions as $i => $sc)
            <a href="{{ route('corex.leases.index', array_merge(request()->except(['scope', 'page']), ['scope' => $sc])) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ $resolvedScope === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }}</a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- §39, 2026-09-28 — summary tiles row, the same reused FICA/rental-
         applications tab-tile pattern (compliance/fica/index.blade.php),
         never a new design. One row, no helper text. --}}
    @php
        $currentTile = null;
        if (($filters['status'] ?? '') === 'draft') { $currentTile = 'draft'; }
        elseif (($filters['status'] ?? '') === 'active') { $currentTile = 'active'; }
        elseif (($filters['status'] ?? '') === 'expired') { $currentTile = 'expired'; }
        elseif (($filters['status'] ?? '') === 'cancelled') { $currentTile = 'cancelled'; }
        elseif ($filters['expiring_soon'] ?? false) { $currentTile = 'expiring_soon'; }

        $tileDefs = [
            'draft' => ['label' => 'Draft', 'params' => ['status' => 'draft']],
            'active' => ['label' => 'Active', 'params' => ['status' => 'active']],
            'expired' => ['label' => 'Expired', 'params' => ['status' => 'expired']],
            'cancelled' => ['label' => 'Cancelled', 'params' => ['status' => 'cancelled']],
            'expiring_soon' => ['label' => 'Expiring soon', 'params' => ['expiring_soon' => 1]],
        ];
        $tileClearParams = ['status' => null, 'expiring_soon' => null, 'page' => null];
        $tileHref = fn ($key, $def) => route('corex.leases.index', array_merge(
            request()->except(array_keys($tileClearParams)),
            $currentTile === $key ? $tileClearParams : array_merge($tileClearParams, $def['params'])
        ));
    @endphp
    <div class="flex flex-wrap gap-1 text-sm font-medium" style="border-bottom: 1px solid var(--border);">
        @foreach($tileDefs as $key => $def)
            @php $active = $currentTile === $key; @endphp
            <a href="{{ $tileHref($key, $def) }}"
               class="px-4 py-2 transition-colors"
               style="{{ $active
                    ? 'color: var(--brand-icon, #0ea5e9); border-bottom: 2px solid var(--brand-icon, #0ea5e9); font-weight:600;'
                    : 'color: var(--text-secondary); border-bottom: 2px solid transparent;' }}">
                {{ $def['label'] }}
                <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full" style="background: var(--surface-2); color: var(--text-secondary);">{{ number_format($tileCounts[$key]) }}</span>
            </a>
        @endforeach
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
