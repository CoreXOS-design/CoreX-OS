{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="comp-fica-create-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Send FICA Request</h1>
                <p class="text-sm text-white/60">Select a contact to send a FICA verification form to.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('compliance.fica.index') }}" class="corex-btn-outline corex-btn-on-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    Back to FICA
                </a>
            </div>
        </div>
    </div>

    {{-- Validation errors (Alert block — §3.9 danger) --}}
    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <div class="flex-1">
                <strong>Please fix the following:</strong>
                <ul class="list-disc ml-4 mt-1 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('compliance.fica.store') }}"
          x-data="{
              search: '',
              open: false,
              selected: {{ \Illuminate\Support\Js::from(old('contact_id') ?: null) }},
              selectedName: {{ \Illuminate\Support\Js::from('') }},
              contactInfo: null
          }">
        @csrf

        {{-- Select Contact --}}
        <div class="rounded-md p-5" style="background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0);" data-tour="comp-fica-create-contact">
            <h3 class="text-sm font-semibold mb-4" style="color: var(--text-primary);">Select Contact <span style="color: var(--ds-crimson, #c41e3a);">*</span></h3>

            <div class="relative mb-3">
                <input type="text"
                       x-model="search"
                       @focus="open = true"
                       @click.away="open = false"
                       placeholder="Search contacts..."
                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                       style="border: 1px solid var(--border, #e2e8f0); background: var(--surface-2, #f8fafc); color: var(--text-primary);"
                       x-show="!selected">
                <div x-show="selected" x-cloak class="flex items-center justify-between rounded-md px-3 py-2" style="border: 1px solid var(--border); background: var(--surface-2);">
                    <span class="text-sm font-medium" style="color: var(--text-primary);" x-text="selectedName"></span>
                    <button type="button" @click="selected = null; selectedName = ''; search = ''; contactInfo = null" class="transition-colors hover:text-[var(--ds-crimson)]" style="color: var(--text-muted);">&times;</button>
                </div>
                <input type="hidden" name="contact_id" :value="selected">

                <div x-show="open && search.length >= 2" x-cloak
                     class="absolute z-30 mt-1 w-full max-h-60 overflow-y-auto rounded-md"
                     style="background: var(--surface, #fff); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.18);">
                    @foreach($contacts as $c)
                        @php
                            $haystack = strtolower(trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '') . ' ' . ($c->email ?? '')));
                            $label    = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                            $info     = json_encode([
                                'name'  => $label,
                                'email' => $c->email ?? 'No email',
                                'phone' => $c->phone ?? 'No phone',
                            ]);
                        @endphp
                        <button type="button"
                                x-show="{{ \Illuminate\Support\Js::from($haystack) }}.includes(search.toLowerCase())"
                                @click="selected = {{ (int) $c->id }}; selectedName = {{ \Illuminate\Support\Js::from($label) }}; open = false; contactInfo = {{ $info }}"
                                class="w-full text-left px-3 py-2 text-sm transition-colors hover:bg-[var(--surface-2)]" style="border-bottom: 1px solid var(--border);">
                            <div class="font-medium" style="color: var(--text-primary);">{{ $c->first_name }} {{ $c->last_name }}</div>
                            <div class="text-xs" style="color: var(--text-muted);">{{ $c->email ?? 'No email' }} {{ $c->phone ? '/ ' . $c->phone : '' }}</div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Contact info summary --}}
            <div x-show="contactInfo" x-cloak class="rounded-md p-3 text-xs" style="background: var(--surface-2); border: 1px solid var(--border);">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div><span style="color: var(--text-muted);">Email:</span> <span style="color: var(--text-primary);" x-text="contactInfo?.email"></span></div>
                    <div><span style="color: var(--text-muted);">Phone:</span> <span style="color: var(--text-primary);" x-text="contactInfo?.phone"></span></div>
                </div>
            </div>

            <p class="mt-3 text-xs" style="color: var(--text-muted);">The contact must have an email address on file — that's where the secure verification link is sent.</p>
        </div>

        {{-- Submit --}}
        <div class="flex flex-wrap items-center gap-3 mt-5">
            <button type="submit" class="corex-btn-primary" data-tour="comp-fica-create-send">
                Send FICA Request
            </button>
            <a href="{{ route('compliance.fica.index') }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
</div>
@endsection
