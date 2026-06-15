{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $stepKeys = ['user','personal','tax_banking','employment','compensation','leave','compliance','review'];
@endphp
<div class="w-full space-y-5">

    {{-- Page header (branded — §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Staff Take-On</h1>
                <p class="text-sm text-white/60">Onboard new employees and capture their payroll, leave and compliance details.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('staff-take-on.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Start New Take-On
                </a>
            </div>
        </div>
    </div>

    {{-- Flash messages (§3.9) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('info'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent); color: var(--text-primary);">
            {{ session('info') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif

    {{-- Status filter tabs --}}
    <div class="flex gap-1 flex-wrap" style="border-bottom:1px solid var(--border);">
        @foreach(['all' => 'All', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'this_month' => 'This Month'] as $key => $label)
            <a href="{{ route('staff-take-on.index', ['status' => $key]) }}"
               class="px-3 py-1.5 text-xs font-semibold transition-colors duration-150"
               style="{{ $status === $key
                   ? 'border-bottom:2px solid var(--brand-icon, #0ea5e9); color:var(--brand-icon, #0ea5e9);'
                   : 'color:var(--text-secondary);' }}">
                {{ $label }}
                <span class="ml-1 text-[0.625rem] font-semibold" style="color:var(--text-muted);">{{ number_format($counts[$key]) }}</span>
            </a>
        @endforeach
    </div>

    @if($records->isEmpty())
        {{-- Empty state (§3.10) --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border:1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color:var(--text-primary);">No take-on records yet</h3>
            <p class="text-sm mb-4" style="color:var(--text-muted);">Start a new take-on to onboard an employee and capture their details.</p>
            <a href="{{ route('staff-take-on.create') }}" class="corex-btn-primary inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Start New Take-On
            </a>
        </div>
    @else
        {{-- Records table (§3.7) --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Employee</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Progress</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Started</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($records as $rec)
                            <tr style="border-top:1px solid var(--border);">
                                <td class="px-4 py-3 font-semibold" style="color:var(--text-primary);">{{ $rec->user->name ?? 'Unknown' }}</td>
                                <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ ucfirst(str_replace('_', ' ', $rec->take_on_type)) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="ds-progress-track" style="max-width:6rem;">
                                            <div class="ds-progress-bar ds-bar-navy" style="width:{{ $rec->progressPercentage() }}%;"></div>
                                        </div>
                                        <span class="text-xs font-semibold" style="color:var(--text-secondary);">{{ number_format($rec->progressPercentage()) }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color:var(--text-secondary);">{{ $rec->created_at?->format('d M Y') }}</td>
                                <td class="px-4 py-3">
                                    @if($rec->isComplete())
                                        <span class="ds-badge ds-badge-success">Completed</span>
                                    @else
                                        <span class="ds-badge ds-badge-warning">Step {{ array_search($rec->current_step, $stepKeys) + 1 }} of {{ count($stepKeys) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($rec->isComplete())
                                        <a href="{{ route('staff-take-on.wizard', [$rec, 'review']) }}" class="text-xs font-semibold" style="color:var(--brand-icon, #0ea5e9);">View</a>
                                    @else
                                        <a href="{{ route('staff-take-on.wizard', [$rec, $rec->nextStep() ?? 'review']) }}" class="text-xs font-semibold" style="color:var(--brand-icon, #0ea5e9);">Resume</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($records->hasPages())
                <div class="px-4 py-3" style="border-top:1px solid var(--border);">{{ $records->links() }}</div>
            @endif
        </div>
    @endif

</div>
@endsection
