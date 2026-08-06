@extends('layouts.corex-app')

@section('title', 'Branch — ' . ($report['branch']['label'] ?? ''))

@section('corex-content')
<div class="p-6 space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <a href="{{ route('performance.agency-report', ['period' => $preset]) }}"
               class="text-[11px] no-underline" style="color:var(--text-muted);">&larr; Back to company report</a>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">{{ $report['branch']['label'] }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">
                Branch rollup · {{ $report['period']['label'] }}
                <span class="ml-1">(vs {{ $report['previous_period']['label'] }})</span>
            </p>
        </div>

        <form method="GET" action="{{ route('performance.agency-report.branch', $report['branch']['key']) }}"
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

    {{-- The branch's rolled-up metrics, each with its prior-period trend. --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Branch totals</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach($report['metrics'] as $m)
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
    </div>

    {{-- AT-366-E — this branch's buyer-activity summary --}}
    @includeWhen(isset($buyer), 'performance.agency-report._buyer-summary')

    {{-- Agents in this branch — drill one level further into a single agent's journey. --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Agents in this branch</h2>
        <div class="overflow-x-auto rounded" style="border:1px solid var(--border);">
            <table class="w-full text-xs">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-3 py-2" style="color:var(--text-muted);">Agent</th>
                        @foreach($report['metric_meta'] as $m)
                            <th class="text-right px-3 py-2" style="color:var(--text-muted);">{{ $m['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['agents'] as $agent)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-3 py-2">
                                <a href="{{ route('performance.agency-report.agent', ['user' => $agent['user_id'], 'period' => $preset]) }}"
                                   class="no-underline" style="color:var(--brand, #3b82f6);">{{ $agent['name'] }}</a>
                            </td>
                            @foreach($report['metric_meta'] as $m)
                                <td class="text-right px-3 py-2" style="color:var(--text-primary);">{{ $agent['metrics'][$m['key']] ?? 0 }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="px-3 py-4 text-center" style="color:var(--text-muted);" colspan="{{ count($report['metric_meta']) + 1 }}">No agents in this branch for the period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-[10px]" style="color:var(--text-muted);">
        AT-366-D branch drill-down — {{ count($report['metrics']) }} metrics, current vs prior period.
    </p>
</div>
@endsection
