@extends('layouts.corex')

{{--
    .ai/specs/rental-inspections.md §5 — the tracked/searchable list of every
    inspection. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search
    (property address, tenant name, agent name), sort (default: scheduled
    date, most-recent-first), filter (status, type, date range, has an
    unresolved discrepancy), pagination, real empty state, OWN/BRANCH/AGENCY
    scoping enforced at the query layer (RentalInspection::scopeVisibleTo()).
    Recording an inspection's actual observations/photos/signatures happens
    on the property's Rental Images tab (§1/§4) — this screen is Read plus
    administrative lifecycle only.
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-inspections.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'awaiting_signature' => 'ds-badge-info',
        'in_progress' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Inspections</h1>
    </div>

    <form method="GET" action="{{ route('corex.rental-inspections.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, tenant or agent name" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['draft', 'in_progress', 'awaiting_signature', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['in' => 'In-inspection', 'out' => 'Out-inspection', 'ad_hoc' => 'Ad-hoc'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Scheduled from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Scheduled to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <label class="flex items-center gap-1.5 text-xs pb-2" style="color: var(--text-secondary);">
            <input type="checkbox" name="has_unresolved_discrepancy" value="1" @checked(!empty($filters['has_unresolved_discrepancy']))>
            Has unresolved discrepancy
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy']))
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('type') }}" style="color: var(--text-muted);">Type{{ $sortIndicator('type') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('scheduled_for') }}" style="color: var(--text-muted);">Scheduled{{ $sortIndicator('scheduled_for') }}</a></th>
                    <th class="text-left px-4 py-2">Discrepancy</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($inspections as $inspection)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="rental-inspection-row-{{ $inspection->id }}">
                    <td class="px-4 py-2">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $inspection->lease?->tenantNames() ?? '—' }}</td>
                    <td class="px-4 py-2">{{ ucfirst(str_replace('_', '-', $inspection->type)) }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($inspection->status) }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span></td>
                    <td class="px-4 py-2">{{ $inspection->scheduled_for?->format('Y-m-d') ?? '—' }}</td>
                    <td class="px-4 py-2">
                        @if($inspection->hasUnresolvedDiscrepancy())
                            <span class="ds-badge ds-badge-danger">Unresolved</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(!$hasAnyInspections)
                        No inspections yet on this agency. Recording one starts from a property's Rental Images tab.
                    @else
                        No inspections match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $inspections->links() }}
</div>
@endsection
