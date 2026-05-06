@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Leave Dashboard" :flush="true" />

    <div class="p-4 lg:p-6">
        {{-- Stats cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
            <div class="p-4 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Active Employees</p>
                <p class="text-2xl font-bold" style="color:var(--text-primary, #0f172a);">{{ $activeEmployees }}</p>
            </div>
            <div class="p-4 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Approved This Month</p>
                <p class="text-2xl font-bold" style="color:var(--brand-icon);">{{ $approvedThisMonth }}</p>
            </div>
            <div class="p-4 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Pending Applications</p>
                <p class="text-2xl font-bold" style="color:{{ $pendingApplications > 0 ? '#eab308' : 'var(--text-primary, #0f172a)' }};">{{ $pendingApplications }}</p>
            </div>
            <div class="p-4 text-center" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">Days Taken (YTD)</p>
                <p class="text-2xl font-bold" style="color:var(--text-primary, #0f172a);">{{ number_format($daysTakenThisYear, 1) }}</p>
            </div>
        </div>

        {{-- Quick links --}}
        <div class="flex flex-wrap gap-3 mb-6">
            <a href="{{ route('payroll.leave.balances.index') }}" class="px-4 py-2 text-xs font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;">View Balances</a>
            <a href="#" class="px-4 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;" title="Coming in Prompt J">View Applications</a>
            <a href="#" class="px-4 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;" title="Coming in Prompt N">View Reports</a>
        </div>

        {{-- Compliance warnings --}}
        @if(!empty($warnings))
        <div class="p-4" style="background:rgba(234,179,8,0.04); border:1px solid rgba(234,179,8,0.15); border-radius:6px;">
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--ds-amber); letter-spacing:0.05em;">Compliance Warnings</h4>
            <ul class="space-y-1.5">
                @foreach($warnings as $w)
                    <li class="text-xs" style="color:var(--text-primary, #0f172a);">{{ $w }}</li>
                @endforeach
            </ul>
        </div>
        @else
        <div class="p-4" style="background:rgba(0,212,170,0.04); border:1px solid color-mix(in srgb, var(--brand-icon) 15%, transparent); border-radius:6px;">
            <p class="text-xs font-semibold" style="color:var(--brand-icon);">No compliance warnings. All leave balances within acceptable range.</p>
        </div>
        @endif
    </div>
</div>
@endsection
