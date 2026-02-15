@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm text-slate-500 space-x-2">
                <a class="hover:underline" href="{{ route('admin.daily.summary', array_filter(['range'=>$range,'month'=>$month])) }}">Company Summary</a>
                <span>›</span>
                <a class="hover:underline" href="{{ route('admin.daily.summary.activity', array_filter(['definition'=>$def->id,'range'=>$range,'month'=>$month])) }}">{{ $def->name }}</a>
                <span>›</span>
                <a class="hover:underline" href="{{ route('admin.daily.summary.activity.branch', array_filter(['definition'=>$def->id,'branch'=>$branchId,'range'=>$range,'month'=>$month])) }}">{{ $branchName }}</a>
                <span>›</span>
                <span class="text-slate-700">{{ $agentName }}</span>
            </div>

            <h1 class="text-2xl font-semibold text-slate-900 mt-1">{{ $agentName }} — {{ $def->name }}</h1>
            <div class="text-sm text-slate-600">
                {{ $start->toFormattedDateString() }} → {{ $end->toFormattedDateString() }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Total Count</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ (int)$totalCount }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Weight</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ number_format((float)$def->weight, 2) }}</div>
        </div>
        <div class="rounded-2xl border bg-white p-5 shadow-sm">
            <div class="text-xs uppercase tracking-wide text-slate-500">Total Points</div>
            <div class="mt-1 text-3xl font-extrabold text-slate-900">{{ number_format((float)$totalPoints, 0) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b bg-slate-50/60">
            <div class="font-semibold text-slate-900">Dates performed</div>
            <div class="text-xs text-slate-500">Newest first.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
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
                        <tr><td colspan="3" class="p-6 text-center text-slate-500">No entries in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
