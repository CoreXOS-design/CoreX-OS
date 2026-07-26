{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">
    {{-- Page header — flat neutral bar (AT-336). Type scale matches /worksheet:
         16px bold title + 12px muted subtitle. --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Lost Deals Analysis</h1>
                <p class="text-xs" style="color: var(--text-muted);">Understand why buyers and sellers leave. Last {{ number_format($days) }} days.</p>
            </div>
        </div>
    </div>

    {{-- Summary cards --}}
    @php $recovered = DB::table('buyer_lost_records')->where('agency_id', auth()->user()->effectiveAgencyId() ?? 1)->where('recorded_at', '>=', now()->subDays($days))->whereNotNull('recovered_at')->count(); @endphp
    @php $recoveryRate = $valueData['count'] > 0 ? round($recovered / $valueData['count'] * 100) : 0; @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--ds-amber, #f59e0b);">{{ number_format($valueData['count']) }}</div>
            <div class="text-[0.6875rem] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Buyers Lost</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-lg font-semibold" style="color: var(--ds-amber, #f59e0b);">R {{ number_format($valueData['value']) }}</div>
            <div class="text-[0.6875rem] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Value at Loss</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--ds-green, #059669);">{{ number_format($recovered) }}</div>
            <div class="text-[0.6875rem] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Recovered</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold" style="color: var(--text-primary);">{{ number_format($recoveryRate) }}%</div>
            <div class="text-[0.6875rem] uppercase tracking-wider mt-1" style="color: var(--text-muted);">Recovery Rate</div>
        </div>
    </div>

    {{-- Reason distribution --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Reason Distribution</h2>
        @if($distribution->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="border: 1px dashed var(--border);">
                <p class="text-sm" style="color: var(--text-muted);">No lost records in this period.</p>
            </div>
        @else
            <div class="space-y-3">
                @php $maxCnt = $distribution->max('cnt') ?: 1; @endphp
                @foreach($distribution as $row)
                    <div class="flex items-center gap-3">
                        <span class="text-[13px] w-48 truncate" style="color: var(--text-primary);">{{ $row->reason_label }}</span>
                        <div class="flex-1 ds-progress-track">
                            <div class="ds-progress-bar ds-bar-amber" style="width: {{ ($row->cnt / $maxCnt) * 100 }}%;"></div>
                        </div>
                        <span class="text-[13px] font-semibold w-8 text-right" style="color: var(--text-primary);">{{ number_format($row->cnt) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
