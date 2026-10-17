{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('agent.portal') }}" class="text-xs no-underline" style="color: var(--text-muted);">&larr; My Portal</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">My Leave</h1>
                <p class="text-xs" style="color: var(--text-muted);">Your leave balances and applications.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                @if($employee)
                    <a href="{{ route('my-portal.leave.apply') }}" class="corex-btn-primary text-xs inline-flex items-center gap-1.5" data-tour="portal-leave-apply">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        Apply for Leave
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div>
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--brand-icon);">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); border-radius:6px; color:var(--ds-crimson);">{{ session('error') }}</div>
        @endif

        @if(!$employee)
            <div class="rounded-md py-12 px-6 text-center text-sm" style="background: var(--surface); border: 1px solid var(--border); color:var(--text-muted);">You are not on the payroll roster. Contact your administrator.</div>
        @else
            {{-- Balance cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6" data-tour="portal-leave-balances">
                @foreach($balances as $bal)
                    <div class="p-3" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                        <p class="text-[10px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">{{ $bal['leave_type']->label }}</p>
                        <p class="text-lg font-bold" style="color:var(--brand-icon);">{{ number_format((float)$bal['available_days'], 1) }}</p>
                        <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">of {{ number_format((float)$bal['entitlement_days'], 0) }} per cycle</p>
                        @if($bal['cycle_end_date'])
                            <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">Cycle ends {{ $bal['cycle_end_date']->format('d M Y') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Application history --}}
            <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;" data-tour="portal-leave-applications">My Applications</h4>

            <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);" data-tour="portal-leave-status-tabs">
                @foreach(['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','cancelled'=>'Cancelled'] as $key => $label)
                    <a href="{{ route('my-portal.leave.index', ['status' => $key]) }}" class="px-3 py-1.5 text-xs font-semibold transition" style="{{ ($status ?? 'all') === $key ? 'border-bottom:2px solid var(--brand-icon); color:var(--brand-icon);' : 'color:var(--text-secondary, #6b7280);' }}">
                        {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] ?? 0 }}</span>
                    </a>
                @endforeach
            </div>

            @if($applications->isEmpty())
                <div class="rounded-md py-8 px-6 text-center text-xs" style="background: var(--surface); border: 1px solid var(--border); color:var(--text-muted);">No leave applications yet. Click + Apply for Leave to get started.</div>
            @else
                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">App #</th>
                            <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                            <th class="text-left px-3 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Period</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Days</th>
                            <th class="text-center px-2 py-2 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($applications as $app)
                        <tr>
                            <td class="px-3 py-2.5 text-xs" style="font-family:monospace; color:var(--text-secondary, #94a3b8);">{{ $app->application_number }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $app->leaveType->label ?? '-' }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $app->start_date?->format('d M') }} — {{ $app->end_date?->format('d M Y') }}</td>
                            <td class="px-2 py-2.5 text-center text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ number_format($app->working_days_requested, 1) }}</td>
                            <td class="px-2 py-2.5 text-center">
                                @php
                                    $badgeClassMap = [
                                        'submitted' => 'ds-badge-warning',
                                        'approved' => 'ds-badge-success',
                                        'rejected' => 'ds-badge-danger',
                                        'cancelled' => 'ds-badge-default',
                                        'taken' => 'ds-badge-info',
                                    ];
                                    $appBadgeClass = $badgeClassMap[$app->status] ?? 'ds-badge-default';
                                @endphp
                                <span class="ds-badge {{ $appBadgeClass }}">{{ ucfirst($app->status) }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a href="{{ route('my-portal.leave.show', $app) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">View</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($applications->hasPages())
                <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $applications->links() }}</div>
                @endif
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
