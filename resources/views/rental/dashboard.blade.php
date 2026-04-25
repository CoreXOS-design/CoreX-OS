@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Rental Division</h1>
                <p class="text-sm text-white/60">Overview of rental workflows, signatures, and lease management.</p>
            </div>
        </div>
    </div>

    {{-- Metric Tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4">
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['needs_approval'] > 0 ? 'var(--ds-amber)' : 'var(--text-muted)' }};">{{ number_format($counts['needs_approval']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Needs Approval</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['draft'] > 0 ? 'var(--text-primary)' : 'var(--text-muted)' }};">{{ number_format($counts['draft']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Draft</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['ready_to_sign'] > 0 ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ number_format($counts['ready_to_sign']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Ready to Sign</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['in_progress'] > 0 ? 'var(--ds-amber)' : 'var(--text-muted)' }};">{{ number_format($counts['in_progress']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Awaiting Signatures</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['completed'] > 0 ? 'var(--ds-green)' : 'var(--text-muted)' }};">{{ number_format($counts['completed']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Completed</div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['active_leases'] > 0 ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ number_format($counts['active_leases']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Active Leases</div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-4 text-center transition cursor-pointer block">
            <div class="text-[1.625rem] font-semibold" style="color: {{ $counts['expiring_soon'] > 0 ? 'var(--ds-amber)' : 'var(--text-muted)' }};">{{ number_format($counts['expiring_soon']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Expiring (90 days)</div>
        </a>
    </div>

    {{-- Quick Actions --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-5 transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-md flex items-center justify-center" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold" style="color: var(--text-primary);">Electronic Signatures</div>
                    <div class="text-xs" style="color: var(--text-muted);">Manage signing workflows</div>
                </div>
            </div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-5 transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-md flex items-center justify-center" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold" style="color: var(--text-primary);">Active Leases</div>
                    <div class="text-xs" style="color: var(--text-muted);">View and manage active leases</div>
                </div>
            </div>
        </a>
        <a href="{{ route('rental.expired-leases') }}" class="ds-status-card p-5 transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-md flex items-center justify-center" style="background: var(--surface-2); color: var(--text-secondary);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold" style="color: var(--text-primary);">Expired Leases</div>
                    <div class="text-xs" style="color: var(--text-muted);">Review expired and terminated leases</div>
                </div>
            </div>
        </a>
    </div>

</div>
@endsection
