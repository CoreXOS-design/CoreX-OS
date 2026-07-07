{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — agency-setup wizard step.
     Data-driven from config/agency-onboarding-copy.php. --}}
@extends('layouts.agency-setup')

@section('setup-content')
@php
    $isGuide = ($config['mode'] ?? 'form') === 'guide';
    $isLast  = $nav['isLast'];
@endphp

<div class="max-w-2xl mx-auto px-4 sm:px-6 py-8 sm:py-10">

    <div class="rounded-lg overflow-hidden" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
        {{-- Step header --}}
        <div class="px-6 pt-6 pb-4" style="border-bottom:1px solid var(--border,#e5e7eb);">
            <div class="text-xs font-semibold uppercase tracking-wider mb-1 setup-accent">
                Step {{ $progress['current'] }} of {{ $progress['total'] }}
            </div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary,#0f172a);">{{ $config['title'] }}</h1>
            <p class="text-sm mt-2" style="color:var(--text-muted,#64748b);">{{ $config['intro'] }}</p>
        </div>

        @if (session('success'))
            <div class="mx-6 mt-4 rounded-md px-3 py-2 text-sm"
                 style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-default,#0b2a4a);">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST"
              action="{{ route('corex.agency-setup.step.save', ['step' => $stepKey]) }}"
              enctype="multipart/form-data"
              class="px-6 py-5 space-y-6">
            @csrf

            {{-- Rich inline partial (complex steps render their real settings form
                 here, inside the wizard form, posting to the same canonical saver). --}}
            @if (!empty($config['partial']))
                @include($config['partial'])
            @endif

            {{-- Live controls (data-driven simple settings) --}}
            @foreach (($config['controls'] ?? []) as $control)
                @php
                    $key = $control['key'];
                    $val = old($key, $values[$key] ?? ($control['default'] ?? null));
                    $type = $control['type'];
                @endphp
                <div>
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="f_{{ $key }}" class="block text-sm font-semibold" style="color:var(--text-primary,#0f172a);">
                                {{ $control['label'] }}
                            </label>
                            @if (!empty($control['explain']))
                                <p class="text-xs mt-0.5" style="color:var(--text-muted,#64748b);">{{ $control['explain'] }}</p>
                            @endif
                            @if (!empty($control['affects']))
                                <p class="text-[11px] mt-0.5 italic" style="color:var(--text-muted,#94a3b8);">Affects: {{ $control['affects'] }}</p>
                            @endif
                        </div>

                        @if ($type === 'toggle')
                            <label class="relative inline-flex items-center cursor-pointer flex-shrink-0 mt-1">
                                <input type="hidden" name="{{ $key }}" value="0">
                                <input id="f_{{ $key }}" type="checkbox" name="{{ $key }}" value="1" class="sr-only peer" @checked((bool) $val)>
                                <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                                <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                            </label>
                        @endif
                    </div>

                    @if ($type === 'text')
                        <input id="f_{{ $key }}" name="{{ $key }}" type="text" value="{{ $val }}"
                               class="mt-2 w-full rounded-md px-3 py-2 text-sm outline-none"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    @elseif ($type === 'textarea')
                        <textarea id="f_{{ $key }}" name="{{ $key }}" rows="3"
                                  class="mt-2 w-full rounded-md px-3 py-2 text-sm outline-none"
                                  style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">{{ $val }}</textarea>
                    @elseif ($type === 'number')
                        <input id="f_{{ $key }}" name="{{ $key }}" type="number"
                               value="{{ $val }}" min="{{ $control['min'] ?? '' }}" max="{{ $control['max'] ?? '' }}"
                               class="mt-2 w-32 rounded-md px-3 py-2 text-sm outline-none"
                               style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                    @elseif ($type === 'select')
                        <select id="f_{{ $key }}" name="{{ $key }}"
                                class="mt-2 w-full rounded-md px-3 py-2 text-sm outline-none"
                                style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                            @foreach (($control['options'] ?? []) as $ov => $ol)
                                <option value="{{ $ov }}" @selected((string) $val === (string) $ov)>{{ $ol }}</option>
                            @endforeach
                        </select>
                    @endif

                    @error($key)
                        <p class="text-xs mt-1" style="color:var(--ds-crimson,#e11d48);">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            {{-- Deep links into the full editors for complex collections --}}
            @if (!empty($config['links']))
                <div class="space-y-2">
                    @foreach ($config['links'] as $link)
                        <a href="{{ route($link['route'], $link['params'] ?? []) }}" target="_blank" rel="noopener"
                           class="flex items-start gap-3 rounded-md px-3 py-3 no-underline transition-colors hover:bg-black/[.03]"
                           style="border:1px solid var(--border,#e5e7eb);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                                 class="w-4 h-4 mt-0.5 flex-shrink-0 setup-accent">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                            </svg>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold" style="color:var(--text-primary,#0f172a);">{{ $link['label'] }}</span>
                                @if (!empty($link['explain']))
                                    <span class="block text-xs mt-0.5" style="color:var(--text-muted,#64748b);">{{ $link['explain'] }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                    @if ($isGuide)
                        <p class="text-[11px] italic" style="color:var(--text-muted,#94a3b8);">
                            Opens in a new tab so you don't lose your place here.
                        </p>
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex items-center justify-between pt-4" style="border-top:1px solid var(--border,#e5e7eb);">
                <div>
                    @if ($nav['prev'])
                        <a href="{{ route('corex.agency-setup.step', ['step' => $nav['prev']]) }}"
                           class="text-sm font-medium no-underline" style="color:var(--text-secondary,#475569);">← Back</a>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    @unless ($isLast)
                        <button type="submit"
                                formaction="{{ route('corex.agency-setup.step.skip', ['step' => $stepKey]) }}"
                                class="text-sm font-medium" style="background:none;border:none;cursor:pointer;color:var(--text-muted,#64748b);">
                            Skip for now
                        </button>
                    @endunless
                    <button type="submit" class="setup-cta rounded-md px-5 py-2.5 text-sm font-semibold">
                        {{ $isLast ? 'Finish setup' : 'Save & continue' }}
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- Auxiliary inline editors (collections with their own add/remove sub-forms,
         kept OUTSIDE the main form so forms are never nested). --}}
    @if (!empty($config['aux_partial']))
        <div class="rounded-lg overflow-hidden mt-5" style="background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb);">
            @include($config['aux_partial'])
        </div>
    @endif
</div>
@endsection
