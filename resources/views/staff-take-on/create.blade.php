{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Start New Take-On</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <a href="{{ route('staff-take-on.index') }}" class="corex-btn-outline text-xs inline-flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Staff Take-On
                </a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="p-3 text-sm font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); color:var(--ds-crimson);">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('staff-take-on.store') }}">
        @csrf
        <div class="max-w-2xl space-y-5">
            <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">Select User</h4>
                @if($eligibleUsers->isEmpty())
                    <p class="text-xs" style="color:var(--text-muted);">All users are already on payroll. Create a new user in User Management first.</p>
                @else
                    <select name="user_id" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                        <option value="">-- Choose a user --</option>
                        @foreach($eligibleUsers as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->designation ?? 'No designation' }}) — {{ $user->email }}</option>
                        @endforeach
                    </select>
                    @error('user_id') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                @endif
            </div>

            <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">Take-On Details</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Take-On Type <span style="color:var(--ds-crimson);">*</span></label>
                        <select name="take_on_type" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="new_hire">New Hire</option>
                            <option value="migration_from_old_system">Migration from Old System</option>
                            <option value="transfer_from_other_branch">Transfer from Other Branch</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Take-On Date <span style="color:var(--ds-crimson);">*</span></label>
                        <input type="date" name="take_on_date" value="{{ date('Y-m-d') }}" required
                               class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="corex-btn-primary text-xs disabled:opacity-40 disabled:cursor-not-allowed" {{ $eligibleUsers->isEmpty() ? 'disabled' : '' }}>Start Wizard</button>
                <a href="{{ route('staff-take-on.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            </div>
        </div>
    </form>
</div>
@endsection
