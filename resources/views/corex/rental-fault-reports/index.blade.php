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
<div class="p-6 space-y-4" x-data="rentalFaultReportsPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Rental Fault Reports</h1>
        @permission('rental_fault_reports.create')
        <a href="{{ route('corex.rental-fault-reports.create') }}" class="corex-btn-primary text-xs">Report a Fault</a>
        @endpermission
    </div>

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. --}}
    @php
        $currentTile = null;
        foreach (['reported', 'awaiting_approval', 'approved', 'declined', 'work_order_raised', 'owner_handling', 'resolved', 'cancelled'] as $s) {
            if (($filters['status'] ?? '') === $s) { $currentTile = $s; break; }
        }
        if (!$currentTile && ($filters['open_no_work_order'] ?? false)) { $currentTile = 'open_no_work_order'; }
        if (!$currentTile && !($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'reported' => ['label' => 'Reported', 'params' => ['status' => 'reported']],
            'awaiting_approval' => ['label' => 'Awaiting approval', 'params' => ['status' => 'awaiting_approval']],
            'approved' => ['label' => 'Approved', 'params' => ['status' => 'approved']],
            'declined' => ['label' => 'Declined', 'params' => ['status' => 'declined']],
            'work_order_raised' => ['label' => 'Work order raised', 'params' => ['status' => 'work_order_raised']],
            'owner_handling' => ['label' => 'Owner handling', 'params' => ['status' => 'owner_handling']],
            'resolved' => ['label' => 'Resolved', 'params' => ['status' => 'resolved']],
            'cancelled' => ['label' => 'Cancelled', 'params' => ['status' => 'cancelled']],
            'open_no_work_order' => ['label' => 'Open, no work order', 'params' => ['open_no_work_order' => 1]],
        ];
        $tileClearParams = ['status' => null, 'open_no_work_order' => null, 'page' => null];
        $tileHref = fn ($key, $def) => route('corex.rental-fault-reports.index', array_merge(
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
        route-name="corex.rental-fault-reports.index"
        :per-page="$perPage"
        :per-page-options="$perPageOptions"
        :archivable="true"
        :archived="$showArchived"
        :print-url="route('corex.rental-fault-reports.print-list', request()->query())"
        :export-xlsx-url="route('corex.rental-fault-reports.export', array_merge(request()->query(), ['format' => 'xlsx']))"
        :export-csv-url="route('corex.rental-fault-reports.export', array_merge(request()->query(), ['format' => 'csv']))"
    />

    <form method="GET" action="{{ route('corex.rental-fault-reports.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
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
        <div class="relative">
            <label class="text-xs" style="color: var(--text-muted);">Property</label><br>
            {{-- Only properties with a fault report visible to this user —
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
        @if(request()->hasAny(['q', 'status', 'outcome', 'property_id', 'date_from', 'date_to']))
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <script>
    function rentalFaultReportsPropertyFilter(old) {
        old = old || {};
        return {
            propertyQuery: old.propertyLabel || '', propertyResults: [],
            selectedPropertyId: old.propertyId || '',
            async searchProperties() {
                if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
                const res = await fetch('{{ route('corex.rental-fault-reports.search-properties', ['archived' => $showArchived ? 1 : 0]) }}&q=' + encodeURIComponent(this.propertyQuery));
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

    {{-- §"Navigation" — reached via a link (property tab, lease's own page,
         contact's own page), never a dropdown of every property/lease. --}}
    @if($filteredProperty || $filteredLease)
    <div class="text-xs flex items-center gap-2" style="color: var(--text-muted);">
        Filtered to:
        @if($filteredProperty)
            <span class="ds-badge ds-badge-info">{{ $filteredProperty->buildDisplayAddress() }}</span>
        @endif
        @if($filteredLease)
            <span class="ds-badge ds-badge-info">{{ $filteredLease->tenantNames() ?: ('Lease #' . $filteredLease->id) }}</span>
        @endif
        <a href="{{ route('corex.rental-fault-reports.index', request()->except(['property_id', 'lease_id', 'page'])) }}" class="underline">Clear</a>
    </div>
    @endif

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
                    <td class="px-4 py-2">{{ $faultReport->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $faultReport->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-2">{{ $faultReport->title }}</td>
                    <td class="px-4 py-2">
                        @if($showArchived)
                            <span class="ds-badge ds-badge-muted">Archived {{ $faultReport->deleted_at?->format('Y-m-d') }}</span>
                        @else
                            <span class="ds-badge {{ $statusBadgeClass($faultReport->status) }}">{{ ucfirst(str_replace('_', ' ', $faultReport->status)) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2">{{ $faultReport->outcome ? ucfirst(str_replace('_', ' ', $faultReport->outcome)) : '—' }}</td>
                    <td class="px-4 py-2">{{ $faultReport->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-2 text-right">
                        @if($showArchived)
                            @permission('rental_fault_reports.create')
                            <form method="POST" action="{{ route('corex.rental-fault-reports.restore', $faultReport->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                            @endpermission
                        @else
                            <a href="{{ route('corex.rental-fault-reports.show', $faultReport) }}" class="corex-btn-outline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if($showArchived)
                        No archived fault reports on this agency.
                    @elseif(!$hasAnyFaultReports)
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
