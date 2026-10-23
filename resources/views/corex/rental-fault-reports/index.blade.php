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
    $statusTone = fn ($status) => match ($status) {
        'resolved' => '--ds-green',
        'reported', 'under_review', 'awaiting_approval' => '--brand-icon',
        'declined', 'cancelled' => '--ds-crimson',
        default => '--text-muted',
    };
    $badge = fn (string $tone) => match ($tone) {
        '--ds-green' => 'ds-badge-success',
        '--ds-amber' => 'ds-badge-warning',
        '--ds-crimson' => 'ds-badge-danger',
        '--brand-icon' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
@endphp

@section('corex-content')
<div class="w-full space-y-4" x-data="rentalFaultReportsPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Fault Reports</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every fault reported on a rental property, and where each one stands.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('rental_fault_reports.create')
                <a href="{{ route('corex.rental-fault-reports.create') }}" class="corex-btn-primary text-xs">Report a Fault</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- .ai/specs/rentals-menu-tabs.md - Fault Reports is a tab of Rentals > Maintenance --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\MaintenanceTabs::class])

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
            'reported' => ['label' => \App\Models\RentalFaultReport::statusWord('reported'), 'params' => ['status' => 'reported']],
            'under_review' => ['label' => \App\Models\RentalFaultReport::statusWord('under_review'), 'params' => ['status' => 'under_review']],
            'awaiting_approval' => ['label' => \App\Models\RentalFaultReport::statusWord('awaiting_approval'), 'params' => ['status' => 'awaiting_approval']],
            'approved' => ['label' => \App\Models\RentalFaultReport::statusWord('approved'), 'params' => ['status' => 'approved']],
            'declined' => ['label' => \App\Models\RentalFaultReport::statusWord('declined'), 'params' => ['status' => 'declined']],
            'work_order_raised' => ['label' => \App\Models\RentalFaultReport::statusWord('work_order_raised'), 'params' => ['status' => 'work_order_raised']],
            'owner_handling' => ['label' => \App\Models\RentalFaultReport::statusWord('owner_handling'), 'params' => ['status' => 'owner_handling']],
            'resolved' => ['label' => \App\Models\RentalFaultReport::statusWord('resolved'), 'params' => ['status' => 'resolved']],
            'cancelled' => ['label' => \App\Models\RentalFaultReport::statusWord('cancelled'), 'params' => ['status' => 'cancelled']],
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

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);">
    <form method="GET" action="{{ route('corex.rental-fault-reports.index') }}" class="flex flex-wrap items-end gap-3">
        @if($showArchived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, title or description" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ filled($filters['status'] ?? null) ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                @foreach(['reported', 'under_review', 'awaiting_approval', 'approved', 'declined', 'work_order_raised', 'owner_handling', 'resolved', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ (new \App\Models\RentalFaultReport(['status' => $s]))->statusLabel() }}</option>
                @endforeach
            </select>
        </div>
        <div class="relative flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Property</label>
            {{-- Only properties with a fault report visible to this user —
                 never every rental property (that's the create form's own
                 job, unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()"
                   placeholder="Search properties by address…"
                   class="list-header-filter text-sm" :class="{ 'is-active': propertyQuery }">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto" style="top:100%; background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-xs" style="color:var(--text-primary);">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Outcome</label>
            <select name="outcome" class="list-header-filter text-sm {{ filled($filters['outcome'] ?? null) ? 'is-active' : '' }}">
                <option value="">All outcomes</option>
                @foreach(['repaired', 'repaired_partially', 'not_repaired', 'owner_declined', 'tenant_liable'] as $o)
                    <option value="{{ $o }}" @selected(($filters['outcome'] ?? '') === $o)>{{ ucfirst(str_replace('_', ' ', $o)) }}</option>
                @endforeach
            </select>
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
            <input type="checkbox" name="open" value="1" @checked(($filters['open'] ?? false)) data-qa="fault-filter-open">
            Open only
        </label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        @if(request()->hasAny(['q', 'status', 'outcome', 'property_id', 'date_from', 'date_to', 'open']))
            <a href="{{ route('corex.rental-fault-reports.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
    </form>
    </div>

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
            <span class="ds-badge {{ $badge('--brand-icon') }}">{{ $filteredProperty->buildDisplayAddress() }}</span>
        @endif
        @if($filteredLease)
            <span class="ds-badge {{ $badge('--brand-icon') }}">{{ $filteredLease->tenantNames() ?: ('Lease #' . $filteredLease->id) }}</span>
        @endif
        <a href="{{ route('corex.rental-fault-reports.index', request()->except(['property_id', 'lease_id', 'page'])) }}" class="underline">Clear</a>
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
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Outcome</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('reported_at') }}">Reported{{ $sortIndicator('reported_at') }}</a></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($faultReports as $faultReport)
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-secondary);" data-qa="fault-report-row-{{ $faultReport->id }}">
                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $faultReport->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $faultReport->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-3">@if($faultReport->isOpenEmergency())<span class="ds-badge ds-badge-danger" data-emergency-flag>Emergency</span> @endif{{ $faultReport->title }}</td>
                    <td class="px-4 py-3">
                        @if($showArchived)
                            <span class="ds-badge {{ $badge('--text-muted') }}">Archived {{ $faultReport->deleted_at?->format('Y-m-d') }}</span>
                        @else
                            <span class="ds-badge {{ $badge($statusTone($faultReport->status)) }}">{{ $faultReport->statusLabel() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $faultReport->outcome ? ucfirst(str_replace('_', ' ', $faultReport->outcome)) : '—' }}</td>
                    <td class="px-4 py-3">{{ $faultReport->reported_at?->format('Y-m-d') }}</td>
                    <td class="px-4 py-3 text-right">
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
                <tr><td colspan="6" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
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
        @if($faultReports->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $faultReports->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
