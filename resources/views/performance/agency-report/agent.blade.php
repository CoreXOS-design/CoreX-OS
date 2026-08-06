@extends('layouts.corex-app')

@section('title', 'Agent Journey — ' . ($journey['agent']['name'] ?? ''))

@section('corex-content')
<div class="p-6 space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <a href="{{ route('performance.agency-report', ['period' => $preset]) }}"
               class="text-[11px] no-underline" style="color:var(--text-muted);">&larr; Back to report</a>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">{{ $journey['agent']['name'] }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">
                {{ $journey['agent']['branch_label'] }} · {{ $journey['period']['label'] }}
                <span class="ml-1">(vs {{ $journey['previous_period']['label'] }})</span>
            </p>
        </div>

        <form method="GET" action="{{ route('performance.agency-report.agent', $journey['agent']['user_id']) }}"
              class="flex items-end gap-2 flex-wrap">
            <label class="text-[11px]" style="color:var(--text-muted);">
                Period
                <select name="period" onchange="this.form.submit()"
                        class="block mt-1 text-xs rounded px-2 py-1"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    @foreach($presets as $p)
                        <option value="{{ $p }}" @selected($preset === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    </div>

    @if(session('period_error'))
        <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">{{ session('period_error') }}</div>
    @endif

    {{-- The agent journey: every metric with its prior-period trend. --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        @foreach($journey['metrics'] as $m)
            @php
                $delta = $m['delta'];
                $arrow = $delta > 0 ? '&#9650;' : ($delta < 0 ? '&#9660;' : '&ndash;');
                $color = $delta > 0 ? '#22c55e' : ($delta < 0 ? '#ef4444' : 'var(--text-muted)');
            @endphp
            <div class="rounded p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ number_format($m['value']) }}</div>
                <div class="text-[11px] mb-1" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                <div class="text-[10px]" style="color:{{ $color }};">
                    {!! $arrow !!} {{ $delta > 0 ? '+' : '' }}{{ number_format($delta) }}
                    <span style="color:var(--text-muted);">vs {{ number_format($m['previous']) }} prior</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- AT-366-E (Q7) — the agent's period-scoped buyer-activity picture. --}}
    @isset($buyer)
    <div class="pt-2 space-y-4">
        <div class="flex items-baseline justify-between flex-wrap gap-2">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Buyer activity</h2>
            <span class="text-[11px]" style="color:var(--text-muted);">
                Pipeline last worked:
                <strong style="color:var(--text-primary);">{{ $buyer['last_worked_at'] ? \Illuminate\Support\Carbon::parse($buyer['last_worked_at'])->diffForHumans() : 'never' }}</strong>
            </span>
        </div>

        {{-- Aggregate buyer tiles for the period. --}}
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach($buyer['metrics'] as $m)
                @php $val = $buyer['aggregate'][$m['key']] ?? 0; @endphp
                <div class="rounded p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $m['currency'] ? 'R ' . number_format((float) $val) : number_format((int) $val) }}</div>
                    <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Pipeline distribution (where the buyers sit right now). --}}
        @if(!empty($buyer['stage_summary']))
            <div class="flex flex-wrap gap-2">
                @foreach($buyer['stage_summary'] as $state => $count)
                    <span class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        {{ ucfirst(str_replace('_', ' ', $state)) }}: <strong>{{ $count }}</strong>
                    </span>
                @endforeach
            </div>
        @endif

        {{-- Per-buyer breakdown: where each sits and how they've been worked this period. --}}
        <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Buyer</th>
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Stage</th>
                        <th class="text-right px-3 py-2" style="color:var(--text-muted);">Days in stage</th>
                        <th class="text-right px-3 py-2" style="color:var(--text-muted);">Days in pipeline</th>
                        <th class="text-right px-3 py-2" style="color:var(--text-muted);">Appts (period)</th>
                        <th class="text-right px-3 py-2" style="color:var(--text-muted);">Comms (period)</th>
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Last worked</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($buyer['buyers'] as $b)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-3 py-2" style="color:var(--text-primary);">{{ $b['name'] }}</td>
                            <td class="px-3 py-2" style="color:var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $b['state'])) }}</td>
                            <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $b['days_in_state'] !== null ? $b['days_in_state'] : '—' }}</td>
                            <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $b['days_in_pipeline'] !== null ? $b['days_in_pipeline'] : '—' }}</td>
                            <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $b['appointments'] }}</td>
                            <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $b['comms'] }}</td>
                            <td class="px-3 py-2" style="color:var(--text-muted);">{{ $b['last_worked_at'] ? \Illuminate\Support\Carbon::parse($b['last_worked_at'])->diffForHumans() : 'never' }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-center" style="color:var(--text-muted);" colspan="7">This agent holds no buyers.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Buyers lost in the period + reasons + value. --}}
        @if(!empty($buyer['lost']))
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Buyers lost this period</h3>
                @if(!empty($buyer['lost_reasons']))
                    <div class="flex flex-wrap gap-2 mb-2">
                        @foreach($buyer['lost_reasons'] as $reason => $agg)
                            <span class="text-[11px] px-2 py-1 rounded" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b;">
                                {{ $reason }}: <strong>{{ $agg['count'] }}</strong>@if($agg['value'] > 0) · R {{ number_format($agg['value']) }}@endif
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
                    <table class="w-full text-xs">
                        <thead>
                            <tr style="background:var(--surface-2);">
                                <th class="text-left px-3 py-2" style="color:var(--text-muted);">Buyer</th>
                                <th class="text-left px-3 py-2" style="color:var(--text-muted);">Reason</th>
                                <th class="text-left px-3 py-2" style="color:var(--text-muted);">Stage at loss</th>
                                <th class="text-right px-3 py-2" style="color:var(--text-muted);">Days in pipeline</th>
                                <th class="text-right px-3 py-2" style="color:var(--text-muted);">Pre-approval value</th>
                                <th class="text-left px-3 py-2" style="color:var(--text-muted);">Recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($buyer['lost'] as $l)
                                <tr style="border-top:1px solid var(--border);">
                                    <td class="px-3 py-2" style="color:var(--text-primary);">{{ $l['name'] }}</td>
                                    <td class="px-3 py-2" style="color:var(--text-muted);">{{ $l['reason'] }}</td>
                                    <td class="px-3 py-2" style="color:var(--text-muted);">{{ $l['state_at_loss'] ? ucfirst(str_replace('_', ' ', $l['state_at_loss'])) : '—' }}</td>
                                    <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $l['days_in_pipeline'] !== null ? $l['days_in_pipeline'] : '—' }}</td>
                                    <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $l['value'] > 0 ? 'R ' . number_format($l['value']) : '—' }}</td>
                                    <td class="px-3 py-2" style="color:var(--text-muted);">{{ $l['recorded_at'] ? \Illuminate\Support\Carbon::parse($l['recorded_at'])->format('d M Y') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <p class="text-[10px]" style="color:var(--text-muted);">
            Captured-and-matched floor: comms count only where a buyer contact was matched and a WhatsApp/email channel is provisioned; shared-mailbox email is not agent-attributable.
        </p>
    </div>
    @endisset

    <p class="text-[10px]" style="color:var(--text-muted);">
        AT-366-C agent journey — {{ count($journey['metrics']) }} metrics, current vs prior period.
    </p>
</div>
@endsection
