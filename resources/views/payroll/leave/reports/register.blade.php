@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
@php
    $hasFilters = $status || $typeFilter || $branchFilter || ($q ?? false);
@endphp
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Register</h1>
                <p class="text-sm text-white/60">All leave applications across the agency for the selected period.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.leave.reports.register.export', ['format' => 'xlsx', 'from' => $dateFrom, 'to' => $dateTo, 'status' => $status, 'type' => $typeFilter, 'branch' => $branchFilter]) }}"
                   class="corex-btn-outline corex-btn-on-brand text-sm inline-flex items-center gap-1.5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Export CSV
                </a>
            </div>
        </div>
    </div>

    {{-- Report navigation tabs --}}
    <div class="flex gap-1" style="border-bottom:1px solid var(--border);">
        <a href="{{ route('payroll.leave.reports.register') }}" class="px-3 py-1.5 text-xs font-semibold" style="border-bottom:2px solid var(--brand-icon, #0ea5e9); color:var(--brand-icon, #0ea5e9);">Register</a>
        <a href="{{ route('payroll.leave.reports.branch-summary') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary);">Branch Summary</a>
        <a href="{{ route('payroll.leave.reports.audit-log') }}" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary);">Audit Log</a>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('payroll.leave.reports.register') }}"
          class="rounded-md p-4 flex flex-wrap items-end gap-3"
          style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">From</label>
            <input type="date" name="from" value="{{ $dateFrom }}" class="list-header-filter">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">To</label>
            <input type="date" name="to" value="{{ $dateTo }}" class="list-header-filter">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Status</label>
            <select name="status" class="list-header-filter">
                <option value="">All</option>
                @foreach(['submitted','approved','rejected','cancelled','taken'] as $s)
                    <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Type</label>
            <select name="type" class="list-header-filter">
                <option value="">All</option>
                @foreach($leaveTypes as $lt)
                    <option value="{{ $lt->id }}" {{ ($typeFilter ?? '') == $lt->id ? 'selected' : '' }}>{{ $lt->label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Search</label>
            <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Employee name..." class="list-header-filter w-40">
        </div>
        <button type="submit" class="corex-btn-primary text-sm">Apply</button>
        @if($hasFilters)
            <a href="{{ route('payroll.leave.reports.register') }}" class="text-xs font-medium" style="color:var(--text-muted);">Reset</a>
        @endif
        <div class="ml-auto text-xs" style="color:var(--text-muted);">
            Showing {{ number_format($applications->count()) }} of {{ number_format($applications->total()) }}
        </div>
    </form>

    {{-- Applications table / empty state --}}
    @if($applications->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No applications found</h3>
            <p class="text-sm" style="color:var(--text-muted);">No leave applications match this period or filters. Try widening the date range or clearing filters.</p>
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">App #</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Employee</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Period</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Days</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Decided By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $badgeVariant = [
                                'submitted' => 'ds-badge-warning',
                                'approved'  => 'ds-badge-success',
                                'rejected'  => 'ds-badge-danger',
                                'cancelled' => 'ds-badge-default',
                                'taken'     => 'ds-badge-info',
                            ];
                        @endphp
                        @foreach($applications as $app)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3 text-xs font-mono" style="color:var(--text-secondary);">{{ $app->application_number }}</td>
                            <td class="px-4 py-3 text-xs font-semibold" style="color:var(--text-primary);">{{ $app->user->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $app->leaveType->label ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-primary);">{{ $app->start_date?->format('d M') }} – {{ $app->end_date?->format('d M') }}</td>
                            <td class="px-4 py-3 text-center text-xs font-semibold" style="color:var(--text-primary);">{{ number_format($app->working_days_requested, 1) }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="ds-badge {{ $badgeVariant[$app->status] ?? 'ds-badge-default' }}">{{ ucfirst($app->status) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $app->decidedBy->name ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($applications->hasPages())
                <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $applications->links() }}</div>
            @endif
        </div>
    @endif
</div>
@endsection
