@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <div class="text-sm text-white/60">
                    <a class="hover:underline text-white/60" href="{{ route('agent.daily.summary', array_filter(['range'=>$range, 'month'=>$month])) }}">&larr; Back to Summary</a>
                </div>
                <h2 class="text-xl font-bold text-white leading-tight mt-1">{{ $def->name }}</h2>
                <div class="text-sm text-white/60">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </div>
            </div>
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
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800">
            <h3 class="ds-section-header">Dates performed</h3>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Newest first.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead class="bg-white">
                    <tr class="border-b text-slate-600">
                        <th class="text-left p-3">Date</th>
                        <th class="text-right p-3">Count</th>
                        <th class="text-right p-3">Points</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr class="border-b hover:bg-slate-50/70">
                            <td class="p-3 font-medium text-slate-900">
                                {{ \Illuminate\Support\Carbon::parse($r['date'])->format('D j M Y') }}
                            </td>
                            <td class="p-3 text-right">{{ (int)$r['count'] }}</td>
                            <td class="p-3 text-right">{{ number_format((float)$r['points'], 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="p-6 text-center text-slate-500">No entries in this range.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
