{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    <nav class="text-xs" style="color: var(--text-muted);">
        <a href="{{ route('bm.my.dashboard') }}" style="color: var(--brand-icon);">Dashboard</a>
        <span class="mx-1">/</span>
        <span>Daily Activity Summary</span>
    </nav>

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Daily Activity Summary (Branch)</h1>
                <p class="text-sm text-white/60">
                    {{ $branchName ?? ('Branch #' . (int)$branchId) }} &middot; {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </p>
            </div>
        </div>
    </div>

    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('bm.daily.summary') }}" class="flex flex-wrap items-center gap-2">
            <select name="range" onchange="this.form.submit()" class="list-header-filter">
                <option value="7d"  {{ $range==='7d' ? 'selected' : '' }}>Last 7 days</option>
                <option value="month" {{ $range==='month' ? 'selected' : '' }}>This month</option>
                <option value="3m"  {{ $range==='3m' ? 'selected' : '' }}>Last 3 months</option>
                <option value="6m"  {{ $range==='6m' ? 'selected' : '' }}>Last 6 months</option>
                <option value="12m" {{ $range==='12m' ? 'selected' : '' }}>Last 12 months</option>
            </select>

            @if($range === 'month')
                <input type="text" name="month" value="{{ $month ?? '' }}" placeholder="YYYY-MM"
                       class="list-header-filter" style="width: 7.5rem;" />
            @endif

            <button type="submit" class="corex-btn-primary">Apply</button>
        </form>
    </div>

    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total Count" :value="number_format((int)$grandCount)" />
        <x-corex-kpi-card title="Total Points" :value="number_format((float)$grandPoints, 0)" />
        <x-corex-kpi-card title="Activities Tracked" :value="number_format(count($items))" />
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">By Activity</h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Click the activity name or count to drill down to agents.</p>
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
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-medium">
                                <a class="hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);"
                                   href="{{ route('bm.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap transition-all duration-300"
                                   style="background: var(--surface-2); color: var(--text-primary);"
                                   href="{{ route('bm.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ number_format((int)$it['count']) }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary);">{{ number_format((float)$it['points'], 0) }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-secondary);">{{ number_format((float)$it['pct_points'], 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No activities recorded for this branch in the selected range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
