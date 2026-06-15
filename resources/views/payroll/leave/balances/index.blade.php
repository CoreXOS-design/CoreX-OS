{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Leave Balances</h1>
                <p class="text-sm text-white/60">Track annual, sick and family responsibility leave per employee.</p>
            </div>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('payroll.leave.balances.index') }}" class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search employee…"
                       class="list-header-filter w-full" style="padding-left: 2.25rem;">
            </div>
            <select name="branch" class="list-header-filter">
                <option value="">All Branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ ($branchFilter ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="corex-btn-primary text-sm">Filter</button>
            @if($q || $branchFilter)
                <a href="{{ route('payroll.leave.balances.index') }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
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
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active payroll employees {{ ($q || $branchFilter) ? 'match your filters' : 'found' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ ($q || $branchFilter) ? 'Try a different search term, or clear your filters.' : 'Leave balances appear here once payroll employees are added.' }}</p>
        @if($q || $branchFilter)
            <a href="{{ route('payroll.leave.balances.index') }}" class="corex-btn-outline text-sm">Clear filters</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col>{{-- Employee --}}
                    <col style="width:160px;">{{-- Branch --}}
                    <col style="width:110px;">{{-- Annual --}}
                    <col style="width:110px;">{{-- Sick --}}
                    <col style="width:110px;">{{-- FRL --}}
                    <col style="width:100px;">{{-- Take-On --}}
                    <col style="width:80px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Employee</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Annual</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Sick</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">FRL</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Take-On</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($employees as $emp)
                    @php $bal = $balances[$emp->id] ?? []; @endphp
                    <tr>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $emp->user->name ?? 'Unknown' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $emp->user->branch->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-center text-xs">
                            @if(isset($bal['annual']))
                                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((float)$bal['annual']['available_days'], 1) }}</span>
                                <span style="color: var(--text-muted);">/ {{ number_format((float)$bal['annual']['entitlement_days'], 0) }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs">
                            @if(isset($bal['sick']))
                                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((float)$bal['sick']['available_days'], 1) }}</span>
                                <span style="color: var(--text-muted);">/ {{ number_format((float)$bal['sick']['entitlement_days'], 0) }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs">
                            @if(isset($bal['frl']))
                                <span class="font-semibold" style="color: var(--text-primary);">{{ number_format((float)$bal['frl']['available_days'], 1) }}</span>
                                <span style="color: var(--text-muted);">/ {{ number_format((float)$bal['frl']['entitlement_days'], 0) }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @php $to = \App\Models\Leave\StaffTakeOnRecord::where('user_id', $emp->user_id)->first(); @endphp
                            @if($to?->isComplete())
                                <span class="ds-badge ds-badge-success">Done</span>
                            @elseif($to)
                                <span class="ds-badge ds-badge-warning">{{ number_format($to->progressPercentage()) }}%</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('payroll.leave.balances.show', $emp) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination (inside card border) --}}
        <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($employees->firstItem()) }}–{{ number_format($employees->lastItem()) }} of {{ number_format($employees->total()) }} employees</p>
            @if($employees->hasPages())
                <div>{{ $employees->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
