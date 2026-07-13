{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Edit a demo grant — notes + CRM link ONLY. Owner-only.
    Spec: .ai/specs/demo-access-control.md §9
--}}
@extends('layouts.corex')

@section('title', 'Edit grant')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Back link --}}
    <a href="{{ route('admin.demo-access.show', $grant) }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Back to grant
    </a>

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Edit {{ $grant->company_name }}</h1>
        <p class="text-sm text-white/60">Notes and the CRM link. Everything else about a grant is fixed once it is issued.</p>
    </div>

    {{-- No Silent Locks (STANDARDS): the two things you CANNOT edit here are named,
         with the reason and the way forward — rather than simply being absent and
         leaving someone hunting for them. §3.9 info alert. --}}
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3 max-w-3xl"
         style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
        </svg>
        <div class="flex-1">
            <strong>Access length and access code cannot be changed.</strong>
            The length ({{ number_format($grant->expiry_hours) }} hours) was fixed when the grant was issued —
            editing it would move a deadline the prospect was already told. The code is stored
            only as a hash, so there is nothing to reveal or re-send.
            For either, <a href="{{ route('admin.demo-access.create') }}" class="font-semibold" style="color: var(--brand-icon, #0ea5e9);">issue a new grant</a>.
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm max-w-3xl"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.demo-access.update', $grant) }}" class="max-w-3xl">
        @csrf @method('PUT')

        <div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">

            <div>
                <label for="contact_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Contact name
                </label>
                <input id="contact_name" name="contact_name" type="text"
                       value="{{ old('contact_name', $grant->contact_name) }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
            </div>

            <div>
                <label for="contact_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Linked contact (CRM)
                </label>
                <input id="contact_id" name="contact_id" type="number" min="1"
                       value="{{ old('contact_id', $grant->contact_id) }}"
                       placeholder="Contact ID"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    Optional. Links this prospect to a Contact record (the Contact pillar).
                </p>
                <x-input-error :messages="$errors->get('contact_id')" class="mt-1" />
            </div>

            <div>
                <label for="notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Notes
                </label>
                <textarea id="notes" name="notes" rows="4"
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical;">{{ old('notes', $grant->notes) }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
            <a href="{{ route('admin.demo-access.show', $grant) }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
