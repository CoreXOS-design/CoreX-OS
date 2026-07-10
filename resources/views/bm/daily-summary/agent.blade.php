{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    <nav class="text-xs" style="color: var(--text-muted);">
        <a href="{{ route('bm.daily.summary', array_filter(['range'=>$range,'month'=>$month])) }}" style="color: var(--brand-icon);">Daily Activity Summary</a>
        <span class="mx-1">/</span>
        <a href="{{ route('bm.daily.summary.activity', array_filter(['definition'=>$def->id,'range'=>$range,'month'=>$month])) }}" style="color: var(--brand-icon);">{{ $def->name }}</a>
        <span class="mx-1">/</span>
        <span>{{ $agentName }}</span>
    </nav>

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $agentName }} &mdash; {{ $def->name }}</h1>
                <p class="text-sm text-white/60">
                    {{ $branchName ?? ('Branch #' . (int)$branchId) }} &middot; {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('bm.daily.summary.activity', array_filter(['definition'=>$def->id,'range'=>$range,'month'=>$month])) }}" class="corex-btn-outline">&larr; Back to Activity</a>
            </div>
        </div>
    </div>

    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total Count" :value="number_format((int)$totalCount)" />
        <x-corex-kpi-card title="Weight" :value="number_format((float)$def->weight, 2)" />
        <x-corex-kpi-card title="Total Points" :value="number_format((float)$totalPoints, 0)" />
    </div>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Dates performed</h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Newest first.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Count</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Points</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">
                                {{ \Illuminate\Support\Carbon::parse($r['date'])->format('D j M Y') }}
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary);">{{ number_format((int)$r['count']) }}</td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary);">{{ number_format((float)$r['points'], 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                No entries in this range.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
