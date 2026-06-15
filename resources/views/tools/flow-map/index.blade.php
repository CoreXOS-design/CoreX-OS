{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20. --}}
{{--
    Flow Map — a full, read-only, permission-aware guide to everything in
    CoreX: every module, how to use it, and what it connects to next.
    Spec: .ai/specs/flows-map.md. Owns no data; clickable navigation only.
--}}
@extends('layouts.corex')

@section('corex-content')
@php
    $svgFor = function (string $key) {
        return match ($key) {
            'dashboard'    => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'calendar'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
            'tasks'        => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m18 9 2 2 4-4"/>',
            'radar'        => '<path d="M19.07 4.93A10 10 0 0 0 6.99 3.34"/><path d="M4 6h.01"/><path d="M2.29 9.62A10 10 0 1 0 21.31 8.35"/><path d="M16.24 7.76A6 6 0 1 0 8.23 16.67"/><path d="M12 18h.01"/><circle cx="12" cy="12" r="2"/><path d="m13.41 10.59 5.66-5.66"/>',
            'layers'       => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
            'contacts'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'presentation' => '<path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/>',
            'send'         => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'signature'    => '<path d="M20 19.5v.5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7l5 5v3.5"/><path d="M13 2v6h6"/><path d="M16 18s1-2 3-2 3 2 3 2"/>',
            'milestone'    => '<path d="M12 13v8"/><path d="M12 3v3"/><path d="M4 6h13l3 3-3 3H4Z"/>',
            'home'         => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M9 22V12h6v10"/>',
            'match'        => '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M8.5 8.5 15 15"/><path d="M9 6h6a3 3 0 0 1 3 3v6"/>',
            'handshake'    => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M3 4h8"/><path d="m3 9 4 4"/>',
            'shield'       => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/><path d="m9 12 2 2 4-4"/>',
            'cash'         => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
            'key'          => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
            'folder'       => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/>',
            'file'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
            'book'         => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
            'sparkle'      => '<path d="M9.94 14.06 8.5 20l-1.44-5.94L1 12.5l6.06-1.44L8.5 5l1.44 6.06L16 12.5Z"/><path d="M19 4v4"/><path d="M17 6h4"/>',
            'shield-user'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1Z"/><circle cx="12" cy="10" r="2"/><path d="M9 16a3 3 0 0 1 6 0"/>',
            'user-plus'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
            'building'     => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01"/>',
            'cog'          => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
            'flow'         => '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M6 9v6a3 3 0 0 0 3 3h6"/>',
            'code'         => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'import'       => '<path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M3 21h18"/>',
            default        => '<circle cx="12" cy="12" r="9"/>',
        };
    };

    $nodesByCategory = collect($nodes)->groupBy('category');
    $nodeIndex = collect($nodes)->keyBy('key');
@endphp

<div class="w-full space-y-5" x-data="{ cat: 'all' }">

    {{-- Page header (branded, Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Flow Map</h1>
                <p class="text-sm text-white/60">Your guide to all of CoreX — what each part does, how to use it, and what comes next. New here? Start at the top and follow the arrows.</p>
            </div>
        </div>
    </div>

        {{-- Section jump / filter chips --}}
        <div class="flex flex-wrap gap-2">
            <button type="button" @click="cat = 'all'"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md transition"
                    :style="cat === 'all'
                        ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);'
                        : 'background: var(--surface-2, #f0f2f8); color: var(--text-secondary, #4b5563);'">
                Show everything
            </button>
            @foreach($categories as $category)
            <button type="button" @click="cat = '{{ $category['key'] }}'"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md transition"
                    :style="cat === '{{ $category['key'] }}'
                        ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);'
                        : 'background: var(--surface-2, #f0f2f8); color: var(--text-secondary, #4b5563);'">
                {{ $category['label'] }}
            </button>
            @endforeach
        </div>

        @foreach($categories as $ci => $category)
        @php $catNodes = $nodesByCategory->get($category['key'], collect()); @endphp
        @if($catNodes->isNotEmpty())
        <section
                 x-show="cat === 'all' || cat === '{{ $category['key'] }}'"
                 x-transition>

            {{-- Section header --}}
            <div class="flex items-baseline gap-3 mb-1">
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0"
                      style="background: var(--brand-default, #0b2a4a); color: #ffffff;">{{ $ci + 1 }}</span>
                <h2 class="text-lg font-bold" style="color: var(--text-primary, #111827);">{{ $category['label'] }}</h2>
            </div>
            <p class="text-sm mb-4 ml-9" style="color: var(--text-muted, #9ca3af);">{{ $category['description'] }}</p>

            {{-- Responsive wrapping grid — no horizontal scroll --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($catNodes as $node)
                @php
                    $clickable = !empty($node['url']);
                    $tag = $clickable ? 'a' : 'div';
                @endphp
                <{{ $tag }}
                    @if($clickable) href="{{ $node['url'] }}" @endif
                    @if(!$clickable) title="{{ $node['label'] }} — {{ $node['description'] }}" @endif
                    class="group flex flex-col rounded-md p-4 transition-all duration-300 {{ $clickable ? 'cursor-pointer' : 'cursor-default' }}"
                    style="background: var(--surface, #ffffff); border: 1px solid var(--border, rgba(0,0,0,0.07));"
                    @if($clickable)
                    onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'"
                    onmouseout="this.style.borderColor='var(--border, rgba(0,0,0,0.07))'"
                    @endif
                >
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-md flex-shrink-0"
                              style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                {!! $svgFor($node['icon']) !!}
                            </svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-bold" style="color: var(--text-primary, #111827);">{{ $node['label'] }}</span>
                                @if($clickable)
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-icon, #0ea5e9);"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                                @else
                                <span class="text-[10px] px-1 py-0.5 rounded" style="background: var(--surface-2, #f0f2f8); color: var(--text-muted, #9ca3af);">step</span>
                                @endif
                            </div>
                            <p class="text-xs mt-1" style="color: var(--text-secondary, #4b5563);">{{ $node['description'] }}</p>
                        </div>
                    </div>

                    {{-- How to use it --}}
                    @if(!empty($node['steps']))
                    <ol class="mt-3 space-y-1">
                        @foreach($node['steps'] as $si => $step)
                        <li class="flex gap-2 text-xs" style="color: var(--text-secondary, #4b5563);">
                            <span class="font-bold flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);">{{ $si + 1 }}.</span>
                            <span>{{ $step }}</span>
                        </li>
                        @endforeach
                    </ol>
                    @endif

                    {{-- Sets in motion (live from event catalogue) --}}
                    @if(!empty($node['triggers']))
                    <div class="mt-3 pt-3" style="border-top: 1px dashed var(--border, rgba(0,0,0,0.07));">
                        <div class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color: var(--text-muted, #9ca3af);">Sets in motion</div>
                        <div class="flex flex-wrap gap-1">
                            @foreach($node['triggers'] as $trigger)
                            <span class="text-[10px] font-medium px-1.5 py-0.5 rounded"
                                  title="{{ $trigger['summary'] ?: $trigger['name'] }}"
                                  style="background: var(--surface-2, #f0f2f8); color: var(--text-secondary, #4b5563);">
                                {{ $trigger['name'] }}
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- What comes next --}}
                    @if(!empty($node['next']))
                    <div class="mt-3 flex flex-wrap items-center gap-1.5">
                        <span class="text-[10px] font-semibold uppercase tracking-wide" style="color: var(--text-muted, #9ca3af);">Next</span>
                        @foreach($node['next'] as $nextKey)
                        @php $nextNode = $nodeIndex->get($nextKey); @endphp
                        @if($nextNode)
                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded inline-flex items-center gap-1"
                              style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent); color: var(--brand-icon, #0ea5e9);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            {{ $nextNode['label'] }}
                        </span>
                        @endif
                        @endforeach
                    </div>
                    @endif
                </{{ $tag }}>
                @endforeach
            </div>
        </section>
        @endif
        @endforeach

        @if(empty($nodes))
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface, #ffffff); border: 1px solid var(--border, rgba(0,0,0,0.07));">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="15" width="6" height="6" rx="1"/><path d="M6 9v6a3 3 0 0 0 3 3h6"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">Nothing to map yet</h3>
            <p class="text-sm" style="color: var(--text-muted, #9ca3af);">No parts of CoreX are visible for your access level yet.</p>
        </div>
        @endif

        <p class="text-xs" style="color: var(--text-muted, #9ca3af);">
            You only see the parts of CoreX you have access to. Click any card with an arrow to jump straight there.
        </p>
</div>
@endsection
