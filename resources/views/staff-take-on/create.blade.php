{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Start New Take-On" :back-route="route('staff-take-on.index')" back-label="Staff Take-On" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold rounded-md" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); color:var(--ds-crimson);">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('staff-take-on.store') }}">
            @csrf
            <div class="max-w-2xl space-y-5">
                <div class="p-4 rounded-md" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb);">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Select User</h4>
                    @if($eligibleUsers->isEmpty())
                        <p class="text-xs" style="color:var(--text-secondary, #94a3b8);">All users are already on payroll. Create a new user in User Management first.</p>
                    @else
                        <select name="user_id" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                            <option value="">-- Choose a user --</option>
                            @foreach($eligibleUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->designation ?? 'No designation' }}) — {{ $user->email }}</option>
                            @endforeach
                        </select>
                        @error('user_id') <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div class="p-4 rounded-md" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb);">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Take-On Details</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Take-On Type <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                            <select name="take_on_type" required class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                                <option value="new_hire">New Hire</option>
                                <option value="migration_from_old_system">Migration from Old System</option>
                                <option value="transfer_from_other_branch">Transfer from Other Branch</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Take-On Date <span style="color:var(--ds-crimson, #c41e3a);">*</span></label>
                            <input type="date" name="take_on_date" value="{{ date('Y-m-d') }}" required
                                   class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed" {{ $eligibleUsers->isEmpty() ? 'disabled' : '' }}>Start Wizard</button>
                    <a href="{{ route('staff-take-on.index') }}" class="corex-btn-outline">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
