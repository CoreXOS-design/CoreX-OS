{{--
    Shared syndication viewer for the Properties index. One instance per page;
    every card/row trigger fills it via openSyn(payload) on the page-root scope.

    Read-only by design — it answers "where is this listing published, and can I
    open it?". Changing syndication state stays on the property's own page,
    where the compliance gates and per-portal controls live.
--}}
<template x-teleport="body">
<div x-show="syn" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4"
     style="background:rgba(0,0,0,0.5);"
     @click.self="syn = null"
     @keydown.escape.window="syn = null"
     x-transition.opacity>
    <div class="w-full max-w-md rounded-md overflow-hidden flex flex-col" style="max-height:80vh;
         background:var(--surface);border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,0.3);">

        <div class="flex items-start justify-between gap-3 px-4 py-3 flex-shrink-0" style="border-bottom:1px solid var(--border);">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold" style="color:var(--text-primary);">Syndication</h3>
                <p class="text-xs truncate mt-0.5" style="color:var(--text-muted);" x-text="syn?.title"></p>
            </div>
            <button type="button" @click="syn = null"
                    class="inline-flex items-center justify-center w-7 h-7 rounded-md flex-shrink-0 transition-all duration-300"
                    style="color:var(--text-muted);"
                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''"
                    aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="flex-1 p-3 space-y-2" style="overflow-y:auto;">
            <template x-for="link in (syn?.links ?? [])" :key="link.portal">
                <div class="flex items-center gap-3 px-3 py-2.5 rounded-md" style="background:var(--surface-2);border:1px solid var(--border);">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-semibold truncate" style="color:var(--text-primary);" x-text="link.label"></div>
                        <div class="text-[10px] font-mono mt-0.5 truncate" style="color:var(--text-muted);"
                             x-text="link.ref ? ('Ref: ' + link.ref) : 'No reference'"></div>
                    </div>

                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded flex-shrink-0"
                          :style="link.status === 'live'
                              ? 'background:var(--ds-green, #059669);color:#fff;'
                              : 'background:var(--surface);color:var(--text-muted);border:1px solid var(--border);'"
                          x-text="link.status === 'live' ? 'Live' : 'Not published'"></span>

                    <template x-if="link.url">
                        <a :href="link.url" target="_blank" rel="noopener"
                           class="corex-btn-outline text-[10px] px-2 py-1 inline-flex items-center gap-1 flex-shrink-0 no-underline"
                           :title="'Open on ' + link.label">
                            Open
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                            </svg>
                        </a>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
</template>
