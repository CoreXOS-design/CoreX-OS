{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-240 — Edit surface for a saved wishlist / Core Match criteria.

    Pure entry point: reuses the existing `_match-form` partial in edit mode
    ($isEdit → pre-filled from $match, PUTs to corex.contacts.matches.update).
    Linked from every render site of a saved match (contact record, Core Matches
    page, All-view, match-results). No new form, no new endpoint — just the door
    that was missing.
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-4xl mx-auto space-y-6">

    {{-- Header + back --}}
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="min-w-0">
            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
               class="inline-flex items-center gap-1.5 text-xs font-semibold no-underline mb-2"
               style="color:var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Back to {{ $contact->first_name ?: 'contact' }}’s Core Matches
            </a>
            <h1 class="text-lg font-bold" style="color:var(--text-primary);">Edit Match Criteria</h1>
            <p class="text-xs mt-1" style="color:var(--text-muted);">
                {{ $contact->first_name }} {{ $contact->last_name }}
                @if($match->name) · <span style="color:var(--text-secondary);">{{ $match->name }}</span> @endif
            </p>
        </div>
    </div>

    {{-- The reusable criteria form, in edit mode --}}
    <div class="rounded-md p-5 space-y-5" style="background:var(--surface-2); border:1px solid var(--border);">
        @include('corex.contacts._match-form', ['contact' => $contact, 'match' => $match])
    </div>

</div>
@endsection
