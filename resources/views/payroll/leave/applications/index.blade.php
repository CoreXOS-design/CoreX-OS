{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Applications</h1>
                <p class="text-sm text-white/60">Review, approve and reject staff leave requests.</p>
            </div>
        </div>
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
    </div>
    @endif
    @if(session('error'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
        <div class="flex-1">{{ session('error') }}</div>
    </div>
    @endif

    {{-- Status filter tabs --}}
    <div class="flex flex-wrap gap-1" style="border-bottom: 1px solid var(--border);">
        @foreach(['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'this_month' => 'This Month'] as $key => $label)
            @php $isActiveTab = $status === $key; @endphp
            <a href="{{ route('payroll.leave.applications.index', ['status' => $key, 'q' => $q, 'type' => $typeFilter]) }}"
               class="px-3 py-2 text-xs font-semibold transition-all duration-300 inline-flex items-center gap-1.5"
               style="{{ $isActiveTab ? 'border-bottom: 2px solid var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);' : 'border-bottom: 2px solid transparent; color: var(--text-secondary);' }}">
                {{ $label }}
                <span class="text-[0.6875rem] px-1.5 py-0.5 rounded-full"
                      style="background: {{ $isActiveTab ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent)' : 'var(--surface-2)' }}; color: {{ $isActiveTab ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-muted)' }};">{{ number_format($counts[$key]) }}</span>
            </a>
        @endforeach
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('payroll.leave.applications.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search name or app #…"
                       class="list-header-filter w-full" style="padding-left: 2.25rem;">
            </div>
            <select name="type" class="list-header-filter">
                <option value="">All Types</option>
                @foreach($leaveTypes as $lt)
                    <option value="{{ $lt->id }}" {{ ($typeFilter ?? '') == $lt->id ? 'selected' : '' }}>{{ $lt->label }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary text-sm">Filter</button>
            @if($q || $typeFilter)
                <a href="{{ route('payroll.leave.applications.index', ['status' => $status]) }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($applications->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No leave applications {{ ($q || $typeFilter) ? 'match your filters' : ($status !== 'all' ? 'with this status' : 'yet') }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ ($q || $typeFilter) ? 'Try a different search term, or clear your filters.' : 'Staff leave requests will appear here once submitted.' }}</p>
        @if($q || $typeFilter)
            <a href="{{ route('payroll.leave.applications.index', ['status' => $status]) }}" class="corex-btn-outline text-sm">Clear filters</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col style="width:120px;">{{-- App # --}}
                    <col>{{-- Employee --}}
                    <col style="width:120px;">{{-- Type --}}
                    <col style="width:160px;">{{-- Period --}}
                    <col style="width:70px;">{{-- Days --}}
                    <col style="width:110px;">{{-- Status --}}
                    <col style="width:120px;">{{-- Submitted --}}
                    <col style="width:80px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">App #</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employee</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Period</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Days</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Submitted</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
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
                            'draft'     => 'ds-badge-default',
                            'no_show'   => 'ds-badge-danger',
                        ];
                    @endphp
                    @foreach($applications as $app)
                    <tr>
                        <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $app->application_number }}</td>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $app->user->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $app->leaveType->label ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-primary);">{{ $app->start_date?->format('d M') }} – {{ $app->end_date?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-center text-xs font-semibold" style="color: var(--text-primary);">{{ number_format($app->working_days_requested, 1) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="ds-badge {{ $badgeVariant[$app->status] ?? 'ds-badge-default' }}">{{ ucfirst(str_replace('_', ' ', $app->status)) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $app->submitted_at?->format('d M H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('payroll.leave.applications.show', $app) }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination (inside card border) --}}
        <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($applications->firstItem()) }}–{{ number_format($applications->lastItem()) }} of {{ number_format($applications->total()) }} results</p>
            @if($applications->hasPages())
                <div>{{ $applications->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
