{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Dashboard</h1>
                <p class="text-sm text-white/60">Track balances, applications and compliance at a glance.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @permission('manage_leave')
                <a href="{{ route('payroll.leave.balances.index') }}" class="corex-btn-primary text-sm">View Balances</a>
                @endpermission
                @permission('approve_leave')
                <a href="{{ route('payroll.leave.applications.index') }}" class="corex-btn-outline corex-btn-on-brand text-sm">View Applications</a>
                @endpermission
                @permission('view_leave_reports')
                <a href="{{ route('payroll.leave.reports.register') }}" class="corex-btn-outline corex-btn-on-brand text-sm">View Reports</a>
                @endpermission
                @permission('access_settings')
                <a href="{{ url('/corex/settings?s=leave-visibility') }}"
                   title="Leave Settings"
                   aria-label="Leave Settings"
                   class="inline-flex items-center justify-center w-[30px] h-[30px] rounded-md text-white bg-white/10 hover:bg-white/20 border border-white/20 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    {{-- KPI stats --}}
    @php
        $kpiTiles = [
            [
                'label' => 'Active Employees',
                'value' => number_format($activeEmployees),
                'bg'    => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)',
                'fg'    => 'var(--brand-icon)',
                'value_color' => 'var(--text-primary, #0f172a)',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>',
            ],
            [
                'label' => 'Approved This Month',
                'value' => number_format($approvedThisMonth),
                'bg'    => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',
                'fg'    => 'var(--ds-green)',
                'value_color' => 'var(--text-primary, #0f172a)',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            ],
            [
                'label' => 'Pending Applications',
                'value' => number_format($pendingApplications),
                'bg'    => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',
                'fg'    => 'var(--ds-amber)',
                'value_color' => $pendingApplications > 0 ? 'var(--ds-amber)' : 'var(--text-primary, #0f172a)',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            ],
            [
                'label' => 'Days Taken (YTD)',
                'value' => number_format($daysTakenThisYear, 1),
                'bg'    => 'color-mix(in srgb, var(--ds-navy) 12%, transparent)',
                'fg'    => 'var(--ds-navy)',
                'value_color' => 'var(--text-primary, #0f172a)',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>',
            ],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 xl:gap-4">
        @foreach($kpiTiles as $kpi)
        <div class="rounded-md px-4 py-3 flex items-center gap-3" style="background:var(--surface); border:1px solid var(--border);">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background:{{ $kpi['bg'] }};color:{{ $kpi['fg'] }};">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    {!! $kpi['icon'] !!}
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color:{{ $kpi['value_color'] }};">{{ $kpi['value'] }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Compliance warnings --}}
    @if(!empty($warnings))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
        </svg>
        <div class="flex-1">
            <strong class="block mb-1">Compliance warnings</strong>
            <ul class="space-y-1">
                @foreach($warnings as $w)
                    <li>{{ $w }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @else
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
        <div class="flex-1">No compliance warnings. All leave balances are within the acceptable range.</div>
    </div>
    @endif

</div>
@endsection
