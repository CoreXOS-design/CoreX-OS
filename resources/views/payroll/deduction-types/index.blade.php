{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Payroll Deduction Types</h1>
                <p class="text-sm text-white/60">Configure agency-specific deduction categories. Statutory deductions (PAYE, UIF) are auto-calculated by the payroll engine.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.deduction-types.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Deduction Type
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
            'all'      => ['label' => 'All Types',  'bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)', 'fg' => 'var(--brand-icon)'],
            'active'   => ['label' => 'Active',     'bg' => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',   'fg' => 'var(--ds-green)'],
            'inactive' => ['label' => 'Inactive',   'bg' => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',   'fg' => 'var(--ds-amber)'],
        ];
    @endphp
    <div class="grid grid-cols-3 gap-3 xl:gap-4">
        @foreach($tileMeta as $key => $meta)
        @php $isActive = $status === $key; @endphp
        <a href="{{ route('payroll.deduction-types.index', ['status' => $key, 'q' => $q]) }}"
           class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-300 no-underline cursor-pointer hover:opacity-80"
           style="background: var(--surface); border: {{ $isActive ? '2px' : '1px' }} solid {{ $isActive ? $meta['fg'] : 'var(--border)' }};">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background: {{ $meta['bg'] }}; color: {{ $meta['fg'] }};">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg>
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
        <form method="GET" action="{{ route('payroll.deduction-types.index') }}" class="flex flex-wrap items-center gap-3">
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
                <a href="{{ route('payroll.deduction-types.index', ['status' => $status]) }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($types->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m-6 4h6m-6 4h4M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No deduction types {{ $q ? 'match your search' : 'yet' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ $q ? 'Try a different search term, or clear your filters.' : 'Add your first deduction type to configure agency payroll categories.' }}</p>
        @if($q)
            <a href="{{ route('payroll.deduction-types.index') }}" class="corex-btn-outline text-sm">Clear filters</a>
        @else
            <a href="{{ route('payroll.deduction-types.create') }}" class="corex-btn-primary text-sm">Add Deduction Type</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col style="width:120px;">{{-- Code --}}
                    <col>{{-- Label --}}
                    <col style="width:90px;">{{-- SARS --}}
                    <col style="width:110px;">{{-- Statutory --}}
                    <col style="width:70px;">{{-- Sort --}}
                    <col style="width:100px;">{{-- Status --}}
                    <col style="width:140px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Code</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Label</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">SARS</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Statutory</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Sort</th>
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
                                <span class="ds-badge ds-badge-default ml-1.5">System</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-secondary);">{{ $type->sars_source_code ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($type->is_statutory)
                                <span class="ds-badge ds-badge-warning">Statutory</span>
                            @else
                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs" style="color: var(--text-secondary);">{{ number_format($type->sort_order) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($type->is_active)
                                <span class="ds-badge ds-badge-success">Active</span>
                            @else
                                <span class="ds-badge ds-badge-default">Inactive</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('payroll.deduction-types.edit', $type) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                @if(!$type->is_system && !$type->is_statutory)
                                    <form method="POST" action="{{ route('payroll.deduction-types.destroy', $type) }}" class="inline"
                                          onsubmit="return confirm('Delete this deduction type? It will be archived and can be recovered by an admin.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson); background: none; border: none; cursor: pointer;">Delete</button>
                                    </form>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted); cursor: not-allowed;" title="{{ $type->is_statutory ? 'Statutory types cannot be deleted' : 'System types cannot be deleted' }}">Delete</span>
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
