@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="New Employee Screening" :back-route="route('compliance.screening.dashboard.index')" back-label="Dashboard" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-lg">
            <form method="POST" action="{{ route('compliance.screenings.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Staff Member *</label>
                    <select name="user_id" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        <option value="">Select staff member...</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ ($selectedUser && $selectedUser->id === $u->id) ? 'selected' : '' }}>
                            {{ $u->name }} ({{ $u->role }}) â€” {{ $u->screening_status ?? 'never screened' }}
                        </option>
                        @endforeach
                    </select>
                    @error('user_id') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Screening Type *</label>
                        <select name="screening_type" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                            <option value="pre_employment" {{ $suggestedType === 'pre_employment' ? 'selected' : '' }}>Pre-Employment (all checks)</option>
                            <option value="periodic" {{ $suggestedType === 'periodic' ? 'selected' : '' }}>Periodic Review</option>
                            <option value="tfs_list_update">TFS List Update</option>
                            <option value="triggered">Triggered Review</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Risk Tier *</label>
                        <select name="risk_tier" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                            <option value="high" {{ ($selectedUser?->risk_tier ?? 'medium') === 'high' ? 'selected' : '' }}>High (annual review)</option>
                            <option value="medium" {{ ($selectedUser?->risk_tier ?? 'medium') === 'medium' ? 'selected' : '' }}>Medium (3-year review)</option>
                            <option value="low" {{ ($selectedUser?->risk_tier ?? 'medium') === 'low' ? 'selected' : '' }}>Low (5-year review)</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">Start Screening</button>
                    <a href="{{ route('compliance.screening.dashboard.index') }}" class="text-sm" style="color:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
