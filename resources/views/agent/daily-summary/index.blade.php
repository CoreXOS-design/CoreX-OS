{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="text-sm text-white/60">
                    <a class="hover:underline text-white/60" href="{{ route('agent.dashboard') }}">&larr; Dashboard</a>
                </div>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Daily Activity Summary</h1>
                <p class="text-sm text-white/60">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </p>
            </div>

            <form method="GET" action="{{ route('agent.daily.summary') }}" class="flex flex-wrap items-center gap-2">
                <select name="range" class="rounded-md border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                    <option value="7d"  {{ $range==='7d' ? 'selected' : '' }}>Last 7 days</option>
                    <option value="month" {{ $range==='month' ? 'selected' : '' }}>This month</option>
                    <option value="3m"  {{ $range==='3m' ? 'selected' : '' }}>Last 3 months</option>
                    <option value="6m"  {{ $range==='6m' ? 'selected' : '' }}>Last 6 months</option>
                    <option value="12m" {{ $range==='12m' ? 'selected' : '' }}>Last 12 months</option>
                </select>

                @if($range === 'month')
                    <input type="text" name="month" value="{{ $month ?? '' }}" placeholder="YYYY-MM"
                           class="w-28 rounded-md border-0 bg-white/10 text-white text-sm px-3 py-1.5 placeholder:text-white/40" />
                @endif

                <button class="corex-btn-primary text-sm">Apply</button>
            </form>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total Count" :value="number_format((int)$grandCount)" />
        <x-corex-kpi-card title="Total Points" :value="number_format((float)$grandPoints, 0)" />
        <x-corex-kpi-card title="Activities Tracked" :value="number_format(count($items))" />
    </div>

    {{-- By Activity Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">By Activity</h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Click an activity name to drill down to your dates list.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Activity</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Count</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Points</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">% (Points)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr>
                            <td class="px-4 py-2.5 font-medium">
                                <a class="hover:underline" style="color: var(--brand-icon, #0ea5e9);"
                                   href="{{ route('agent.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap" style="background: var(--surface-2); color: var(--text-primary);">
                                    {{ number_format((int)$it['count']) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['points'], 0) }}</td>
                            <td class="px-4 py-2.5 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['pct_points'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No activity recorded in this range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
