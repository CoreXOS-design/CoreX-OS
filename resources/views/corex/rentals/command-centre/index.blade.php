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

    LAYOUT (conductor browser-verification fix round 2, 2026-10-04): a
    flex row, not a grid — the needs-action queue is a FIXED narrow column
    (flex-basis 300px, capped at 28% of the row so it never crowds the
    table on an in-between width), the table takes the REST via flex-1.
    This replaces round 1's grid-col-span-2/3 split, which gave the queue
    a full 40% and squeezed the table's columns at normal laptop widths
    (~1125px content). The queue also collapses (header + toggle stay,
    rows hide) — when collapsed it drops out of the flex row entirely so
    the table takes the full width. State remembered per user, server-
    side (RentalCommandCentreUserPreference — same reason a laptop-width
    preference can't live in localStorage alone: it has to still be right
    the next time this same user opens the screen, on any device).
    Deliberately no Alpine anywhere on this page — plain vanilla JS below,
    and native <details> for the row actions menu — so this page carries
    none of the x-data/quote-escaping risk STANDARDS.md's render-gate
    sections exist to catch.

    LAYOUT round 3 (2026-10-04): at 1280px+ with the queue OPEN, the table
    was still clipped on the right (Agent cut off, Actions off-screen —
    the agent could not reach row actions without collapsing the queue).
    Fixed two ways: (1) Agent moved OFF its own column, into a small muted
    second line under Tenant(s) — one fewer column frees real width
    rather than fighting for it; (2) the Actions column is
    `position: sticky; right: 0` on both the header and body cells (with
    an opaque background so scrolled content doesn't show through) — it
    now stays reachable regardless of how wide the rest of the row gets,
    inside the table's own existing `overflow-x-auto` wrapper (so any
    residual horizontal scroll stays local to the table, never the page).
    The Property cell's 2-line clamp was also under-filling (truncating
    after roughly one line's worth of text) — widened from 260px to
    340px and given explicit `line-height`/`max-height`/`white-space:
    normal` alongside `-webkit-line-clamp` so two FULL lines render
    before the ellipsis, not a narrower, height-ambiguous clamp.
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

    <div class="flex flex-col lg:flex-row gap-4 items-start" id="rcc-layout">

        {{-- §3.2 — needs-action queue. One row per item, named by its own
             record (fault/work-order title, or lease tenant), one compact
             action button each, opening THAT specific record. Fixed
             narrow column on wide screens (300px, capped 28%); collapses
             to its first 5 rows on narrow screens. Collapse/expand state
             remembered per user. --}}
        <div id="rcc-queue-collapsed-bar" class="{{ $queueCollapsed ? 'flex' : 'hidden' }} w-full lg:w-auto items-center">
            <button type="button" onclick="corexRccSetQueueCollapsed(false)" class="corex-btn-outline text-xs">Needs action ({{ $queue->total() }}) — Expand</button>
        </div>

        <div id="rcc-queue-panel" class="{{ $queueCollapsed ? 'hidden' : '' }} w-full lg:basis-[300px] lg:max-w-[28%] lg:flex-shrink-0 lg:grow-0 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-3 py-2 flex items-center justify-between gap-2" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm font-semibold">Needs action ({{ $queue->total() }})</span>
                <button type="button" onclick="corexRccSetQueueCollapsed(true)" class="text-xs flex-shrink-0" style="color: var(--text-muted);">Collapse</button>
            </div>
            <div>
                @forelse($queue as $i => $item)
                <div class="px-3 py-2 flex items-center justify-between gap-2 {{ $i >= 5 ? 'hidden lg:flex' : '' }}" style="border-bottom: 1px solid var(--border);">
                    <div class="min-w-0">
                        <div class="text-xs font-medium truncate">{{ $item['detail'] }}{{ $item['age_days'] > 0 ? ' · ' . $item['age_days'] . 'd' : '' }}</div>
                        <div class="text-[11px] truncate" style="color: var(--text-muted);">{{ $item['property']?->buildDisplayAddress() ?? 'Unknown property' }}</div>
                    </div>
                    <a href="{{ route($item['route'], $item['route_params']) }}" class="corex-btn-outline text-[11px] px-2 py-1 flex-shrink-0">{{ $item['label'] }}</a>
                </div>
                @empty
                <div class="px-3 py-6 text-center text-sm" style="color: var(--text-muted);">Nothing needs action right now.</div>
                @endforelse
            </div>
            @if($queue->hasPages())
            <div class="px-3 py-2">{{ $queue->links() }}</div>
            @endif
        </div>

        {{-- §3.3/§4 — full table: search, status/agent/branch/date filters, sort, pagination, empty state. --}}
        <div class="w-full lg:flex-1 min-w-0 space-y-4">
            <form method="GET" action="{{ route('corex.rentals.command-centre.index') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="scope" value="{{ $scope }}">
                @if($filters['tile'])<input type="hidden" name="tile" value="{{ $filters['tile'] }}">@endif
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
                    <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Address, tenant, landlord, or erf number" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
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
                            <th class="text-left px-3 py-2"><a href="{{ $sortLink('address') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('address') }}</a></th>
                            <th class="text-left px-3 py-2"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                            <th class="text-left px-3 py-2">Tenant(s)</th>
                            <th class="text-left px-3 py-2"><a href="{{ $sortLink('lease_end') }}" style="color: var(--text-muted);">Lease end{{ $sortIndicator('lease_end') }}</a></th>
                            <th class="text-left px-3 py-2"><a href="{{ $sortLink('open_total') }}" style="color: var(--text-muted);" title="Open faults · open work orders">Open{{ $sortIndicator('open_total') }}</a></th>
                            <th class="text-left px-3 py-2"><a href="{{ $sortLink('last_inspection') }}" style="color: var(--text-muted);">Last inspection{{ $sortIndicator('last_inspection') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; right: 0; background: var(--surface);"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($properties as $property)
                        <tr style="border-bottom: 1px solid var(--border);" data-qa="rcc-row-{{ $property->id }}">
                            <td class="px-3 py-2" style="max-width: 340px;">
                                <span title="{{ $property->buildDisplayAddress() }}" style="display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: normal; line-height: 1.3; max-height: 2.6em;">{{ $property->buildDisplayAddress() }}</span>
                            </td>
                            <td class="px-3 py-2"><span class="ds-badge {{ $statusBadgeClass($property->status) }}">{{ $humanise($property->status) }}</span></td>
                            <td class="px-3 py-2">
                                <div>{{ $property->active_lease_id ? ($tenantNamesByLeaseId[$property->active_lease_id] ?? 'No tenant linked') : '— vacant —' }}</div>
                                {{-- Agent — merged into this cell (round 3 layout fix) to free a whole
                                     column's width for the sticky Actions column at 1280px with the
                                     queue open. --}}
                                <div class="text-[11px]" style="color: var(--text-muted);">{{ $property->agent?->name ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $property->active_end_date ? \Illuminate\Support\Carbon::parse($property->active_end_date)->format('Y-m-d') : ($property->active_month_to_month ? 'Month-to-month' : '—') }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                @if((int) $property->open_faults_count > 0)
                                    <a href="{{ route('corex.rental-fault-reports.index', ['property_id' => $property->id]) }}">{{ (int) $property->open_faults_count }} F</a>
                                @else
                                    <span style="color: var(--text-muted);">0 F</span>
                                @endif
                                ·
                                @if((int) $property->open_work_orders_count > 0)
                                    <a href="{{ route('corex.rental-work-orders.index', ['property_id' => $property->id]) }}">{{ (int) $property->open_work_orders_count }} WO</a>
                                @else
                                    <span style="color: var(--text-muted);">0 WO</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $property->last_inspection_at ? \Illuminate\Support\Carbon::parse($property->last_inspection_at)->format('Y-m-d') : 'Never' }}</td>
                            <td class="px-3 py-2 text-right" style="position: sticky; right: 0; background: var(--surface); min-width: 90px;">
                                <details class="relative inline-block">
                                    <summary class="corex-btn-outline text-xs cursor-pointer list-none" style="display: inline-block;">Actions ▾</summary>
                                    <div class="absolute right-0 z-10 mt-1 rounded-md text-xs" style="background: var(--surface); border: 1px solid var(--border); min-width: 160px; box-shadow: 0 2px 8px rgba(0,0,0,0.12);">
                                        @if($property->active_lease_id)
                                        <a href="{{ route('corex.leases.show', $property->active_lease_id) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Open lease</a>
                                        @endif
                                        <a href="{{ route('corex.properties.show', $property->id) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Open property</a>
                                        <a href="{{ route('corex.rental-fault-reports.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Report fault</a>
                                        <a href="{{ route('corex.rental-work-orders.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">New work order</a>
                                        <a href="{{ route('corex.rental-inspections.create', ['property_id' => $property->id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Start inspection</a>
                                    </div>
                                </details>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
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
    </div>
</div>

<script>
function corexRccSetQueueCollapsed(collapsed) {
    document.getElementById('rcc-queue-panel').classList.toggle('hidden', collapsed);
    var bar = document.getElementById('rcc-queue-collapsed-bar');
    bar.classList.toggle('hidden', !collapsed);
    bar.classList.toggle('flex', collapsed);

    fetch('{{ route('corex.rentals.command-centre.preference') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ preference_key: 'queue_collapsed', value: collapsed })
    });
}
</script>
@endsection
