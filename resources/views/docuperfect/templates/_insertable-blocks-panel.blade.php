{{-- Shared "Insertable Blocks & Clauses" panel (2026-08-20) — @include'd by
     both cds-builder.blade.php (CDS import review) and edit-web.blade.php's
     Content tab (the ongoing template editor). Same markup, same Alpine
     methods (via insertableBlocksMixin() in _insertable-blocks-mixin.blade.php,
     spread into each page's own x-data) — one insertion mechanism, not two
     that can drift apart. Requires a contenteditable #docContainer on the
     page and the mixin's state/methods present on the enclosing x-data. --}}
<div class="mb-4 border border-gray-200 rounded-lg overflow-hidden">
    <button type="button" @click="insertBlockExpanded = !insertBlockExpanded"
            class="w-full flex items-center justify-between px-3 py-2.5 bg-gray-50 hover:bg-gray-100 transition-colors text-left">
        <span class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
            <span>&#10133;</span> Insertable Blocks &amp; Clauses
        </span>
        <span class="text-xs text-gray-500" x-text="insertBlockExpanded ? '&#9650;' : '&#9660;'"></span>
    </button>

    <div x-show="insertBlockExpanded" x-transition class="border-t border-gray-200 p-3 space-y-3">
        <div>
            <label class="text-[10px] font-semibold text-gray-500 uppercase block mb-1">Insert block placeholder</label>
            <p class="text-[10px] text-gray-400 mb-2">Click in the document where you want the block, then click an option.</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="insertBlockMarker('OTHER_CONDITIONS')"
                        class="text-[11px] px-2 py-1 rounded border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800">
                    Other Conditions
                </button>
                <button type="button" @click="insertBlockMarker('INCLUDED_ITEMS')"
                        class="text-[11px] px-2 py-1 rounded border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-800">
                    Included Items
                </button>
                <button type="button" @click="insertBlockMarker('EXCLUDED_ITEMS')"
                        class="text-[11px] px-2 py-1 rounded border border-rose-300 bg-rose-50 hover:bg-rose-100 text-rose-800">
                    Excluded Items
                </button>
                <button type="button" @click="insertBlockMarker('CUSTOM')"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 bg-white hover:bg-gray-50 text-gray-700">
                    Custom Named…
                </button>
            </div>
        </div>

        <div class="border-t border-gray-100 pt-3">
            <label class="text-[10px] font-semibold text-gray-500 uppercase block mb-1">Clause library</label>
            <p class="text-[10px] text-gray-400 mb-2">Insert pre-approved clause text at cursor.</p>
            <input type="text" x-model="clauseSearch" @input.debounce.300ms="loadClauses()"
                   placeholder="Search clauses…"
                   class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 bg-white mb-2">
            <div class="max-h-48 overflow-y-auto border border-gray-100 rounded">
                <template x-if="!clauseList.length">
                    <div class="text-[10px] text-gray-400 px-2 py-2" x-text="clausesLoading ? 'Loading…' : 'No clauses match.'"></div>
                </template>
                <template x-for="c in clauseList" :key="c.id">
                    <button type="button" @click="insertClauseAtCursor(c)"
                            class="w-full text-left text-xs px-2 py-1.5 hover:bg-teal-50 border-b border-gray-50 last:border-b-0">
                        <div class="font-semibold text-gray-700" x-text="c.name"></div>
                        <div class="text-[10px] text-gray-500 line-clamp-2" x-text="(c.text || '').substring(0, 90)"></div>
                    </button>
                </template>
            </div>
        </div>
    </div>
</div>
