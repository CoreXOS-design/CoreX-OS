{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Payroll Employees</h1>
                <p class="text-sm text-white/60">Manage employee payroll profiles, earnings, deductions and banking.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.employees.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Employee
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

    {{-- Status filter tiles --}}
    @php
        $tileMeta = [
            'active'     => ['label' => 'Active',     'bg' => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',  'fg' => 'var(--ds-green)'],
            'inactive'   => ['label' => 'Inactive',   'bg' => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',  'fg' => 'var(--ds-amber)'],
            'terminated' => ['label' => 'Terminated', 'bg' => 'color-mix(in srgb, var(--ds-crimson) 12%, transparent)','fg' => 'var(--ds-crimson)'],
            'all'        => ['label' => 'All',         'bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)','fg' => 'var(--brand-icon)'],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 xl:gap-4">
        @foreach($tileMeta as $key => $meta)
        @php $isActive = $status === $key; @endphp
        <a href="{{ route('payroll.employees.index', ['status' => $key, 'q' => $q]) }}"
           class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-300 no-underline cursor-pointer hover:opacity-80"
           style="background: var(--surface); border: {{ $isActive ? '2px' : '1px' }} solid {{ $isActive ? $meta['fg'] : 'var(--border)' }};">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background: {{ $meta['bg'] }}; color: {{ $meta['fg'] }};">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1h5M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>
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
        <form method="GET" action="{{ route('payroll.employees.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search name or email…"
                       class="list-header-filter w-full" style="padding-left: 2.25rem;">
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Search</button>
            @if($q)
                <a href="{{ route('payroll.employees.index', ['status' => $status]) }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($employees->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No payroll employees {{ $q ? 'match your search' : 'yet' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ $q ? 'Try a different search term, or clear your filters.' : 'Add someone from your user list to start running payroll.' }}</p>
        @if($q)
            <a href="{{ route('payroll.employees.index', ['status' => $status]) }}" class="corex-btn-outline text-sm">Clear filters</a>
        @else
            <a href="{{ route('payroll.employees.create') }}" class="corex-btn-primary text-sm">Add Employee</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col>{{-- Employee --}}
                    <col style="width:140px;">{{-- Branch --}}
                    <col style="width:140px;">{{-- Basic Salary --}}
                    <col style="width:120px;">{{-- Employed --}}
                    <col style="width:110px;">{{-- Status --}}
                    <col style="width:180px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employee</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Basic Salary</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employed</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $emp)
                    <tr style="{{ !$emp->is_active ? 'opacity:0.55;' : '' }}">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background: var(--brand-icon, #0ea5e9);">
                                    {{ strtoupper(substr($emp->user->name ?? '?', 0, 1)) }}
                                </div>
                                <div class="min-w-0">
                                    <a href="{{ route('payroll.employees.show', $emp) }}" class="font-semibold" style="color: var(--text-primary);">{{ $emp->user->name ?? 'Unknown' }}</a>
                                    @if($emp->designation_snapshot)
                                        <p class="text-xs" style="color: var(--text-muted);">{{ $emp->designation_snapshot }}</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $emp->user->branch->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right text-xs font-mono" style="color: var(--text-primary);">
                            @if($emp->basic_salary !== null)
                                R {{ number_format($emp->basic_salary, 2) }}
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $emp->employment_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($emp->termination_date)
                                <span class="ds-badge ds-badge-danger">Terminated</span>
                            @elseif($emp->is_active)
                                <span class="ds-badge ds-badge-success">Active</span>
                            @else
                                <span class="ds-badge ds-badge-default">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('payroll.employees.show', $emp) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                                <a href="{{ route('payroll.employees.edit', $emp) }}" class="text-xs font-semibold" style="color: var(--text-secondary);">Edit</a>
                                @if($emp->is_active && !$emp->termination_date)
                                    <form method="POST" action="{{ route('payroll.employees.deactivate', $emp) }}" class="inline"
                                          onsubmit="return confirm('Deactivate this employee? They will be skipped in future runs.')">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-amber); background: none; border: none; cursor: pointer;">Deactivate</button>
                                    </form>
                                @elseif(!$emp->termination_date)
                                    <form method="POST" action="{{ route('payroll.employees.reactivate', $emp) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--brand-icon); background: none; border: none; cursor: pointer;">Reactivate</button>
                                    </form>
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
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($employees->firstItem()) }}–{{ number_format($employees->lastItem()) }} of {{ number_format($employees->total()) }} results</p>
            @if($employees->hasPages())
                <div>{{ $employees->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
