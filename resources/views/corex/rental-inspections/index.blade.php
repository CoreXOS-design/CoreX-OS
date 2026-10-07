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
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-semibold">Rental Inspections</h1>
            {{-- §45.7 (Build I-5) — the Due tab: In/Out due + the interim dates the agency loads. --}}
            <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                <a href="{{ route('corex.rental-inspections.index') }}" class="px-3 py-1.5 text-xs font-semibold" style="background: var(--brand-icon, #0ea5e9); color: #fff;">All inspections</a>
                <a href="{{ route('corex.rental-inspections.due') }}" data-qa="due-tab" class="px-3 py-1.5 text-xs font-semibold" style="border-left: 1px solid var(--border); background: var(--surface); color: var(--text-muted);">Due</a>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @permission('rental_inspections.create')
            <a href="{{ route('corex.rental-inspections.create') }}" class="corex-btn-primary text-xs">Start Inspection</a>
            @endpermission
        </div>
    </div>

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

    <form method="GET" action="{{ route('corex.rental-inspections.index') }}" class="flex flex-wrap items-end gap-3">
        @if(! empty($filters['lease_id']))
            <input type="hidden" name="lease_id" value="{{ $filters['lease_id'] }}">
        @endif
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
                @foreach(['draft', 'in_progress', 'awaiting_signature', 'completed', 'cancelled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
            <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach(['in' => 'In-inspection', 'interim' => 'Interim', 'out' => 'Out-inspection', 'ad_hoc' => 'Ad-hoc'] as $value => $label)
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
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Inspector</label><br>
            <select name="inspector_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($inspectorOptions as $inspectorOption)
                    <option value="{{ $inspectorOption->id }}" @selected(($filters['inspector_id'] ?? '') == $inspectorOption->id)>{{ $inspectorOption->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Attendance</label><br>
            <select name="attendance" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                <option value="incomplete" @selected(($filters['attendance'] ?? '') === 'incomplete')>Not fully recorded</option>
                <option value="did_not_attend" @selected(($filters['attendance'] ?? '') === 'did_not_attend')>Someone did not attend</option>
            </select>
        </div>
        <label class="flex items-center gap-1.5 text-xs pb-2" style="color: var(--text-secondary);">
            <input type="checkbox" name="has_unresolved_discrepancy" value="1" @checked(!empty($filters['has_unresolved_discrepancy']))>
            Has unresolved discrepancy
        </label>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'type', 'date_from', 'date_to', 'has_unresolved_discrepancy', 'inspector_id', 'attendance', 'lease_id']))
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
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('inspector') }}" style="color: var(--text-muted);">Inspector{{ $sortIndicator('inspector') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('attended') }}" style="color: var(--text-muted);">Attended{{ $sortIndicator('attended') }}</a></th>
                    <th class="text-left px-4 py-2">{{ $archived ? 'Archived' : 'Discrepancy' }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($inspections as $inspection)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="rental-inspection-row-{{ $inspection->id }}">
                    <td class="px-4 py-2">{{ $inspection->property?->buildDisplayAddress() ?? 'Unknown property' }}{{ $inspection->property?->trashed() ? ' (archived)' : '' }}</td>
                    <td class="px-4 py-2">{{ $inspection->lease?->tenantNames() ?? '—' }}</td>
                    <td class="px-4 py-2">{{ ucfirst(str_replace('_', '-', $inspection->type)) }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($inspection->status) }}">{{ ucfirst(str_replace('_', ' ', $inspection->status)) }}</span></td>
                    <td class="px-4 py-2">
                        {{ $inspection->scheduled_for?->format('Y-m-d') ?? $inspection->created_at?->format('Y-m-d') . ' (started)' }}
                        @if($inspection->scheduled_for && $inspection->scheduled_time)
                            {{ substr((string) $inspection->scheduled_time, 0, 5) }}
                        @endif
                    </td>
                    <td class="px-4 py-2">{{ $inspection->inspector?->name ?? '—' }}</td>
                    <td class="px-4 py-2" data-qa="attended-cell">
                        {{-- §45.5 — attended of expected; ad-hoc checks and cancelled inspections have no attendance rule. --}}
                        @if($inspection->type === 'ad_hoc' || $inspection->status === 'cancelled' || ! ($expectedCounts[$inspection->id] ?? 0))
                            —
                        @else
                            {{ $attendedCounts[$inspection->id] ?? 0 }} of {{ $expectedCounts[$inspection->id] }}
                        @endif
                    </td>
                    <td class="px-4 py-2">
                        @if($archived)
                            {{ $inspection->archivedBy?->name ?? 'Unknown' }} — {{ $inspection->deleted_at?->format('Y-m-d') }}
                        @elseif($inspection->hasUnresolvedDiscrepancy())
                            <span class="ds-badge ds-badge-danger">Unresolved</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
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
                            <a href="{{ route('corex.rental-inspections.show', $inspection) }}" class="corex-btn-outline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
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

    {{ $inspections->links() }}
</div>
@endsection
