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

    <div class="flex flex-col lg:flex-row gap-4 items-stretch flex-1" id="rcc-layout" style="min-height: 0;">

        {{-- §3.2 — needs-action queue. One row per item, named by its own
             record (fault/work-order title, or lease tenant), one compact
             action button each, opening THAT specific record. Fixed
             narrow column on wide screens (300px, capped 28%); collapses
             to its first 5 rows on narrow screens. Collapse/expand state
             remembered per user.

             LAYOUT round 4 (2026-10-05, Johan) — this panel is its own
             scroll container (#rcc-queue-scroll) filling the space between
             its sticky header/controls and its pinned-at-bottom pagination,
             so the page itself never has to scroll to reach a queue row,
             and scrolling this panel never moves the properties table. --}}
        <div id="rcc-queue-collapsed-bar" class="{{ $queueCollapsed ? 'flex' : 'hidden' }} w-full lg:w-auto items-center">
            <button type="button" onclick="corexRccSetQueueCollapsed(false)" class="corex-btn-outline text-xs">Needs action ({{ $queueTotalCount }}) — Expand</button>
        </div>

        <div id="rcc-queue-panel" class="{{ $queueCollapsed ? 'hidden' : 'flex' }} w-full lg:basis-[300px] lg:max-w-[28%] lg:flex-shrink-0 lg:grow-0 flex-col rounded-md" style="background: var(--surface); border: 1px solid var(--border); min-height: 0;">
            <div class="px-3 py-2 flex items-center justify-between gap-2 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm font-semibold">Needs action ({{ $queueTotalCount }})</span>
                <button type="button" onclick="corexRccSetQueueCollapsed(true)" class="text-xs flex-shrink-0" style="color: var(--text-muted);">Collapse</button>
            </div>

            {{-- Round 6 (2026-10-05, Johan) — one Expand all/Collapse all
                 toggle for the per-group disclosures below; only meaningful
                 (and only shown) once grouping is active. Scoped to the
                 groups actually rendered on this page of the queue, not
                 every group across every queue page — "collapse all" reads
                 as "all I can currently see," matching how the chevrons
                 themselves work. --}}
            @if($queueGroupBy !== 'none')
            <div class="px-3 py-1 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <button type="button" id="rcc-queue-groups-toggle-all" onclick="corexRccToggleAllQueueGroups()" class="text-[11px]" style="color: var(--brand-icon, #0ea5e9); background: none; border: none; cursor: pointer; padding: 0;">Collapse all</button>
            </div>
            @endif

            {{-- Fix B1 (2026-10-05) — sort / group-by / filter by property and
                 date range. Query-layer filtering + the existing own/branch/all
                 scoping happen in RentalCommandCentreService::queueItems();
                 group-by/sort are remembered per user
                 (RentalCommandCentreUserPreference — see resolveQueuePreference()).

                 Round 4 (2026-10-05, Johan: "make the controls fit on ONE
                 line at this panel's width") — flex-nowrap, same technique
                 the right-hand filter bar already uses (Fix B2).

                 Round 5 (2026-10-05, Johan — real-browser check at 1366/1536):
                 round 4's flex-nowrap row still OVERFLOWED this ~300px panel
                 (horizontal scrollbar, last date field cut off) — the
                 overflow-x-auto fallback hid the symptom instead of fixing
                 it. Removed that fallback on purpose: a horizontal
                 scrollbar here is itself the defect, not an acceptable
                 degradation. Fixed by shrinking the three selects further
                 and replacing the two side-by-side date inputs (180px) with
                 a single "Dates" disclosure button (~40px) holding both
                 fields stacked vertically in a dropdown — same net filter,
                 a fraction of the horizontal width. Not teleported (unlike
                 row-actions-popup) because this control sits in the fixed
                 header area of the panel, never inside the independently-
                 scrolling #rcc-queue-scroll — there's no sticky-column
                 stacking context here to escape. --}}
            <form method="GET" action="{{ route('corex.rentals.command-centre.index') }}" class="px-3 py-2 flex flex-nowrap items-center gap-1 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                @foreach(request()->except(['queue_group_by', 'queue_sort', 'queue_property_id', 'queue_date_from', 'queue_date_to', 'queue_page']) as $qk => $qv)
                    @if(!is_array($qv))<input type="hidden" name="{{ $qk }}" value="{{ $qv }}">@endif
                @endforeach
                <select name="queue_group_by" onchange="this.form.submit()" class="rounded-md px-1 py-1 text-[11px] flex-shrink-0 min-w-0" style="border: 1px solid var(--border); width: 56px;" aria-label="Group needs-action by">
                    <option value="none" @selected($queueGroupBy === 'none')>No grp</option>
                    <option value="property" @selected($queueGroupBy === 'property')>By prop</option>
                    <option value="date" @selected($queueGroupBy === 'date')>By date</option>
                </select>
                <select name="queue_sort" onchange="this.form.submit()" class="rounded-md px-1 py-1 text-[11px] flex-shrink-0 min-w-0" style="border: 1px solid var(--border); width: 46px;" aria-label="Sort needs-action by">
                    <option value="urgency" @selected($queueSort === 'urgency')>Urgent</option>
                    <option value="date" @selected($queueSort === 'date')>Date</option>
                    <option value="property" @selected($queueSort === 'property')>Prop</option>
                </select>
                <select name="queue_property_id" onchange="this.form.submit()" class="rounded-md px-1 py-1 text-[11px] flex-shrink-0 min-w-0" style="border: 1px solid var(--border); width: 52px;" aria-label="Filter needs-action by property">
                    <option value="">Prop</option>
                    @foreach($queuePropertyOptions as $p)
                        <option value="{{ $p->id }}" @selected($queuePropertyId === $p->id)>{{ \Illuminate\Support\Str::limit($p->buildDisplayAddress(), 18) }}</option>
                    @endforeach
                </select>
                {{-- "Dates" disclosure — replaces two side-by-side date inputs
                     (180px) with a ~40px button; the two fields live stacked
                     vertically inside the dropdown instead of side by side,
                     so the dropdown itself only needs to be as wide as ONE
                     date input, not two. --}}
                <details id="rcc-queue-dates" class="relative inline-block flex-shrink-0">
                    <summary class="rounded-md px-1 py-1 text-[11px] cursor-pointer list-none text-center" style="border: 1px solid var(--border); width: 40px;" aria-label="Needs-action date range">Dates{{ ($queueDateFrom || $queueDateTo) ? ' •' : '' }}</summary>
                    <div class="absolute z-20 mt-1 rounded-md p-2 space-y-1.5" style="right: 0; width: 132px; background: var(--surface); border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.12);">
                        <label class="block text-[10px]" style="color: var(--text-muted);">From
                            <input type="date" name="queue_date_from" value="{{ $queueDateFrom }}" onchange="this.form.submit()" class="w-full rounded-md px-1 py-1 text-[11px] mt-0.5" style="border: 1px solid var(--border);" aria-label="Needs-action date from">
                        </label>
                        <label class="block text-[10px]" style="color: var(--text-muted);">To
                            <input type="date" name="queue_date_to" value="{{ $queueDateTo }}" onchange="this.form.submit()" class="w-full rounded-md px-1 py-1 text-[11px] mt-0.5" style="border: 1px solid var(--border);" aria-label="Needs-action date to">
                        </label>
                    </div>
                </details>
                @if($queuePropertyId || $queueDateFrom || $queueDateTo)
                <a href="{{ route('corex.rentals.command-centre.index', request()->except(['queue_property_id', 'queue_date_from', 'queue_date_to', 'queue_page'])) }}" class="text-[11px] flex-shrink-0" style="color: var(--text-muted);">Clear</a>
                @endif
            </form>

            <div id="rcc-queue-scroll" class="flex-1 overflow-y-auto" style="min-height: 0;">
                @if($queueGroupBy === 'none')
                    @php($i = 0)
                    @forelse($queue as $item)
                        @include('corex.rentals.command-centre._queue-row', ['item' => $item, 'index' => $i])
                        @php($i++)
                    @empty
                    <div class="px-3 py-6 text-center text-sm" style="color: var(--text-muted);">Nothing needs action right now.</div>
                    @endforelse
                @else
                    @php($i = 0)
                    @forelse($queueGroups as $group)
                        @php($groupCollapsed = in_array($group['key'], $collapsedQueueGroups, true))
                        <button type="button"
                                class="rcc-queue-group-heading w-full items-center justify-between gap-2 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide {{ $i >= 5 ? 'hidden lg:flex' : 'flex' }}"
                                data-group-key="{{ $group['key'] }}"
                                onclick="corexRccToggleQueueGroup(this)"
                                style="background: color-mix(in srgb, var(--brand-icon,#6366f1) 6%, var(--surface)); color: var(--text-muted); border: none; cursor: pointer; text-align: left;">
                            <span class="flex items-center gap-1 min-w-0">
                                <svg class="rcc-queue-group-chevron flex-shrink-0" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="transition: transform 0.15s; transform: rotate({{ $groupCollapsed ? '-90deg' : '0deg' }});"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                <span class="truncate">{{ $group['heading'] }}</span>
                            </span>
                            <span class="flex-shrink-0">({{ $group['items']->count() }})</span>
                        </button>
                        <div class="rcc-queue-group-items {{ $groupCollapsed ? 'hidden' : '' }}" data-group-key="{{ $group['key'] }}">
                            @foreach($group['items'] as $item)
                                @include('corex.rentals.command-centre._queue-row', ['item' => $item, 'index' => $i, 'hidePropertyLine' => $queueGroupBy === 'property'])
                                @php($i++)
                            @endforeach
                        </div>
                    @empty
                    <div class="px-3 py-6 text-center text-sm" style="color: var(--text-muted);">Nothing needs action right now.</div>
                    @endforelse
                @endif
            </div>
            @if($queueGroupBy === 'none' && $queue->hasPages())
            <div class="px-3 py-2 flex-shrink-0" style="border-top: 1px solid var(--border);">{{ $queue->links() }}</div>
            @elseif($queueGroupBy !== 'none' && $queueGroups?->hasPages())
            <div class="px-3 py-2 flex-shrink-0" style="border-top: 1px solid var(--border);">{{ $queueGroups->links() }}</div>
            @endif
        </div>

        {{-- §3.3/§4 — full table: search, status/agent/branch/date filters, sort, pagination, empty state.

             LAYOUT round 4 (2026-10-05, Johan) — its own scroll container
             (#rcc-table-scroll) between the fixed filter bar and the
             pagination pinned at the bottom of this section, matching the
             queue panel's structure; the <thead> is sticky inside that
             container (position: sticky; top: 0) so column headers stay
             visible while rows scroll — independent of the queue panel's
             own scroll, and without the page itself ever needing to scroll
             to reach a row. --}}
        <div id="rcc-table-section" class="w-full lg:flex-1 min-w-0 flex flex-col" style="min-height: 0;">
            {{-- Fix B2 (2026-10-05) — one line at normal desktop widths:
                 labels dropped in favour of aria-label/placeholder, padding
                 and control widths cut down, flex-nowrap with a local
                 horizontal-scroll fallback instead of wrapping to a second
                 line if a narrower agency's data pushes it past the fold. --}}
            <form method="GET" action="{{ route('corex.rentals.command-centre.index') }}" class="flex flex-nowrap items-center gap-1.5 overflow-x-auto flex-shrink-0 mb-2" style="padding-bottom: 2px;">
                <input type="hidden" name="scope" value="{{ $scope }}">
                @if($filters['tile'])<input type="hidden" name="tile" value="{{ $filters['tile'] }}">@endif
                @foreach(request()->only(['queue_group_by', 'queue_sort', 'queue_property_id', 'queue_date_from', 'queue_date_to']) as $qk => $qv)
                    <input type="hidden" name="{{ $qk }}" value="{{ $qv }}">
                @endforeach
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search" aria-label="Search address, tenant, landlord, or erf number" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 120px;">
                <select name="status" aria-label="Status" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 86px;">
                    <option value="">Status</option>
                    @foreach($statusOptions as $s)
                        <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $humanise($s) }}</option>
                    @endforeach
                </select>
                <select name="agent_id" aria-label="Agent" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 90px;">
                    <option value="">Agent</option>
                    @foreach($agents as $a)
                        <option value="{{ $a->id }}" @selected((string) ($filters['agent_id'] ?? '') === (string) $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
                <select name="branch_id" aria-label="Branch" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 86px;">
                    <option value="">Branch</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="Lease end from" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 98px;">
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="Lease end to" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 98px;">
                <select name="per_page" onchange="this.form.submit()" aria-label="Rows per page" class="rounded-md px-2 py-1 text-xs flex-shrink-0" style="border: 1px solid var(--border); width: 54px;">
                    @foreach([10, 25, 50, 100] as $pp)
                        <option value="{{ $pp }}" @selected($properties->perPage() === $pp)>{{ $pp }}</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-outline text-xs flex-shrink-0">Filter</button>
                @if(request()->hasAny(['q', 'status', 'agent_id', 'branch_id', 'date_from', 'date_to', 'tile']))
                    <a href="{{ route('corex.rentals.command-centre.index', array_merge(['scope' => $scope], request()->only(['queue_group_by', 'queue_sort', 'queue_property_id', 'queue_date_from', 'queue_date_to']))) }}" class="corex-btn-outline text-xs flex-shrink-0">Clear</a>
                @endif
            </form>

            <div id="rcc-table-scroll" class="rounded-md flex-1 overflow-auto" style="background: var(--surface); border: 1px solid var(--border); min-height: 0;">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);"><a href="{{ $sortLink('address') }}" style="color: var(--text-muted);">Property{{ $sortIndicator('address') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);"><a href="{{ $sortLink('status') }}" style="color: var(--text-muted);">Status{{ $sortIndicator('status') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);">Tenant(s)</th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);"><a href="{{ $sortLink('lease_end') }}" style="color: var(--text-muted);">Lease end{{ $sortIndicator('lease_end') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);"><a href="{{ $sortLink('open_total') }}" style="color: var(--text-muted);" title="Open faults · open work orders">Open{{ $sortIndicator('open_total') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; background: var(--surface);"><a href="{{ $sortLink('last_inspection') }}" style="color: var(--text-muted);">Last inspection{{ $sortIndicator('last_inspection') }}</a></th>
                            <th class="text-left px-3 py-2" style="position: sticky; top: 0; right: 0; background: var(--surface);"></th>
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
                                <x-row-actions-popup>
                                    @if($property->active_lease_id)
                                    <a href="{{ route('corex.leases.show', $property->active_lease_id) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Open lease</a>
                                    {{-- AT-444 follow-up (2026-10-05) — opens the Lease Hub's matching
                                         "Lease actions" dialog directly (LeaseActionDialogResolver);
                                         ignored by the hub if not valid for the lease's current state. --}}
                                    <a href="{{ route('corex.leases.show', ['lease' => $property->active_lease_id, 'action' => 'renew']) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Renew</a>
                                    <a href="{{ route('corex.leases.show', ['lease' => $property->active_lease_id, 'action' => 'tenant-notice']) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Record notice</a>
                                    @if((int) $property->pending_renewal_draft_count > 0 && $property->pending_renewal_draft_lease_id)
                                    <a href="{{ route('corex.leases.show', $property->pending_renewal_draft_lease_id) }}" class="block px-3 py-2 no-underline" style="color: var(--ds-red, #dc2626);">Cancel renewal draft</a>
                                    @endif
                                    @endif
                                    <a href="{{ route('corex.properties.show', $property->id) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Open property</a>
                                    <a href="{{ route('corex.rental-fault-reports.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Report fault</a>
                                    <a href="{{ route('corex.rental-work-orders.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">New work order</a>
                                    <a href="{{ route('corex.rental-inspections.create', ['property_id' => $property->id, 'lease_id' => $property->active_lease_id]) }}" class="block px-3 py-2 no-underline" style="color: var(--text-primary);">Start inspection</a>
                                </x-row-actions-popup>
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

            <div class="flex-shrink-0 pt-2">{{ $properties->links() }}</div>
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

// Round 6 (2026-10-05, Johan) — per-property/per-date group collapse in the
// needs-action queue, plus one Expand-all/Collapse-all toggle. State is
// read back from the DOM each time (which groups are currently .hidden),
// not tracked in a separate JS array — self-correcting, and the server
// round trip always persists exactly what's actually on screen.
function corexRccAllQueueGroupKeys() {
    return Array.from(document.querySelectorAll('.rcc-queue-group-heading')).map(function (el) {
        return el.getAttribute('data-group-key');
    });
}

function corexRccCollapsedQueueGroupKeys() {
    return Array.from(document.querySelectorAll('.rcc-queue-group-items.hidden')).map(function (el) {
        return el.getAttribute('data-group-key');
    });
}

function corexRccPersistCollapsedQueueGroups(keys) {
    fetch('{{ route('corex.rentals.command-centre.preference') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ preference_key: 'collapsed_queue_groups', value: keys })
    });
}

function corexRccSetQueueGroupCollapsed(key, collapsed) {
    var items = document.querySelector('.rcc-queue-group-items[data-group-key="' + key + '"]');
    var heading = document.querySelector('.rcc-queue-group-heading[data-group-key="' + key + '"]');
    if (!items || !heading) { return; }
    items.classList.toggle('hidden', collapsed);
    var chevron = heading.querySelector('.rcc-queue-group-chevron');
    if (chevron) { chevron.style.transform = collapsed ? 'rotate(-90deg)' : 'rotate(0deg)'; }
}

function corexRccUpdateToggleAllQueueGroupsLabel() {
    var btn = document.getElementById('rcc-queue-groups-toggle-all');
    if (!btn) { return; }
    var all = corexRccAllQueueGroupKeys();
    var collapsed = corexRccCollapsedQueueGroupKeys();
    btn.textContent = (all.length > 0 && collapsed.length >= all.length) ? 'Expand all' : 'Collapse all';
}

function corexRccToggleQueueGroup(button) {
    var key = button.getAttribute('data-group-key');
    var items = document.querySelector('.rcc-queue-group-items[data-group-key="' + key + '"]');
    if (!items) { return; }
    var collapsing = !items.classList.contains('hidden');
    corexRccSetQueueGroupCollapsed(key, collapsing);
    corexRccPersistCollapsedQueueGroups(corexRccCollapsedQueueGroupKeys());
    corexRccUpdateToggleAllQueueGroupsLabel();
}

function corexRccToggleAllQueueGroups() {
    var all = corexRccAllQueueGroupKeys();
    var collapsed = corexRccCollapsedQueueGroupKeys();
    var collapseAll = collapsed.length < all.length;
    all.forEach(function (key) { corexRccSetQueueGroupCollapsed(key, collapseAll); });
    corexRccPersistCollapsedQueueGroups(collapseAll ? all : []);
    corexRccUpdateToggleAllQueueGroupsLabel();
}

document.addEventListener('DOMContentLoaded', corexRccUpdateToggleAllQueueGroupsLabel);

// Round 5 (2026-10-05) — the "Dates" disclosure above is a single control,
// not a per-row popup, so it doesn't need row-actions-popup's teleport
// machinery (it isn't inside a sticky-column/scroll-clip context). It does
// still need the ordinary disclosure courtesies a native <details> doesn't
// give for free: close on outside click and on Escape.
(function () {
    var datesDetails = document.getElementById('rcc-queue-dates');
    if (!datesDetails) { return; }

    document.addEventListener('click', function (e) {
        if (datesDetails.open && !datesDetails.contains(e.target)) {
            datesDetails.open = false;
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && datesDetails.open) {
            datesDetails.open = false;
        }
    });
})();

// Fix B3 (2026-10-05) / round 2 (2026-10-05, same day): the row "Actions ▾"
// popup's own stacking-context fix (position:fixed computed from the
// button) was still painted BEHIND later rows' sticky Actions cells — a
// position:fixed descendant of a sticky table cell does not reliably
// out-rank siblings elsewhere in the tree by z-index alone. Replaced with
// the row-actions-popup component (resources/views/components/row-actions-popup.blade.php),
// which physically teleports the open panel to a direct child of document.body —
// removing it from the table's DOM subtree entirely, not just its paint
// layer, so there is no ancestor stacking context left to be trapped in.
// That component's own shared, once-per-page script now drives
// every one of this table's row-action menus.

// Round 4 (2026-10-05) — fill the remaining viewport height with the two
// panels below the tiles, each scrolling independently, instead of the
// whole page scrolling to reach a row. #rcc-layout's own top is wherever
// the tiles/heading pushed it to (varies by scope-switcher/print-button
// wrapping), so the available height has to be measured, not hardcoded.
//
// Round 6 (2026-10-05, Johan, real-browser check): a flat
// `window.innerHeight - top` estimate still left an outer scrollbar — it
// has no way to know about every padding layer between #rcc-layout and
// the viewport's bottom edge that it doesn't own (<main id="appScroll">'s
// own padding, the shared .hfc-card wrapper <main> renders @section
// ('content') inside, this page's own container padding). Rather than
// hardcode that stack (fragile — it lives in the shared layout, not here,
// and any of it changing silently breaks this number again), measure the
// ACTUAL resulting overflow on #appScroll — the real scrolling element;
// html/body never scroll, by the layout's own h-screen + overflow-hidden
// wrapper — and subtract exactly that much. Self-correcting regardless of
// what's below #rcc-layout or how the QA/demo environment banners (also
// outside this page, above #rcc-layout) change its height.
(function () {
    function applyHeight(px) {
        var queuePanel = document.getElementById('rcc-queue-panel');
        var tableSection = document.getElementById('rcc-table-section');
        if (queuePanel) { queuePanel.style.height = px + 'px'; }
        if (tableSection) { tableSection.style.height = px + 'px'; }
    }

    function sizeScrollPanels() {
        var layout = document.getElementById('rcc-layout');
        if (!layout) { return; }

        var top = layout.getBoundingClientRect().top;
        var height = Math.max(240, window.innerHeight - top);
        applyHeight(height);

        var appScroll = document.getElementById('appScroll');
        if (appScroll) {
            var overflow = appScroll.scrollHeight - appScroll.clientHeight;
            if (overflow > 0) {
                applyHeight(Math.max(240, height - overflow));
            }
        }
    }

    window.addEventListener('resize', sizeScrollPanels);
    document.addEventListener('DOMContentLoaded', sizeScrollPanels);
    // Fonts/images can still reflow the title/tiles row after
    // DOMContentLoaded — re-measure once everything has actually painted.
    window.addEventListener('load', sizeScrollPanels);
    sizeScrollPanels();
})();
</script>
@endsection
