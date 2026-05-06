@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Appoint Compliance Officer" :back-route="route('compliance.officer.index')" back-label="Officers" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <div class="mb-4 text-sm" style="color:#64748b;">
                Appointing a new compliance officer will automatically end the current officer's appointment.
            </div>

            <form method="POST" action="{{ route('compliance.officer.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Link to System User</label>
                    <select name="user_id" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        <option value="">-- None (external person) --</option>
                        @foreach($users as $user)
                        <option value="{{ $user->id }}" {{ old('user_id') == $user->id ? 'selected' : '' }}>{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Full Name *</label>
                        <input type="text" name="full_name" value="{{ old('full_name') }}" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        @error('full_name') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">ID Number</label>
                        <input type="text" name="id_number" value="{{ old('id_number') }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Cell</label>
                        <input type="text" name="cell" value="{{ old('cell') }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Title</label>
                        <input type="text" name="title" value="{{ old('title', 'FICA Compliance Officer') }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Appointed On *</label>
                        <input type="date" name="appointed_on" value="{{ old('appointed_on', now()->format('Y-m-d')) }}" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        @error('appointed_on') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Appointment Notes</label>
                    <textarea name="appointment_notes" rows="3" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">{{ old('appointment_notes') }}</textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        Appoint Officer
                    </button>
                    <a href="{{ route('compliance.officer.index') }}" class="text-sm" style="color:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
