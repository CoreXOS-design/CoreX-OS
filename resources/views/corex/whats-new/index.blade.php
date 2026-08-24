{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     What's New — the user-facing archive. Read-only.
     Spec: .ai/specs/system-updates.md §7.5 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">What's New</h1>
                <p class="text-xs" style="color: var(--text-muted);">Everything that has changed in CoreX since you joined.</p>
            </div>
        </div>
    </div>

    {{-- Type filter --}}
    @php $types = collect(config('system-updates.types', []))->sortBy('sort'); @endphp
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <a href="{{ route('corex.whats-new.index') }}"
           class="px-3 py-1.5 rounded-md no-underline"
           style="background:{{ $activeType === '' ? 'var(--surface-2)' : 'transparent' }}; color:var(--text-primary); border:1px solid var(--border);">
            All
        </a>
        @foreach($types as $key => $meta)
            <a href="{{ route('corex.whats-new.index', ['type' => $key]) }}"
               class="px-3 py-1.5 rounded-md no-underline"
               style="background:{{ $activeType === $key ? "color-mix(in srgb, var({$meta['token']}, {$meta['fallback']}) 15%, transparent)" : 'transparent' }};
                      color:{{ $activeType === $key ? "var({$meta['token']}, {$meta['fallback']})" : 'var(--text-primary)' }};
                      border:1px solid {{ $activeType === $key ? "var({$meta['token']}, {$meta['fallback']})" : 'var(--border)' }};">
                {{ $meta['label'] }}
            </a>
        @endforeach
    </div>

    {{-- The archive is a SCANNABLE LIST, not a wall of release notes. Each row carries
         only the three things you triage on — what kind of change it is, what it was,
         and when — and opens on click to reveal the detail. Rendering every body at full
         length made a year of updates unreadable: the reader had to scroll past changes
         they already knew about to find the one they were looking for.

         The detail is rendered server-side inside the row (not fetched) — it is already
         on the page, and the eligibility filter has already run, so expanding is instant
         and costs no request. --}}
    @forelse($updates as $update)
        @php $isPending = in_array($update->id, $pendingIds, true); @endphp
        <div x-data="{ open: false }"
             class="rounded-md overflow-hidden"
             style="background:var(--surface); border:1px solid var(--border);">

            {{-- Collapsed row — the whole thing is the control, so the click target is the
                 row and not a word inside it. --}}
            <button type="button"
                    @click="open = !open"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-controls="whats-new-detail-{{ $update->id }}"
                    class="w-full text-left px-5 py-4 flex items-center gap-3">

                {{-- What it is --}}
                @php $chip = $update->typeChip(); @endphp
                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-[0.6875rem] font-bold uppercase tracking-wide shrink-0"
                      style="background:color-mix(in srgb, var({{ $chip['token'] }}, {{ $chip['fallback'] }}) 15%, transparent);
                             color:var({{ $chip['token'] }}, {{ $chip['fallback'] }});">
                    {{ $chip['label'] }}
                </span>

                {{-- Title --}}
                <span class="flex-1 min-w-0 text-sm font-semibold truncate" style="color:var(--text-primary);">
                    {{ $update->title }}
                </span>

                @if($isPending)
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[0.6875rem] font-bold uppercase tracking-wide shrink-0"
                          style="background:color-mix(in srgb, var(--brand-icon) 15%, transparent); color:var(--brand-icon);">
                        New
                    </span>
                @endif

                {{-- When --}}
                <span class="text-xs tabular-nums shrink-0" style="color:var(--text-muted);">
                    {{ $update->published_at?->format('d M Y') }}
                </span>

                {{-- Affordance: this row opens. --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                     stroke="currentColor" class="w-4 h-4 shrink-0 transition-transform duration-150"
                     :class="open ? 'rotate-180' : ''" style="color:var(--text-muted);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {{-- Full detail --}}
            <div x-show="open" x-cloak
                 id="whats-new-detail-{{ $update->id }}"
                 class="px-5 pb-5 pt-1"
                 style="border-top:1px solid var(--border);">
                @include('layouts.partials._system-update-card', ['update' => $update, 'showHeader' => false])
            </div>
        </div>
    @empty
        <div class="rounded-md p-8 text-center text-sm"
             style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
            @if($activeType !== '')
                Nothing of that kind yet.
            @else
                Nothing new since you joined. When something changes in CoreX, it will appear here.
            @endif
        </div>
    @endforelse
</div>
@endsection
