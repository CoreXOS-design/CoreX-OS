{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Types</h1>
                <p class="text-sm text-white/60">Configure agency-specific leave types — BCEA-mandated types are system-locked.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.leave.types.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Add Leave Type
                </a>
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

    {{-- Filter tiles --}}
    @php
        $tileMeta = [
            'all'      => ['label' => 'All',      'bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)',  'fg' => 'var(--brand-icon, #0ea5e9)'],
            'active'   => ['label' => 'Active',   'bg' => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',   'fg' => 'var(--ds-green, #059669)'],
            'inactive' => ['label' => 'Inactive', 'bg' => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',   'fg' => 'var(--ds-amber, #f59e0b)'],
            'system'   => ['label' => 'System',   'bg' => 'color-mix(in srgb, var(--ds-navy) 12%, transparent)',    'fg' => 'var(--ds-navy, #0b2a4a)'],
            'custom'   => ['label' => 'Custom',   'bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)', 'fg' => 'var(--brand-icon, #0ea5e9)'],
        ];
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 xl:gap-4">
        @foreach($tileMeta as $key => $meta)
        @php $isActive = $status === $key; @endphp
        <a href="{{ route('payroll.leave.types.index', ['status' => $key, 'q' => $q]) }}"
           class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-300 no-underline cursor-pointer hover:opacity-80"
           style="background: var(--surface); border: {{ $isActive ? '2px' : '1px' }} solid {{ $isActive ? $meta['fg'] : 'var(--border)' }};">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background: {{ $meta['bg'] }}; color: {{ $meta['fg'] }};">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color: var(--text-primary);">{{ number_format($counts[$key]) }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color: var(--text-muted);">{{ $meta['label'] }}</div>
            </div>
        </a>
        @endforeach
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('payroll.leave.types.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search code or label…"
                       class="list-header-filter w-full" style="padding-left: 2.25rem;">
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Search</button>
            @if($q)
                <a href="{{ route('payroll.leave.types.index', ['status' => $status]) }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($types->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No leave types {{ $q ? 'match your search' : 'yet' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ $q ? 'Try a different search term, or clear your filters.' : 'Add your first leave type to start tracking entitlements.' }}</p>
        @if($q)
            <a href="{{ route('payroll.leave.types.index', ['status' => $status]) }}" class="corex-btn-outline text-sm">Clear filters</a>
        @else
            <a href="{{ route('payroll.leave.types.create') }}" class="corex-btn-primary text-sm">Add Leave Type</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col style="width:110px;">{{-- Code --}}
                    <col>{{-- Label --}}
                    <col style="width:100px;">{{-- Category --}}
                    <col style="width:110px;">{{-- Entitlement --}}
                    <col style="width:70px;">{{-- Cycle --}}
                    <col style="width:140px;">{{-- Accrual --}}
                    <col style="width:90px;">{{-- Pre-approval --}}
                    <col style="width:90px;">{{-- Status --}}
                    <col style="width:130px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Code</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Label</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Category</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Entitlement</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Cycle</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Accrual</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);" title="Requires pre-approval before booking">Pre-Approval</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                    <tr style="{{ !$type->is_active ? 'opacity:0.55;' : '' }}">
                        <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $type->code }}</td>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">
                            {{ $type->label }}
                            @if($type->is_system)
                                <span class="ds-badge ds-badge-info ml-1.5">System</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ ucfirst(str_replace('_', ' ', $type->category)) }}</td>
                        <td class="px-4 py-3 text-center text-xs" style="color: var(--text-primary);">
                            @if($type->entitlement_days_per_cycle > 0)
                                {{ number_format($type->entitlement_days_per_cycle, 0) }}d / {{ number_format($type->entitlement_days_per_cycle_six_day, 0) }}d
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs" style="color: var(--text-secondary);">
                            {{ $type->cycle_months > 0 ? $type->cycle_months . 'mo' : 'Per child' }}
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                            @switch($type->accrual_method)
                                @case('full_at_start') Full at start @break
                                @case('accrual_per_day_worked') 1 per {{ $type->accrual_rate_per_days }} worked @break
                                @case('accrual_first_six_months') 1 per {{ $type->accrual_rate_per_days }} first 6mo @break
                                @default None
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($type->requires_pre_approval)
                                <span class="inline-block w-2 h-2 rounded-full" style="background: var(--brand-icon, #0ea5e9);" title="Requires pre-approval"></span>
                            @else
                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($type->is_active)
                                <span class="ds-badge ds-badge-success">Active</span>
                            @else
                                <span class="ds-badge ds-badge-default">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('payroll.leave.types.edit', $type) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                @if(!$type->is_system)
                                    <form method="POST" action="{{ route('payroll.leave.types.destroy', $type) }}" class="inline"
                                          onsubmit="return confirm('Delete this leave type?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson); background: none; border: none; cursor: pointer;">Delete</button>
                                    </form>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted); cursor: not-allowed;" title="System types cannot be deleted">Delete</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination (inside card border) --}}
        <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($types->firstItem()) }}–{{ number_format($types->lastItem()) }} of {{ number_format($types->total()) }} results</p>
            @if($types->hasPages())
                <div>{{ $types->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
