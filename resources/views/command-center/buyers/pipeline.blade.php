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
    // 2026-09-14 (Johan/conductor) — a per-card Sale/Rental badge only carries
    // information when the visible set is actually mixed. On the Rentals-locked
    // entry point, or on the general board filtered to one lead_type, every card
    // is already the same type by construction (the query enforces it) — a badge
    // on every row there is pure noise per Johan's own screen rule ("real estate
    // lost to nice parts"). The ONLY place a card can genuinely be either type is
    // the general board with no lead_type filter (the "All" view). Board-level
    // chrome (the h1, the lead-type toggle's own highlighted state, the "Showing
    // rentals only" caption) already says what a single-type view is showing;
    // this flag is what's missing on the one view where the rows themselves vary.
    $showsMixedTypes = !($isRentalEntry ?? false) && empty($leadType ?? null);
@endphp
{{-- Kanban view fills the content area (md:h-full / md:min-h-0 — same mechanism as the
     Task board and Today page) so the four state columns own ALL the height left under the
     filter bar and scroll inside themselves, with Won / Success a collapsed strip beneath
     them (header + count; opens in place as a one-line-per-buyer list). The page itself does
     not scroll. Below md the columns stack and the page scrolls. List view keeps normal page
     flow. Fit proof: scripts/buyer-pipeline-fit-check.mjs; spec: buyer-pipeline.md "Kanban fits the screen". --}}
<style>
    /* Kanban: the content block is one column of flex items — chrome, the four columns (take the rest), the Won strip. */
    @media (min-width: 768px) { .bp-kanban { gap: .75rem; } }
    .bp-won { display: flex; flex-direction: column; flex: 0 0 auto; min-height: 0; }
    @media (min-width: 768px) { .bp-kanban .bp-won.is-open { flex: 0 1 clamp(9rem, 23vh, 20rem); min-height: 6.5rem; } }
    .bp-won-head { display: flex; align-items: center; gap: .5rem; width: 100%; padding: .6rem 1rem; background: none; border: 0; cursor: pointer; text-align: left; flex: 0 0 auto; }
    .bp-won.is-open .bp-won-head { border-bottom: 2px solid var(--ds-green, #059669); }
    .bp-won-head:focus-visible { outline: 2px solid var(--brand-icon, #0ea5e9); outline-offset: -2px; }
    .bp-won-chev { width: .9rem; height: .9rem; flex: 0 0 auto; transition: transform .15s; color: var(--text-muted); }
    .bp-won.is-open .bp-won-chev { transform: rotate(90deg); }
    .bp-won-body { flex: 1 1 auto; min-height: 0; overflow: auto; max-height: 16rem; }
    @media (min-width: 768px) { .bp-kanban .bp-won-body { max-height: none; } }
    .bp-won-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: .75rem; }
    .bp-won-table th { position: sticky; top: 0; z-index: 1; background: var(--surface-2); color: var(--text-muted); font-weight: 600; text-align: left; padding: .35rem .75rem; border-bottom: 1px solid var(--border); white-space: nowrap; }
    .bp-won-table td { padding: 0 .75rem; height: 2rem; line-height: 2rem; border-bottom: 1px solid var(--border); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-muted); }
    .bp-won-table tbody tr:hover td { background: var(--surface-2); }
    .bp-won-table td a { color: var(--text-primary); font-weight: 600; text-decoration: none; }
</style>
<div class="w-full flex flex-col gap-5 {{ $view === 'kanban' ? 'bp-kanban md:h-full md:min-h-0' : '' }}">
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
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['scope' => 'own'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ ($pipelineScope ?? 'own') === 'own' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Mine</a>
                    @if($canSeeBranch ?? false)
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['scope' => 'branch'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($pipelineScope ?? '') === 'branch' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Branch</a>
                    @endif
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['scope' => 'agency'])) }}"
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
                    {{-- AT-401 status label, restyled 2026-09-14 (Johan/conductor)
                         — the pill/border made this read as a toggle sitting in a
                         row of real ones (Mine/Branch/All) even though it was
                         never wired as one (confirmed: never an <a>, see git
                         history on this line). Plain caption text with no
                         container reads as "state of the screen", not "thing to
                         click" — the fix for the misreading, not a functional
                         change; the server-side lock this describes is unchanged. --}}
                    <span class="text-xs whitespace-nowrap" style="color: var(--text-muted); cursor:default;" title="This entry point always shows rental leads only">Showing rentals only</span>
                @else
                <div data-tour="buyers-lead-type" class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to')) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ empty($leadType ?? null) ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">All</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['lead_type' => 'sale'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($leadType ?? '') === 'sale' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Sales</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('view', 'scope', 'state', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['lead_type' => 'rental'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ ($leadType ?? '') === 'rental' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Rentals</a>
                </div>
                @endif
                {{-- View toggle --}}
                <div data-tour="buyers-view" class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('scope', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['view' => 'kanban'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="{{ $view === 'kanban' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">Kanban</a>
                    <a href="{{ route($indexRouteName ?? 'command-center.buyers.pipeline', array_merge(request()->only('scope', 'state', 'lead_type', 'agent_id', 'q', 'sort', 'dir', 'entered_from', 'entered_to'), ['view' => 'list'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold whitespace-nowrap no-underline"
                       style="border-left: 1px solid var(--border); {{ $view === 'list' ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">List</a>
                </div>
            </div>
        </div>
    </div>

    {{-- .ai/specs/real-estate-menu-tabs.md - Buyer pipeline is a tab of Real Estate > Buyers (the Rentals pipeline route shows no bar) --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\BuyersTabs::class])
    {{-- .ai/specs/rentals-menu-tabs.md - opened from Rentals (its own route) this is a tab of Rentals > Letting --}}
    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\LettingTabs::class])

    {{-- Search + filters --}}
    <div class="props-filter-panel rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route($indexRouteName ?? 'command-center.buyers.pipeline') }}" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="scope" value="{{ $pipelineScope ?? '' }}">
            @unless($isRentalEntry ?? false)
                <input type="hidden" name="lead_type" value="{{ $leadType ?? '' }}">
            @endunless
            @if(!empty($sortBy ?? null))<input type="hidden" name="sort" value="{{ $sortBy }}">@endif
            @if(!empty($sortDir ?? null))<input type="hidden" name="dir" value="{{ $sortDir }}">@endif
            <input class="list-header-filter {{ (($search ?? '') !== '') ? 'is-active' : '' }}" type="text" name="q" data-tour="buyers-search" value="{{ $search ?? '' }}" placeholder="Search name, phone or email…"
                   style="flex: 1 1 0%; min-width: 200px; cursor: text;">
            <select class="list-header-filter {{ (!empty($stateFilter ?? null)) ? 'is-active' : '' }}" name="state" onchange="this.form.submit()"
                   >
                <option value="">All states</option>
                @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateOptKey => $stateOptLabel)
                    <option value="{{ $stateOptKey }}" {{ ($stateFilter ?? '') === $stateOptKey ? 'selected' : '' }}>{{ $stateOptLabel }}</option>
                @endforeach
            </select>
            <select class="list-header-filter {{ (!empty($agentFilter ?? null)) ? 'is-active' : '' }}" name="agent_id" onchange="this.form.submit()"
                   style="max-width: 200px;">
                <option value="">All agents</option>
                @foreach($agentOptions ?? [] as $agentOpt)
                    <option value="{{ $agentOpt->id }}" {{ (string) ($agentFilter ?? '') === (string) $agentOpt->id ? 'selected' : '' }}>{{ $agentOpt->name }}</option>
                @endforeach
            </select>
            {{-- BUILD_STANDARD.md §1b — date-range filter, minimum bar for any
                 list screen. Filters on buyer_pipeline_entered_at (the same
                 "Since" date already on every card/row) so a manager can find
                 who's been sitting on the board since before a given date,
                 not just what state they're in. --}}
            <span class="text-xs whitespace-nowrap" style="color: var(--text-muted);">Since</span>
            <input class="list-header-filter {{ (!empty($enteredFrom ?? null)) ? 'is-active' : '' }}" type="date" name="entered_from" value="{{ $enteredFrom ?? '' }}"
                   title="Entered pipeline on/after"
                  >
            <span class="text-xs" style="color: var(--text-muted);">–</span>
            <input class="list-header-filter {{ (!empty($enteredTo ?? null)) ? 'is-active' : '' }}" type="date" name="entered_to" value="{{ $enteredTo ?? '' }}"
                   title="Entered pipeline on/before"
                  >
            <button type="submit" data-tour="buyers-search-btn" class="corex-btn-outline text-xs">Search</button>
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
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 md:flex-1 md:min-h-0" x-data="kanbanDrag()" data-tour="buyers-board">
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
                <div class="flex flex-col rounded-md overflow-hidden md:min-h-0" style="background: var(--surface); border: 1px solid var(--border);"
                     @dragover.prevent="dragOverColumn('{{ $stateKey }}')"
                     @drop.prevent="dropOnColumn('{{ $stateKey }}')"
                     :style="dragTarget === '{{ $stateKey }}' ? 'outline: 2px solid {{ $stateColour }}; outline-offset: -2px;' : ''">
                    <div class="px-4 py-3 flex items-center justify-between shrink-0" style="border-bottom: 2px solid {{ $stateColour }};">
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $stateLabel }}</span>
                        <span class="ds-badge ds-badge-pill" style="--ds-badge-c: {{ $stateColour }};">{{ number_format($counts[$stateKey] ?? 0) }}</span>
                    </div>
                    {{-- Column body — fills the column and scrolls inside itself, sidebar-style bar --}}
                    <div class="flex-1 min-h-[8rem] md:min-h-0 p-2 space-y-2 overflow-y-auto corex-brand-scroll">
                        @forelse($stateItems as $buyer)
                            @php
                                $buyerRisk = $riskScores[$buyer->id] ?? null;
                                // Same test applyLeadTypeFilter() uses to decide whether this contact
                                // belongs on the board at all — Contact::primaryMatchIsRental(), the
                                // ONE shared method both now read (2026-09-18 fix), so the filter and
                                // this label can never independently disagree again. The PERSON's own
                                // primary wishlist decides tenant vs buyer, not which board/entry
                                // point rendered them — a mixed "All" board can show both in one
                                // column, so this must still be evaluated per card, never fixed
                                // page-wide.
                                $buyerPrimaryWishlist = $buyer->primaryMatch();
                                $buyerIsRental = $buyer->primaryMatchIsRental();
                            @endphp
                            @php $buyerReadOnly = in_array((int) $buyer->id, $readOnlyBuyerIds ?? [], true); @endphp
                            <a href="{{ route('command-center.buyers.show', $buyer) }}"
                               @unless($buyerReadOnly)
                               draggable="true"
                               @dragstart="startDrag({{ $buyer->id }}, '{{ $stateKey }}', {{ $buyerIsRental ? 'true' : 'false' }})"
                               @dragend="endDrag()"
                               @endunless
                               class="block p-3 rounded-md transition hover:opacity-80 no-underline relative {{ $buyerReadOnly ? '' : 'cursor-grab active:cursor-grabbing' }}"
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
                                        <div class="text-xs font-semibold truncate flex items-center gap-1.5">
                                            <span class="truncate" style="color: var(--text-primary);">{{ $buyer->full_name }}</span>
                                            @if($showsMixedTypes)
                                                <span class="ds-badge ds-badge-pill {{ $buyerIsRental ? 'ds-badge-info' : 'ds-badge-success' }} flex-shrink-0">{{ $buyerIsRental ? 'RENTAL' : 'SALE' }}</span>
                                            @endif
                                        </div>
                                        <div class="text-[10px] truncate" style="color: var(--text-muted);">
                                            {{ $buyer->agent?->name ?? 'Unassigned' }}
                                            · Since {{ $buyer->buyer_pipeline_entered_at?->format('d M Y') ?? '—' }}
                                        </div>
                                    </div>
                                </div>
                                @unless($buyer->hasCountableWishlist())
                                    <div class="mt-1">
                                        <span class="ds-badge ds-badge-pill ds-badge-text ds-badge-warning" title="On the pipeline but has no countable wishlist (search criteria removed), so this {{ $personNoun }} is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
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
                            @if(($canMoveBuyers ?? false) && ! in_array((int) $buyer->id, $readOnlyBuyerIds ?? [], true))
                            <button type="button" x-data
                                    @click="$dispatch('open-move-buyer', { name: @js($buyer->full_name), current: @js($buyer->agent?->name), currentId: {{ (int) $buyer->agent_id }}, action: @js(route('corex.core-matches.reassign-buyer', $buyer)) })"
                                    class="block w-full text-center text-[10px] font-medium py-1 mt-1 rounded-md hover:opacity-80 transition cursor-pointer"
                                    style="color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border);"
                                    title="Move this {{ $personNoun }}, and all their saved searches, to another agent">Move to another agent</button>
                            @endif
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
            <table class="min-w-full text-sm ds-table" data-tour="buyers-list">
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
                            $buyerPrimaryWishlist = $buyer->primaryMatch();
                            $buyerIsRental = $buyer->primaryMatchIsRental();
                        @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <a href="{{ route('command-center.buyers.show', $buyer) }}" class="text-sm font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $buyer->full_name }}
                                </a>
                                @if($showsMixedTypes)
                                    <span class="ds-badge ds-badge-pill {{ $buyerIsRental ? 'ds-badge-info' : 'ds-badge-success' }} ml-1.5 align-middle">{{ $buyerIsRental ? 'RENTAL' : 'SALE' }}</span>
                                @endif
                                @unless($buyer->hasCountableWishlist())
                                    <span class="ds-badge ds-badge-pill ds-badge-text ds-badge-warning ml-2 align-middle" title="On the pipeline but has no countable wishlist (search criteria removed), so this {{ $personNoun }} is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
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
                                @if(($canMoveBuyers ?? false) && ! in_array((int) $buyer->id, $readOnlyBuyerIds ?? [], true))
                                <button type="button" x-data
                                        @click="$dispatch('open-move-buyer', { name: @js($buyer->full_name), current: @js($buyer->agent?->name), currentId: {{ (int) $buyer->agent_id }}, action: @js(route('corex.core-matches.reassign-buyer', $buyer)) })"
                                        class="corex-btn-outline text-xs ml-1 cursor-pointer"
                                        title="Move this {{ $personNoun }}, and all their saved searches, to another agent">Move to another agent</button>
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
         off the ContactLinkedToProperty(role:buyer) event; terminal state, never decayed by cron.
         Johan 2026-10-29: a collapsed strip (header + count) at the bottom, opened in place as a dense
         one-line-per-buyer list; open/closed is remembered per user (this browser). --}}
    @php
        $wonBuyers = $wonBuyers ?? collect();
        $wonDeals = $wonDeals ?? collect();
    @endphp
    <div class="bp-won rounded-md overflow-hidden" data-bp-won
         x-data="bpWon({{ (int) auth()->id() }})" :class="open ? 'is-open' : ''"
         style="background: var(--surface); border: 1px solid var(--border);">
        <button type="button" class="bp-won-head" data-bp-won-toggle @click="toggle()" :aria-expanded="open ? 'true' : 'false'" aria-controls="bp-won-body">
            <svg class="bp-won-chev" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.2 4.2a1 1 0 0 1 1.4 0l5 5a1 1 0 0 1 0 1.4l-5 5a1 1 0 1 1-1.4-1.4L11.6 10 7.2 5.6a1 1 0 0 1 0-1.4Z" clip-rule="evenodd"/></svg>
            <span class="text-sm font-semibold" style="color: var(--text-primary);">Won / Success</span>
            <span class="ds-badge ds-badge-pill ds-badge-success">{{ number_format($counts['won'] ?? $wonBuyers->count()) }}</span>
            <span class="text-xs ml-auto" style="color: var(--text-muted);" x-text="open ? 'Hide list' : 'Show list'">Show list</span>
        </button>
        <div id="bp-won-body" class="bp-won-body" x-show="open" style="display: none;">
            @if($wonBuyers->isEmpty())
                <div class="px-4 py-4 text-xs" style="color: var(--text-muted);">No won {{ $personNounPlural }} yet. When a {{ $personNoun }} is linked to a deal in the Deal Register, they move here automatically.</div>
            @else
                <table class="bp-won-table">
                    <colgroup><col style="width: 24%"><col style="width: 20%"><col style="width: 14%"><col><col style="width: 90px"></colgroup>
                    <thead><tr><th>Name</th><th>Agent</th><th>Last activity</th><th>Property</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($wonBuyers as $buyer)
                            @php
                                $wonDeal = $wonDeals->get($buyer->id);
                                $wonProperty = trim((string) ($wonDeal->property_address ?? ''));
                            @endphp
                            <tr>
                                <td title="{{ $buyer->full_name }}"><a href="{{ route('command-center.buyers.show', $buyer) }}">{{ $buyer->full_name }}</a></td>
                                <td title="{{ $buyer->agent->name ?? 'Unassigned' }}">{{ $buyer->agent->name ?? 'Unassigned' }}</td>
                                <td>{{ $buyer->last_activity_at ? $buyer->last_activity_at->format('d M Y') : '—' }}</td>
                                <td title="{{ $wonProperty }}">{{ $wonProperty !== '' ? $wonProperty : '—' }}</td>
                                <td><span class="ds-badge ds-badge-success text-[10px] whitespace-nowrap">Won</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

<script>
// Won / Success strip: collapsed by default; the choice is remembered per user (key carries the user id, so two
// people on one browser never inherit each other's). Browser storage may be blocked — then it simply starts closed.
function bpWon(userId) {
    const key = 'corex.buyerPipeline.wonOpen.' + userId;
    return {
        open: false,
        init() { try { this.open = window.localStorage.getItem(key) === '1'; } catch (_) {} },
        toggle() {
            this.open = !this.open;
            try { window.localStorage.setItem(key, this.open ? '1' : '0'); } catch (_) {}
        },
    };
}
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

            // Lost needs a reason — open the shared Mark-Lost dialog (the same partial the buyer page and
            // Core Matches use) right here, aimed at THIS buyer's mark-lost endpoint. The card has not
            // moved (a drop only moves a card after the server accepts it), so cancelling the dialog
            // leaves it exactly where it was; only a saved reason moves the buyer to Lost.
            if (newState === 'lost') {
                const detail = { url: '/corex/command-center/buyers/' + this.draggingId + '/mark-lost', noun: this.draggingIsRental ? 'tenant' : 'buyer' };
                this.endDrag();
                window.dispatchEvent(new CustomEvent('open-pipeline-lost', { detail }));
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
{{-- Drag-to-Lost: the ONE shared Mark-Lost dialog (reason list required, same endpoint and validation
     as the buyer page and Core Matches). The drop handler above fills in the buyer's endpoint and opens it. --}}
<div x-data
     @open-pipeline-lost.window="
         const dlg = $refs.pipelineLostModal;
         dlg.querySelector('[data-mark-lost-form]').action = $event.detail.url;
         dlg.querySelectorAll('[data-lost-noun]').forEach(el => el.textContent = $event.detail.noun);
         dlg.querySelector('[data-mark-lost-form]').reset();
         dlg.showModal();
     ">
    @include('command-center.buyers._mark-lost-dialog', ['ref' => 'pipelineLostModal', 'agencyId' => (int) auth()->user()->effectiveAgencyId(), 'noun' => 'buyer', 'action' => null])
</div>
@if($canMoveBuyers ?? false)
    @include('corex.core-matches._move-buyer-modal', ['moveBuyerAgents' => $moveBuyerAgents])
@endif
@endsection
