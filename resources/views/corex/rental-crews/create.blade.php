@extends('layouts.corex')

{{-- 2026-10-05 — a crew can be several named members or just the name itself ("Team 1") — members are added on the next screen once the crew exists. --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Add Crew</h1>
        <p class="text-xs" style="color: var(--text-muted);">A maintenance team your job cards can assign work to. Members are added on the next screen.</p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-crews.store') }}" class="space-y-5 rounded-md p-6 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rmnt-crewform">
        @csrf
        <div data-tour="rmnt-crewform-name">
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="191" placeholder="e.g. Team 1, or a person's name" class="prop-input w-full">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-tour="rmnt-crewform-contact">
            <div>
                <label class="prop-label">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" maxlength="191" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Contact number</label>
                <input type="text" name="phone" value="{{ old('phone') }}" maxlength="30" inputmode="tel" class="prop-input w-full">
            </div>
        </div>
        <div data-tour="rmnt-crewform-notes">
            <label class="prop-label">Notes</label>
            <textarea name="notes" rows="3" class="prop-input w-full">{{ old('notes') }}</textarea>
        </div>
        <div class="flex items-center gap-2" style="color: var(--text-primary);" data-tour="rmnt-crewform-active">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end gap-2 pt-4" style="border-top: 1px solid var(--border);">
            <a href="{{ route('corex.rental-crews.index') }}" class="corex-btn-outline text-sm">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm" data-tour="rmnt-crewform-save">Add Crew</button>
        </div>
    </form>
</div>
@endsection
