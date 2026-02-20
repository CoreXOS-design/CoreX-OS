@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Finance Audit Center</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Read-only view of all finance audit runs.</p>
        </div>

        <form method="GET" action="{{ route('admin.finance.audit.index') }}" class="flex items-center gap-2">
            <input type="text" name="period" value="{{ request('period') }}"
                   placeholder="YYYY-MM"
                   class="w-32 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
            <button class="rounded-xl bg-slate-900 dark:bg-slate-700 text-white px-4 py-2 text-sm font-semibold">Filter</button>
            @if(request('period'))
                <a href="{{ route('admin.finance.audit.index') }}" class="text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">Clear</a>
            @endif
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-900/40">
            <div class="font-semibold text-slate-900 dark:text-slate-100">Audit Runs</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 dark:border-slate-800 text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-950">
                        <th class="text-left px-4 py-3">Run ID</th>
                        <th class="text-left px-4 py-3">Period</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-right px-4 py-3">Items</th>
                        <th class="text-right px-4 py-3">Errors</th>
                        <th class="text-right px-4 py-3">Warnings</th>
                        <th class="text-left px-4 py-3">Started</th>
                        <th class="text-left px-4 py-3">Finished</th>
                        <th class="text-left px-4 py-3">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse($runs as $run)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/40">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.finance.audit.run', $run) }}"
                                   class="font-semibold text-blue-600 dark:text-blue-400 hover:underline">
                                    #{{ $run->id }}
                                </a>
                            </td>
                            <td class="px-4 py-3 font-mono text-slate-700 dark:text-slate-300">{{ $run->period }}</td>
                            <td class="px-4 py-3">
                                @if($run->status === 'complete')
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">complete</span>
                                @elseif($run->status === 'failed')
                                    <span class="inline-flex items-center rounded-full bg-red-50 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400">failed</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400">{{ $run->status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-slate-700 dark:text-slate-300">{{ number_format($run->items_count) }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($run->errors_count > 0)
                                    <span class="font-semibold text-red-600 dark:text-red-400">{{ $run->errors_count }}</span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($run->warnings_count > 0)
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $run->warnings_count }}</span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs">{{ $run->started_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500 text-xs">{{ $run->finished_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500 text-xs">{{ $run->created_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-slate-400 dark:text-slate-600">No audit runs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($runs->hasPages())
            <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                {{ $runs->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
