@extends('layouts.corex')

{{--
    2026-10-05 — members are managed inline here, same "parent record owns
    its children" pattern RentalJobCard's own tasks/lines already use. A
    crew with zero members is valid — nothing forces adding any.
--}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">{{ $crew->name }}</h1>
        <a href="{{ route('corex.rental-crews.index') }}" class="corex-btn-outline text-xs">&larr; All crews</a>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('corex.rental-crews.update', $crew) }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
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
        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked($crew->is_active) class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-xs">Save</button>
        </div>
    </form>

    @include('corex.rental-crews._crew-link-panel', ['crew' => $crew])

    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold">Members</h2>
        @forelse($crew->members as $member)
            <div class="flex items-center justify-between gap-2 text-sm" style="border-top: 1px solid var(--border); padding-top: 6px;">
                <div>
                    {{ $member->name }}
                    @if($member->role)<span style="color: var(--text-muted);"> — {{ $member->role }}</span>@endif
                    @if($member->phone)<span style="color: var(--text-muted);"> · {{ $member->phone }}</span>@endif
                </div>
                <form method="POST" action="{{ route('corex.rental-crews.members.archive', [$crew, $member]) }}" data-confirm="Archive this member?" data-confirm-danger data-confirm-label="Archive">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs" style="color: var(--ds-red, #dc2626);">Archive</button>
                </form>
            </div>
        @empty
            <p class="text-xs" style="color: var(--text-muted);">No members yet — a crew with no named members is still a valid, pickable team.</p>
        @endforelse

        <form method="POST" action="{{ route('corex.rental-crews.members.store', $crew) }}" class="grid grid-cols-4 gap-2 pt-2 items-end">
            @csrf
            <input type="text" name="name" required maxlength="191" placeholder="Name" aria-label="Member name" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
            <input type="text" name="role" maxlength="100" placeholder="Role (optional)" aria-label="Role" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
            <input type="text" name="phone" maxlength="30" placeholder="Phone (optional)" aria-label="Phone" class="rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
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
                            <button type="submit" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">Restore</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
