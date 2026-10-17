{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Leave Types list — rendered inside the Settings hub (corex/settings?s=leave-types)
     since AT-455 (Andre, 2026-10-14). Expects $types (paginator), $status, $q, $counts
     from LeaveTypeController::listData(). Every filter / search / page link stays on
     the Settings section. --}}
@php
    $listUrl = fn (array $params = []) => route('corex.settings', array_merge(['s' => 'leave-types'], $params));
    $tileMeta = [
        'all'      => 'All',
        'active'   => 'Active',
        'inactive' => 'Inactive',
        'system'   => 'System',
        'custom'   => 'Custom',
    ];
@endphp

<div class="space-y-4">

    {{-- Count tiles — one per filter --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        @foreach($tileMeta as $key => $label)
        @php $isActive = $status === $key; @endphp
        <a href="{{ $listUrl(['status' => $key, 'q' => $q]) }}"
           class="rounded-md px-4 py-3 no-underline transition-colors"
           style="background: var(--surface); border: 1px solid {{ $isActive ? 'var(--brand-icon, #0ea5e9)' : 'var(--border)' }}; {{ $isActive ? 'box-shadow: inset 0 -2px 0 var(--brand-icon, #0ea5e9);' : '' }}">
            <div class="text-xl font-semibold leading-none" style="color: var(--text-primary); font-variant-numeric: tabular-nums;">{{ number_format($counts[$key]) }}</div>
            <div class="text-[0.6875rem] font-medium mt-1.5 uppercase tracking-wider" style="color: {{ $isActive ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-muted)' }};">{{ $label }}</div>
        </a>
        @endforeach
    </div>

    {{-- Search --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('corex.settings') }}" class="flex flex-wrap items-center gap-3">
            <input type="hidden" name="s" value="leave-types">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $q ?? '' }}" placeholder="Search code or label…"
                       class="w-full rounded-md py-2 text-sm" style="padding-left: 2.25rem; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Search</button>
            @if($q)
                <a href="{{ $listUrl(['status' => $status]) }}" class="corex-btn-outline text-sm">Clear</a>
            @endif
            <div class="flex-1"></div>
            <a href="{{ route('payroll.leave.types.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Leave Type
            </a>
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($types->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No leave types {{ $q ? 'match your search' : 'yet' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ $q ? 'Try a different search term, or clear your filters.' : 'Add your first leave type to start tracking entitlements.' }}</p>
        @if($q)
            <a href="{{ $listUrl(['status' => $status]) }}" class="corex-btn-outline text-sm">Clear filters</a>
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
                                <span class="ml-1.5 text-xs font-medium" style="color: var(--text-muted);" title="BCEA-mandated — locked">System</span>
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
                        <td class="px-4 py-3 text-center text-xs" style="color: var(--text-primary);">
                            <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background: {{ $type->is_active ? 'var(--ds-green, #059669)' : 'var(--text-muted)' }}; margin-right:5px; vertical-align:middle;"></span>{{ $type->is_active ? 'Active' : 'Inactive' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('payroll.leave.types.edit', $type) }}" class="corex-btn-outline text-xs">Edit</a>
                                @if(!$type->is_system)
                                    <form method="POST" action="{{ route('payroll.leave.types.destroy', $type) }}" class="inline"
                                          onsubmit="return confirm('Delete this leave type?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Delete</button>
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

        <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($types->firstItem()) }}–{{ number_format($types->lastItem()) }} of {{ number_format($types->total()) }} results</p>
            @if($types->hasPages())
                <div>{{ $types->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
