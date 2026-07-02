{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="buyers-intro">
                <h1 class="text-xl font-bold text-white">Buyer Pipeline</h1>
                <p class="text-sm text-white/60">Track buyer lifecycle: New → Warm → Cold → Lost</p>
            </div>
            <div class="flex items-center gap-3">
                @include('layouts.partials.tour-header-launcher')
                {{-- Pipeline scope toggle (Layer 3) --}}
                <div data-tour="buyers-scope" class="inline-flex rounded-md overflow-hidden" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);">
                    <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->only('view', 'state'), ['scope' => 'own'])) }}"
                       class="px-2.5 py-1 text-[10px] font-semibold {{ ($pipelineScope ?? 'own') === 'own' ? 'text-white' : 'text-white/50' }}"
                       style="{{ ($pipelineScope ?? 'own') === 'own' ? 'background: var(--brand-button);' : '' }}">Mine</a>
                    @if($canSeeBranch ?? false)
                    <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->only('view', 'state'), ['scope' => 'branch'])) }}"
                       class="px-2.5 py-1 text-[10px] font-semibold {{ ($pipelineScope ?? '') === 'branch' ? 'text-white' : 'text-white/50' }}"
                       style="{{ ($pipelineScope ?? '') === 'branch' ? 'background: var(--brand-button);' : '' }}">Branch</a>
                    @endif
                    <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->only('view', 'state'), ['scope' => 'agency'])) }}"
                       class="px-2.5 py-1 text-[10px] font-semibold {{ ($pipelineScope ?? '') === 'agency' ? 'text-white' : 'text-white/50' }}"
                       style="{{ ($pipelineScope ?? '') === 'agency' ? 'background: var(--brand-button);' : '' }}">All</a>
                </div>
                {{-- View toggle --}}
                <div data-tour="buyers-view" class="inline-flex rounded-md overflow-hidden" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
                    <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->only('scope', 'state'), ['view' => 'kanban'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold {{ $view === 'kanban' ? 'text-white' : 'text-white/60' }}"
                       style="{{ $view === 'kanban' ? 'background: var(--brand-button);' : '' }}">Kanban</a>
                    <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->only('scope', 'state'), ['view' => 'list'])) }}"
                       class="px-3 py-1.5 text-xs font-semibold {{ $view === 'list' ? 'text-white' : 'text-white/60' }}"
                       style="{{ $view === 'list' ? 'background: var(--brand-button);' : '' }}">List</a>
                </div>
            </div>
        </div>
    </div>

    {{-- Prospecting drill-down context banner (set when arriving via a
         "N buyers" badge click from the prospecting tab). --}}
    @if(!empty($contextListing))
        <div class="rounded-md px-4 py-3 flex items-center justify-between gap-3"
             style="background: color-mix(in srgb, var(--brand-button) 12%, transparent); border: 1px solid color-mix(in srgb, var(--brand-button) 30%, transparent);">
            <div class="min-w-0">
                <div class="text-[10px] uppercase tracking-wider" style="color: var(--brand-button);">
                    Filtered to buyers matching prospecting listing
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
                   class="text-xs no-underline hover:underline" style="color: var(--brand-button);">View listing →</a>
                <a href="{{ route('command-center.buyers.pipeline', array_merge(request()->except('prospecting_listing_id'), [])) }}"
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
                            @php $buyerRisk = $riskScores[$buyer->id] ?? null; @endphp
                            <a href="{{ route('command-center.buyers.show', $buyer) }}"
                               draggable="true"
                               @dragstart="startDrag({{ $buyer->id }}, '{{ $stateKey }}')"
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
                                        <div class="text-[10px]" style="color: var(--text-muted);">{{ $buyer->agent?->name ?? 'Unassigned' }}</div>
                                    </div>
                                </div>
                                @unless($buyer->hasCountableWishlist())
                                    <div class="mt-1">
                                        <span class="inline-block text-[9px] font-semibold px-1.5 py-0.5 rounded"
                                              style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);"
                                              title="On the pipeline but has no countable wishlist (search criteria removed), so this buyer is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
                                    </div>
                                @endunless
                                <div class="flex items-center justify-between text-[10px] mt-1" style="color: var(--text-muted);">
                                    <span>{{ $buyer->last_activity_at ? $buyer->last_activity_at->diffForHumans() : 'No activity' }}</span>
                                    {{-- AT-108 — canonical Core Match count (same number as Core Matches surface + detail). NOT viewings. --}}
                                    <span>{{ $coreMatchCounts->get($buyer->id, 0) }} matches</span>
                                </div>
                            </a>
                            <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                               class="block mt-1 text-center text-[10px] font-medium py-1 rounded no-underline hover:opacity-80 transition"
                               style="color: var(--ds-green, #059669); background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);">
                                Schedule Viewing
                            </a>
                        @empty
                            <div class="py-6 text-center text-xs" style="color: var(--text-muted);">No buyers in this state</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- List View --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Name</th>
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">State</th>
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Agent</th>
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Last Activity</th>
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Core Matches</th>
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
                                default => 'ds-badge-default',
                            };
                        @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <a href="{{ route('command-center.buyers.show', $buyer) }}" class="text-sm font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $buyer->full_name }}
                                </a>
                                @unless($buyer->hasCountableWishlist())
                                    <span class="inline-block ml-2 text-[9px] font-semibold px-1.5 py-0.5 rounded align-middle"
                                          style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--ds-amber, #f59e0b);"
                                          title="On the pipeline but has no countable wishlist (search criteria removed), so this buyer is excluded from all match figures. Add a wishlist to include them.">No core match · not in figures</span>
                                @endunless
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge {{ $stateBadgeClass }}">
                                    {{ ucfirst($buyer->buyer_state ?? 'unknown') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $buyer->agent?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $buyer->last_activity_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ number_format($coreMatchCounts->get($buyer->id, 0)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No buyers found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($view === 'list' && method_exists($buyers, 'links'))
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $buyers->links() }}</div>
            @endif
        </div>
    @endif
</div>

<script>
function kanbanDrag() {
    return {
        draggingId: null,
        draggingFrom: null,
        dragTarget: null,

        startDrag(buyerId, fromState) {
            this.draggingId = buyerId;
            this.draggingFrom = fromState;
        },
        endDrag() {
            this.draggingId = null;
            this.draggingFrom = null;
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
                    (window.showToast || alert)('Could not transition buyer state.', 'error');
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
