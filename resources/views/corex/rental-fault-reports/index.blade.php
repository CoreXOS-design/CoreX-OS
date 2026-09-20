@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §6a — the Rental Fault Reports list
    screen. CRUD/list-screen floor (BUILD_STANDARD §1a-§1d): search (property
    address, title/description), sort (default: reported date, most recent
    first), filter (status, outcome, date range), pagination, real empty
    state, OWN/BRANCH/AGENCY scoping enforced at the query layer
    (RentalFaultReport::scopeVisibleTo()).
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-fault-reports.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match ($status) {
        'resolved' => 'ds-badge-success',
        'reported', 'awaiting_approval' => 'ds-badge-info',
        'declined', 'cancelled' => 'ds-badge-danger',
        default => 'ds-badge-muted',
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Fault Reports</h1>
        @permission('rental_fault_reports.create')
        <a href="{{ route('corex.rental-fault-reports.create') }}" class="corex-btn-primary text-xs">Report a Fault</a>
        @endpermission
    </div>

    <form method="GET" action="{{ route('corex.rental-fault-reports.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, title or description" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['reported', 'awaiting_approval', 'approved', 'declined', 'work_order_raised', 'owner_handling', 'resolved', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Outcome</label><br>
            <select name="outcome" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['repaired', 'repaired_partially', 'not_repaired', 'owner_declined', 'tenant_liable'] as $o)
                    <option value="{{ $o }}" @selected(($filters['outcome'] ?? '') === $o)>{{ ucfirst(str_replace('_', ' ', $o)) }}</option>
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
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'outcome', 'date_from', 'date_to']))
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('property') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-2">Title</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2">Outcome</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('reported_at') }}" style="color: var(--text-muted);">Reported{{ $sortIndicator('reported_at') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($faultReports as $faultReport)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="fault-report-row-{{ $faultReport->id }}">
                    <td class="px-4 py-2">{{ $faultReport->property?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $faultReport->title }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($faultReport->status) }}">{{ ucfirst(str_replace('_', ' ', $faultReport->status)) }}</span></td>
                    <td class="px-4 py-2">{{ $faultReport->outcome ? ucfirst(str_replace('_', ' ', $faultReport->outcome)) : '—' }}</td>
                    <td class="px-4 py-2">{{ $faultReport->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route('corex.rental-fault-reports.show', $faultReport) }}" class="corex-btn-outline text-xs">View</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(!$hasAnyFaultReports)
                        No fault reports yet on this agency. Every reported fault starts here — click "Report a Fault" to log the first one.
                    @else
                        No fault reports match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $faultReports->links() }}
</div>
@endsection
