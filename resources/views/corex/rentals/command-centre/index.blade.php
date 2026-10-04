@extends('layouts.corex')

{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    .ai/specs/rental-command-centre.md — AT-441. Every rental property's
    current state, in one place: tiles (clickable filters), a needs-action
    queue, and the full property list. Search/sort/filter/pagination/
    empty-state/own-branch-all scoping per BUILD_STANDARD §1a-§1d. Every
    action button on this screen navigates into the OWNING screen's own
    permission-gated route — this screen never creates, edits, or archives
    anything itself.
--}}

@php
    $tileHref = fn ($key) => route('corex.rentals.command-centre.index', array_merge(
        request()->except(['tile', 'page']),
        $filters['tile'] === $key ? ['tile' => null] : ['tile' => $key]
    ));
    $sortLink = fn ($col) => route('corex.rentals.command-centre.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortIndicator = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusBadgeClass = fn ($status) => match (true) {
        in_array($status, ['withdrawn', 'unavailable', 'archived'], true) => 'ds-badge-muted',
        in_array($status, ['sold', 'sold_by_3rd_party'], true) => 'ds-badge-danger',
        default => 'ds-badge-success',
    };
    $humanise = fn ($value) => $value ? ucwords(str_replace('_', ' ', (string) $value)) : '—';
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <h1 class="text-lg font-semibold">Rental Command Centre</h1>
        <div class="flex items-center gap-3">
            @if(count($scopeOptions) > 1)
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    @foreach($scopeOptions as $i => $sc)
                    <a href="{{ route('corex.rentals.command-centre.index', array_merge(request()->except(['scope', 'page']), ['scope' => $sc])) }}"
                       class="px-3 py-1.5 text-xs font-semibold"
                       style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ $scope === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }}</a>
                    @endforeach
                </div>
            </div>
            @endif
            <a href="{{ route('corex.rentals.command-centre.print', request()->query()) }}" target="_blank" class="corex-btn-outline text-xs">Print</a>
            @if(\Illuminate\Support\Facades\Route::has('corex.rentals.reports.index'))
            <a href="{{ route('corex.rentals.reports.index') }}" class="corex-btn-outline text-xs">Reports</a>
            @endif
        </div>
    </div>

    {{-- §3.1 — ten clickable tiles, Properties-screen pstat-v2 pattern. --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
        @foreach($tiles as $key => $label)
        @php $active = $filters['tile'] === $key; @endphp
        <a href="{{ $tileHref($key) }}"
           class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"
           style="{{ $active ? 'border-color:color-mix(in srgb, var(--brand-icon,#6366f1) 40%, transparent);background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface));' : '' }}">
            <div class="min-w-0">
                <div class="text-lg font-bold leading-none tabular-nums" style="color:var(--text-primary);">{{ number_format((int) $tileCounts[$key]) }}</div>
                <div class="text-[0.6875rem] font-medium mt-0.5 uppercase tracking-wider" style="color:var(--text-muted);">{{ $label }}</div>
            </div>
        </a>
        @endforeach
    </div>

    {{-- §3.2 — needs-action queue. One row per item, one action button each. --}}
    <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-2 text-sm font-semibold" style="border-bottom: 1px solid var(--border);">Needs action ({{ $queue->total() }})</div>
        <table class="w-full text-sm">
            <tbody>
                @forelse($queue as $item)
                <tr style="border-bottom: 1px solid var(--border);">
                    <td class="px-4 py-2" style="color: var(--text-muted);">{{ $item['property']?->buildDisplayAddress() ?? 'Unknown property' }}</td>
                    <td class="px-4 py-2">{{ $item['detail'] }}</td>
                    <td class="px-4 py-2 text-right" style="color: var(--text-muted);">{{ $item['age_days'] > 0 ? $item['age_days'] . ' day' . ($item['age_days'] === 1 ? '' : 's') : '' }}</td>
                    <td class="px-4 py-2 text-right">
                        <a href="{{ route($item['route'], $item['route_params']) }}" class="corex-btn-outline text-xs">{{ $item['label'] }}</a>
                    </td>
                </tr>
                @empty
                <tr><td class="px-4 py-6 text-center text-sm" style="color: var(--text-muted);">Nothing needs action right now.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if($queue->hasPages())
        <div class="px-4 py-2">{{ $queue->links() }}</div>
        @endif
    </div>

    {{-- §3.3/§4 — full table: search, status/agent/branch/date filters, sort, pagination, empty state. --}}
    <form method="GET" action="{{ route('corex.rentals.command-centre.index') }}" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="scope" value="{{ $scope }}">
        @if($filters['tile'])<input type="hidden" name="tile" value="{{ $filters['tile'] }}">@endif
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Address, tenant, or erf number" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Status</label><br>
            <select name="status" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($statusOptions as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $humanise($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Agent</label><br>
            <select name="agent_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((string) ($filters['agent_id'] ?? '') === (string) $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Branch</label><br>
            <select name="branch_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                <option value="">All</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Lease end from</label><br>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Lease end to</label><br>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs" style="color: var(--text-muted);">Per page</label><br>
            <select name="per_page" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                @foreach([10, 25, 50, 100] as $pp)
                    <option value="{{ $pp }}" @selected($properties->perPage() === $pp)>{{ $pp }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline text-xs">Filter</button>
        @if(request()->hasAny(['q', 'status', 'agent_id', 'branch_id', 'date_from', 'date_to', 'tile']))
            <a href="{{ route('corex.rentals.command-centre.index', ['scope' => $scope]) }}" class="corex-btn-outline text-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('address') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('address') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                    <th class="text-left px-4 py-2">Tenant(s)</th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('lease_end') }}" style="color: var(--text-muted);">Lease end{{ $sortIndicator('lease_end') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('open_faults') }}" style="color: var(--text-muted);">Open faults{{ $sortIndicator('open_faults') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('open_work_orders') }}" style="color: var(--text-muted);">Open work orders{{ $sortIndicator('open_work_orders') }}</a></th>
                    <th class="text-left px-4 py-2"><a href="{{ $sortLink('last_inspection') }}" style="color: var(--text-muted);">Last inspection{{ $sortIndicator('last_inspection') }}</a></th>
                    <th class="text-left px-4 py-2">Agent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($properties as $property)
                <tr style="border-bottom: 1px solid var(--border);" data-qa="rcc-row-{{ $property->id }}">
                    <td class="px-4 py-2">{{ $property->buildDisplayAddress() }}</td>
                    <td class="px-4 py-2"><span class="ds-badge {{ $statusBadgeClass($property->status) }}">{{ $humanise($property->status) }}</span></td>
                    <td class="px-4 py-2">{{ $property->active_lease_id ? ($tenantNamesByLeaseId[$property->active_lease_id] ?? 'No tenant linked') : '— vacant —' }}</td>
                    <td class="px-4 py-2">{{ $property->active_end_date ? \Illuminate\Support\Carbon::parse($property->active_end_date)->format('Y-m-d') : ($property->active_month_to_month ? 'Month-to-month' : '—') }}</td>
                    <td class="px-4 py-2">{{ (int) $property->open_faults_count }}</td>
                    <td class="px-4 py-2">{{ (int) $property->open_work_orders_count }}</td>
                    <td class="px-4 py-2">{{ $property->last_inspection_at ? \Illuminate\Support\Carbon::parse($property->last_inspection_at)->format('Y-m-d') : 'Never' }}</td>
                    <td class="px-4 py-2">{{ $property->agent?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @if($property->active_lease_id)
                        <a href="{{ route('corex.leases.show', $property->active_lease_id) }}" class="corex-btn-outline text-xs">Open lease</a>
                        @endif
                        <a href="{{ route('corex.properties.show', $property->id) }}" class="corex-btn-outline text-xs">Open property</a>
                        <a href="{{ route('corex.rental-fault-reports.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="corex-btn-outline text-xs">Report fault</a>
                        <a href="{{ route('corex.rental-work-orders.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="corex-btn-outline text-xs">New work order</a>
                        <a href="{{ route('corex.rental-inspections.create', ['property_id' => $property->id]) }}" class="corex-btn-outline text-xs">Start inspection</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                    @if(!$hasAnyRentalProperties)
                        No rental properties yet. Mark a property as a rental listing to see it here.
                    @else
                        Nothing matches this filter. Try clearing a filter.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $properties->links() }}
</div>
@endsection
