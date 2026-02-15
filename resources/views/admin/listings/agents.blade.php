@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Listings per Agent</h1>
            <p class="text-sm text-slate-600 dark:text-slate-300">Read-only overview from imported listing stock.</p>
        </div>

        <form method="get" class="flex flex-wrap gap-2 items-end">
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Status</label>
                <select name="status" class="rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                    @foreach(['active'=>'Active (contains active/for sale)','all'=>'All'] as $k=>$label)
                        <option value="{{ $k }}" @selected(($status ?? 'active') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Source</label>
                <input name="source" value="{{ $source ?? 'propcon' }}" class="w-40 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
            </div>
            <button class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                Apply
            </button>
        </form>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active listings</div>
            <div class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format((int)($totals->listing_count ?? 0)) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total asking value</div>
            <div class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">R {{ number_format(((int)($totals->total_value_cents ?? 0))/100, 0) }}</div>
        </div>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Agents with stock</div>
            <div class="mt-1 text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ number_format(count($rows ?? [])) }}</div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">Agent breakdown</div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Click an agent to drill in</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600 dark:text-slate-300">
                    <tr>
                        <th class="text-left px-4 py-3">Agent</th>
                        <th class="text-right px-4 py-3">Active</th>
                        <th class="text-right px-4 py-3">Asking value</th>
                        <th class="text-left px-4 py-3">Mandates</th>
                        <th class="text-left px-4 py-3">Top types</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($rows as $r)
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $r['name'] }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $r['email'] }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900 dark:text-slate-100">{{ number_format($r['listing_count']) }}</td>
                            <td class="px-4 py-3 text-right text-slate-900 dark:text-slate-100">R {{ number_format($r['total_value_cents']/100, 0) }}</td>
                            <td class="px-4 py-3">
                                @php $m = $r['mandates'] ?? []; @endphp
                                @if(count($m))
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($m as $k => $c)
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
                                                <span class="font-medium">{{ $k }}</span>
                                                <span class="opacity-70">{{ $c }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-500 dark:text-slate-400">(none)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php $t = $r['top_types'] ?? []; @endphp
                                @if(count($t))
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($t as $x)
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-800">
                                                <span class="font-medium">{{ $x['type'] }}</span>
                                                <span class="opacity-70">{{ $x['c'] }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-500 dark:text-slate-400">(none)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100"
                                   href="{{ route('admin.listings.agents.show', ['user' => $r['user_id'], 'status' => $status ?? 'active', 'source' => $source ?? 'propcon']) }}">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-slate-500 dark:text-slate-400" colspan="6">
                                No listing stock found for this filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
