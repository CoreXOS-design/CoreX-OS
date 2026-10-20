@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner" data-tour="cop-fin-aud-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Finance Audit Center</h1>
                <p class="text-xs" style="color: var(--text-muted);">View past audit runs or run a new audit for any period.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    <div class="props-filter-panel rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);" data-tour="cop-fin-aud-filter">
        <form method="GET" action="{{ route('admin.finance.audit.index') }}" class="flex flex-wrap items-center gap-3">
            <select name="period" class="list-header-filter {{ request('period') ? 'is-active' : '' }}" data-tour="cop-fin-aud-period">
                <option value="">All periods</option>
                @foreach($availablePeriods as $p)
                    <option value="{{ $p }}" {{ request('period') === $p ? 'selected' : '' }}>
                        {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                    </option>
                @endforeach
            </select>
            <button class="corex-btn-primary text-xs px-4 py-1.5">Filter</button>
            @if(request('period'))
                <a href="{{ route('admin.finance.audit.index') }}" class="text-xs underline" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Flash messages handled by global toast system --}}

    <div class="ds-status-card p-5" data-tour="cop-fin-aud-run">
        <h3 class="ds-section-header mb-3">Run New Audit</h3>
        <form method="POST" action="{{ route('admin.finance.recalculate') }}" class="flex items-center gap-3"
              onsubmit="return confirm('Run recalculation and audit for the selected period?')">
            @csrf
            <input type="hidden" name="mode" value="single">
            <select name="period" class="list-header-filter" data-tour="cop-fin-aud-run-period">
                @foreach($availablePeriods as $p)
                    <option value="{{ $p }}" {{ $p === now()->format('Y-m') ? 'selected' : '' }}>
                        {{ \Carbon\Carbon::createFromFormat('Y-m', $p)->format('F Y') }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary text-sm">Audit This Period</button>
        </form>
    </div>

    <div class="rounded-md border overflow-hidden" style="border-color:var(--border); background:var(--surface)" data-tour="cop-fin-aud-runs">
        <div class="px-4 py-3 border-b" style="border-color:var(--border)">
            <h3 class="ds-section-header">Audit Runs</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Run</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Period</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Items</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Errors</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Warnings</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pass</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Started</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Finished</th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color:var(--border)">
                    @forelse($runs as $run)
                        @php $passCount = $run->items_count - $run->errors_count - $run->warnings_count; @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.finance.audit.run', $run) }}"
                                   class="font-semibold hover:underline" style="color:var(--brand-icon)" data-tour="cop-fin-aud-open">
                                    #{{ $run->id }}
                                </a>
                            </td>
                            <td class="px-4 py-3" style="color:var(--text-secondary)">
                                {{ \Carbon\Carbon::createFromFormat('Y-m', $run->period)->format('F Y') }}
                            </td>
                            <td class="px-4 py-3">
                                @if($run->status === 'complete')
                                    <span class="ds-badge ds-badge-success">complete</span>
                                @elseif($run->status === 'failed')
                                    <span class="ds-badge ds-badge-danger">failed</span>
                                @else
                                    <span class="ds-badge ds-badge-warning">{{ $run->status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right" style="color:var(--text-secondary)">{{ number_format($run->items_count) }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($run->errors_count > 0)
                                    <span class="font-semibold text-red-600 dark:text-red-400">{{ $run->errors_count }}</span>
                                @else
                                    <span style="color:var(--text-faint)">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($run->warnings_count > 0)
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $run->warnings_count }}</span>
                                @else
                                    <span style="color:var(--text-faint)">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($passCount > 0)
                                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $passCount }}</span>
                                @else
                                    <span style="color:var(--text-faint)">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-muted)">{{ $run->started_at?->format('d M Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-muted)">{{ $run->finished_at?->format('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center" style="color:var(--text-muted)">No audit runs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($runs->hasPages())
            <div class="px-4 py-3 border-t" style="border-color:var(--border)">
                {{ $runs->links() }}
            </div>
        @endif
    </div>

</div>
@endsection
