@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="text-sm text-white/60 space-x-2">
            <a class="hover:underline" href="{{ route('admin.daily.summary', array_filter(['range'=>$range,'month'=>$month])) }}">Company Summary</a>
            <span>&rsaquo;</span>
            <a class="hover:underline" href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$def->id,'range'=>$range,'month'=>$month])) }}">{{ $def->name }}</a>
            <span>&rsaquo;</span>
            <span class="text-white/80">{{ $branchName }}</span>
        </div>
        <h2 class="text-xl font-bold text-white leading-tight mt-1">{{ $branchName }} &mdash; {{ $def->name }}</h2>
        <div class="text-sm text-white/60">
            {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Total Count</div>
            <div class="ds-value-xl">{{ (int)$totalCount }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Weight</div>
            <div class="ds-value-xl">{{ number_format((float)$def->weight, 2) }}</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Total Points</div>
            <div class="ds-value-xl">{{ number_format((float)$totalPoints, 0) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <h3 class="ds-section-header">By Agent</h3>
            <div class="text-xs text-slate-500 dark:text-slate-400">Click agent name or count to see dates performed.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                        <th class="text-left px-4 py-3">Agent</th>
                        <th class="text-right px-4 py-3">Count</th>
                        <th class="text-right px-4 py-3">Points</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($items as $it)
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                                <a class="hover:underline"
                                   href="{{ route('admin.daily.summary.activity.branch.agent', array_filter(['definition'=>$def->id,'branch'=>$branchId,'user'=>$it['user_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="inline-flex items-center rounded-lg bg-slate-900/5 px-2 py-1 font-semibold text-slate-900 dark:text-slate-100 hover:bg-slate-900/10 hover:underline"
                                   href="{{ route('admin.daily.summary.activity.branch.agent', array_filter(['definition'=>$def->id,'branch'=>$branchId,'user'=>$it['user_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ (int)$it['count'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-900 dark:text-slate-100">{{ number_format((float)$it['points'], 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">No entries in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
