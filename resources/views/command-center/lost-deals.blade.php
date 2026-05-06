@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white">Lost Deals Analysis</h1>
        <p class="text-sm text-white/60">Understand why buyers and sellers leave. Last {{ $days }} days.</p>
    </div>

    {{-- Summary cards --}}
    @php $recovered = DB::table('buyer_lost_records')->where('agency_id', auth()->user()->effectiveAgencyId() ?? 1)->where('recorded_at', '>=', now()->subDays($days))->whereNotNull('recovered_at')->count(); @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: #ef4444;">{{ $valueData['count'] }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Buyers Lost</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-lg font-bold" style="color: #ef4444;">R {{ number_format($valueData['value']) }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Value at Loss</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid #10b981;">
            <div class="text-2xl font-bold" style="color: #10b981;">{{ $recovered }}</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Recovered</div>
        </div>
        <div class="rounded-md p-4 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-2xl font-bold" style="color: var(--text-primary);">{{ $valueData['count'] > 0 ? round($recovered / $valueData['count'] * 100) : 0 }}%</div>
            <div class="text-[10px] uppercase" style="color: var(--text-muted);">Recovery Rate</div>
        </div>
    </div>

    {{-- Reason distribution --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Reason Distribution</h2>
        @if($distribution->isEmpty())
            <p class="text-xs" style="color: var(--text-muted);">No lost records in this period.</p>
        @else
            <div class="space-y-2">
                @php $maxCnt = $distribution->max('cnt') ?: 1; @endphp
                @foreach($distribution as $row)
                    <div class="flex items-center gap-3">
                        <span class="text-xs w-48 truncate" style="color: var(--text-primary);">{{ $row->reason_label }}</span>
                        <div class="flex-1 h-4 rounded overflow-hidden" style="background: var(--surface-2);">
                            <div class="h-full rounded" style="width: {{ ($row->cnt / $maxCnt) * 100 }}%; background: #ef4444;"></div>
                        </div>
                        <span class="text-xs font-bold w-8 text-right" style="color: var(--text-primary);">{{ $row->cnt }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
