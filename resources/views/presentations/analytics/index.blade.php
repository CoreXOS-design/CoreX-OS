{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="pres-analytics-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Presentations Analytics</h1>
                <p class="text-sm text-white/60">Lifecycle pipeline: generated → shared → viewed → leads → outcomes.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @include('layouts.partials.tour-header-launcher')
                @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
                <a href="{{ route('presentations.index') }}" class="corex-btn-outline corex-btn-on-brand text-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
                    Presentations
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Filters (§3.8 / §3.6) --}}
    <form method="GET" class="rounded-md p-4 flex flex-wrap items-end gap-3"
          style="background: var(--surface); border: 1px solid var(--border);" data-tour="pres-analytics-filters">
        <div class="flex-1 min-w-[160px]">
            <label for="filter-from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From</label>
            <input id="filter-from" type="date" name="from" value="{{ $from->toDateString() }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label for="filter-to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label>
            <input id="filter-to" type="date" name="to" value="{{ $to->toDateString() }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        @if($isManager)
            <div class="flex-1 min-w-[160px]">
                <label for="filter-agent" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
                <select id="filter-agent" name="agent_id" class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All</option>
                    @foreach($agents as $a)
                        <option value="{{ $a->id }}" @selected((int) $agentFilter === (int) $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <button type="submit" class="corex-btn-primary">Apply</button>
        </div>
    </form>

    {{-- Funnel KPI tiles (§3.2) --}}
    @php
        $sharedPct   = $generatedCount > 0 ? round($sharedCount / $generatedCount * 100, 1) : 0;
        $viewedPct   = $generatedCount > 0 ? round($viewedCount / $generatedCount * 100, 1) : 0;
        $recordedPct = $generatedCount > 0 ? round($outcomeRecordedCount / $generatedCount * 100, 1) : 0;
        $tiles = [
            ['Presentations',        number_format($generatedCount),       null,                                  null],
            ['Shared',               number_format($sharedCount),          number_format($sharedPct, 1) . '%',    'of generated'],
            ['Viewed at least once', number_format($viewedCount),          number_format($viewedPct, 1) . '%',    'of generated'],
            ['Leads captured',       number_format($leadCount),            null,                                  'from teaser links'],
            ['Outcomes recorded',    number_format($outcomeRecordedCount), number_format($recordedPct, 1) . '%',  'of generated'],
            ['Outcomes pending',     number_format($outcomePendingCount),  null,                                  'no outcome yet'],
            ['Win rate',             number_format($winRate, 1) . '%',     null,                                  ($wonCount > 0 ? number_format($wonCount) . ' won' : 'no wins yet')],
        ];
    @endphp
    <div class="corex-kpi-grid" data-tour="pres-analytics-tiles">
        @foreach($tiles as [$label, $value, $pct, $sub])
            <div class="corex-kpi-card">
                <p class="corex-kpi-title">{{ $label }}</p>
                <p class="corex-kpi-value">{{ $value }}</p>
                @if($pct)
                    <p class="text-[0.6875rem] mt-1" style="color: var(--text-muted);">{{ $pct }} {{ $sub }}</p>
                @elseif($sub)
                    <p class="text-[0.6875rem] mt-1" style="color: var(--text-muted);">{{ $sub }}</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Funnel visualization --}}
    @if($generatedCount > 0)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-tour="pres-analytics-funnel">
            <h2 class="ds-section-header mb-3">Funnel</h2>
            @php
                $rows = [
                    ['Generated',          $generatedCount,       'var(--brand-default, #0b2a4a)'],
                    ['Shared with seller', $sharedCount,          'var(--brand-icon, #0ea5e9)'],
                    ['Viewed by seller',   $viewedCount,          'var(--ds-cyan, #00b4d8)'],
                    ['Outcome recorded',   $outcomeRecordedCount, 'var(--ds-amber, #f59e0b)'],
                    ['Won mandate / sale', $wonCount,             'var(--ds-green, #059669)'],
                ];
            @endphp
            <div class="flex flex-col gap-2.5">
                @foreach($rows as [$lbl, $n, $colour])
                    @php $w = $generatedCount > 0 ? max(2, round(($n / $generatedCount) * 100)) : 0; @endphp
                    <div class="grid items-center gap-3" style="grid-template-columns: minmax(120px, 180px) 1fr 50px;">
                        <div class="text-sm" style="color: var(--text-secondary);">{{ $lbl }}</div>
                        <div class="ds-progress-track">
                            <div class="ds-progress-bar" style="width: {{ $w }}%; background: {{ $colour }};"></div>
                        </div>
                        <div class="text-sm font-semibold text-right" style="color: var(--text-primary);">{{ number_format($n) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Per-agent leaderboard (managers only) (§3.7) --}}
    @if($isManager && $byAgent->isNotEmpty())
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="p-4" style="border-bottom: 1px solid var(--border);">
                <h2 class="ds-section-header">Per-agent breakdown</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Generated</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Outcomes recorded</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Won</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Win rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($byAgent as $a)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3" style="color: var(--text-primary);">{{ $a['name'] }}</td>
                            <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary);">{{ number_format($a['generated']) }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ number_format($a['recorded']) }}</td>
                            <td class="px-4 py-3 text-right font-semibold" style="color: var(--ds-green, #059669);">{{ number_format($a['won']) }}</td>
                            <td class="px-4 py-3 text-right font-medium" style="color: var(--text-primary);">
                                {{ $a['win_rate'] !== null ? number_format($a['win_rate'], 1) . '%' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Empty state (§3.10) --}}
    @if($generatedCount === 0)
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No presentations generated in this window</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Adjust the date range, or generate a presentation to start populating the funnel.</p>
            @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
            <a href="{{ route('presentations.index') }}" class="corex-btn-primary text-sm">Go to Presentations</a>
            @endif
        </div>
    @endif

</div>
@endsection
