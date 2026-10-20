@extends('layouts.corex')

{{--
    .ai/specs/rental-work-orders.md §14.23 — Job Cards list, design standard.
    Everything on this screen is data or a control. Search / sort / filter / tiles
    / scope counts / paging all come from RentalJobCardListQuery (the same object
    the Print list uses). Header, tiles and filters are fixed; only the table
    scrolls, inside its own panel; the page itself never scrolls.
--}}

@php
    $sortLink = fn ($col) => route('corex.rental-job-cards.index', array_merge(
        request()->except('page'),
        ['sort' => $col, 'direction' => ($sort === $col && $direction === 'asc') ? 'desc' : 'asc']
    ));
    $sortMark = fn ($col) => $sort === $col ? ($direction === 'asc' ? ' ▲' : ' ▼') : '';
    $statusTone = fn ($status) => match ($status) {
        'completed' => '--ds-green',
        'cancelled', 'disputed' => '--ds-crimson',
        'draft' => '--text-muted',
        default => '--brand-icon',
    };
    $badge = fn (string $tone) => match ($tone) {
        '--ds-green' => 'ds-badge-success',
        '--ds-amber' => 'ds-badge-warning',
        '--ds-crimson' => 'ds-badge-danger',
        '--brand-icon' => 'ds-badge-info',
        default => 'ds-badge-muted',
    };
    $money = fn ($v) => $v === null ? '—' : 'R ' . number_format($v, 2, '.', ',');

    $tiles = [
        ['key' => null, 'label' => 'Total', 'value' => $tileCounts['total']],
        ['key' => 'draft', 'label' => 'Draft', 'value' => $tileCounts['draft']],
        ['key' => 'quoted', 'label' => 'Quoted', 'value' => $tileCounts['quoted']],
        ['key' => 'approved', 'label' => 'Approved', 'value' => $tileCounts['approved']],
        ['key' => 'scheduled', 'label' => 'Scheduled', 'value' => $tileCounts['scheduled']],
        ['key' => 'in_progress', 'label' => 'In progress', 'value' => $tileCounts['in_progress']],
        ['key' => 'disputed', 'label' => 'Disputed', 'value' => $tileCounts['disputed'] ?? 0],
        ['key' => 'overdue', 'label' => 'Overdue', 'value' => $tileCounts['overdue']],
        ['key' => 'needs_pricing', 'label' => 'Needs pricing', 'value' => $tileCounts['needs_pricing'] ?? 0],
        // §17.17 — the owner owes an answer: the quote is out (awaiting owner), or extra work was put to him (variation pending).
        ['key' => 'awaiting_owner', 'label' => 'Awaiting owner', 'value' => $tileCounts['awaiting_owner'] ?? 0],
        ['key' => 'variation_pending', 'label' => 'Variation pending', 'value' => $tileCounts['variation_pending'] ?? 0],
        ['key' => 'completed', 'label' => 'Completed', 'value' => $tileCounts['completed']],
        ['key' => 'cancelled', 'label' => 'Cancelled', 'value' => $tileCounts['cancelled']],
    ];
    // Yes/no filters (not statuses): a tile for one of these clears the others, exactly as the status tiles do.
    $flagKeys = ['overdue', 'needs_pricing', 'awaiting_owner', 'variation_pending'];
    $filterKeys = ['q', 'status', 'rental_crew_id', 'property_id', 'date_from', 'date_to', ...$flagKeys, 'archived'];
    $isFiltered = request()->hasAny($filterKeys);
@endphp

@section('corex-content')
<div class="w-full h-full flex flex-col gap-3" x-data="rentalJobCardsPropertyFilter({{ Js::from([
    'propertyId' => $filteredProperty?->id ?? ($filters['property_id'] ?? ''),
    'propertyLabel' => $filteredProperty?->buildDisplayAddress() ?? '',
]) }})">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="rmnt-jc-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Job Cards</h1>
                <p class="text-xs" style="color: var(--text-muted);">Repair and maintenance jobs on rental properties, with their quotes and where each stands.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            @if(count($scopeOptions) > 1)
            <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                @foreach($scopeOptions as $i => $sc)
                <a href="{{ route('corex.rental-job-cards.index', array_merge(request()->except(['scope', 'page']), ['scope' => $sc])) }}"
                   data-scope="{{ $sc }}"
                   class="px-3 py-1.5 text-xs font-semibold"
                   style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ $resolvedScope === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }} <span class="tabular-nums">{{ number_format($scopeCounts[$sc] ?? 0) }}</span></a>
                @endforeach
            </div>
            @endif
            @permission('rental_job_cards.create')
            <a href="{{ route('corex.rental-job-cards.create', request()->only(['property_id', 'lease_id'])) }}" class="corex-btn-primary text-xs" data-tour="rmnt-jc-new">New Job Card</a>
            @endpermission
            <a href="{{ route('corex.rental-job-cards.print-list', request()->query()) }}" target="_blank" class="corex-btn-outline text-xs" data-tour="rmnt-jc-print">Print list</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex-shrink-0" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="grid grid-cols-3 md:grid-cols-5 xl:grid-cols-7 gap-2 flex-shrink-0" data-tour="rmnt-jc-tiles">
        @foreach($tiles as $tile)
            @php
                // overdue, needs_pricing (§17.5.5), awaiting_owner and variation_pending (§17.17) are yes/no filters, not statuses.
                $flagTile = in_array($tile['key'], $flagKeys, true);
                $noFlags = collect($flagKeys)->every(fn ($k) => !request()->boolean($k));
                $tileParams = $flagTile
                    ? array_merge(request()->except(['page', 'status', ...$flagKeys]), [$tile['key'] => 1])
                    : array_merge(request()->except(['page', 'status', ...$flagKeys]), $tile['key'] ? ['status' => $tile['key']] : []);
                $isActive = $flagTile
                    ? request()->boolean($tile['key'])
                    : (request('status') === $tile['key'] && $noFlags)
                        || ($tile['key'] === null && !request('status') && $noFlags);
            @endphp
            <a href="{{ route('corex.rental-job-cards.index', $tileParams) }}"
               data-tile="{{ $tile['key'] ?? 'total' }}"
               class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"
               style="{{ $isActive ? 'border-color:color-mix(in srgb, var(--brand-icon,#6366f1) 40%, transparent);background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface));' : '' }}">
                <div class="min-w-0">
                    <div class="text-lg font-bold leading-none tabular-nums" style="color:var(--text-primary);">{{ number_format((int) $tile['value']) }}</div>
                    <div class="text-[0.6875rem] font-medium mt-0.5 uppercase tracking-wider truncate" style="color:var(--text-muted);">{{ $tile['label'] }}</div>
                </div>
            </a>
        @endforeach
    </div>

    <div class="rounded-md px-5 py-4 flex-shrink-0" style="background:var(--surface); border:1px solid var(--border);" data-tour="rmnt-jc-filters">
    <form method="GET" action="{{ route('corex.rental-job-cards.index') }}" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="scope" value="{{ $resolvedScope }}">
        @if(request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
        @foreach($flagKeys as $flagKey)
            @if(request()->boolean($flagKey))<input type="hidden" name="{{ $flagKey }}" value="1">@endif
        @endforeach
        @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
        @if(request('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
        <div class="flex flex-col gap-1" data-tour="rmnt-jc-search">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" aria-label="Search job cards" class="list-header-filter text-sm w-64 {{ filled($filters['q'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-jc-crew">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Crew</label>
            <select name="rental_crew_id" class="list-header-filter text-sm {{ filled($filters['rental_crew_id'] ?? null) ? 'is-active' : '' }}">
                <option value="">All crews</option>
                @foreach($crews as $c)
                    <option value="{{ $c->id }}" @selected(($filters['rental_crew_id'] ?? null) == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="relative flex flex-col gap-1" data-tour="rmnt-jc-property">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Property</label>
            {{-- Only properties with a job card visible to this user — never
                 every rental property (that's the create form's own job,
                 unaffected). --}}
            <input type="text" x-model="propertyQuery" @input.debounce.300ms="searchProperties()" @input="if (!propertyQuery) selectedPropertyId = ''"
                   aria-label="Filter by property"
                   class="list-header-filter text-sm w-56" :class="{ 'is-active': propertyQuery }">
            <input type="hidden" name="property_id" x-model="selectedPropertyId">
            <div class="absolute z-10 mt-1 rounded-md max-h-72 overflow-y-auto" style="top:100%; background: var(--surface); border: 1px solid var(--border);" x-show="propertyResults.length" x-cloak>
                <template x-for="p in propertyResults" :key="p.id">
                    <button type="button" @click="selectProperty(p)" class="list-row-hover block w-full text-left px-3 py-2 text-xs" style="color:var(--text-primary);">
                        <span x-text="p.label"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex flex-col gap-1" data-tour="rmnt-jc-dates">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Due from</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_from'] ?? '') ? 'is-active' : '' }}">
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium" style="color: var(--text-secondary);">Due to</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="list-header-filter text-sm {{ filled($filters['date_to'] ?? '') ? 'is-active' : '' }}">
        </div>
        <label class="flex items-center gap-2 text-xs" style="height:2.25rem; color: var(--text-secondary);">
            <input type="checkbox" name="archived" value="1" @checked($showArchived)> Show archived
        </label>
        <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;" data-tour="rmnt-jc-filter-btn">Filter</button>
        @if($isFiltered)
            <a href="{{ route('corex.rental-job-cards.index', ['scope' => $resolvedScope]) }}" class="corex-btn-outline text-sm" style="height:2.25rem;">Clear</a>
        @endif
    </form>
    </div>

    <script>
    function rentalJobCardsPropertyFilter(old) {
        old = old || {};
        return {
            propertyQuery: old.propertyLabel || '', propertyResults: [],
            selectedPropertyId: old.propertyId || '',
            async searchProperties() {
                if (this.propertyQuery.length < 2) { this.propertyResults = []; return; }
                const res = await fetch('{{ route('corex.rental-job-cards.search-properties', ['archived' => $showArchived ? 1 : 0]) }}&q=' + encodeURIComponent(this.propertyQuery));
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

    <div class="flex-1 min-h-0 overflow-auto rounded-md" style="background: var(--surface); border: 1px solid var(--border);" data-job-cards-panel data-tour="rmnt-jc-table">
        <table class="w-full text-sm">
            <thead class="sticky top-0 z-[1]">
                <tr style="background: var(--surface-2); color: var(--text-muted); border-bottom: 1px solid var(--border);">
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap">No.</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('property') }}" data-sort="property">Property{{ $sortMark('property') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('title') }}" data-sort="title">Title{{ $sortMark('title') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('tenant') }}" data-sort="tenant">Tenant{{ $sortMark('tenant') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('crew') }}" data-sort="crew">Crew{{ $sortMark('crew') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('status') }}" data-sort="status">Status{{ $sortMark('status') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('due_at') }}" data-sort="due_at">Due{{ $sortMark('due_at') }}</a></th>
                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('created_at') }}" data-sort="created_at">Created{{ $sortMark('created_at') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap"><a class="no-underline transition-colors duration-150" style="color:inherit;" href="{{ $sortLink('total') }}" data-sort="total">Total incl{{ $sortMark('total') }}</a></th>
                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($jobCards as $jc)
                    @php
                        $quote = \App\Services\Rentals\RentalJobCardListQuery::currentQuoteOf($jc);
                        // §17.7.1 — after the owner approves an amount a change is a variation, never a re-send, so "Changed since sent" only applies before that.
                        $changed = $quote ? ($jc->quoteChangedSinceSent($quote) && ! $jc->workOrder?->hasApprovedBaseline()) : false;
                    @endphp
                    <tr style="border-top: 1px solid var(--border); color: var(--text-secondary);" data-job-card-row="{{ $jc->id }}">
                        <td class="px-4 py-3 tabular-nums whitespace-nowrap">#{{ $jc->id }}</td>
                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $jc->property?->buildDisplayAddress() ?? '—' }}{{ $jc->property?->trashed() ? ' (archived)' : '' }}</td>
                        <td class="px-4 py-3">{{ $jc->title }}</td>
                        <td class="px-4 py-3">{{ $jc->lease?->tenantNames() ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $jc->crew?->name ?? ($jc->assigned_user_id ? 'Previously assigned: ' . ($jc->assignedUser?->name ?? '—') : '—') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="ds-badge {{ $badge($statusTone($jc->status)) }}">{{ ucfirst(str_replace('_', ' ', $jc->status)) }}</span>
                            @if($quote && $quote->revision > 1)<span class="ds-badge {{ $badge('--text-muted') }}" data-quote-revision>Rev {{ $quote->revision }}</span>@endif
                            @if($changed)<span class="ds-badge {{ $badge('--ds-amber') }}" data-quote-changed>Changed since sent</span>@endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $jc->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $jc->created_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $money($list->inclusiveTotal($jc)) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('corex.rental-job-cards.show', $jc->id) }}" class="corex-btn-outline text-xs" @if($loop->first) data-tour="rmnt-jc-open" @endif>Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center text-sm" style="color: var(--text-muted);" data-empty-state>
                            @if(!$hasAny && !$isFiltered)
                                <div class="font-medium mb-3">No job cards</div>
                                @permission('rental_job_cards.create')
                                <a href="{{ route('corex.rental-job-cards.create') }}" class="corex-btn-primary text-xs">New Job Card</a>
                                @endpermission
                            @else
                                <div class="font-medium mb-3">No job cards match</div>
                                <a href="{{ route('corex.rental-job-cards.index', ['scope' => $resolvedScope]) }}" class="corex-btn-outline text-xs">Clear filters</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between gap-3 flex-shrink-0 text-xs" style="color: var(--text-muted);" data-job-cards-footer>
        <div class="tabular-nums">
            @if($jobCards->total() > 0){{ number_format($jobCards->firstItem()) }}–{{ number_format($jobCards->lastItem()) }} of {{ number_format($jobCards->total()) }}@else 0 @endif
        </div>
        <div>{{ $jobCards->onEachSide(1)->links() }}</div>
    </div>
</div>
@endsection
