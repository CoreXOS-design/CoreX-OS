@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Edit Compliance Officer" :back-route="route('compliance.officer.index')" back-label="Officers" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl">
            <form method="POST" action="{{ route('compliance.officer.update', $officer) }}" class="space-y-5">
                @csrf
                @method('PATCH')

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Full Name *</label>
                        <input type="text" name="full_name" value="{{ old('full_name', $officer->full_name) }}" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                        @error('full_name') <p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">ID Number</label>
                        <input type="text" name="id_number" value="{{ old('id_number', $officer->id_number) }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Cell</label>
                        <input type="text" name="cell" value="{{ old('cell', $officer->cell) }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Email</label>
                        <input type="email" name="email" value="{{ old('email', $officer->email) }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Title</label>
                    <input type="text" name="title" value="{{ old('title', $officer->title) }}" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--text-primary, #1f2937);">Appointment Notes</label>
                    <textarea name="appointment_notes" rows="3" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">{{ old('appointment_notes', $officer->appointment_notes) }}</textarea>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        Save Changes
                    </button>
                    <a href="{{ route('compliance.officer.index') }}" class="text-sm" style="color:#6b7280;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
