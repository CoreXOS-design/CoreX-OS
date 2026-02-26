@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Rental Settings</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.dashboard') }}" class="text-white/60 hover:text-white">&larr; Rentals</a>
            </div>
        </div>
    </div>

    <div class="ds-status-card p-8 text-center">
        <div class="text-lg text-slate-400">Coming Soon</div>
        <div class="text-sm text-slate-400 mt-1">Lease expiry reminder rules and rental preferences will be configured here.</div>
    </div>

</div>
@endsection
