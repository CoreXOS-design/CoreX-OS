{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <nav class="text-xs" style="color: var(--text-muted);">
        <a href="{{ route('compliance.fica.index') }}" style="color: var(--brand-icon);">Compliance</a>
        <span class="mx-1">/</span>
        <span>Staff Screening</span>
    </nav>

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Staff Screening Dashboard</h1>
                <p class="text-sm text-white/60">Track screening status, flagged concerns, and upcoming renewals across the team.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('compliance.screenings.index') }}" class="corex-btn-outline corex-btn-on-brand text-sm">All Screenings</a>
                <a href="{{ route('compliance.screenings.create') }}" class="corex-btn-primary inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Screening
                </a>
            </div>
        </div>
    </div>

    {{-- Metric tiles --}}
    @php
        $metrics = [
            ['label' => 'Active Staff', 'value' => $totalStaff, 'tone' => 'default'],
            ['label' => 'Clear', 'value' => $clearCount, 'tone' => 'success'],
            ['label' => 'Flagged', 'value' => $flaggedCount, 'tone' => 'warning'],
            ['label' => 'Overdue', 'value' => $overdueCount, 'tone' => 'warning'],
            ['label' => 'Pending', 'value' => $pendingCount, 'tone' => 'warning'],
            ['label' => 'Never Screened', 'value' => $neverCount, 'tone' => 'warning'],
        ];
        $toneColor = [
            'default' => 'var(--text-primary)',
            'success' => 'var(--ds-green)',
            'warning' => 'var(--ds-amber)',
            'danger'  => 'var(--ds-crimson)',
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach($metrics as $m)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: {{ $toneColor[$m['tone']] }};">{{ number_format($m['value']) }}</div>
            <div class="text-xs font-semibold mt-1 uppercase tracking-wider" style="color: var(--text-muted);">{{ $m['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- Filter bar --}}
    <form method="GET" class="rounded-md p-3 flex flex-wrap items-center gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search staff..."
               class="flex-1 min-w-[200px] rounded-md px-3 py-2 text-sm"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        <select name="risk_tier" onchange="this.form.submit()"
                class="rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <option value="">All tiers</option>
            <option value="high" {{ request('risk_tier') === 'high' ? 'selected' : '' }}>High</option>
            <option value="medium" {{ request('risk_tier') === 'medium' ? 'selected' : '' }}>Medium</option>
            <option value="low" {{ request('risk_tier') === 'low' ? 'selected' : '' }}>Low</option>
        </select>
        <select name="status" onchange="this.form.submit()"
                class="rounded-md px-3 py-2 text-sm"
                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            <option value="">All statuses</option>
            <option value="clear" {{ request('status') === 'clear' ? 'selected' : '' }}>Clear</option>
            <option value="concerns_flagged" {{ request('status') === 'concerns_flagged' ? 'selected' : '' }}>Flagged</option>
            <option value="overdue" {{ request('status') === 'overdue' ? 'selected' : '' }}>Overdue</option>
            <option value="never_screened" {{ request('status') === 'never_screened' ? 'selected' : '' }}>Never Screened</option>
        </select>
        <button type="submit" class="corex-btn-outline">Filter</button>
        @if(request('search') || request('risk_tier') || request('status'))
        <a href="{{ route('compliance.screening.dashboard.index') }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Clear</a>
        @endif
    </form>

    {{-- Result count --}}
    <div class="flex items-center">
        <span class="text-xs" style="color: var(--text-muted);">Showing {{ number_format(count($staffData)) }} of {{ number_format($totalStaff) }} staff</span>
    </div>

    {{-- Staff table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Staff Member</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Role</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Risk Tier</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Next Due</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($staffData as $s)
                    @php
                        $st = $s['status'];
                        $statusBadge = match($st) {
                            'clear' => 'ds-badge-success',
                            'concerns_flagged' => 'ds-badge-warning',
                            'overdue', 'expired', 'never_screened' => 'ds-badge-warning',
                            'pre_employment_pending' => 'ds-badge-warning',
                            default => 'ds-badge-default',
                        };
                        $riskBadge = match($s['risk_tier']) {
                            'high' => 'ds-badge-warning',
                            'medium' => 'ds-badge-info',
                            default => 'ds-badge-success',
                        };
                        $dueIsPast = $s['screening_due'] && \Carbon\Carbon::parse($s['screening_due'])->isPast();
                    @endphp
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                        <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $s['user']->name }}</td>
                        <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $s['user']->role }}</td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $riskBadge }}">{{ ucfirst($s['risk_tier']) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="ds-badge {{ $statusBadge }}">{{ str_replace('_', ' ', ucfirst($st)) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color: {{ $dueIsPast ? 'var(--ds-amber)' : 'var(--text-secondary)' }};">
                            {{ $s['screening_due'] ? \Carbon\Carbon::parse($s['screening_due'])->format('d M Y') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($s['latest'])
                            <a href="{{ route('compliance.screenings.show', $s['latest']) }}" class="text-xs font-semibold mr-2" style="color: var(--brand-icon);">View</a>
                            @endif
                            @if(in_array($st, ['never_screened', 'overdue', 'expired']))
                            <a href="{{ route('compliance.screenings.create', $s['user']) }}" class="corex-btn-primary px-2.5 py-1 text-[0.6875rem]">Screen</a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No staff found.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
