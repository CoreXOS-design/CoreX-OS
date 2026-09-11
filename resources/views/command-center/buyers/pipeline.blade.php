{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    // AT-401 — the Rentals entry point is locked to tenant leads only, so the
    // sale vocabulary ("buyer") throughout this shared view swaps to "tenant"
    // whenever isRentalEntry is true. Same ONE-set-of-code pattern as the
    // listing_type lock itself: a plain variable swap, never a forked view.
    $personNoun = ($isRentalEntry ?? false) ? 'tenant' : 'buyer';
    $personNounPlural = ($isRentalEntry ?? false) ? 'tenants' : 'buyers';
@endphp
<div class="w-full space-y-5">
    {{-- Header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="buyers-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ ($isRentalEntry ?? false) ? 'Rental Pipeline' : 'Buyer Pipeline' }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Track {{ $personNoun }} lifecycle: New → Warm → Cold → Lost</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                {{-- Pipeline scope toggle (Layer 3) --}}
                <div data-tour="buyers-scope" class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir'), ['scope' => 'own'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ ($pipelineScope ?? 'own') === 'own' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Mine</a>
                    @if($canSeeBranch ?? false)
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir'), ['scope' => 'branch'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($pipelineScope ?? '') === 'branch' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Branch</a>
                    @endif
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir'), ['scope' => 'agency'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($pipelineScope ?? '') === 'agency' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">All</a>
                </div>
                {{-- Lead type: Rentals vs Sales (Johan) — a rental (tenant) lead is separated
                     from a sale (buyer) lead by the buyer's wishlist listing_type.
                     AT-401: locked, not merely hidden, on the Rentals entry point —
                     BuyerPipelineController::index() forces lead_type='rental'
                     server-side whenever isRentalEntry is true, so a static label
                     here is honest, not decorative. --}}
                @if($isRentalEntry ?? false)
                    <div class="inline-flex rounded-md overflow-hidden px-3 py-1.5 text-xs font-semibold whitespace-nowrap" style="border: 1px solid var(--border); cursor:default;" title="This entry point always shows rental leads only">Rentals only</div>
                @else
                <div data-tour="buyers-lead-type" class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir')) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ empty($leadType ?? null) ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">All</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir'), ['lead_type' => 'sale'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($leadType ?? '') === 'sale' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Sales</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir'), ['lead_type' => 'rental'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($leadType ?? '') === 'rental' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Rentals</a>
                </div>
                @endif
                {{-- View toggle --}}
                <div data-tour="buyers-view" class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('scope', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir'), ['view' => 'kanban'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ $view === 'kanban' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Kanban</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('scope', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir'), ['view' => 'list'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ $view === 'list' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">List</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Search + filters --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route($indexRouteName ?? 'command-center.buyers.pipeline') }}" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="scope" value="{{ $pipelineScope ?? '' }}">
            @unless($isRentalEntry ?? false)
                <input type="hidden" name="lead_type" value="{{ $leadType ?? '' }}">
            @endunless
            @if(!empty($sortBy ?? null))<input type="hidden" name="sort" value="{{ $sortBy }}">@endif
            @if(!empty($sortDir ?? null))<input type="hidden" name="dir" value="{{ $sortDir }}">@endif
            <input type="text" name="q" value="{{ $search ?? '' }}" placeholder="Search name, phone or email…"
                   class="flex-1 min-w-[200px] px-3 py-1.5 rounded-md text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <select name="state" onchange="this.form.submit()" class="px-3 py-1.5 rounded-md text-xs"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All states</option>
                @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateOptKey => $stateOptLabel)
                    <option value="{{ $stateOptKey }}" {{ ($stateFilter ?? '') === $stateOptKey ? 'selected' : '' }}>{{ $stateOptLabel }}</option>
                @endforeach
            </select>
            <select name="agent_id" onchange="this.form.submit()" class="px-3 py-1.5 rounded-md text-xs max-w-[200px]"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All agents</option>
                @foreach($agentOptions ?? [] as $agentOpt)
                    <option value="{{ $agentOpt->id }}" {{ (string) ($agentFilter ?? '') === (string) $agentOpt->id ? 'selected' : '' }}>{{ $agentOpt->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-outline text-xs">Search</button>
            @if(($search ?? '') !== '' || !empty($stateFilter ?? null) || !empty($agentFilter ?? null))
                <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'scope', 'lead_type'), [])) }}"
                   class="text-xs no-underline" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Prospecting drill-down context banner (set when arriving via a
         "N buyers" badge click from the prospecting tab). --}}
    @if(!empty($contextListing))
        <div class="rounded-md px-4 py-3 flex items-center justify-between gap-3"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);">
            <div class="min-w-0">
                <div class="text-[10px] uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">
                    Filtered to {{ $personNounPlural }} matching prospecting listing
                </div>
                <div class="text-sm font-semibold truncate mt-0.5" style="color: var(--text-primary);">
                    {{ $contextListing->address ?? ('Listing #' . $contextListing->id) }}
                    @if(!empty($contextListing->suburb))
                        <span class="font-normal" style="color: var(--text-muted);">· {{ $contextListing->suburb }}</span>
                    @endif
                    @if(!empty($contextListing->price))
                        <span class="font-normal" style="color: var(--text-muted);">· R {{ number_format($contextListing->price) }}</span>
                    @endif
                    @if(!empty($contextListing->portal_source))
                        <span class="ds-badge {{ $contextListing->portal_source === 'p24' ? 'ds-badge-info' : 'ds-badge-success' }} ml-1">
                            {{ strtoupper($contextListing->portal_source) === 'P24' ? 'P24' : 'PP' }}
                        </span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <a href="{{ route('market-intelligence.show', $contextListing->id) }}"
                   class="text-xs no-underline hover:underline" style="color: var(--brand-icon, #0ea5e9);">View listing →</a>
                <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->except('prospecting_listing_id'), [])) }}"
                   class="text-xs no-underline hover:underline" style="color: var(--text-muted);">Clear filter</a>
            </div>
        </div>
    @endif

    @if($view === 'kanban')
        {{-- Kanban View (drag-drop enabled) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" x-data="kanbanDrag()">
            @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateKey => $stateLabel)
                @php
                    $stateColour = match($stateKey) {
                        'new' => 'var(--brand-icon, #0ea5e9)',
                        'warm' => 'var(--ds-green, #059669)',
                        'cold' => 'var(--ds-amber, #f59e0b)',
                        'lost' => 'var(--ds-crimson, #c41e3a)',
                    };
                    $stateItems = $columns[$stateKey] ?? collect();
                @endphp
                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);"
                     @dragover.prevent="dragOverColumn('{{ $stateKey }}')"
                     @drop.prevent="dropOnColumn('{{ $stateKey }}')"
                     :style="dragTarget === '{{ $stateKey }}' ? 'outline: 2px solid {{ $stateColour }}; outline-offset: -2px;' : ''">
                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 2px solid {{ $stateColour }};">
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $stateLabel }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-bold whitespace-nowrap" style="background: color-mix(in srgb, {{ $stateColour }} 15%, transparent); color: {{ $stateColour }};">{{ number_format($counts[$stateKey] ?? 0) }}</span>
                    </div>
                    <div class="p-2 space-y-2 max-h-[60vh] overflow-y-auto">
                        @forelse($stateItems as $buyer)
                            @php
                                $buyerRisk = $riskScores[$buyer->id] ?? null;
                                $buyerPrimaryWishlist = $buyer->matches->firstWhere('is_primary', true) ?? $buyer->matches->first();
                                // Same rental-lead test as applyLeadTypeFilter() above — the PERSON's
                                // own wishlist decides tenant vs buyer, not which board/entry point
                                // rendered them. A mixed "All" board can show both in one column, so
                                // this must be evaluated per card, never fixed page-wide.
                                $buyerIsRental = in_array(strtolower((string) ($buyerPrimaryWishlist->listing_type ?? '')), ['rental', 'rent', 'to_let', 'to let', 'letting'], true);
                            @endphp
                            <a href="{{ route('command-center.buyers.show', $buyer) }}"
                               draggable="true"
                               @dragstart="startDrag({{ $buyer->id }}, '{{ $stateKey }}', {{ $buyerIsRental ? 'true' : 'false' }})"
                               @dragend="endDrag()"
                               class="block p-3 rounded-md transition hover:opacity-80 no-underline relative cursor-grab active:cursor-grabbing"
                               style="background: var(--surface-2); border: 1px solid var(--border);">
                                @if($buyerRisk !== null && $buyerRisk > 30)
                                    <span class="absolute top-2 right-2 w-2.5 h-2.5 rounded-full"
                                          style="background: {{ $buyerRisk > 60 ? 'var(--ds-crimson, #c41e3a)' : 'var(--ds-amber, #f59e0b)' }};"
                                          title="Lost risk: {{ number_format($buyerRisk) }}/100"></span>
                                @endif
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0"
                                         style="background: {{ $stateColour }};">
                                        {{ strtoupper(substr($buyer->first_name ?? '', 0, 1) . substr($buyer->last_name ?? '', 0, 1)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $buyer->full_name }}</div>
                                        <div class="text-[10px] truncate" style="color: var(--text-muted);">
                                            {{ $buyer->agent?->name ?? 'Unassigned' }}
                                            · Since {{ $buyer->buyer_pipeline_entered_at?->format('d M Y') ?? '—' }}
                                        </div>
                                    </div>
                                </div>
                                @unless($buyer->hasCountableWishlist())
                                    <div class="mt-1">
                                        <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md whitespace-nowrap"
                                              style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);"
                                              title="On the pipeline but has no countable wishlist (search criteria removed), so this {{ $personNoun }} is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
                                    </div>
                                @endunless
                                <div class="flex items-center justify-between text-[10px] mt-1" style="color: var(--text-muted);">
                                    <span>{{ $buyer->last_activity_at ? $buyer->last_activity_at->diffForHumans() : 'No activity' }}</span>
                                    {{-- AT-108 — canonical Core Match count (same number as Core Matches surface + detail). NOT viewings. --}}
                                    <span>{{ number_format($coreMatchCounts->get($buyer->id, 0)) }} matches</span>
                                </div>
                            </a>
                            <div class="grid grid-cols-2 gap-1 mt-1">
                                @if($buyerPrimaryWishlist)
                                <a href="{{ route('corex.contacts.matches.results', [$buyer, $buyerPrimaryWishlist]) }}"
                                   class="block text-center text-[10px] font-medium py-1 rounded-md no-underline hover:opacity-80 transition"
                                   style="color: var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent);">
                                    View Matches
                                </a>
                                @endif
                                <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                                   class="block text-center text-[10px] font-medium py-1 rounded-md no-underline hover:opacity-80 transition {{ $buyerPrimaryWishlist ? '' : 'col-span-2' }}"
                                   style="color: var(--ds-green, #059669); background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);">
                                    Schedule Viewing
                                </a>
                            </div>
                        @empty
                            <div class="py-6 text-center text-xs" style="color: var(--text-muted);">No {{ $personNounPlural }} in this state</div>
                        @endforelse
                    </div>
                    @php $stateHiddenCount = ($columnTotals[$stateKey] ?? 0) - $stateItems->count(); @endphp
                    @if($stateHiddenCount > 0)
                        <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->except(['view']), ['view' => 'list', 'state' => $stateKey])) }}"
                           class="block text-center text-[10px] font-semibold py-2 no-underline hover:opacity-80"
                           style="border-top: 1px solid var(--border); color: var(--brand-icon, #0ea5e9);">
                            +{{ number_format($stateHiddenCount) }} more · View all in List
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        {{-- List View --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
            @php
                // Sort doors — the query already accepted any of these three via
                // ?sort=, so every clickable header just links to what already
                // worked. Clicking the active column flips direction; a fresh
                // column starts ascending.
                $sortLink = function (string $col) use ($indexRouteName, $sortBy, $sortDir) {
                    $nextDir = ($sortBy ?? null) === $col && ($sortDir ?? 'desc') === 'asc' ? 'desc' : 'asc';
                    return route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(
                        request()->except(['sort', 'dir']),
                        ['sort' => $col, 'dir' => $nextDir]
                    ));
                };
                $sortArrow = fn (string $col) => ($sortBy ?? null) === $col ? (($sortDir ?? 'desc') === 'asc' ? '↑' : '↓') : '';
            @endphp
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ $sortLink('name') }}" class="no-underline" style="color: inherit;">Name {{ $sortArrow('name') }}</a>
                        </th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ $sortLink('buyer_state') }}" class="no-underline" style="color: inherit;">State {{ $sortArrow('buyer_state') }}</a>
                        </th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Since</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ $sortLink('last_activity_at') }}" class="no-underline" style="color: inherit;">Last Activity {{ $sortArrow('last_activity_at') }}</a>
                        </th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Core Matches</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($buyers as $buyer)
                        @php
                            $stateBadgeClass = match($buyer->buyer_state) {
                                'new' => 'ds-badge-info',
                                'warm' => 'ds-badge-success',
                                'cold' => 'ds-badge-warning',
                                'lost' => 'ds-badge-danger',
                                'won' => 'ds-badge-success',
                                default => 'ds-badge-default',
                            };
                            $buyerPrimaryWishlist = $buyer->matches->firstWhere('is_primary', true) ?? $buyer->matches->first();
                        @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <a href="{{ route('command-center.buyers.show', $buyer) }}" class="text-sm font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $buyer->full_name }}
                                </a>
                                @unless($buyer->hasCountableWishlist())
                                    <span class="inline-block ml-2 text-[10px] font-semibold px-1.5 py-0.5 rounded-md align-middle whitespace-nowrap"
                                          style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);"
                                          title="On the pipeline but has no countable wishlist (search criteria removed), so this {{ $personNoun }} is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $stateBadgeClass }}">
                                    {{ ucfirst($buyer->buyer_state ?? 'unknown') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $buyer->agent?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $buyer->buyer_pipeline_entered_at?->format('d M Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $buyer->last_activity_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ number_format($coreMatchCounts->get($buyer->id, 0)) }}</td>
                            <td class="px-4 py-3">
                                @if($buyerPrimaryWishlist)
                                <a href="{{ route('corex.contacts.matches.results', [$buyer, $buyerPrimaryWishlist]) }}"
                                   class="corex-btn-outline text-xs no-underline inline-flex items-center gap-1.5">
                                    View Matches
                                </a>
                                @else
                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No {{ $personNounPlural }} found{{ ($search ?? '') !== '' ? ' matching “' . $search . '”' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            @if($view === 'list' && method_exists($buyers, 'links'))
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $buyers->links() }}</div>
            @endif
        </div>
    @endif

    {{-- Won / Success section (Johan 2026-08-13) — buyers who converted (linked to / bought a
         property) live HERE, out of the active pipeline above. Fed by BuyerStateService::markWon
         off the ContactLinkedToProperty(role:buyer) event; terminal state, never decayed by cron. --}}
    @php $wonBuyers = $wonBuyers ?? collect(); @endphp
    <div class="mt-6 rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 2px solid var(--ds-green, #059669);">
            <span class="text-sm font-semibold" style="color: var(--text-primary);">Won / Success</span>
            <span class="text-xs px-2 py-0.5 rounded-full font-bold whitespace-nowrap" style="background: color-mix(in srgb, var(--ds-green, #059669) 15%, transparent); color: var(--ds-green, #059669);">{{ number_format($counts['won'] ?? $wonBuyers->count()) }}</span>
        </div>
        @if($wonBuyers->isEmpty())
            <div class="px-4 py-4 text-xs" style="color: var(--text-muted);">No won {{ $personNounPlural }} yet. When a {{ $personNoun }} is linked to a property, they move here automatically.</div>
        @else
            <div class="p-2 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                @foreach($wonBuyers as $buyer)
                    <a href="{{ route('command-center.buyers.show', $buyer) }}"
                       class="block p-3 rounded-md transition hover:opacity-80 no-underline"
                       style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $buyer->full_name }}</span>
                            <span class="ds-badge ds-badge-success text-[10px] whitespace-nowrap">Won</span>
                        </div>
                        <div class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">
                            {{ $buyer->agent->name ?? 'Unassigned' }}@if($buyer->last_activity_at) · {{ $buyer->last_activity_at->format('d M Y') }}@endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>

<script>
function kanbanDrag() {
    return {
        draggingId: null,
        draggingFrom: null,
        draggingIsRental: false,
        dragTarget: null,

        startDrag(buyerId, fromState, isRental) {
            this.draggingId = buyerId;
            this.draggingFrom = fromState;
            this.draggingIsRental = !!isRental;
        },
        endDrag() {
            this.draggingId = null;
            this.draggingFrom = null;
            this.draggingIsRental = false;
            this.dragTarget = null;
        },
        dragOverColumn(stateKey) {
            if (!this.draggingId) return;
            this.dragTarget = stateKey;
        },
        async dropOnColumn(newState) {
            if (!this.draggingId || newState === this.draggingFrom) {
                this.endDrag();
                return;
            }

            // Lost requires reason — redirect to buyer hub Mark Lost
            if (newState === 'lost') {
                window.location.href = '/corex/command-center/buyers/' + this.draggingId + '?action=mark-lost';
                return;
            }

            try {
                const r = await fetch('/corex/command-center/buyers/' + this.draggingId + '/state', {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ state: newState }),
                });
                if (r.ok) {
                    window.location.reload();
                } else {
                    const noun = this.draggingIsRental ? 'tenant' : 'buyer';
                    const message = r.status === 404
                        ? `This ${noun} is no longer on the board — someone may have archived or moved them. Refresh to see the current list.`
                        : `Could not transition ${noun} state.`;
                    (window.showToast || alert)(message, 'error');
                }
            } catch (e) {
                (window.showToast || alert)('Network error.', 'error');
            }
            this.endDrag();
        },
    };
}
</script>
@endsection
