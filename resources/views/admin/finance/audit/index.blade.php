@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Finance Audit Center</h2>
                <div class="text-sm text-white/60">View past audit runs or run a new audit for any period.</div>
            </div>

            <form method="GET" action="{{ route('admin.finance.audit.index') }}" class="flex items-center gap-2">
                <select name="period"
                        class="rounded-lg border-0 bg-white/10 text-white text-sm px-3 py-1.5 [&>option]:text-slate-900">
                    <option value="">All periods</option>
                    @foreach($availablePeriods as $p)
                        <option value="{{ $p }}" {{ request('period') === $p ? 'selected' : '' }}>
                            {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                        </option>
                    @endforeach
                </select>
                <button class="corex-btn-primary text-sm">Filter</button>
                @if(request('period'))
                    <a href="{{ route('admin.finance.audit.index') }}" class="text-sm text-white/60 hover:underline">Clear</a>
                @endif
            </form>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-3">Run New Audit</h3>
        <form method="POST" action="{{ route('admin.finance.recalculate') }}" class="flex items-center gap-3"
              onsubmit="return confirm('Run recalculation and audit for the selected period?')">
            @csrf
            <input type="hidden" name="mode" value="single">
            <select name="period"
                    class="rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                @foreach($availablePeriods as $p)
                    <option value="{{ $p }}" {{ $p === now()->format('Y-m') ? 'selected' : '' }}>
                        {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary text-sm">Audit This Period</button>
        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
            <h3 class="ds-section-header">Audit Runs</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr class="border-b text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-900/40">
                        <th class="text-left px-4 py-3">Run</th>
                        <th class="text-left px-4 py-3">Period</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-right px-4 py-3">Items</th>
                        <th class="text-right px-4 py-3">Errors</th>
                        <th class="text-right px-4 py-3">Warnings</th>
                        <th class="text-right px-4 py-3">Pass</th>
                        <th class="text-left px-4 py-3">Started</th>
                        <th class="text-left px-4 py-3">Finished</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse($runs as $run)
                        @php $passCount = $run->items_count - $run->errors_count - $run->warnings_count; @endphp
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-900/30">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.finance.audit.run', $run) }}"
                                   class="font-semibold text-[#0b2a4a] dark:text-[#00b4d8] hover:underline">
                                    #{{ $run->id }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-slate-700 dark:text-slate-300">
                                {{ \Carbon\Carbon::createFromFormat('Y-m', $run->period)->format('F Y') }}
                            </td>
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
                            <td class="px-4 py-3 text-right">
                                @if($passCount > 0)
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $passCount }}</span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ $run->started_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400 text-xs">{{ $run->finished_at?->format('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">No audit runs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($runs->hasPages())
            <div class="px-4 py-3 border-t border-slate-200 dark:border-slate-800">
                {{ $runs->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
