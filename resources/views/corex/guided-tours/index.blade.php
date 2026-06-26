@extends('layouts.corex')

{{-- Guided Tours directory (AT-41) — the agent's self-serve training index.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (var(--token,#fallback)). --}}

@section('corex-content')
<style>
    .corex-tour-card:hover {
        border-color: color-mix(in srgb, var(--brand-button, #0ea5e9) 55%, var(--border)) !important;
        box-shadow: 0 6px 20px rgba(0,0,0,0.10);
        transform: translateY(-2px);
    }
</style>
<div class="w-full space-y-5">

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
        </div>
    </div>

    @if($groups->isEmpty())
        <div class="rounded-md p-8 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-base font-semibold" style="color: var(--text-primary);">No tours available yet</p>
            <p class="mt-1 text-sm" style="color: var(--text-secondary);">Tours will appear here as they become available for your role.</p>
        </div>
    @else
        @foreach($groups as $groupName => $tours)
        <section class="space-y-3">
            <h2 class="text-xs font-bold uppercase tracking-widest pt-1" style="color: var(--text-muted);">
                {{ $groupName }} <span style="opacity:0.6;">· {{ $tours->count() }}</span>
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-stretch">
            @foreach($tours as $tour)
                <div class="corex-tour-card rounded-md p-5 flex flex-col h-full transition-all duration-300"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-md flex-shrink-0"
                              style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 14%, transparent); color: var(--brand-button, #0ea5e9);">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
                            </svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-sm font-bold leading-tight" style="color: var(--text-primary);">{{ $tour['title'] }}</h2>
                            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ $tour['steps'] }} step{{ $tour['steps'] === 1 ? '' : 's' }}</div>
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
                               class="inline-flex items-center justify-center gap-1.5 w-full px-3 py-2 rounded-md text-xs font-semibold no-underline transition-all duration-300"
                               style="background: var(--brand-button, #0ea5e9); color:#fff;"
                               onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter='none'">
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
    @endif

</div>
@endsection
