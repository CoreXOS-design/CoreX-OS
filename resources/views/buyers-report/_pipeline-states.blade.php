{{-- "What buyers do we have now" (Johan, 2026-08-20): "why or where is that
     reflected on the buyers report - I would think this report especially
     should marry up to the buyers pipeline". pipelineSnapshot's per-state
     counts are computed the EXACT SAME way the pipeline board's own
     stateCounts() computes them (PipelineStateService + BuyerPipelineScope,
     both Eloquent) -- for the same viewer/scope this section's numbers
     equal the board's kanban badges, verified against the board's own
     method on qa1/staging, not just "close". pipelineMovement is a
     DIFFERENT question (entered/left THIS PERIOD) shown alongside, never
     blended into the snapshot number -- a point-in-time count and a
     period count answer different questions. --}}
@php
    $s = $pipelineSnapshot ?? ['states' => [], 'no_state' => 0, 'total' => 0];
    $m = $pipelineMovement ?? [];
    $stateColour = fn ($k) => match ($k) {
        'new' => 'var(--brand-icon, #0ea5e9)', 'warm' => 'var(--ds-green, #059669)',
        'cold' => 'var(--ds-amber, #f59e0b)', 'lost' => 'var(--ds-crimson, #c41e3a)',
        'won' => 'var(--ds-green, #059669)', default => 'var(--text-muted)',
    };
@endphp
<h2 class="text-base font-semibold mb-3" style="color: var(--text-primary);">What buyers do we have now</h2>
<p class="text-[11px] mb-3" style="color: var(--text-muted);">
    Current state, right now — matches the Buyer Pipeline board exactly (not a period figure).
</p>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
    @foreach(\App\Services\BuyersReport\PipelineStateService::STATES as $key => $label)
        @php $mv = $m[$key] ?? ['entered' => 0, 'left' => 0]; @endphp
        <button type="button" @click="drill('pipeline_state', @js($label), null, @js($key))"
                class="group rounded-md px-3 py-3 text-left transition-colors"
                style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;"
                onmouseover="this.style.borderColor='{{ $stateColour($key) }}'; this.style.background='var(--surface-2)';"
                onmouseout="this.style.borderColor='var(--border)'; this.style.background='var(--surface)';"
                title="Click to see the buyers in this state">
            <div class="flex items-center justify-between">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                <span class="text-[11px] opacity-0 group-hover:opacity-100 transition-opacity" style="color: {{ $stateColour($key) }};">view &rarr;</span>
            </div>
            <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">{{ number_format($s['states'][$key] ?? 0) }}</div>
            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                +{{ number_format($mv['entered']) }} in · -{{ number_format($mv['left']) }} out this period
            </div>
        </button>
    @endforeach
    @if(($s['no_state'] ?? 0) > 0)
        <button type="button" @click="drill('pipeline_state', 'No state recorded', null, 'no_state')"
                class="group rounded-md px-3 py-3 text-left transition-colors"
                style="background: var(--surface); border: 1px dashed var(--border); cursor: pointer;"
                title="Click to see the buyers with no pipeline state recorded">
            <div class="flex items-center justify-between">
                <div class="text-[11px]" style="color: var(--text-muted);">No state recorded</div>
                <span class="text-[11px] opacity-0 group-hover:opacity-100 transition-opacity" style="color: var(--brand-icon, #0ea5e9);">view &rarr;</span>
            </div>
            <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">{{ number_format($s['no_state']) }}</div>
            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">a real gap in the data, not hidden</div>
        </button>
    @endif
</div>

{{-- Reconciliation: "Buyers held" (the period tile above, HierarchyResolver-
     scoped -- who counts toward performance rollups) vs the pipeline
     snapshot total for the SAME scope (who is actually on the board).
     Johan (2026-08-20): "If held != the sum of the states, say why on the
     page itself." Shown even when they match, so the reader never has to
     wonder whether it was checked. --}}
@php $hvp = $heldVsPipeline ?? ['report_held' => 0, 'pipeline_total' => 0, 'gap' => 0, 'reasons' => []]; @endphp
<div class="rounded-md px-4 py-3 mb-6 text-xs" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <span style="color: var(--text-muted);">Reconciliation — "Buyers held" tile vs. the pipeline board's total, same scope</span>
        <span class="font-semibold" style="color: {{ $hvp['gap'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--ds-green, #059669)' }};">
            {{ $hvp['report_held'] }} held · {{ $hvp['pipeline_total'] }} on the board
            {{ $hvp['gap'] > 0 ? '— ' . $hvp['gap'] . ' buyer' . ($hvp['gap'] === 1 ? '' : 's') . ' not counted in "held"' : '— match' }}
        </span>
    </div>
    @if($hvp['gap'] > 0)
        <div class="mt-1.5" style="color: var(--text-muted);">
            Why: "Buyers held" only counts buyers whose agent is active and included in performance reporting.
            @foreach($hvp['reasons'] as $reason => $count)
                {{ [
                    'unassigned' => $count . ' buyer' . ($count === 1 ? '' : 's') . ' with no agent assigned',
                    'inactive_agent' => $count . ' buyer' . ($count === 1 ? '' : 's') . ' whose agent is inactive',
                    'owner_role_agent' => $count . ' buyer' . ($count === 1 ? '' : 's') . ' assigned to an owner-role account',
                    'report_excluded_agent' => $count . ' buyer' . ($count === 1 ? '' : 's') . ' whose agent is excluded from performance reports',
                ][$reason] ?? "$count buyer(s) — $reason" }}{{ !$loop->last ? '; ' : '.' }}
            @endforeach
            These buyers ARE on the pipeline board and ARE counted in the state tiles above — they're only absent from the period "Buyers held" figure.
        </div>
    @endif
</div>
