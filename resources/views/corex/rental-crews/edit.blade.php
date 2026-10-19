@extends('layouts.corex')

{{--
    2026-10-05 — members are managed inline here, same "parent record owns
    its children" pattern RentalJobCard's own tasks/lines already use. A
    crew with zero members is valid — nothing forces adding any.
--}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $crew->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Edit this crew, give it a link for its phone, and manage its members.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.rental-crews.index') }}" class="corex-btn-outline text-xs">&larr; All crews</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm max-w-3xl" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-crews.update', $crew) }}" class="space-y-5 rounded-md p-6 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')
        <div>
            <label class="prop-label">Name</label>
            <input type="text" name="name" value="{{ old('name', $crew->name) }}" required maxlength="191" class="prop-input w-full">
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="prop-label">Email</label>
                <input type="email" name="email" value="{{ old('email', $crew->email) }}" maxlength="191" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Contact number</label>
                <input type="text" name="phone" value="{{ old('phone', $crew->phone) }}" maxlength="30" inputmode="tel" class="prop-input w-full">
            </div>
        </div>
        <div>
            <label class="prop-label">Notes</label>
            <textarea name="notes" rows="3" class="prop-input w-full">{{ old('notes', $crew->notes) }}</textarea>
        </div>
        <div class="flex items-center gap-2" style="color: var(--text-primary);">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked($crew->is_active) class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>

    @include('corex.rental-crews._crew-link-panel', ['crew' => $crew])

    <div class="rounded-md p-6 space-y-3 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Members</h2>
        @forelse($crew->members as $member)
            <div class="flex items-center justify-between gap-2 text-sm" style="color: var(--text-primary); border-top: 1px solid var(--border); padding-top: 8px;">
                <div>
                    {{ $member->name }}
                    @if($member->role)<span style="color: var(--text-muted);"> — {{ $member->role }}</span>@endif
                    @if($member->phone)<span style="color: var(--text-muted);"> · {{ $member->phone }}</span>@endif
                </div>
                <form method="POST" action="{{ route('corex.rental-crews.members.archive', [$crew, $member]) }}" data-confirm="Archive this member?" data-confirm-danger data-confirm-label="Archive">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="corex-btn-danger text-xs">Archive</button>
                </form>
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No members yet — a crew with no named members is still a valid, pickable team.</p>
        @endforelse

        <form method="POST" action="{{ route('corex.rental-crews.members.store', $crew) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-2 pt-3 items-end">
            @csrf
            <input type="text" name="name" required maxlength="191" placeholder="Name" aria-label="Member name" class="prop-input">
            <input type="text" name="role" maxlength="100" placeholder="Role (optional)" aria-label="Role" class="prop-input">
            <input type="text" name="phone" maxlength="30" placeholder="Phone (optional)" aria-label="Phone" class="prop-input">
            <button type="submit" class="corex-btn-outline text-xs">Add member</button>
        </form>

        @if($archivedMembers->isNotEmpty())
            <button type="button" onclick="document.getElementById('archived-members').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedMembers->count() }} archived member(s)</button>
            <ul id="archived-members" class="hidden space-y-1 text-sm pt-1">
                @foreach($archivedMembers as $am)
                    <li class="flex items-center justify-between gap-2">
                        <span style="color: var(--text-muted);">{{ $am->name }}</span>
                        <form method="POST" action="{{ route('corex.rental-crews.members.restore', [$crew, $am->id]) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
