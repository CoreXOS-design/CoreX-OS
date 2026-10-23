{{-- AT-454 - the summary bar. Needs $bar (SummaryBarService::forAgent), $progress (RecipeProgressService, v2 phase 2) and $drilldownBase.
     The funnel tiles open the rows behind them; each recipe step with a target adds one tile of the same size: tasks met and progress. --}}
@if(! empty($bar['steps']) || ! empty($progress['steps']))
<div x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); grid-auto-rows: 1fr;">
        @foreach($bar['steps'] as $step)
            @php
                $edge = match ($step['status']) {
                    'green' => 'var(--ds-green, #059669)',
                    'amber' => 'var(--ds-amber, #f59e0b)',
                    'red'   => 'var(--ds-red, #f87171)',
                    default => 'var(--border)',
                };
                $statusWord = match ($step['status']) { 'green' => 'On track', 'amber' => 'Slightly behind', 'red' => 'Behind', default => '' };
                $should = $step['should_be'];
                $fmt = fn ($v) => $v === null ? '' : (floor($v) == $v ? number_format((int) $v) : number_format((float) $v, 1));
            @endphp
            <button type="button" @click="drill(@js($step['metric']), @js($step['label'] . ' - ' . $bar['month']))"
                    class="rounded-md px-3 py-3 text-left"
                    style="background: var(--surface); border: 1px solid var(--border); border-left: 4px solid {{ $edge }}; cursor: pointer;"
                    title="Click to see the rows behind this figure">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $step['label'] }}</div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                    {{ number_format($step['actual']) }}
                    @if($should !== null)<span class="text-xs font-normal" style="color: var(--text-muted);"> of {{ $fmt($should) }}</span>@endif
                </div>
                <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                    @if($should !== null && $should > 0)
                        {{ round($step['pct']) }}% of where you should be · {{ $statusWord }}
                    @elseif($should !== null)
                        No target this month
                    @else
                        This month so far
                    @endif
                </div>
            </button>
        @endforeach
        @foreach($progress['steps'] ?? [] as $rs)
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
    @include('buyers-report._drilldown-modal')
</div>
@endif
