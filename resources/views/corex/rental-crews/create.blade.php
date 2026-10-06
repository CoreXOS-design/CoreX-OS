@extends('layouts.corex')

{{-- 2026-10-05 — a crew can be several named members or just the name itself ("Team 1") — members are added on the next screen once the crew exists. --}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">Add Crew</h1>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-crews.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="191" placeholder="e.g. Team 1, or a person's name" class="prop-input w-full">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="prop-label">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" maxlength="191" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Contact number</label>
                <input type="text" name="phone" value="{{ old('phone') }}" maxlength="30" inputmode="tel" class="prop-input w-full">
            </div>
        </div>
        <div>
            <label class="prop-label">Notes</label>
            <textarea name="notes" rows="3" class="prop-input w-full">{{ old('notes') }}</textarea>
        </div>
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-crews.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Add Crew</button>
        </div>
    </form>
</div>
@endsection
