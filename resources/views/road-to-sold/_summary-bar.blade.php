{{-- AT-454 - what used to be the v3 summary cards (Calls made, Conversations, Evaluations, Mandates, Listings) is gone: the belt tiles above ARE those figures
     (Johan, 2 Nov: one counter for the day, the tiles are the belt). What stays here is the recipe's own step tiles - tasks met and progress - and only when the agency set targets.
     Needs $progress (RecipeProgressService). --}}
@if(! empty($progress['steps']))
<div>
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); grid-auto-rows: 1fr;" data-tour="smk-rts-summary">
        @foreach($progress['steps'] as $rs)
            @php
                $rsEdge = match ($rs['state']) {
                    'achieved' => 'var(--rts-achieved)', 'ahead' => 'var(--rts-ahead)', 'on_track' => 'var(--rts-on-track)',
                    'amber' => 'var(--rts-amber)', 'behind' => 'var(--rts-behind)', default => 'var(--rts-open)',
                };
                $rsWord = \App\Services\RoadToSold\RecipeProgressService::STATE_LABELS[$rs['state']] ?? '';
            @endphp
            <div class="rounded-md px-3 py-3 text-left" style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $rsEdge }};">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $rs['label'] }}</div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                    {{ $rs['met'] }}<span class="text-xs font-normal" style="color: var(--text-muted);"> of {{ $rs['total'] }} {{ $rs['total'] === 1 ? 'task' : 'tasks' }}</span>
                </div>
                <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ $rs['pct'] }}% of target{{ $rsWord !== '' ? ' · ' . $rsWord : '' }}</div>
            </div>
        @endforeach
    </div>
</div>
@endif
