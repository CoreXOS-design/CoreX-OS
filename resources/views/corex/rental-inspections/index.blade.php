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

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="rmnt-insp-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Inspections</h1>
                <p class="text-xs" style="color: var(--text-muted);">Every in, out, interim and routine inspection: when it is scheduled, who attends and any discrepancies still open.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            {{-- §45.7 (Build I-5) — the Due tab: In/Out due + the interim dates the agency loads. --}}
            <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);" data-tour="rmnt-insp-tabs">
                <a href="{{ route('corex.rental-inspections.index') }}" class="px-3 py-1.5 text-xs font-semibold" style="background: var(--brand-icon, #0ea5e9); color: #fff;">All inspections</a>
                <a href="{{ route('corex.rental-inspections.due') }}" data-qa="due-tab" class="px-3 py-1.5 text-xs font-semibold" style="border-left: 1px solid var(--border); background: var(--surface); color: var(--text-muted);">Due</a>
            </div>
                @permission('rental_inspections.create')
                <a href="{{ route('corex.rental-inspections.create') }}" class="corex-btn-primary text-xs" data-tour="rmnt-insp-new">Start Inspection</a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- .ai/specs/rentals-menu-tabs.md - Inspections is a tab of Rentals > Leases --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\LeasesTabs::class])

    {{-- AT-439 Part 3 — shared rental list standard (status tiles + toolbar):
         .ai/specs/rentals-rebuild.md §1.1. First tile = Total. Each tile's own
         href sets the query param(s) that already drive this page's existing
         status/has_unresolved_discrepancy/scheduled filters
         (RentalInspectionController::index()) — clicking the ACTIVE tile
         again clears back to "All" instead of reapplying it. --}}
    @php
        $currentTile = null;
        if (($filters['status'] ?? '') === 'draft') { $currentTile = 'draft'; }
        elseif (($filters['status'] ?? '') === 'in_progress') { $currentTile = 'in_progress'; }
        elseif (($filters['status'] ?? '') === 'awaiting_signature') { $currentTile = 'awaiting_signature'; }
        elseif (($filters['status'] ?? '') === 'completed') { $currentTile = 'completed'; }
        elseif ($filters['has_unresolved_discrepancy'] ?? false) { $currentTile = 'unresolved_discrepancies'; }
        elseif ($scheduled ?? false) { $currentTile = 'scheduled'; }
        elseif (!($filters['status'] ?? null)) { $currentTile = 'total'; }

        $tileDefs = [
            'total' => ['label' => 'Total', 'params' => []],
            'draft' => ['label' => 'Draft', 'params' => ['status' => 'draft']],
            'in_progress' => ['label' => 'In progress', 'params' => ['status' => 'in_progress']],
            'awaiting_signature' => ['label' => 'Awaiting signature', 'params' => ['status' => 'awaiting_signature']],
            'completed' => ['label' => 'Completed', 'params' => ['status' => 'completed']],
            'unresolved_discrepancies' => ['label' => 'Unresolved discrepancies', 'params' => ['has_unresolved_discrepancy' => 1]],
            'scheduled' => ['label' => 'Scheduled (upcoming)', 'params' => ['scheduled' => 1]],
        ];
        $tileClearParams = ['status' => null, 'has_unresolved_discrepancy' => null, 'scheduled' => null, 'page' => null];
        $tileHref = fn ($key, $def) => route('corex.rental-inspections.index', array_merge(
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
        route-name="corex.rental-inspections.index"
        :per-page="$perPage"
        :per-page-options="$perPageOptions"
        :archivable="true"
        :archived="$archived"
        :print-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.print-list', request()->query()) : null"
        :export-xlsx-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.export', array_merge(request()->query(), ['format' => 'xlsx'])) : null"
        :export-csv-url="auth()->user()->hasPermission('rental_inspections.export') ? route('corex.rental-inspections.export', array_merge(request()->query(), ['format' => 'csv'])) : null"
    />

    @error('export')
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson, #e11d48) 8%, transparent); border: 1px solid var(--ds-crimson, #e11d48); color: var(--text-primary);" data-qa="export-refused">{{ $message }}</div>
    @enderror

    {{-- §45.8 (Build I-6b) — the list is narrowed to one lease (the Lease Hub link); never an invisible narrowing. --}}
    @if(! empty($filters['lease_id']))
        <div class="flex items-center gap-2 text-xs" data-qa="lease-filter-chip" style="color: var(--text-secondary);">
            <span class="ds-badge ds-badge-info">Showing inspections for {{ $leaseFilterLabel }}</span>
            <a href="{{ route('corex.rental-inspections.index', request()->except(['lease_id', 'page'])) }}" class="underline" style="color: var(--text-muted);">Show all</a>
        </div>
    @endif

    <div class="rounded-md px-5 py-4" style="background:var(--surface); border:1px solid var(--border);" data-tour="rmnt-insp-filters">
    <form method="GET" action="{{ route('corex.rental-inspections.index') }}" class="flex flex-wrap items-end gap-3">
        @if(! empty($filters['lease_id']))
            <input type="hidden" name="lease_id" value="{{ $filters['lease_id'] }}">
        @endif
        @if($archived)
            <input type="hidden" name="archived" value="1">
        @endif
        <div class="flex flex-col gap-1" data-tour="rmnt-insp-search">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Property address, tenant or agent name" class="list-header-filter text-sm {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-insp-status">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter text-sm {{ filled($filters['status'] ?? null) ? 'is-active' : '' }}">
                <option value="">All statuses</option>
                @foreach(['draft', 'in_progress', 'awaiting_signature', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-insp-type">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Type</label>
            <select name="type" class="list-header-filter text-sm {{ filled($filters['type'] ?? null) ? 'is-active' : '' }}">
                <option value="">All types</option>
                @foreach(['in' => 'In-inspection', 'ad_hoc' => 'Routine', 'interim' => 'Interim', 'out' => 'Out-inspection'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-insp-dates">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Scheduled from</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Scheduled to</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_to'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-insp-inspector">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Inspector</label>
            <select name="inspector_id" class="list-header-filter text-sm {{ filled($filters['inspector_id'] ?? null) ? 'is-active' : '' }}">
                <option value="">All inspectors</option>
                @foreach($inspectorOptions as $inspectorOption)
                    <option value="{{ $inspectorOption->id }}" @selected(($filters['inspector_id'] ?? '') == $inspectorOption->id)>{{ $inspectorOption->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Attendance</label>
            <select name="attendance" class="list-header-filter text-sm {{ filled($filters['attendance'] ?? null) ? 'is-active' : '' }}">
                <option value="">All attendance</option>
                <option value="incomplete" @selected(($filters['attendance'] ?? '') === 'incomplete')>Not fully recorded</option>
                <option value="did_not_attend" @selected(($filters['attendance'] ?? '') === 'did_not_attend')>Someone did not attend</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);" data-tour="rmnt-insp-discrepancy">
            <input type="checkbox" name="has_unresolved_discrepancy" value="1" @checked(!empty($filters['has_unresolved_discrepancy']))>
            Has unresolved discrepancy
        </label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;" data-tour="rmnt-insp-filter-btn">Filter</button>
        @if(request()->hasAny(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy', 'inspector_id', 'attendance', 'lease_id']))
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
            <div class="flex-1"></div>
        <span class="ds-badge ds-badge-pill ds-badge-info">
            {{ number_format($inspections->total()) }} {{ \Illuminate\Support\Str::plural('inspection', $inspections->total()) }}
        </span>
    </form>
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-insp-table">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}">Property{{ $sortIndicator('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">Tenant(s)</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('type') }}">Type{{ $sortIndicator('type') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('scheduled_for') }}">Scheduled{{ $sortIndicator('scheduled_for') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('inspector') }}">Inspector{{ $sortIndicator('inspector') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('attended') }}">Attended{{ $sortIndicator('attended') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">{{ $archived ? 'Archived' : 'Discrepancy' }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($inspections as $inspection)
                <tr style="border-bottom: 1px solid var(--border); color: var(--text-secondary);" data-qa="rental-inspection-row-{{ $inspection->id }}">
                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $inspection->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-3">{{ $inspection->lease?->tenantNames() ?? '—' }}</td>
                    <td class="px-4 py-3">{{ \App\Models\RentalInspection::typeLabel($inspection->type) }}</td>
                    <td class="px-4 py-3"><span class="ds-badge {{ $statusBadgeClass($inspection->status) }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span></td>
                    <td class="px-4 py-3">
                        {{ $inspection->scheduled_for?->format('Y-m-d') ?? $inspection->created_at?->format('Y-m-d') . ' (started)' }}
                        @if($inspection->scheduled_for && $inspection->scheduled_time)
                            {{ substr((string) $inspection->scheduled_time, 0, 5) }}
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $inspection->inspector?->name ?? '—' }}</td>
                    <td class="px-4 py-3" data-qa="attended-cell">
                        {{-- §45.5 — attended of expected; ad-hoc checks and cancelled inspections have no attendance rule. --}}
                        @if($inspection->type === 'ad_hoc' || $inspection->status === 'cancelled' || ! ($expectedCounts[$inspection->id] ?? 0))
                            —
                        @else
                            {{ $attendedCounts[$inspection->id] ?? 0 }} of {{ $expectedCounts[$inspection->id] }}
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($archived)
                            {{ $inspection->archivedBy?->name ?? 'Unknown' }} — {{ $inspection->deleted_at?->format('Y-m-d') }}
                        @elseif($inspection->hasUnresolvedDiscrepancy())
                            <span class="ds-badge ds-badge-danger">Unresolved</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($archived)
                            @permission('rental_inspections.restore')
                            <form method="POST" action="{{ route('corex.rental-inspections.restore', $inspection->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                            </form>
                            @endpermission
                        @else
                            {{-- §43 — a scheduled inspection hasn't been recorded
                                 yet; "Start" opens the SAME property Inspections
                                 tab the original immediate-Start flow always
                                 landed on, alongside "View" (the read-only
                                 detail page). --}}
                            @if($inspection->scheduled_for && $inspection->isRecordable())
                            <a href="{{ route('corex.properties.show', ['property' => $inspection->property_id, 'tab' => 'inspections']) }}" class="corex-btn-primary text-xs">Start</a>
                            @endif
                            <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs" @if($loop->first) data-tour="rmnt-insp-view" @endif>View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);">
                    @if($archived)
                        No archived inspections on this agency.
                    @elseif(!$hasAnyInspections)
                        No inspections yet on this agency. Click "Start Inspection" above, or start one from a property's Rental Images tab.
                    @else
                        No inspections match this search or filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
        @if($inspections->hasPages())
        <div class="px-5 py-4" style="border-top:1px solid var(--border);">
            {{ $inspections->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
