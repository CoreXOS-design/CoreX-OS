@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Branch Leave Summary</h1>
                <p class="text-sm text-white/60">Annual and sick leave totals per branch, with at-risk balances flagged.</p>
            </div>
        </div>
    </div>

    {{-- Report navigation tabs --}}
    @include('payroll.leave.reports._tabs')

    @if(empty($summary))
        {{-- Empty state --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No branches found</h3>
            <p class="text-sm" style="color:var(--text-muted);">Once branches are configured, their leave summaries will appear here.</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($summary as $s)
            <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-bold" style="color:var(--text-primary);">{{ $s['branch']->name }}</h4>
                    <div class="flex items-center gap-2">
                        <span class="text-xs" style="color:var(--text-secondary);">{{ number_format($s['employee_count']) }} employees</span>
                        @if($s['compliance_flags'] > 0)
                            <span class="ds-badge ds-badge-warning">{{ number_format($s['compliance_flags']) }} at risk</span>
                        @endif
                    </div>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div class="text-center">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Annual Entitled</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">{{ number_format((float)$s['annual_entitled'], 1) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Annual Taken</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">{{ number_format((float)$s['annual_taken'], 1) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Annual Available</p>
                        <p class="text-sm font-bold" style="color:var(--brand-icon, #0ea5e9);">{{ number_format((float)$s['annual_available'], 1) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Sick Taken</p>
                        <p class="text-sm font-bold" style="color:var(--text-primary);">{{ number_format((float)$s['sick_taken'], 1) }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">At Risk (&gt;1.5x)</p>
                        <p class="text-sm font-bold" style="color:{{ $s['annual_at_risk'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-primary)' }};">{{ number_format($s['annual_at_risk']) }}</p>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
