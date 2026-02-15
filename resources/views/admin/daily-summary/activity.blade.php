@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm text-slate-500">
                <a class="hover:underline" href="{{ route('admin.daily.summary', array_filter(['range'=>$range,'month'=>$month])) }}">← Back to Company Summary</a>
            </div>
            <h1 class="text-2xl font-semibold text-slate-900 mt-1">{{ $def->name }}</h1>
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
            <div class="font-semibold text-slate-900">By Branch</div>
            <div class="text-xs text-slate-500">Click branch name or count to drill down to agents.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-white">
                    <tr class="border-b text-slate-600">
                        <th class="text-left p-3">Branch</th>
                        <th class="text-right p-3">Count</th>
                        <th class="text-right p-3">Points</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr class="border-b hover:bg-slate-50/70">
                            <td class="p-3 font-medium text-slate-900">
                                <a class="hover:underline"
                                   href="{{ route('admin.daily.summary.activity.branch', array_filter(['definition'=>$def->id,'branch'=>$it['branch_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['branch_name'] }}
                                </a>
                            </td>
                            <td class="p-3 text-right">
                                <a class="inline-flex items-center rounded-lg bg-slate-900/5 px-2 py-1 font-semibold text-slate-900 hover:bg-slate-900/10 hover:underline"
                                   href="{{ route('admin.daily.summary.activity.branch', array_filter(['definition'=>$def->id,'branch'=>$it['branch_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ (int)$it['count'] }}
                                </a>
                            </td>
                            <td class="p-3 text-right">{{ number_format((float)$it['points'], 0) }}</td>
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
