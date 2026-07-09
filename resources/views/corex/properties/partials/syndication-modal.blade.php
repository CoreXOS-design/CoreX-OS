{{--
    Properties index — the syndication modal.

    One instance per page. A card/row trigger calls openSyn(id, title) on the
    page-root Alpine scope, which fetches the SHARED syndication panel for that
    property (GET /api/v1/properties/{property}/syndication-panel) and injects
    it here. The panel is byte-for-byte the surface the property page renders,
    so toggle / refresh / deactivate / live preview all behave identically and
    can never drift apart.

    Requires on the page-root scope: syn, synStep, synLoading, synError.
    Requires once per page: @include('corex.properties.partials.syndication-scripts')
--}}
<template x-teleport="body">
<div x-show="syn" x-cloak
     class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
     x-transition.opacity>

    {{-- Backdrop --}}
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.55); backdrop-filter:blur(2px);"
         @click="closeSyn()"></div>

    {{-- Modal card — matches the property page's syndication modal --}}
    <div class="relative rounded-md shadow-2xl"
         style="width:440px; max-width:95vw; max-height:88vh; overflow-y:auto; background:var(--surface); border:1px solid var(--border);"
         @keydown.escape.window="closeSyn()"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
            <div class="flex items-center gap-2 min-w-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--brand-icon);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z"/>
                </svg>
                <div class="min-w-0">
                    <div class="text-sm font-semibold leading-tight" style="color:var(--text-primary);">Syndication</div>
                    <div class="text-[11px] truncate" style="color:var(--text-muted);" x-text="syn?.title"></div>
                </div>
            </div>
            <button type="button" @click="closeSyn()"
                    class="p-1 rounded transition-colors flex-shrink-0"
                    style="color:var(--text-muted);"
                    onmouseover="this.style.color='var(--text-primary)'; this.style.background='var(--surface-2)'"
                    onmouseout="this.style.color='var(--text-muted)'; this.style.background='transparent'"
                    aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Loading --}}
        <div x-show="synLoading" class="p-6 text-center text-xs" style="color:var(--text-muted);">
            Loading syndication…
        </div>

        {{-- Load failure — honest, never a blank panel --}}
        <div x-show="synError" x-cloak class="m-4 rounded-md px-3 py-2 text-xs"
             style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);"
             x-text="synError"></div>

        {{-- The shared panel lands here (steps main + preview) --}}
        <div x-ref="synBody" x-show="!synLoading && !synError"></div>
    </div>
</div>
</template>
