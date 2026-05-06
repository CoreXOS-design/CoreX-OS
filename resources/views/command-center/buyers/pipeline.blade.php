@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white">Buyer Pipeline</h1>
                <p class="text-sm text-white/60">Track buyer lifecycle: New → Warm → Cold → Lost</p>
            </div>
            <div class="flex items-center gap-3">
                {{-- Pipeline scope toggle (Layer 3) --}}
                <div class="inline-flex rounded-md overflow-hidden" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);">
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
                <div class="inline-flex rounded-md overflow-hidden" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);">
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

    @if($view === 'kanban')
        {{-- Kanban View (drag-drop enabled) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4" x-data="kanbanDrag()">
            @foreach(['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost'] as $stateKey => $stateLabel)
                @php
                    $stateColour = match($stateKey) {
                        'new' => '#3b82f6',
                        'warm' => '#10b981',
                        'cold' => '#f59e0b',
                        'lost' => '#ef4444',
                    };
                    $stateItems = $columns[$stateKey] ?? collect();
                @endphp
                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);"
                     @dragover.prevent="dragOverColumn('{{ $stateKey }}')"
                     @drop.prevent="dropOnColumn('{{ $stateKey }}')"
                     :style="dragTarget === '{{ $stateKey }}' ? 'outline: 2px solid {{ $stateColour }}; outline-offset: -2px;' : ''">
                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 2px solid {{ $stateColour }};">
                        <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $stateLabel }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-bold" style="background: {{ $stateColour }}20; color: {{ $stateColour }};">{{ $counts[$stateKey] ?? 0 }}</span>
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
                                          style="background: {{ $buyerRisk > 60 ? '#ef4444' : '#f59e0b' }};"
                                          title="Lost risk: {{ $buyerRisk }}/100"></span>
                                @endif
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white flex-shrink-0"
                                         style="background: {{ $stateColour }};">
                                        {{ strtoupper(substr($buyer->first_name ?? '', 0, 1) . substr($buyer->last_name ?? '', 0, 1)) }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $buyer->full_name }}</div>
                                        <div class="text-[10px]" style="color: var(--text-muted);">{{ $buyer->createdBy?->name ?? 'Unassigned' }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between text-[10px]" style="color: var(--text-muted);">
                                    <span>{{ $buyer->last_activity_at ? $buyer->last_activity_at->diffForHumans() : 'No activity' }}</span>
                                    <span>{{ $buyer->buyerPropertyViews()->count() }} properties</span>
                                </div>
                            </a>
                            <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $buyer->id, 'prefill_class' => 'viewing']) }}"
                               class="block mt-1 text-center text-[10px] font-medium py-1 rounded no-underline hover:opacity-80"
                               style="color: #00d4aa; background: color-mix(in srgb, #00d4aa 8%, transparent);">
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
                        <th class="text-left px-4 py-3 text-xs font-medium" style="color: var(--text-muted);">Properties</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($buyers as $buyer)
                        @php
                            $statePill = match($buyer->buyer_state) {
                                'new' => ['bg' => '#3b82f620', 'color' => '#3b82f6'],
                                'warm' => ['bg' => '#10b98120', 'color' => '#10b981'],
                                'cold' => ['bg' => '#f59e0b20', 'color' => '#f59e0b'],
                                'lost' => ['bg' => '#ef444420', 'color' => '#ef4444'],
                                default => ['bg' => '#64748b20', 'color' => '#64748b'],
                            };
                        @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-4 py-3">
                                <a href="{{ route('command-center.buyers.show', $buyer) }}" class="text-sm font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $buyer->full_name }}
                                </a>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full uppercase"
                                      style="background: {{ $statePill['bg'] }}; color: {{ $statePill['color'] }};">
                                    {{ $buyer->buyer_state ?? 'unknown' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $buyer->createdBy?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $buyer->last_activity_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $buyer->buyerPropertyViews()->count() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">No buyers found.</td></tr>
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
                    alert('Could not transition buyer state.');
                }
            } catch (e) {
                alert('Network error.');
            }
            this.endDrag();
        },
    };
}
</script>
@endsection
