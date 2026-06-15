{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $yearOptions = collect($years)->push($year)->unique()->sortDesc()->values();
@endphp
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Public Holidays</h1>
                <p class="text-sm text-white/60">South African public holidays excluded from working-day calculations.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('payroll.leave.public-holidays.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Add Holiday
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

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('payroll.leave.public-holidays.index') }}" class="flex flex-wrap items-center gap-3">
            <label for="year" class="text-xs font-medium" style="color: var(--text-secondary);">Year</label>
            <select id="year" name="year" onchange="this.form.submit()" class="list-header-filter">
                @foreach($yearOptions as $y)
                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
            <span class="text-xs" style="color: var(--text-muted);">{{ number_format($holidays->count()) }} holidays in {{ $year }}</span>
        </form>
    </div>

    {{-- Table / empty state --}}
    @if($holidays->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No public holidays for {{ $year }}</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Add a public holiday for {{ $year }} so it's excluded from working-day calculations.</p>
        <a href="{{ route('payroll.leave.public-holidays.create') }}" class="corex-btn-primary text-sm">Add Holiday</a>
    </div>
    @else
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <colgroup>
                    <col style="width:140px;">{{-- Date --}}
                    <col style="width:130px;">{{-- Day --}}
                    <col>{{-- Name --}}
                    <col style="width:120px;">{{-- Type --}}
                    <col style="width:130px;">{{-- Actions --}}
                </colgroup>
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Day</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($holidays as $h)
                    <tr>
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $h->holiday_date->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $h->holiday_date->format('l') }}</td>
                        <td class="px-4 py-3" style="color: var(--text-primary);">{{ $h->name }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($h->is_movable)
                                <span class="ds-badge ds-badge-warning">Moveable</span>
                            @else
                                <span class="ds-badge ds-badge-default">Fixed</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('payroll.leave.public-holidays.edit', $h) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                                <form method="POST" action="{{ route('payroll.leave.public-holidays.destroy', $h) }}" class="inline"
                                      onsubmit="return confirm('Delete this holiday?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson); background: none; border: none; cursor: pointer;">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
@endsection
