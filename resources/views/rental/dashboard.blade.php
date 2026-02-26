@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Rental Division</h2>
            <div class="text-sm text-white/60">Overview of rental workflows, signatures, and lease management.</div>
        </div>
    </div>

    {{-- Metric Tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4">
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block {{ $counts['needs_approval'] > 0 ? 'border-2 border-red-300 bg-red-50' : '' }}">
            <div class="text-3xl font-bold {{ $counts['needs_approval'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $counts['needs_approval'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Needs Approval</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block">
            <div class="text-3xl font-bold {{ $counts['draft'] > 0 ? 'text-slate-700' : 'text-slate-300' }}">{{ $counts['draft'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Draft</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block">
            <div class="text-3xl font-bold {{ $counts['ready_to_sign'] > 0 ? 'text-blue-600' : 'text-slate-300' }}">{{ $counts['ready_to_sign'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Ready to Sign</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block">
            <div class="text-3xl font-bold {{ $counts['in_progress'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $counts['in_progress'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Awaiting Signatures</div>
        </a>
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block">
            <div class="text-3xl font-bold {{ $counts['completed'] > 0 ? 'text-emerald-600' : 'text-slate-300' }}">{{ $counts['completed'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Completed</div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block">
            <div class="text-3xl font-bold {{ $counts['active_leases'] > 0 ? 'text-teal-600' : 'text-slate-300' }}">{{ $counts['active_leases'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Active Leases</div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-4 text-center hover:shadow-md transition cursor-pointer block {{ $counts['expiring_soon'] > 0 ? 'border-2 border-red-300 bg-red-50' : '' }}">
            <div class="text-3xl font-bold {{ $counts['expiring_soon'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $counts['expiring_soon'] }}</div>
            <div class="text-xs text-slate-500 mt-1">Expiring (90 days)</div>
        </a>
    </div>

    {{-- Quick Actions --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="{{ route('rental.signatures') }}" class="ds-status-card p-5 hover:shadow-md transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold text-slate-800">Electronic Signatures</div>
                    <div class="text-xs text-slate-500">Manage signing workflows</div>
                </div>
            </div>
        </a>
        <a href="{{ route('rental.active-leases') }}" class="ds-status-card p-5 hover:shadow-md transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold text-slate-800">Active Leases</div>
                    <div class="text-xs text-slate-500">View and manage active leases</div>
                </div>
            </div>
        </a>
        <a href="{{ route('rental.expired-leases') }}" class="ds-status-card p-5 hover:shadow-md transition cursor-pointer block">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-semibold text-slate-800">Expired Leases</div>
                    <div class="text-xs text-slate-500">Review expired and terminated leases</div>
                </div>
            </div>
        </a>
    </div>

</div>
@endsection
