{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Issue a demo access grant. Owner-only.
    Spec: .ai/specs/demo-access-control.md §6.1
--}}
@extends('layouts.corex')

@section('title', 'New demo grant')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Back link --}}
    <a href="{{ route('admin.demo-access.index') }}"
       class="inline-flex items-center gap-1.5 text-sm no-underline"
       style="color:var(--text-secondary);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
        </svg>
        Back to Demo Access
    </a>

    {{-- Page header — §2.4 Pattern A --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">New demo grant</h1>
        <p class="text-sm text-white/60">
            We email an access code to this address. The clock starts when they first sign in —
            not now — so an unopened invitation loses them nothing.
        </p>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm max-w-3xl"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Form card — §3.3. Constrained column: a text field the width of a 27" monitor
         is not a better text field. --}}
    <form method="POST" action="{{ route('admin.demo-access.store') }}" class="max-w-3xl">
        @csrf

        <div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">

            <div>
                <label for="company_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Company name <span class="text-red-500">*</span>
                </label>
                <input id="company_name" name="company_name" type="text" required
                       value="{{ old('company_name') }}"
                       placeholder="e.g. Seaside Realty (Pty) Ltd"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    Shown in the watermark on every page they view.
                </p>
                <x-input-error :messages="$errors->get('company_name')" class="mt-1" />
            </div>

            <div>
                <label for="contact_email" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Email address <span class="text-red-500">*</span>
                </label>
                <input id="contact_email" name="contact_email" type="email" required
                       value="{{ old('contact_email') }}"
                       placeholder="thabo@seasiderealty.co.za"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <x-input-error :messages="$errors->get('contact_email')" class="mt-1" />
            </div>

            <div>
                <label for="contact_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Contact name
                </label>
                <input id="contact_name" name="contact_name" type="text"
                       value="{{ old('contact_name') }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
            </div>

            <div>
                <label for="expiry_hours" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Access length (hours)
                </label>
                <input id="expiry_hours" name="expiry_hours" type="number" min="1" max="8760"
                       value="{{ old('expiry_hours', $defaultExpiryHours) }}"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                    Counted from their first sign-in. This value is fixed onto the grant now —
                    changing the default later will not shorten a demo you've already promised.
                </p>
                <x-input-error :messages="$errors->get('expiry_hours')" class="mt-1" />
            </div>

            <div>
                <label for="notes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                    Notes
                </label>
                <textarea id="notes" name="notes" rows="4"
                          placeholder="Context for the sales team — who introduced them, what they care about."
                          class="w-full rounded-md px-3 py-2 text-sm"
                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); resize: vertical;">{{ old('notes') }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4">
            <button type="submit" class="corex-btn-primary text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                </svg>
                Issue grant &amp; send invitation
            </button>
            <a href="{{ route('admin.demo-access.index') }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
