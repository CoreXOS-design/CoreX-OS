{{-- Guided Tours directory (AT-41) — the agent's self-serve training index.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    .corex-tour-card:hover {
        border-color: color-mix(in srgb, var(--brand-button, #0ea5e9) 55%, var(--border)) !important;
        box-shadow: 0 6px 20px rgba(0,0,0,0.10);
        transform: translateY(-2px);
    }
    /* Hover affordance for the Start-tour CTA — CSS :hover instead of inline
       onmouseover JS (UI_DESIGN_SYSTEM.md §5 rule 11). */
    .corex-tour-start { transition: filter 300ms ease; }
    .corex-tour-start:hover { filter: brightness(1.08); }
</style>
@php
    // Searchable text per group, in the same order the directory renders, so the
    // client-side filter can hide whole sections (and surface "no results")
    // without a round-trip. Title + description, lower-cased.
    $tourSearchIndex = $groups->map(fn ($tours, $name) => [
        'name'  => $name,
        'texts' => $tours->map(fn ($t) => mb_strtolower(trim(($t['title'] ?? '').' '.($t['description'] ?? ''))))->values(),
    ])->values();
@endphp
<div class="w-full space-y-5"
     x-data="{
        q: '',
        groups: {{ \Illuminate\Support\Js::from($tourSearchIndex) }},
        norm(s) { return (s || '').toLowerCase().trim(); },
        cardVisible(text) { const k = this.norm(this.q); return k === '' || this.norm(text).includes(k); },
        groupVisible(texts) { const k = this.norm(this.q); return k === '' || texts.some(t => t.includes(k)); },
        get anyVisible() { const k = this.norm(this.q); return k === '' || this.groups.some(g => g.texts.some(t => t.includes(k))); }
     }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Guided Tours</h1>
                <p class="text-sm text-white/60">
                    Short, interactive walkthroughs of CoreX — click one and it takes you to the screen and
                    walks you through it, step by step. Your own training, any time you need a refresher.
                </p>
            </div>

            {{-- Search: filters cards and sections live as you type. --}}
            <div class="relative w-full md:w-72 flex-shrink-0">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-white/50" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.3-4.3m1.8-5.2a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="search" x-model="q"
                       placeholder="Search tours…"
                       aria-label="Search tours"
                       class="w-full rounded-md border-0 pl-9 pr-3 py-2 text-sm text-white placeholder-white/50 focus:outline-none focus:ring-2"
                       style="background: rgba(255,255,255,0.12); --tw-ring-color: var(--brand-button, #0ea5e9);">
            </div>
        </div>
    </div>

    @if($groups->isEmpty())
        {{-- Empty state (§3.10) — icon + heading + body. No CTA: tours are
             system-provided, there is nothing for the user to create here. --}}
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No tours available yet</h3>
            <p class="text-sm" style="color: var(--text-muted);">Tours will appear here as they become available for your role.</p>
        </div>
    @else
        @foreach($groups as $groupName => $tours)
        <section class="space-y-3"
                 x-show="groupVisible({{ \Illuminate\Support\Js::from($tours->map(fn ($t) => mb_strtolower(trim(($t['title'] ?? '').' '.($t['description'] ?? ''))))->values()) }})">
            <h2 class="text-xs font-bold uppercase tracking-widest pt-1" style="color: var(--text-muted);">
                {{ $groupName }} <span style="opacity:0.6;">· {{ $tours->count() }}</span>
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 items-stretch">
            @foreach($tours as $tour)
                <div class="corex-tour-card rounded-md p-5 flex flex-col h-full transition-all duration-300"
                     x-show="cardVisible({{ \Illuminate\Support\Js::from(trim(($tour['title'] ?? '').' '.($tour['description'] ?? ''))) }})"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-md flex-shrink-0"
                              style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 14%, transparent); color: var(--brand-button, #0ea5e9);">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
                            </svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-bold leading-tight" style="color: var(--text-primary);">{{ $tour['title'] }}</h3>
                            <div class="text-[0.6875rem] mt-0.5" style="color: var(--text-muted);">{{ $tour['steps'] }} step{{ $tour['steps'] === 1 ? '' : 's' }}</div>
                        </div>
                    </div>

                    <p class="text-xs mt-3" style="color: var(--text-secondary);">
                        {{ $tour['description'] ?: 'A short, interactive walkthrough of this screen.' }}
                    </p>

                    {{-- Action pinned to the bottom so every card is the same shape,
                         with exactly one uniform full-width action per entry. --}}
                    <div class="mt-auto pt-4">
                        @if($tour['url'])
                            <a href="{{ $tour['url'] }}"
                               class="corex-tour-start inline-flex items-center justify-center gap-1.5 w-full px-3 py-2 rounded-md text-xs font-semibold no-underline"
                               style="background: var(--brand-button, #0ea5e9); color:#fff;">
                                Start tour
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        @else
                            {{-- Route needs a specific record (e.g. open a contact first),
                                 so it can't be linked generically — same footprint, made clear. --}}
                            <span class="inline-flex items-center justify-center gap-1.5 w-full px-3 py-2 rounded-md text-xs font-semibold cursor-default"
                                  title="Open the relevant record, then tap the “?” in its header to start this tour."
                                  style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                                Open record first
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
            </div>
        </section>
        @endforeach

        {{-- Shown only when a search term matches no tours. --}}
        <div x-show="!anyVisible" x-cloak class="rounded-md p-8 text-center"
             style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-base font-semibold" style="color: var(--text-primary);">No tours match your search</p>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">
                Try a different keyword, or <button type="button" @click="q = ''" class="underline" style="color: var(--brand-button, #0ea5e9);">clear the search</button>.
            </p>
        </div>
    @endif

</div>
@endsection
