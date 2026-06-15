{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Payroll Runs</h1>
                <p class="text-sm text-white/60">Create, review and finalise monthly payroll runs and payslips.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.runs.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New Run
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
            'all'       => ['label' => 'All',       'bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)', 'fg' => 'var(--brand-icon)'],
            'draft'     => ['label' => 'Draft',     'bg' => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',  'fg' => 'var(--ds-amber)'],
            'finalised' => ['label' => 'Finalised', 'bg' => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',  'fg' => 'var(--ds-green)'],
            'cancelled' => ['label' => 'Cancelled', 'bg' => 'var(--surface-2)',                                       'fg' => 'var(--text-muted)'],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 xl:gap-4">
        @foreach($tileMeta as $key => $meta)
        @php $isActive = $status === $key; @endphp
        <a href="{{ route('payroll.runs.index', ['status' => $key]) }}"
           class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-300 no-underline cursor-pointer hover:opacity-80"
           style="background: var(--surface); border: {{ $isActive ? '2px' : '1px' }} solid {{ $isActive ? $meta['fg'] : 'var(--border)' }};">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background: {{ $meta['bg'] }}; color: {{ $meta['fg'] }};">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25"/></svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color: var(--text-primary);">{{ number_format($counts[$key]) }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color: var(--text-muted);">{{ $meta['label'] }}</div>
            </div>
        </a>
        @endforeach
    </div>

    {{-- Table / empty state --}}
    @if($runs->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No payroll runs {{ $status !== 'all' ? 'with this status' : 'yet' }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">{{ $status !== 'all' ? 'Try a different status filter, or create a new run.' : 'Create your first payroll run to generate payslips for your employees.' }}</p>
        @if($status !== 'all')
            <a href="{{ route('payroll.runs.index') }}" class="corex-btn-outline text-sm">Show all runs</a>
        @else
            <a href="{{ route('payroll.runs.create') }}" class="corex-btn-primary text-sm">New Run</a>
        @endif
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col style="width:120px;">{{-- Run # --}}
                    <col style="width:110px;">{{-- Period --}}
                    <col style="width:120px;">{{-- Pay date --}}
                    <col style="width:110px;">{{-- Status --}}
                    <col style="width:70px;">{{-- Slips --}}
                    <col style="width:140px;">{{-- Total Net --}}
                    <col style="width:160px;">{{-- Finalised --}}
                    <col style="width:90px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Run #</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Period</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pay Date</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Slips</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Total Net</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Finalised</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($runs as $run)
                    <tr style="{{ $run->isCancelled() ? 'opacity:0.55;' : '' }}">
                        <td class="px-4 py-3 text-xs font-semibold font-mono" style="color: var(--text-primary);">{{ $run->run_number }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-primary);">{{ $run->period_month?->format('M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $run->pay_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($run->isDraft())
                                <span class="ds-badge ds-badge-warning">Draft</span>
                            @elseif($run->isFinalised())
                                <span class="ds-badge ds-badge-success">Finalised</span>
                            @else
                                <span class="ds-badge ds-badge-default">Cancelled</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs" style="color: var(--text-secondary);">{{ number_format($run->payslip_count ?? 0) }}</td>
                        <td class="px-4 py-3 text-right text-xs font-mono" style="color: var(--text-primary);">R {{ number_format($run->total_net ?? 0, 2) }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                            @if($run->finalised_at)
                                {{ $run->finalisedBy->name ?? '—' }}<br>
                                <span style="color: var(--text-muted);">{{ $run->finalised_at->format('d M H:i') }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('payroll.runs.show', $run) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination (inside card border) --}}
        <div class="px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2" style="border-top: 1px solid var(--border);">
            <p class="text-xs" style="color: var(--text-muted);">Showing {{ number_format($runs->firstItem()) }}–{{ number_format($runs->lastItem()) }} of {{ number_format($runs->total()) }} results</p>
            @if($runs->hasPages())
                <div>{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
