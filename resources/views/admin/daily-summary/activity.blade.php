{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page Header --}}
    <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-5">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('admin.daily.summary', array_filter(['range'=>$range,'month'=>$month])) }}" class="text-sm text-white/60 hover:underline transition-all duration-300">&larr; Back to Company Summary</a>
                <h1 class="text-xl font-bold text-white leading-tight tracking-tight mt-1">{{ $def->name }}</h1>
                <p class="text-sm text-white/60">
                    {{ $start->toFormattedDateString() }} &rarr; {{ $end->toFormattedDateString() }}
                </p>
            </div>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Total Count" :value="number_format((int)$totalCount)" />
        <x-corex-kpi-card title="Weight" :value="number_format((float)$def->weight, 2)" />
        <x-corex-kpi-card title="Total Points" :value="number_format((float)$totalPoints, 0)" />
    </div>

    {{-- By Branch Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-sm font-semibold" style="color: var(--text-primary);">By Branch</h3>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Click branch name or count to drill down to agents.</div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Count</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Points</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr class="transition-colors">
                            <td class="px-4 py-3 font-medium">
                                <a class="hover:underline transition-all duration-300" style="color: var(--brand-icon, #0ea5e9);"
                                   href="{{ route('admin.daily.summary.activity.branch', array_filter(['definition'=>$def->id,'branch'=>$it['branch_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ $it['branch_name'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a class="inline-flex items-center rounded-md px-2.5 py-1 font-semibold text-xs transition-colors"
                                   style="background: var(--surface-2); color: var(--text-primary);"
                                   href="{{ route('admin.daily.summary.activity.branch', array_filter(['definition'=>$def->id,'branch'=>$it['branch_id'],'range'=>$range,'month'=>$month])) }}">
                                    {{ number_format((int)$it['count']) }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-right" style="color: var(--text-primary);">{{ number_format((float)$it['points'], 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No entries in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
