{{-- Sticky Action Bar Component --}}
{{-- Usage:
    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="/back" class="inline-flex items-center gap-1 text-sm" style="color: var(--text-secondary);">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold truncate" style="color: var(--text-secondary);">Page Title</h2>
        </x-slot>
        <x-slot name="right">
            <button class="px-4 py-2 text-white text-sm font-medium rounded-lg" style="background: var(--brand-button);">Save</button>
        </x-slot>
    </x-sticky-action-bar>
--}}

<div class="sticky top-0 z-50 shadow-sm -mx-4 lg:-mx-6 -mt-4 lg:-mt-6 mb-4 lg:mb-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
    <div class="px-4 sm:px-6 lg:px-8">
        {{-- Johan, 2026-09-09 — the rental-application review header's Save
             button (opened alongside a document, with the highlighter's own
             tool/category/size/undo/redo controls) was "hanging over /
             clipping outside its container" at narrower widths. Root cause:
             this row was a FIXED h-14 while the right slot's own content
             already wraps (flex-wrap) once it can't fit on one line — a
             fixed height doesn't grow for a wrapped second row, so that row
             rendered outside the bar's boundary instead of inside it.
             min-h-14 (not h-14) lets the bar grow to whatever its content
             actually needs; every existing consumer whose content already
             fits on one line renders byte-identical (56px is still the
             floor), so this is additive, not a visual change for anyone
             already working correctly. --}}
        <div class="flex items-center justify-between min-h-14 py-2 flex-wrap gap-y-2">
            {{-- Left side: Back button, breadcrumbs --}}
            <div class="flex items-center gap-3">
                {{ $left ?? '' }}
            </div>

            {{-- Center: Page title or context --}}
            <div class="flex-1 text-center truncate mx-4" style="color: var(--text-primary);">
                {{ $center ?? '' }}
            </div>

            {{-- Right side: Primary actions --}}
            <div class="flex items-center gap-2">
                {{ $right ?? '' }}
            </div>
        </div>
    </div>
</div>
