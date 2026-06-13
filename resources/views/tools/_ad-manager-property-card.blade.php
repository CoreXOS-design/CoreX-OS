{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Selectable property card for the Ad Manager. Expects Alpine `p` (from x-for) + `selected`. --}}
<label class="rounded-md overflow-hidden cursor-pointer block transition-all duration-300"
       style="background:var(--surface-2); border:1.5px solid var(--border);"
       :style="selected.includes(p.id) ? 'border-color:var(--brand-button,#0ea5e9); box-shadow:0 0 0 1px var(--brand-button,#0ea5e9);' : ''">
    <div class="relative w-full h-36" style="background:var(--surface);">
        <template x-if="p.thumb"><img :src="p.thumb" alt="" class="w-full h-full object-cover block"></template>
        <template x-if="!p.thumb"><div class="w-full h-full flex items-center justify-center text-xs" style="color:var(--text-muted);">No image</div></template>
        <div class="absolute top-2 left-2 rounded-md p-1 leading-none bg-black/40">
            <input type="checkbox" :value="p.id" x-model="selected" class="w-5 h-5 rounded" style="accent-color:var(--brand-button,#0ea5e9);">
        </div>
    </div>
    <div class="p-3">
        <div class="text-sm font-bold truncate" style="color:var(--text-primary);" x-text="p.title"></div>
        <div class="text-xs truncate mt-0.5" style="color:var(--text-secondary);" x-text="p.address || p.suburb"></div>
        <div class="text-xs font-semibold mt-1" style="color:var(--brand-icon,#0ea5e9);" x-text="p.price"></div>
    </div>
</label>
