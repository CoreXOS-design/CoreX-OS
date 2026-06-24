@extends('layouts.corex')

{{-- Guided Tours directory (AT-41) — the agent's self-serve training index.
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (var(--token,#fallback)). --}}

@section('corex-content')
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
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($tours as $tour)
                <div class="rounded-md p-5 flex flex-col" style="background: var(--surface); border: 1px solid var(--border);">
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

                    @if($tour['description'])
                        <p class="text-xs mt-3 flex-1" style="color: var(--text-secondary);">{{ $tour['description'] }}</p>
                    @endif

                    <div class="mt-4">
                        @if($tour['url'])
                            <a href="{{ $tour['url'] }}" class="corex-btn-primary text-sm inline-flex items-center gap-1.5">
                                Start tour
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        @else
                            {{-- Route needs a specific record (e.g. open a contact first). --}}
                            <span class="text-xs" style="color: var(--text-muted);">
                                Open the relevant record, then tap the “?” in its header to start this tour.
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
