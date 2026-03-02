@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Daily Activity Summary (Company)</h2>
                <div class="text-sm text-white/60">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </div>
            </div>

            <form method="GET" action="{{ route('admin.daily.summary') }}" class="flex flex-wrap items-center gap-2">
                <select name="range" class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                    <option value="7d"  {{ $range==='7d' ? 'selected' : '' }}>Last 7 days</option>
                    <option value="month" {{ $range==='month' ? 'selected' : '' }}>This month</option>
                    <option value="3m"  {{ $range==='3m' ? 'selected' : '' }}>Last 3 months</option>
                    <option value="6m"  {{ $range==='6m' ? 'selected' : '' }}>Last 6 months</option>
                    <option value="12m" {{ $range==='12m' ? 'selected' : '' }}>Last 12 months</option>
                </select>

                @if($range === 'month')
                    <input type="text" name="month" value="{{ $month ?? '' }}" placeholder="YYYY-MM"
                           class="w-28 rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 placeholder:text-white/40" />
                @endif

                <button class="corex-btn-primary text-sm">Apply</button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Total Count</div>
            <div class="ds-value-xl">{{ (int)$grandCount }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Total Points</div>
            <div class="ds-value-xl">{{ number_format((float)$grandPoints, 0) }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Activities Tracked</div>
            <div class="ds-value-xl">{{ count($items) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="ds-section-header">By Activity</h3>
            <div class="text-xs text-slate-500 dark:text-slate-400">Click activity name or count to drill down to branches.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                        <th class="text-left px-4 py-3">Activity</th>
                        <th class="text-right px-4 py-3">Count</th>
                        <th class="text-right px-4 py-3">Points</th>
                        <th class="text-right px-4 py-3">% (Points)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach($items as $it)
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                                <a class="hover:underline"
                                   href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="inline-flex items-center rounded-lg bg-slate-900/5 px-2 py-1 font-semibold text-slate-900 dark:text-slate-100 hover:bg-slate-900/10 hover:underline"
                                   href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$it['id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ (int)$it['count'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-900 dark:text-slate-100">{{ number_format((float)$it['points'], 0) }}</td>
                            <td class="px-4 py-3 text-right text-slate-900 dark:text-slate-100">{{ number_format((float)$it['pct_points'], 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
